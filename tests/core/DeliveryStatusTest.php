<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\TestCase;
use wmd\commerceeracuni\core\DeliveryStatus;

final class DeliveryStatusTest extends TestCase
{
    public function testDelivered(): void
    {
        foreach (['documentDeliveryConfirmed', 'documentReceivalConfirmed', 'documentPaymentFulfilled', 'documentPaymentFulfilledPartially'] as $s) {
            $r = DeliveryStatus::classify(['status' => $s]);
            self::assertSame('delivered', $r['bucket'], $s);
            self::assertTrue($r['terminal']);
        }
    }

    public function testPending(): void
    {
        foreach (['documentSendingDraft', 'documentApprovedForSending', 'documentSent'] as $s) {
            $r = DeliveryStatus::classify(['status' => $s]);
            self::assertSame('pending', $r['bucket'], $s);
            self::assertFalse($r['terminal']);
        }
    }

    public function testTerminalFailure(): void
    {
        $r = DeliveryStatus::classify(['status' => 'documentRejectedByReceiver', 'sendingResultMessage' => 'Odbijen']);
        self::assertSame('failed', $r['bucket']);
        self::assertTrue($r['terminal']);
        self::assertSame('Odbijen', $r['message']);
    }

    public function testTransientErrorsAreNotTerminal(): void
    {
        $a = DeliveryStatus::classify(['status' => 'error', 'description' => 'Document cannot be checked for status']);
        $b = DeliveryStatus::classify(['status' => 'error', 'description' => 'Document with requested parameters does not exist.']);
        self::assertSame('error', $a['bucket']);
        self::assertFalse($a['terminal']);
        self::assertFalse($b['terminal']);
    }

    public function testUnknownStatusIsPending(): void
    {
        $r = DeliveryStatus::classify(['status' => 'somethingNew']);
        self::assertSame('pending', $r['bucket']);
        self::assertFalse($r['terminal']);
    }

    public function testMessagePrecedence(): void
    {
        $all = ['status' => 'documentRejectedByReceiver', 'sendingResultMessage' => 'A', 'description' => 'B', 'message' => 'C'];
        self::assertSame('A', DeliveryStatus::classify($all)['message']);
        self::assertSame('B', DeliveryStatus::classify(['status' => 'error', 'description' => 'B', 'message' => 'C'])['message']);
        self::assertSame('C', DeliveryStatus::classify(['status' => 'error', 'message' => 'C'])['message']);
        self::assertSame('', DeliveryStatus::classify(['status' => 'error'])['message']);
    }

    public function testMissingStatusIsPendingWithNullStatus(): void
    {
        $r = DeliveryStatus::classify([]);
        self::assertSame(DeliveryStatus::BUCKET_PENDING, $r['bucket']);
        self::assertFalse($r['terminal']);
        self::assertNull($r['status']);
    }
}
