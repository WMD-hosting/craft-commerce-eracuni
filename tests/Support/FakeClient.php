<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\Support;

use wmd\commerceeracuni\core\ClientInterface;

final class FakeClient implements ClientInterface
{
    /** @var array<int, array{0:string,1:array,2?:int,3?:int|null}> */
    public array $calls = [];

    /** @param array<int, array{method:string, response?:array, throw?:\Throwable}> $script */
    public function __construct(private array $script)
    {
    }

    public function call(string $method, array $data = [], int $maxRetries = 3, int $timeoutSeconds = 30): array
    {
        return $this->next($method, $data, $maxRetries, $timeoutSeconds);
    }

    public function get(string $method, array $params = [], int $maxRetries = 3): array
    {
        return $this->next($method, $params, $maxRetries, null);
    }

    private function next(string $method, array $data, int $maxRetries = 3, ?int $timeoutSeconds = null): array
    {
        $this->calls[] = [$method, $data, $maxRetries, $timeoutSeconds];
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
