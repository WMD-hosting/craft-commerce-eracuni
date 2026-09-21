<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\helpers\App;
use craft\helpers\FileHelper;
use wmd\commerceeracuni\core\BuilderConfig;
use wmd\commerceeracuni\core\Client;
use wmd\commerceeracuni\core\ClientInterface;
use wmd\commerceeracuni\core\Delivery;
use wmd\commerceeracuni\core\EracuniException;
use wmd\commerceeracuni\core\InvoiceBuilder;
use wmd\commerceeracuni\core\Partners;
use wmd\commerceeracuni\core\PaymentMethodMap;
use wmd\commerceeracuni\jobs\SendInvoiceJob;
use wmd\commerceeracuni\models\Document;
use wmd\commerceeracuni\Plugin;
use wmd\commerceeracuni\records\DocumentRecord;

class Documents extends Component
{
    private ?ClientInterface $client = null;

    public function setClient(ClientInterface $client): void
    {
        $this->client = $client;
    }

    public function client(): ClientInterface
    {
        if ($this->client === null) {
            $s = Plugin::getInstance()->getSettings();
            $this->client = new Client(App::parseEnv($s->apiUrl), App::parseEnv($s->username), App::parseEnv($s->authToken));
        }
        return $this->client;
    }

    public function builderConfig(): BuilderConfig
    {
        $s = Plugin::getInstance()->getSettings();
        $last = (new \craft\db\Query())->from(DocumentRecord::tableName())
            ->where(['status' => DocumentRecord::STATUS_SENT])->max('dateCreated');
        return new BuilderConfig(
            sellerCountry: $s->sellerCountry,
            businessUnit: App::parseEnv($s->businessUnit),
            cashRegisterCode: $s->cashRegisterCode,
            dueDays: (int) $s->dueDays,
            invoiceDateSource: $s->invoiceDateSource,
            today: (new \DateTime('now', new \DateTimeZone(Craft::$app->getTimeZone())))->format('Y-m-d'),
            paymentMap: $s->paymentMap,
            knownRates: array_map('floatval', $s->knownRates()),
            shippingKpd: $s->shippingKpd,
            defaultKpd: $s->defaultKpd !== '' ? $s->defaultKpd : null,
            shippingDescription: Craft::t('commerce-eracuni', 'Shipping'),
            discountDescription: Craft::t('commerce-eracuni', 'Discount'),
            lastInvoiceDate: $last ? substr((string) $last, 0, 10) : null,
        );
    }

    public function getForOrder(int $orderId, string $kind = DocumentRecord::KIND_INVOICE): ?Document
    {
        $r = DocumentRecord::findOne(['orderId' => $orderId, 'kind' => $kind]);
        return $r ? Document::fromRecord($r) : null;
    }

    public function shouldAutoSend(Order $order): bool
    {
        $s = Plugin::getInstance()->getSettings();
        if (!$order->isCompleted || !$s->autoSend) {
            return false;
        }
        if (!in_array($order->getOrderStatus()?->handle, $s->triggerStatuses, true)) {
            return false;
        }
        if ($s->requirePaid && !$order->getIsPaid()) {
            return false;
        }
        $existing = DocumentRecord::findOne(['orderId' => $order->id, 'kind' => DocumentRecord::KIND_INVOICE]);
        return $existing === null || $existing->status === DocumentRecord::STATUS_FAILED;
    }

    public function queue(Order|int $order, bool $force = false): void
    {
        $id = $order instanceof Order ? (int) $order->id : $order;
        Craft::$app->getQueue()->priority(10)->push(new SendInvoiceJob(['orderId' => $id, 'force' => $force]));
        Plugin::info("Queued invoice for order {$id}");
    }

