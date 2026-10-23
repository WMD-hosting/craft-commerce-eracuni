<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\console\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use wmd\commerceeracuni\jobs\SendInvoiceJob;
use wmd\commerceeracuni\Plugin;
use wmd\commerceeracuni\records\DocumentRecord;
use yii\console\ExitCode;

/**
 * `craft commerce-eracuni/sync/*` console commands: connection/document status,
 * manual single-order sends, backfilling orders missed by the auto-send trigger,
 * retrying failed documents, and refreshing pending AS4/FINA delivery statuses.
 */
class SyncController extends Controller
{
    public bool $dryRun = false;
    public int $limit = 50;
    public ?string $since = null;
    public bool $all = false;
    /** The attempt of an outcome-unknown document that was checked in e-računi and found not booked. */
    public ?string $verifiedAttempt = null;

    public function options($actionID): array
    {
        return match ($actionID) {
            'backfill' => ['dryRun', 'limit', 'since'],
            'order' => ['verifiedAttempt'],
            'retry' => ['all', 'limit'],
            'delivery-status' => ['limit'],
            default => [],
        };
    }

    /** Connection check and document counts. */
    public function actionStatus(): int
    {
        try {
            $res = Plugin::getInstance()->documents->client()->get('PartnerList', ['limit' => 1], 1);
            $ok = ($res['response']['status'] ?? '') === 'ok';
            $this->stdout('Connection: ' . ($ok ? 'OK' : 'FAILED ' . json_encode($res)) . PHP_EOL, $ok ? Console::FG_GREEN : Console::FG_RED);
        } catch (\Throwable $e) {
            $this->stdout('Connection: ERROR ' . $e->getMessage() . PHP_EOL, Console::FG_RED);
        }
        foreach ([DocumentRecord::STATUS_SENT, DocumentRecord::STATUS_FAILED, DocumentRecord::STATUS_PENDING] as $s) {
            $this->stdout(sprintf("%-8s %d\n", $s, DocumentRecord::find()->where(['status' => $s])->count()));
        }
        $this->stdout('outcome unknown: ' . DocumentRecord::find()->where(['outcomeUnknown' => true])->count() . PHP_EOL);
        $this->stdout('delivery pending: ' . DocumentRecord::find()->where(['deliveryBucket' => 'pending'])->count() . PHP_EOL);
        $unknown = Plugin::getInstance()->documents->triggerStatusIds()['unknown'];
        if ($unknown) {
            $this->stdout('Trigger statuses not found in Commerce: ' . implode(', ', $unknown) . PHP_EOL, Console::FG_YELLOW);
        }
        return ExitCode::OK;
    }

