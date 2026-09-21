<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\core;

final class EracuniException extends \RuntimeException
{
    private bool $transport = false;
    public int $httpStatus = 0;

    public static function domain(string $message): self
    {
        return new self($message);
    }

    public static function transport(string $message, int $httpStatus = 0): self
    {
        $e = new self($message);
        $e->transport = true;
        $e->httpStatus = $httpStatus;
        return $e;
    }

    public function isTransport(): bool
    {
        return $this->transport;
    }
}
