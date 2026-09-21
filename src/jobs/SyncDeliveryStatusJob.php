<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\jobs;

use Craft;
use craft\queue\BaseJob;
use wmd\commerceeracuni\Plugin;

class SyncDeliveryStatusJob extends BaseJob
{
    public int $limit = 100;

    public function execute($queue): void
    {
        $stats = Plugin::getInstance()->deliverySync->syncPending($this->limit);
        Plugin::info('Delivery status sync: ' . json_encode($stats));
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-eracuni', 'Syncing e-računi delivery status');
    }
}
