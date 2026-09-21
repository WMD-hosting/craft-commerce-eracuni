<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\core;

final readonly class BuildResult
{
    /** @param string[] $warnings */
    public function __construct(
        public array $payload,
        public VatTreatment $treatment,
        public string $method,
        public bool $fiscalised,
        public array $warnings,
        public float $computedTotal,
    ) {
    }
}
