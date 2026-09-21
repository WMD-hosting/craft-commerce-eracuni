<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\TestCase;
use wmd\commerceeracuni\core\Buyer;
use wmd\commerceeracuni\core\EracuniException;
use wmd\commerceeracuni\core\Partners;
use wmd\commerceeracuni\core\VatResolver;
use wmd\commerceeracuni\core\VatTreatment;
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

    public function testLookupErrorIsDomainExceptionAndDoesNotCreate(): void
    {
        $fc = new FakeClient([['method' => 'PartnerList', 'response' => ['response' => ['status' => 'error', 'description' => 'Unauthorized']]]]);
        try {
            (new Partners($fc))->getOrCreate($this->b2b(), VatResolver::resolve($this->b2b(), 'HR'), 'c1');
            self::fail('expected exception');
        } catch (EracuniException $e) {
            self::assertStringContainsString('PartnerList failed', $e->getMessage());
            self::assertCount(1, $fc->calls);
        }
    }

    public function testB2gUsesB2gPrefix(): void
    {
        $fc = new FakeClient([['method' => 'PartnerList', 'response' => ['response' => ['status' => 'ok', 'result' => [['documentID' => '7:7', 'BuyerData' => ['buyerCode' => 'B2G-67272246049']]]]]]]);
        $ref = (new Partners($fc))->getOrCreate($this->b2b(), VatResolver::resolve($this->b2b(), 'HR'), 'c1', true);
        self::assertSame(['partnerCode' => 'B2G-67272246049'], $fc->calls[0][1]);
        self::assertSame('B2G-67272246049', $ref->buyerCode);
    }

    public function testFoundPartnerWithoutBuyerCodeFallsBackToOurCode(): void
    {
        $fc = new FakeClient([['method' => 'PartnerList', 'response' => ['response' => ['status' => 'ok', 'result' => [['documentID' => '7:9']]]]]]);
        $ref = (new Partners($fc))->getOrCreate($this->b2b(), VatResolver::resolve($this->b2b(), 'HR'), 'c1');
        self::assertSame('7:9', $ref->documentId);
        self::assertSame('B2B-67272246049', $ref->buyerCode);
    }

    public function testExportB2bWithoutTaxIdIsNotVatRegistered(): void
    {
        $b = new Buyer('J', 'D', 'Firma AG', null, 'Str 1', '8000', 'Zürich', 'CH', 'j@example.com', null);
        $t = VatResolver::resolve($b, 'HR');
        self::assertSame(VatTreatment::EXPORT_B2B, $t->code);
        $fc = new FakeClient([
            ['method' => 'PartnerList', 'response' => ['response' => ['status' => 'ok', 'result' => []]]],
            ['method' => 'PartnerCreate', 'response' => ['response' => ['status' => 'ok', 'result' => ['documentID' => '7:10']]]],
        ]);
        $ref = (new Partners($fc))->getOrCreate($b, $t, 'c7');
        self::assertSame('WEB-c7', $ref->buyerCode);
        $p = json_decode($fc->calls[1][1]['partner'], true);
        self::assertSame('Firma AG', $p['companyName']);
        self::assertSame('Ltd', $p['companyType']);
        self::assertSame('None', $p['vatRegistration']);
        self::assertArrayNotHasKey('personalID', $p);
        self::assertArrayNotHasKey('vatID', $p);
    }

    public function testB2cForeignCompanyKeepsCompanyName(): void
    {
        $b = new Buyer('A', 'B', 'GmbH Ohne', null, 'Str 1', '10115', 'Berlin', 'DE', 'a@example.com', null);
        $fc = new FakeClient([
            ['method' => 'PartnerList', 'response' => ['response' => ['status' => 'ok', 'result' => []]]],
            ['method' => 'PartnerCreate', 'response' => ['response' => ['status' => 'ok', 'result' => ['documentID' => '7:11']]]],
        ]);
        (new Partners($fc))->getOrCreate($b, VatResolver::resolve($b, 'HR'), 'c8');
        $p = json_decode($fc->calls[1][1]['partner'], true);
        self::assertSame('None', $p['vatRegistration']);
        self::assertSame('GmbH Ohne', $p['companyName']);
        self::assertArrayNotHasKey('companyType', $p);
    }

    public function testCreateWithoutDocumentIdResearchesByTaxId(): void
    {
        $fc = new FakeClient([
            ['method' => 'PartnerList', 'response' => ['response' => ['status' => 'ok', 'result' => []]]],
            ['method' => 'PartnerCreate', 'response' => ['response' => ['status' => 'ok', 'result' => []]]],
            ['method' => 'PartnerList', 'response' => ['response' => ['status' => 'ok', 'result' => [['documentID' => '7:12']]]]],
        ]);
        $ref = (new Partners($fc))->getOrCreate($this->b2b(), VatResolver::resolve($this->b2b(), 'HR'), 'c1');
        self::assertSame('7:12', $ref->documentId);
        self::assertTrue($ref->created);
        self::assertSame(['personalID' => '67272246049'], $fc->calls[2][1]);
    }

    public function testCreateWithoutDocumentIdAndEmptyResearchThrows(): void
    {
        $fc = new FakeClient([
            ['method' => 'PartnerList', 'response' => ['response' => ['status' => 'ok', 'result' => []]]],
            ['method' => 'PartnerCreate', 'response' => ['response' => ['status' => 'ok', 'result' => []]]],
            ['method' => 'PartnerList', 'response' => ['response' => ['status' => 'ok', 'result' => []]]],
        ]);
        $this->expectException(EracuniException::class);
        $this->expectExceptionMessageMatches('/no documentID/');
        (new Partners($fc))->getOrCreate($this->b2b(), VatResolver::resolve($this->b2b(), 'HR'), 'c1');
    }
}
