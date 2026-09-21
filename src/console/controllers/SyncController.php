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

    public function options($actionID): array
    {
        return match ($actionID) {
            'backfill' => ['dryRun', 'limit', 'since'],
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
        $this->stdout('delivery pending: ' . DocumentRecord::find()->where(['deliveryBucket' => 'pending'])->count() . PHP_EOL);
        return ExitCode::OK;
    }

    /** Send one order now (synchronously, under the mutex). */
    public function actionOrder(string $idOrNumber): int
    {
        $q = Order::find()->status(null);
        $order = is_numeric($idOrNumber) ? $q->id((int) $idOrNumber)->one() : ($q->number($idOrNumber)->one() ?? Order::find()->status(null)->reference($idOrNumber)->one());
        if (!$order instanceof Order) {
            $this->stderr("Order {$idOrNumber} not found.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire(SendInvoiceJob::MUTEX, 30)) {
            $this->stderr("Mutex busy.\n", Console::FG_RED);
            return ExitCode::TEMPFAIL;
        }
        try {
            $doc = Plugin::getInstance()->documents->send($order, true);
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
        $q = Order::find()->isCompleted(true)->orderStatus($s->triggerStatuses)->orderBy(['dateOrdered' => SORT_ASC])->limit($this->limit);
        if ($s->requirePaid) {
            $q->isPaid(true);
        }
        if ($this->since) {
            $q->dateOrdered('>= ' . $this->since);
        }
        $done = DocumentRecord::find()->select('orderId')->where(['status' => [DocumentRecord::STATUS_SENT, DocumentRecord::STATUS_PENDING]])->column();
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

    /** Re-queue failed documents. */
    public function actionRetry(): int
    {
        $q = DocumentRecord::find()->where(['status' => DocumentRecord::STATUS_FAILED])->orderBy(['dateUpdated' => SORT_ASC]);
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
