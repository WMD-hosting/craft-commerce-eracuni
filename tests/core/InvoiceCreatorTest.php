<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\TestCase;
use wmd\commerceeracuni\core\EracuniException;
use wmd\commerceeracuni\core\InvoiceCreator;
use wmd\commerceeracuni\tests\Support\FakeClient;

final class InvoiceCreatorTest extends TestCase
{
    private function payload(string $method = 'BankTransfer'): array
    {
        return ['businessUnit' => 'WEB', 'methodOfPayment' => $method, 'Items' => []];
    }

    private static function ok(string $documentId = 'D1', string $number = '2026-1'): array
    {
        return ['response' => ['status' => 'ok', 'result' => ['documentID' => $documentId, 'number' => $number]]];
    }

    private static function error(string $description): array
    {
        return ['response' => ['status' => 'error', 'description' => $description]];
    }

    public function testCreatesInvoiceOnFirstCall(): void
    {
        $client = new FakeClient([['method' => 'SalesInvoiceCreate', 'response' => self::ok('DOC-7', '19-1-1')]]);
        $r = (new InvoiceCreator($client))->create($this->payload('CorvusPay'), true, 'CorvusPay');

        self::assertSame('DOC-7', $r->documentId);
        self::assertSame('19-1-1', $r->number);
        self::assertSame('CorvusPay', $r->method);
        self::assertTrue($r->fiscalised);
        self::assertSame('CorvusPay', $r->payload['methodOfPayment']);
        self::assertSame('ok', $r->response['response']['status']);
        $client->assertDrained();
        // Spec §2.2: the create call gets 3 retries and the long (120 s) timeout.
        self::assertSame(['SalesInvoiceCreate', 3, 120], [$client->calls[0][0], $client->calls[0][2], $client->calls[0][3]]);
    }

    public function testRetailFallsBackToTheNextMethodWhenTheFirstIsRejected(): void
    {
        $client = new FakeClient([
            ['method' => 'SalesInvoiceCreate', 'response' => self::error('methodOfPayment BankTransfer is not allowed for Retail')],
            ['method' => 'SalesInvoiceCreate', 'response' => self::ok()],
        ]);
        $r = (new InvoiceCreator($client))->create($this->payload(), true, 'BankTransfer');

        self::assertSame('Visa', $r->method);
        self::assertTrue($r->fiscalised);
        self::assertSame('Visa', $r->payload['methodOfPayment']);
        $client->assertDrained();
    }

    public function testNonMethodErrorStopsWithoutFurtherCalls(): void
    {
        $client = new FakeClient([['method' => 'SalesInvoiceCreate', 'response' => self::error('Invalid KPD code on line 1')]]);

        try {
            (new InvoiceCreator($client))->create($this->payload(), true, 'BankTransfer');
            self::fail('Expected a domain error.');
        } catch (EracuniException $e) {
            self::assertFalse($e->isTransport());
            self::assertStringContainsString('SalesInvoiceCreate failed', $e->getMessage());
        }
        self::assertCount(1, $client->calls);
    }

    public function testUnknownOutcomeTransportBecomesADomainError(): void
    {
        $client = new FakeClient([['method' => 'SalesInvoiceCreate', 'throw' => EracuniException::transport('cURL timeout', 0)]]);

        try {
            (new InvoiceCreator($client))->create($this->payload(), true, 'BankTransfer');
            self::fail('Expected a domain error.');
        } catch (EracuniException $e) {
            self::assertFalse($e->isTransport(), 'An unknown-outcome create must not be retried.');
            self::assertStringContainsString('outcome unknown', $e->getMessage());
            self::assertStringContainsString('cURL timeout', $e->getMessage());
        }
        self::assertCount(1, $client->calls);
    }

    public function testRateLimitExhaustionStaysRetryable(): void
    {
        $original = EracuniException::transport('Eracuni REST HTTP 429 after 3 attempts.', 429);
        $client = new FakeClient([['method' => 'SalesInvoiceCreate', 'throw' => $original]]);

        try {
            (new InvoiceCreator($client))->create($this->payload(), false, 'BankTransfer');
            self::fail('Expected the transport error to be rethrown.');
        } catch (EracuniException $e) {
            self::assertSame($original, $e);
            self::assertTrue($e->isTransport());
        }
    }

    public function testSessionLockExhaustionStaysRetryable(): void
    {
        $original = EracuniException::transport('Eracuni REST session lock persisted after 3 attempts.', 500);
        $client = new FakeClient([['method' => 'SalesInvoiceCreate', 'throw' => $original]]);

        try {
            (new InvoiceCreator($client))->create($this->payload(), false, 'BankTransfer');
            self::fail('Expected the transport error to be rethrown.');
        } catch (EracuniException $e) {
            self::assertSame($original, $e);
            self::assertTrue($e->isTransport());
        }
    }

    public function testMissingDocumentIdIsADomainError(): void
    {
        $client = new FakeClient([['method' => 'SalesInvoiceCreate', 'response' => ['response' => ['status' => 'ok', 'result' => []]]]]);

        $this->expectException(EracuniException::class);
        $this->expectExceptionMessageMatches('/no documentID/');
        (new InvoiceCreator($client))->create($this->payload(), false, 'BankTransfer');
    }
}
