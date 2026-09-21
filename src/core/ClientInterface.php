<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\core;

interface ClientInterface
{
    /** @throws EracuniException */
    public function call(string $method, array $data = [], int $maxRetries = 3, int $timeoutSeconds = 30): array;

    /** @throws EracuniException */
    public function get(string $method, array $params = [], int $maxRetries = 3): array;
}
