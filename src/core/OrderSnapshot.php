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
        /** VAT rate for the shipping line, from Commerce's order-level tax adjustment (spec §7). */
        public float $shippingVatRate = 25.0,
    ) {
    }
}
