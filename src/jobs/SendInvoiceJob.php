<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use wmd\commerceeracuni\core\EracuniException;
use wmd\commerceeracuni\Plugin;
use yii\queue\RetryableJobInterface;

class SendInvoiceJob extends BaseJob implements RetryableJobInterface
{
    public const MUTEX = 'commerce-eracuni';
    /** Seconds a queue reservation is allowed to run before another worker may retry it; also used by Documents::claim() to detect a stale pending row. */
    public const TTR = 900;

    public int $orderId;
    public bool $force = false;

    public function execute($queue): void
    {
        $order = Order::find()->id($this->orderId)->status(null)->one();
        if (!$order instanceof Order) {
            Plugin::warning("SendInvoiceJob: order {$this->orderId} not found.");
            return;
        }
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire(self::MUTEX, 30)) {
            throw new MutexBusyException('e-računi mutex busy; retry.');
        }
        try {
            Plugin::getInstance()->documents->send($order, $this->force);
        } finally {
            $mutex->release(self::MUTEX);
        }
    }

    public function getTtr(): int
    {
        return self::TTR;
    }

    public function canRetry($attempt, $error): bool
    {
        if ($attempt >= 3) {
            return false;
        }
        return $error instanceof MutexBusyException || ($error instanceof EracuniException && $error->isTransport());
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-eracuni', 'Sending order {id} to e-računi', ['id' => $this->orderId]);
    }
}
