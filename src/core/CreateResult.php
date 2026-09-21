<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

/** Outcome of a successful SalesInvoiceCreate, including the method the invoice actually landed on. */
final readonly class CreateResult
{
    public function __construct(
        public array $payload,
        public array $response,
        public string $method,
        public bool $fiscalised,
        public string $documentId,
        public string $number,
    ) {
    }
}
