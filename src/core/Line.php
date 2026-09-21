<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

final readonly class Line
{
    public const KIND_ITEM = 'item';
    public const KIND_SHIPPING = 'shipping';
    public const KIND_DISCOUNT = 'discount';

    public function __construct(
        public string $description,
        public float $quantity,
        public string $unit,
        public float $grossUnitPrice,
        public float $netUnitPrice,
        public float $vatRate,
        public ?string $kpd,
        public string $kind = self::KIND_ITEM,
    ) {
    }

    public function grossTotal(): float
    {
        return round($this->grossUnitPrice * $this->quantity, 2);
    }

    public function netTotal(): float
    {
        return round($this->netUnitPrice * $this->quantity, 2);
    }
}
