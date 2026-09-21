<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\TestCase;
use wmd\commerceeracuni\core\Delivery;
use wmd\commerceeracuni\tests\Support\FakeClient;

final class DeliveryTest extends TestCase
{
    public function testAs4SendOk(): void
    {
        $fc = new FakeClient([['method' => 'SendDocumentToAS4Endpoint', 'response' => ['response' => ['status' => 'ok', 'sendingTransactionID' => '359528427']]]]);
        $r = (new Delivery($fc))->sendAs4('60:951182');
        self::assertTrue($r->ok);
        self::assertSame('as4', $r->channel);
        self::assertSame('359528427', $r->transactionId);
        self::assertSame('pending', $r->bucket);
        self::assertSame('documentSent', $r->status);
        self::assertSame(['documentID' => '60:951182'], $fc->calls[0][1]);
    }

    public function testAs4SendError(): void
    {
        $fc = new FakeClient([['method' => 'SendDocumentToAS4Endpoint', 'response' => ['response' => ['status' => 'error', 'description' => 'Receiver not registered']]]]);
        $r = (new Delivery($fc))->sendAs4('60:1');
        self::assertFalse($r->ok);
        self::assertSame('failed', $r->bucket);
        self::assertSame('Receiver not registered', $r->message);
    }

    public function testFinaReceiverLookup(): void
    {
        $fc = new FakeClient([['method' => 'GetFinaReceiverList', 'response' => ['response' => ['result' => ['GetFinaReceiverListMsg' => [
            ['companyID' => '33061586626', 'partyIdentificationID' => '9934:33061586626', 'status' => 'active'],
        ]]]]]]);
        self::assertTrue((new Delivery($fc))->finaReceiverActive('33061586626'));
        self::assertSame(['companyID' => '33061586626'], $fc->calls[0][1]);
    }

    public function testFinaSend(): void
    {
        $fc = new FakeClient([['method' => 'SendDocumentToFina', 'response' => ['response' => ['status' => 'ok', 'result' => ['SendDocumentToFinaMsg' => [
            'DocumentSendingLog' => ['StatusCode' => 'documentSent', 'SendingTransactionID' => 'F-1'], 'MsgText' => '',
        ]]]]]]);
        $r = (new Delivery($fc))->sendFina('60:2', '33061586626');
        self::assertTrue($r->ok);
        self::assertSame('fina', $r->channel);
        self::assertSame('F-1', $r->transactionId);
        self::assertSame(['documentID' => '60:2', 'partyIdentificationID' => '9934:33061586626'], $fc->calls[0][1]);
    }

    public function testStatusReadUsesSingleAttempt(): void
    {
        $fc = new FakeClient([['method' => 'GetDocumentSendingStatus', 'response' => ['response' => ['status' => 'ok', 'result' => ['status' => 'documentReceivalConfirmed']]]]]);
        $s = (new Delivery($fc))->status('359528427');
        self::assertSame('delivered', $s['bucket']);
    }
}
