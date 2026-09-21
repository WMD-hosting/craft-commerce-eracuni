<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

final readonly class OrderSnapshot
{
    /** @param Line[] $lines */
    public function __construct(
        public int $orderId,
        public string $number,
        public string $dateOrdered,
        public ?string $datePaid,
        public bool $isPaid,
        public string $currency,
        public string $gatewayHandle,
        public float $totalPrice,
        public float $totalShipping,
        public float $totalDiscount,
        public array $lines,
        public Buyer $buyer,
    ) {
    }
}
