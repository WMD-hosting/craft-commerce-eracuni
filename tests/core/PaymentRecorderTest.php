<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\TestCase;
use wmd\commerceeracuni\core\EracuniException;
use wmd\commerceeracuni\core\PaymentRecorder;
use wmd\commerceeracuni\tests\Support\FakeClient;

final class PaymentRecorderTest extends TestCase
{
    private const OK = ['response' => ['status' => 'ok', 'result' => []]];

    private function record(PaymentRecorder $recorder): void
    {
        $recorder->record('60:254939', '125.00', 'EUR', '2026-10-12', 'BankPaymentOrder', 'Payment for order 1042');
    }

    public function testSendsThePaymentUnderTheDocumentsTransactionId(): void
    {
        $client = new FakeClient([['method' => 'SalesInvoicePaymentRecordAdd', 'response' => self::OK]]);
        $this->record(new PaymentRecorder($client));

        self::assertSame([
            'apiTransactionId' => 'payment:60:254939',
            'documentID' => '60:254939',
            'paymentAmount' => '125.00',
            'paymentCurrency' => 'EUR',
            'paymentDate' => '2026-10-12',
            'paymentMethodForInvoice' => 'BankPaymentOrder',
            'description' => 'Payment for order 1042',
        ], $client->calls[0][1]);
        $client->assertDrained();
    }

    /** e-računi answers a repeated transaction id with its first result, so the resent record cannot add a second payment. */
    public function testASendAfterALostAnswerReusesTheTransactionId(): void
    {
        $client = new FakeClient([
            ['method' => 'SalesInvoicePaymentRecordAdd', 'throw' => EracuniException::transport('cURL error 28: Operation timed out')],
            ['method' => 'SalesInvoicePaymentRecordAdd', 'response' => self::OK],
        ]);
        $recorder = new PaymentRecorder($client);
        try {
            $this->record($recorder);
            self::fail('A lost answer must not count as recorded.');
        } catch (EracuniException $e) {
            self::assertTrue($e->isTransport());
        }
        $this->record($recorder);

        self::assertSame('payment:60:254939', $client->calls[0][1]['apiTransactionId']);
        self::assertSame($client->calls[0][1]['apiTransactionId'], $client->calls[1][1]['apiTransactionId']);
    }

    public function testARefusalIsNotTakenAsRecorded(): void
    {
        $client = new FakeClient([['method' => 'SalesInvoicePaymentRecordAdd', 'response' => ['response' => ['status' => 'error', 'description' => 'Document is cancelled.']]]]);

        $this->expectException(EracuniException::class);
        $this->expectExceptionMessage('Document is cancelled.');
        $this->record(new PaymentRecorder($client));
    }
}
