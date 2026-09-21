<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\services;

use craft\base\Component;
use wmd\commerceeracuni\core\Delivery;
use wmd\commerceeracuni\core\DeliveryStatus;
use wmd\commerceeracuni\Plugin;
use wmd\commerceeracuni\records\DocumentRecord;

class DeliverySync extends Component
{
    /**
     * Rate limit: one status read per second (mojwmd rule).
     *
     * @return array{checked:int, delivered:int, failed:int, pending:int, errors:int}
     */
    public function syncPending(int $limit = 100, ?callable $sleep = null): array
    {
        $sleep ??= static fn() => sleep(1);
        $delivery = new Delivery(Plugin::getInstance()->documents->client());
        $stats = ['checked' => 0, 'delivered' => 0, 'failed' => 0, 'pending' => 0, 'errors' => 0];

        /** @var DocumentRecord[] $rows */
        $rows = DocumentRecord::find()
            ->where(['deliveryBucket' => DeliveryStatus::BUCKET_PENDING])
            ->andWhere(['not', ['deliveryTxnId' => null]])
            ->orderBy(['dateUpdated' => SORT_ASC])
            ->limit($limit)->all();

        foreach ($rows as $i => $r) {
            if ($i > 0) {
                $sleep();
            }
            $stats['checked']++;
            try {
                $cls = $delivery->status((string) $r->deliveryTxnId);
            } catch (\Throwable $e) {
                $stats['errors']++;
                Plugin::warning("Delivery status for order {$r->orderId}: " . $e->getMessage());
                continue;
            }
            if ($cls['bucket'] === DeliveryStatus::BUCKET_ERROR) {
                $stats['errors']++;
                continue; // transient; stays pending
            }
            $r->deliveryStatus = $cls['status'];
            $r->deliveryBucket = $cls['bucket'];
            if ($cls['bucket'] === DeliveryStatus::BUCKET_FAILED) {
                $r->error = trim(($r->error ? $r->error . "\n" : '') . 'Delivery: ' . $cls['message']);
            }
            $r->save(false);
            // Guard against an unexpected bucket value (e.g. a future status not yet
            // known to DeliveryStatus::classify()) causing an undefined-index warning.
            if (array_key_exists($cls['bucket'], $stats)) {
                $stats[$cls['bucket']]++;
            } else {
                $stats['errors']++;
            }
        }
        return $stats;
    }
}
