<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\Support;

use wmd\commerceeracuni\core\ClientInterface;
use wmd\commerceeracuni\core\EracuniException;

final class FakeClient implements ClientInterface
{
    /** @var array<int, array{0:string,1:array}> */
    public array $calls = [];

    /** @param array<int, array{method:string, response?:array, throw?:\Throwable}> $script */
    public function __construct(private array $script)
    {
    }

    public function call(string $method, array $data = [], int $maxRetries = 3, int $timeoutSeconds = 30): array
    {
        return $this->next($method, $data);
    }

    public function get(string $method, array $params = [], int $maxRetries = 3): array
    {
        return $this->next($method, $params);
    }

    private function next(string $method, array $data): array
    {
        $this->calls[] = [$method, $data];
        $step = array_shift($this->script);
        if ($step === null || $step['method'] !== $method) {
            throw new \LogicException("Unexpected call {$method}; expected " . ($step['method'] ?? 'nothing'));
        }
        if (isset($step['throw'])) {
            throw $step['throw'];
        }
        return $step['response'] ?? [];
    }

    public function assertDrained(): void
    {
        if ($this->script !== []) {
            throw new \LogicException('Script not fully consumed: ' . json_encode(array_column($this->script, 'method')));
        }
    }
}
