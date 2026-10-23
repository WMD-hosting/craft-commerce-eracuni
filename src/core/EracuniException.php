<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

final class EracuniException extends \RuntimeException
{
    private bool $transport = false;
    private bool $outcomeUnknown = false;
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

    /**
     * A domain error for a create whose request may have been booked before the connection broke.
     * It is terminal like any domain error, and the document row keeps the flag so nothing re-sends
     * the order until staff confirm in e-računi that no invoice exists.
     */
    public static function outcomeUnknown(string $message): self
    {
        $e = new self($message);
        $e->outcomeUnknown = true;
        return $e;
    }

    public function isTransport(): bool
    {
        return $this->transport;
    }

    public function isOutcomeUnknown(): bool
    {
        return $this->outcomeUnknown;
    }
}
