<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\core;

final readonly class DeliveryResult
{
    public function __construct(
        public bool $ok,
        public string $channel,
        public ?string $transactionId,
        public string $bucket,
        public ?string $status,
        public string $message,
    ) {
    }
}