    public function claim(Order $order, bool $force): ?DocumentRecord
    {
        $r = DocumentRecord::findOne(['orderId' => $order->id, 'kind' => DocumentRecord::KIND_INVOICE]);
        if ($r === null) {
            $r = new DocumentRecord();
            $r->orderId = (int) $order->id;
            $r->kind = DocumentRecord::KIND_INVOICE;
            $r->status = DocumentRecord::STATUS_PENDING;
            $r->attempts = 1;
            try {
                $r->save(false);
            } catch (\yii\db\IntegrityException) {
                // Another caller inserted the (orderId, kind) row first.
                return null;
            }
            return $r;
        }
        if ($r->status === DocumentRecord::STATUS_SENT) {
            return null;
        }
        // A pending row past the job's TTR is presumed abandoned by a crashed/killed worker.
        $updatedAt = \craft\helpers\DateTimeHelper::toDateTime($r->dateUpdated, false);
        $stale = $r->status === DocumentRecord::STATUS_PENDING
            && ($updatedAt !== false ? $updatedAt->getTimestamp() : 0) < time() - SendInvoiceJob::TTR;
        if ($r->status === DocumentRecord::STATUS_PENDING && !$force && !$stale) {
            // Another worker owns it (mutex makes this rare); leave it.
            return null;
        }
        // Failed, stale pending, or pending with $force: conditional takeover so two
        // concurrent claimers can't both win.
        $n = DocumentRecord::updateAll(
            [
                'status' => DocumentRecord::STATUS_PENDING,
                'attempts' => $r->attempts + 1,
                'error' => null,
                'dateUpdated' => \craft\helpers\Db::prepareDateForDb(new \DateTime()),
            ],
            ['id' => $r->id, 'status' => $r->status],
        );
        if ($n === 0) {
            return null;
        }
        $r->refresh();
        return $r;
    }

    /** @return array{payload:array, treatment:string, method:string, fiscalised:bool, warnings:string[], computedTotal:float} */
    public function preview(Order $order): array
    {
        $snap = Plugin::getInstance()->snapshots->fromOrder($order);
        $cfg = $this->builderConfig();
        $r = InvoiceBuilder::build($snap, $cfg, '<partnerID>', '<buyerCode>');
        return ['payload' => $r->payload, 'treatment' => $r->treatment->code, 'method' => $r->method, 'fiscalised' => $r->fiscalised, 'warnings' => $r->warnings, 'computedTotal' => $r->computedTotal];
    }

