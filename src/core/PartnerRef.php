<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\core;

final readonly class PartnerRef
{
    public function __construct(public string $documentId, public string $buyerCode, public bool $created)
    {
    }
}
