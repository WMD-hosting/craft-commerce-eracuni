<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\core;

final readonly class BuilderConfig
{
    /**
     * @param array<string, array{method:string, fiscalised:bool, paymentMethodForInvoice:string}> $paymentMap gateway handle → mapping
     * @param float[] $knownRates e.g. [25, 13, 5, 0]
     */
    public function __construct(
        public string $sellerCountry = 'HR',
        public string $businessUnit = '',
        public string $cashRegisterCode = '1',
        public int $dueDays = 8,
        public string $invoiceDateSource = 'now',   // 'now' | 'orderDate'
        public string $today = '',                   // Y-m-d, injected for tests
        public array $paymentMap = [],
        public array $knownRates = [25.0, 13.0, 5.0, 0.0],
        public string $shippingKpd = '532000',
        public ?string $defaultKpd = null,
        public string $shippingDescription = 'Dostava',
        public string $discountDescription = 'Popust',
        public ?string $lastInvoiceDate = null,     // Y-m-d, forward-correction floor
    ) {
    }
}