    /** Runs the whole pipeline for one order. Caller holds the mutex. */
    public function send(Order $order, bool $force = false): Document
    {
        $record = $this->claim($order, $force);
        if ($record === null) {
            $existing = $this->getForOrder((int) $order->id);
            Plugin::info("Order {$order->id}: nothing to send (status {$existing?->status}).");
            return $existing ?? new Document();
        }

        $s = Plugin::getInstance()->getSettings();
        $client = $this->client();

        try {
            $snapshots = Plugin::getInstance()->snapshots;
            $snap = $snapshots->fromOrder($order);
            $cfg = $this->builderConfig();

            // 1. Treatment + partner.
            $dry = InvoiceBuilder::build($snap, $cfg, null, null);
            $oib = $snapshots->billingOib($order);
            $isB2G = $oib !== null && in_array($oib, $s->b2gTaxIds, true);
            $partner = (new Partners($client, App::parseEnv($s->partnerCodePrefix)))->getOrCreate($snap->buyer, $dry->treatment, $snapshots->customerKey($order), $isB2G);

            // 2. Build, then create — unless a previous attempt already created the
            // invoice and only failed on a later (non-fatal) step; resume from there.
            $built = InvoiceBuilder::build($snap, $cfg, $partner->documentId, $partner->buyerCode);
            $payload = $built->payload;
            $warnings = $built->warnings;

            $resuming = $record->documentId !== null && $record->documentId !== '';
            if ($resuming) {
                // Leave $record->treatment/fiscalised/method as claim() refreshed them —
                // a previous attempt may have landed on a different (fallback) method than
                // a fresh build would compute, and payload/response already reflect it.
                Plugin::info("Order {$order->id}: invoice {$record->documentId} already created; resuming post-create steps.");
            } else {
                $record->treatment = $built->treatment->code;
                $record->fiscalised = $built->fiscalised;
                $record->method = $built->method;
                $res = $client->call('SalesInvoiceCreate', ['SalesInvoice' => json_encode($payload, JSON_UNESCAPED_UNICODE)], 3, 120);
                if ($built->treatment->isRetail && ($res['response']['status'] ?? '') === 'error' && self::isMethodRejection($res)) {
                    foreach (PaymentMethodMap::retailFallbacks($built->method) as $fallback) {
                        $payload['methodOfPayment'] = $fallback;
                        $res = $client->call('SalesInvoiceCreate', ['SalesInvoice' => json_encode($payload, JSON_UNESCAPED_UNICODE)], 3, 120);
                        if (($res['response']['status'] ?? '') === 'ok') {
                            $record->method = $fallback;
                            $record->fiscalised = PaymentMethodMap::entry($fallback)['fiscalised'];
                            break;
                        }
                        if (!self::isMethodRejection($res)) {
                            break;
                        }
                    }
                }
                $record->payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
                $record->response = json_encode($res, JSON_UNESCAPED_UNICODE);
                if (($res['response']['status'] ?? '') !== 'ok') {
                    throw EracuniException::domain('SalesInvoiceCreate failed: ' . ($res['response']['description'] ?? json_encode($res)));
                }
                $record->documentId = (string) ($res['response']['result']['documentID'] ?? '');
                $record->number = (string) ($res['response']['result']['number'] ?? '');
                if ($record->documentId === '') {
                    throw EracuniException::domain('SalesInvoiceCreate returned no documentID.');
                }
            }

            // 3. PDF (non-fatal).
            try {
                $pdf = $client->get('SalesInvoiceGetPDF', ['documentID' => $record->documentId]);
                $b64 = $pdf['response']['result']['pdfFile'] ?? $pdf['response']['result']['fileContent'] ?? null;
                if ($b64) {
                    $dir = Craft::getAlias('@storage/commerce-eracuni/' . date('Y'));
                    FileHelper::createDirectory($dir);
                    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $record->number ?: $record->documentId) . '.pdf';
                    file_put_contents($dir . DIRECTORY_SEPARATOR . $name, base64_decode($b64));
                    $record->pdfPath = $dir . DIRECTORY_SEPARATOR . $name;
                }
            } catch (\Throwable $e) {
                $warnings[] = 'PDF: ' . $e->getMessage();
                Plugin::warning("Order {$order->id}: PDF download failed: " . $e->getMessage());
            }

            // 4. Payment record for already-paid orders (non-fatal).
            if ($snap->isPaid && $built->method !== 'Other' && $s->syncPayments) {
                try {
                    $pm = PaymentMethodMap::forGateway($snap->gatewayHandle, $s->paymentMap)['paymentMethodForInvoice'];
                    $client->call('SalesInvoicePaymentRecordAdd', [
                        'documentID' => $record->documentId,
                        'paymentAmount' => number_format($snap->totalPrice, 2, '.', ''),
                        'paymentCurrency' => $snap->currency,
                        'paymentDate' => $snap->datePaid ?? $snap->dateOrdered,
                        'paymentMethodForInvoice' => $pm,
                        'description' => 'Payment for order ' . $snap->number,
                    ]);
                } catch (\Throwable $e) {
                    $warnings[] = 'Payment record: ' . $e->getMessage();
                    Plugin::warning("Order {$order->id}: payment record failed: " . $e->getMessage());
                }
            }

            // 5. Delivery (non-fatal, recorded).
            if ($built->treatment->isBusiness && $oib !== null) {
                try {
                    $delivery = new Delivery($client);
                    $result = null;
                    if ($isB2G && $s->deliverFina) {
                        $result = $delivery->finaReceiverActive($oib) === false
                            ? null
                            : $delivery->sendFina($record->documentId, $oib);
                        if ($result === null) {
                            $warnings[] = "FINA receiver {$oib} not active; not sent.";
                            Plugin::warning("Order {$order->id}: FINA receiver {$oib} not active; not sent.");
                        }
                    } elseif (!$isB2G && $s->deliverAs4) {
                        $result = $delivery->sendAs4($record->documentId);
                    }
                    if ($result !== null) {
                        $record->deliveryChannel = $result->channel;
                        $record->deliveryTxnId = $result->transactionId;
                        $record->deliveryStatus = $result->status;
                        $record->deliveryBucket = $result->bucket;
                        if (!$result->ok) {
                            $warnings[] = "{$result->channel} send failed: {$result->message}";
                            Plugin::warning("Order {$order->id}: {$result->channel} send failed: {$result->message}");
                        }
                    }
                } catch (\Throwable $e) {
                    $warnings[] = 'Delivery: ' . $e->getMessage();
                    Plugin::warning("Order {$order->id}: delivery failed: " . $e->getMessage());
                }
            }

            $record->status = DocumentRecord::STATUS_SENT;
            $record->error = $warnings ? implode("\n", $warnings) : null;
            $record->save(false);
            Plugin::info("Order {$order->id}: invoice {$record->number} created ({$record->treatment}, {$record->method}).");
            return Document::fromRecord($record);
        } catch (\Throwable $e) {
            $record->status = DocumentRecord::STATUS_FAILED;
            $record->error = mb_substr($e->getMessage(), 0, 2000);
            try {
                $record->save(false);
            } catch (\Throwable $saveError) {
                Plugin::error("Order {$order->id}: could not persist failure: " . $saveError->getMessage());
            }
            Plugin::error("Order {$order->id}: " . $e->getMessage());
            throw $e;
        }
    }

    private static function isMethodRejection(array $res): bool
    {
        $d = strtolower((string) ($res['response']['description'] ?? ''));
        return str_contains($d, 'methodofpayment') || str_contains($d, 'not allowed');
    }
}