    /**
     * Send one order now (synchronously, under the mutex). An order whose last create has an
     * unknown outcome needs `--verified-not-booked`, after checking e-računi holds no invoice for it.
     */
    public function actionOrder(string $idOrNumber): int
    {
        $q = Order::find()->status(null);
        $order = is_numeric($idOrNumber) ? $q->id((int) $idOrNumber)->one() : ($q->number($idOrNumber)->one() ?? Order::find()->status(null)->reference($idOrNumber)->one());
        if (!$order instanceof Order) {
            $this->stderr("Order {$idOrNumber} not found.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        $doc = Plugin::getInstance()->documents->getForOrder((int) $order->id);
        $verifiedAttempts = null;
        if ($doc?->outcomeUnknown) {
            // The operator names the attempt they checked, so re-running the same command after
            // another unknown outcome does not send again unchecked.
            if ($this->verifiedAttempt === null || !ctype_digit($this->verifiedAttempt) || (int) $this->verifiedAttempt !== $doc->attempts) {
                $this->stderr("Order {$order->id}: attempt {$doc->attempts} may already have created the invoice. Check e-računi; if it holds no invoice for this order, re-run with --verified-attempt={$doc->attempts}.\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $verifiedAttempts = $doc->attempts;
        }
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire(SendInvoiceJob::MUTEX, 30)) {
            $this->stderr("Mutex busy.\n", Console::FG_RED);
            return ExitCode::TEMPFAIL;
        }
        try {
            // claim() checks the verified attempt against the row again, under the mutex.
            $doc = Plugin::getInstance()->documents->send($order, true, $verifiedAttempts);
            $this->stdout("Order {$order->id}: {$doc->status} {$doc->number}\n", Console::FG_GREEN);
            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("Order {$order->id}: " . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        } finally {
            $mutex->release(SendInvoiceJob::MUTEX);
        }
    }

    /** Queue every completed order matching the trigger conditions that has no document. */
    public function actionBackfill(): int
    {
        $s = Plugin::getInstance()->getSettings();
        ['ids' => $statusIds, 'unknown' => $unknown] = Plugin::getInstance()->documents->triggerStatusIds();
        if ($unknown) {
            $this->stderr('Trigger statuses not found in Commerce: ' . implode(', ', $unknown) . "\n", Console::FG_YELLOW);
        }
        if (!$statusIds) {
            // Querying by the handles would match every completed order.
            $this->stderr("No trigger status exists; nothing queued. Fix Trigger order statuses in the plugin settings.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        $q = Order::find()->isCompleted(true)->orderStatusId($statusIds)->orderBy(['dateOrdered' => SORT_ASC])->limit($this->limit);
        if ($s->requirePaid) {
            $q->isPaid(true);
        }
        if ($this->since) {
            $q->dateOrdered('>= ' . $this->since);
        }
        $done = DocumentRecord::find()->select('orderId')->where(['or', ['status' => [DocumentRecord::STATUS_SENT, DocumentRecord::STATUS_PENDING]], ['outcomeUnknown' => true]])->column();
        $q->andWhere(['not in', 'commerce_orders.id', $done ?: [0]]);
        $n = 0;
        foreach ($q->all() as $order) {
            $n++;
            $this->stdout(sprintf("%s order %d %s %s\n", $this->dryRun ? 'would queue' : 'queued', $order->id, $order->reference ?: $order->number, $order->getTotalPrice()));
            if (!$this->dryRun) {
                Plugin::getInstance()->documents->queue($order);
            }
        }
        $this->stdout("{$n} order(s)\n");
        return ExitCode::OK;
    }

    /** Re-queue failed documents, except those whose create may already have been booked. */
    public function actionRetry(): int
    {
        $unknown = DocumentRecord::find()->select(['orderId', 'attempts'])->where(['outcomeUnknown' => true])->asArray()->all();
        if ($unknown) {
            $this->stderr(sprintf(
                "Skipped %d order(s) whose last send may already have created the invoice (order@attempt): %s. Check each in e-računi; if it holds no invoice, run sync/order <id> --verified-attempt=<attempt>.\n",
                count($unknown),
                implode(', ', array_map(static fn(array $r) => $r['orderId'] . '@' . $r['attempts'], $unknown)),
            ), Console::FG_YELLOW);
        }
        $q = DocumentRecord::find()->where(['status' => DocumentRecord::STATUS_FAILED, 'outcomeUnknown' => false])->orderBy(['dateUpdated' => SORT_ASC]);
        if (!$this->all) {
            $q->limit($this->limit);
        }
        $n = 0;
        /** @var DocumentRecord[] $rows */
        $rows = $q->all();
        foreach ($rows as $r) {
            Plugin::getInstance()->documents->queue((int) $r->orderId, true);
            $n++;
        }
        $this->stdout("Re-queued {$n} document(s)\n");
        return ExitCode::OK;
    }

    /** Refresh pending AS4/FINA deliveries. Run from cron every 15 minutes. */
    public function actionDeliveryStatus(): int
    {
        $stats = Plugin::getInstance()->deliverySync->syncPending($this->limit);
        $this->stdout(json_encode($stats) . PHP_EOL);
        return ExitCode::OK;
    }
}
