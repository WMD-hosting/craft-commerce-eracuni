<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\TestCase;
use wmd\commerceeracuni\core\ClaimPolicy;

final class ClaimPolicyTest extends TestCase
{
    public function testDefiniteFailureIsRetryable(): void
    {
        self::assertTrue(ClaimPolicy::mayTakeOver('failed', false, false, false, false));
        self::assertTrue(ClaimPolicy::mayTakeOver('failed', false, false, true, false));
    }

    public function testOutcomeUnknownIsNeverTakenWithoutVerification(): void
    {
        // Auto-send (no force), CP/console retry and the order panel (force) all stop here.
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$force, $stale]) {
            self::assertFalse(ClaimPolicy::mayTakeOver('failed', true, $stale, $force, false), sprintf('force=%d stale=%d', $force, $stale));
        }
    }

    public function testOutcomeUnknownIsTakenOnceStaffVerifiedNotBooked(): void
    {
        self::assertTrue(ClaimPolicy::mayTakeOver('failed', true, false, true, true));
        self::assertTrue(ClaimPolicy::mayTakeOver('failed', true, false, false, true));
    }

    public function testFlaggedPendingRowWaitsUntilStale(): void
    {
        // Flagged before the request left: a create may be in flight, so no check can be complete yet.
        self::assertFalse(ClaimPolicy::mayTakeOver('pending', true, false, true, true));
        self::assertFalse(ClaimPolicy::mayTakeOver('pending', true, true, true, false), 'a dead worker still needs verification');
        self::assertTrue(ClaimPolicy::mayTakeOver('pending', true, true, true, true), 'past the TTR the worker is gone');
    }

    public function testSentIsNeverTakenOver(): void
    {
        self::assertFalse(ClaimPolicy::mayTakeOver('sent', false, false, true, true));
    }

    public function testPendingNeedsForceOrStaleness(): void
    {
        self::assertFalse(ClaimPolicy::mayTakeOver('pending', false, false, false, false));
        self::assertTrue(ClaimPolicy::mayTakeOver('pending', false, true, false, false));
        self::assertTrue(ClaimPolicy::mayTakeOver('pending', false, false, true, false));
    }
}
