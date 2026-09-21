<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

final readonly class Buyer
{
    public function __construct(
        public ?string $firstName,
        public ?string $lastName,
        public ?string $organization,
        public ?string $taxId,
        public ?string $street,
        public ?string $postalCode,
        public ?string $city,
        public string $countryCode,
        public ?string $email,
        public ?string $phone,
    ) {
    }

    public function isBusiness(): bool
    {
        return trim((string) $this->organization) !== '';
    }

    public function displayName(): string
    {
        if ($this->isBusiness()) {
            return trim((string) $this->organization);
        }
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }
}
