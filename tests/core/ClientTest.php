<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use wmd\commerceeracuni\core\Client;
use wmd\commerceeracuni\core\EracuniException;

final class ClientTest extends TestCase
{
    private array $history = [];
    private array $sleeps = [];

    private function client(array $responses): Client
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $http = new Guzzle(['handler' => $stack]);
        return new Client('https://e-racuni.com/H7i/API-CLI/', 'USER', 'TOKEN', $http, function (int $s) { $this->sleeps[] = $s; });
    }

    public function testPostsFormEncodedWithJsonObjectsAndBasicAuth(): void
    {
        $c = $this->client([new Response(200, [], '{"response":{"status":"ok","result":{"documentID":"60:1"}}}')]);
        $r = $c->call('SalesInvoiceCreate', ['SalesInvoice' => ['a' => 1]], 3, 120);
        self::assertSame('60:1', $r['response']['result']['documentID']);
        $req = $this->history[0]['request'];
        self::assertSame('https://e-racuni.com/H7i/API-CLI/SalesInvoiceCreate', (string) $req->getUri());
        self::assertSame('Basic ' . base64_encode('USER:TOKEN'), $req->getHeaderLine('Authorization'));
        self::assertSame('SalesInvoice=' . urlencode('{"a":1}'), (string) $req->getBody());
        self::assertSame(120, $this->history[0]['options']['timeout']);
        self::assertFalse($this->history[0]['options']['decode_content'], 'decode_content must be off: e-racuni sends Content-Encoding: 8-bit');
    }

    public function testRetriesOn429WithBackoff(): void
    {
        $c = $this->client([new Response(429, [], 'slow down'), new Response(429, [], 'slow down'), new Response(200, [], '{"ok":1}')]);
        self::assertSame(['ok' => 1], $c->call('PartnerList'));
        self::assertSame([5, 10], $this->sleeps);
    }

    public function testNeverRetries429OnStatusRead(): void
    {
        $c = $this->client([new Response(429, [], 'x')]);
        $this->expectException(EracuniException::class);
        $c->call('GetDocumentSendingStatus', ['sendingTransactionID' => '1']);
    }

    public function testSessionLockRetries(): void
    {
        $c = $this->client([new Response(500, [], 'Another web request is currently being processed'), new Response(200, [], '{"ok":1}')]);
        self::assertSame(['ok' => 1], $c->call('SalesInvoiceCreate'));
        self::assertSame([15], $this->sleeps);
    }

    public function testNonJsonIsWrapped(): void
    {
        $c = $this->client([new Response(200, [], 'plain')]);
        self::assertSame(['raw' => 'plain'], $c->get('SalesInvoiceGetPDF', ['documentID' => '1']));
    }

    public function testHttpErrorIsTransportException(): void
    {
        $c = $this->client([new Response(401, [], 'nope')]);
        try {
            $c->call('PartnerList');
            self::fail('expected exception');
        } catch (EracuniException $e) {
            self::assertTrue($e->isTransport());
            self::assertSame(401, $e->httpStatus);
        }
    }

    public function testGuzzleExceptionBecomesTransportException(): void
    {
        $c = $this->client([new \GuzzleHttp\Exception\ConnectException('dns fail', new \GuzzleHttp\Psr7\Request('POST', 'x'))]);
        try {
            $c->call('PartnerList');
            self::fail('expected exception');
        } catch (EracuniException $e) {
            self::assertTrue($e->isTransport());
            self::assertStringContainsString('dns fail', $e->getMessage());
        }
    }

    public function testExhausts429AfterMaxRetries(): void
    {
        $c = $this->client([new Response(429, [], 'a'), new Response(429, [], 'b'), new Response(429, [], 'c')]);
        try {
            $c->call('PartnerList', [], 3);
            self::fail('expected exception');
        } catch (EracuniException $e) {
            self::assertTrue($e->isTransport());
            self::assertSame(429, $e->httpStatus);
            self::assertSame([5, 10], $this->sleeps);
            self::assertCount(3, $this->history);
        }
    }

    public function testExhaustsSessionLockAfterMaxRetries(): void
    {
        $lock = 'Another web request is currently being processed';
        $c = $this->client([new Response(500, [], $lock), new Response(500, [], $lock)]);
        try {
            $c->call('SalesInvoiceCreate', [], 2);
            self::fail('expected exception');
        } catch (EracuniException $e) {
            self::assertTrue($e->isTransport());
            self::assertSame(500, $e->httpStatus);
            self::assertSame([15], $this->sleeps);
            self::assertCount(2, $this->history);
        }
    }
}
