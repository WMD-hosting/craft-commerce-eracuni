<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\core;

final readonly class VatTreatment
{
    public const DOMESTIC_B2B = 'domestic_b2b';
    public const DOMESTIC_B2C = 'domestic_b2c';
    public const EU_B2B = 'eu_b2b';
    public const EU_B2C = 'eu_b2c';
    public const EXPORT_B2B = 'export_b2b';
    public const EXPORT_B2C = 'export_b2c';

    public function __construct(
        public string $code,
        public bool $isBusiness,
        public bool $isRetail,
        public ?string $vatTransactionType,
        public ?string $taxIdField,
        public ?string $taxIdValue,
    ) {
    }
}
