<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\TestCase;
use wmd\commerceeracuni\core\Buyer;
use wmd\commerceeracuni\core\EracuniException;
use wmd\commerceeracuni\core\Partners;
use wmd\commerceeracuni\core\VatResolver;
use wmd\commerceeracuni\tests\Support\FakeClient;

final class PartnersTest extends TestCase
{
    private function b2c(): Buyer
    {
        return new Buyer('Ana', 'Anić', null, null, '', '10000', 'Zagreb', 'HR', 'ana@example.com', '+38591');
    }

    private function b2b(): Buyer
    {
        return new Buyer('Ivo', 'Ivić', 'Tvrtka d.o.o.', 'HR67272246049', 'Ilica 1', '10000', 'Zagreb', 'HR', 'ivo@example.com', null);
    }

    public function testFoundByCodeReturnsExistingIdAndBuyerCode(): void
    {
        $fc = new FakeClient([
            ['method' => 'PartnerList', 'response' => ['response' => ['status' => 'ok', 'result' => [['documentID' => '7:1', 'BuyerData' => ['buyerCode' => 'OLD-1']]]]]],
        ]);
        $ref = (new Partners($fc))->getOrCreate($this->b2b(), VatResolver::resolve($this->b2b(), 'HR'), 'c1');
        self::assertSame('7:1', $ref->documentId);
        self::assertSame('OLD-1', $ref->buyerCode);
        self::assertFalse($ref->created);
        self::assertSame(['partnerCode' => 'B2B-67272246049'], $fc->calls[0][1]);
    }

    public function testCreatesB2bWithPersonalIdAndRegisteredVat(): void
    {
        $fc = new FakeClient([
            ['method' => 'PartnerList', 'response' => ['response' => ['status' => 'ok', 'result' => []]]],
            ['method' => 'PartnerCreate', 'response' => ['response' => ['status' => 'ok', 'result' => ['documentID' => '7:2']]]],
        ]);
        $ref = (new Partners($fc))->getOrCreate($this->b2b(), VatResolver::resolve($this->b2b(), 'HR'), 'c1');
        self::assertSame('7:2', $ref->documentId);
        self::assertSame('B2B-67272246049', $ref->buyerCode);
        self::assertTrue($ref->created);
        $p = json_decode($fc->calls[1][1]['partner'], true);
        self::assertSame('B2B-67272246049', $p['partnerCode']);
        self::assertSame('Tvrtka d.o.o.', $p['companyName']);
        self::assertSame('Ltd', $p['companyType']);
        self::assertSame('Registered', $p['vatRegistration']);
        self::assertSame('67272246049', $p['personalID']);
        self::assertArrayNotHasKey('vatID', $p);
        self::assertSame('Primary', $p['Addresses'][0]['type']);
        self::assertSame('HR', $p['Addresses'][0]['country']);
        self::assertSame('B2B-67272246049', $p['BuyerData']['buyerCode']);
    }

    public function testCreatesB2cWithPlaceholderStreetAndNoVat(): void
    {
        $fc = new FakeClient([
            ['method' => 'PartnerList', 'response' => ['response' => ['status' => 'ok', 'result' => []]]],
            ['method' => 'PartnerCreate', 'response' => ['response' => ['status' => 'ok', 'result' => ['documentID' => '7:3']]]],
        ]);
        $ref = (new Partners($fc, 'SHOP'))->getOrCreate($this->b2c(), VatResolver::resolve($this->b2c(), 'HR'), 'u55');
        $p = json_decode($fc->calls[1][1]['partner'], true);
        self::assertSame('SHOP-u55', $p['partnerCode']);
        self::assertSame('SHOP-u55', $ref->buyerCode);
        self::assertSame('Ana', $p['firstName']);
        self::assertSame('Anić', $p['lastName']);
        self::assertSame('-', $p['Addresses'][0]['street']);
        self::assertSame('None', $p['vatRegistration']);
        self::assertArrayNotHasKey('companyName', $p);
        self::assertArrayNotHasKey('personalID', $p);
    }

    public function testEuB2bUsesVatIdField(): void
    {
        $b = new Buyer('J', 'N', 'Podjetje d.o.o.', 'SI29865174', 'C 1', '1000', 'Ljubljana', 'SI', 'j@example.com', null);
        $fc = new FakeClient([
            ['method' => 'PartnerList', 'response' => ['response' => ['status' => 'ok', 'result' => []]]],
            ['method' => 'PartnerCreate', 'response' => ['response' => ['status' => 'ok', 'result' => ['documentID' => '7:4']]]],
        ]);
        (new Partners($fc))->getOrCreate($b, VatResolver::resolve($b, 'HR'), 'c9');
        $p = json_decode($fc->calls[1][1]['partner'], true);
        self::assertSame('SI29865174', $p['vatID']);
        self::assertArrayNotHasKey('personalID', $p);
        self::assertSame('B2B-SI29865174', $p['partnerCode']);
    }

    public function testCreateErrorIsDomainException(): void
    {
        $fc = new FakeClient([
            ['method' => 'PartnerList', 'response' => ['response' => ['status' => 'ok', 'result' => []]]],
            ['method' => 'PartnerCreate', 'response' => ['response' => ['status' => 'error', 'description' => '#PrimaryAddress_street - This field is required']]],
        ]);
        $this->expectException(EracuniException::class);
        $this->expectExceptionMessageMatches('/PrimaryAddress_street/');
        (new Partners($fc))->getOrCreate($this->b2c(), VatResolver::resolve($this->b2c(), 'HR'), 'u1');
    }
}
