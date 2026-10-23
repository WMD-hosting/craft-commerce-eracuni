<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\TestCase;
use wmd\commerceeracuni\core\TriggerStatuses;

final class TriggerStatusesTest extends TestCase
{
    private const STATUSES = [
        ['id' => 1, 'handle' => 'new'],
        ['id' => 2, 'handle' => 'shipped'],
        // A second store with its own copy of the same handle.
        ['id' => 7, 'handle' => 'shipped'],
    ];

    public function testResolvesHandlesInEveryStore(): void
    {
        self::assertSame(['ids' => [2, 7], 'unknown' => []], TriggerStatuses::resolve(['shipped'], self::STATUSES));
    }

    public function testNoMatchingHandleResolvesToNoIds(): void
    {
        // The caller must then send nothing: querying by the handle would match every order.
        self::assertSame(['ids' => [], 'unknown' => ['confirmed']], TriggerStatuses::resolve(['confirmed'], self::STATUSES));
    }

    public function testReportsUnknownHandlesNextToResolvedOnes(): void
    {
        self::assertSame(['ids' => [1], 'unknown' => ['confirmed']], TriggerStatuses::resolve(['confirmed', 'new'], self::STATUSES));
    }

    public function testNoHandlesResolveToNothing(): void
    {
        self::assertSame(['ids' => [], 'unknown' => []], TriggerStatuses::resolve([], self::STATUSES));
    }
}
