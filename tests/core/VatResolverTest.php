<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\TestCase;
use wmd\commerceeracuni\core\Buyer;
use wmd\commerceeracuni\core\VatResolver;
use wmd\commerceeracuni\core\VatTreatment;

final class VatResolverTest extends TestCase
{
    private function buyer(string $cc, ?string $org, ?string $tax): Buyer
    {
        return new Buyer('A', 'B', $org, $tax, 'S 1', '10000', 'City', $cc, 'a@b.c', null);
    }

    public function testOibValidation(): void
    {
        self::assertTrue(VatResolver::isValidOib('67272246049'));
        self::assertTrue(VatResolver::isValidOib('HR67272246049'));
        self::assertFalse(VatResolver::isValidOib('67272246040'));
        self::assertFalse(VatResolver::isValidOib('123'));
    }

    public function testDomesticB2bWithOib(): void
    {
        $t = VatResolver::resolve($this->buyer('HR', 'Tvrtka d.o.o.', ' 6727 2246049 '), 'HR');
        self::assertSame(VatTreatment::DOMESTIC_B2B, $t->code);
        self::assertTrue($t->isBusiness);
        self::assertFalse($t->isRetail);
        self::assertNull($t->vatTransactionType);
        self::assertSame('personalID', $t->taxIdField);
        self::assertSame('67272246049', $t->taxIdValue);
    }

    public function testDomesticCompanyWithoutValidOibIsRetail(): void
    {
        $t = VatResolver::resolve($this->buyer('HR', 'Tvrtka', '111'), 'HR');
        self::assertSame(VatTreatment::DOMESTIC_B2C, $t->code);
        self::assertTrue($t->isRetail);
        self::assertNull($t->taxIdField);
    }

    public function testDomesticB2c(): void
    {
        $t = VatResolver::resolve($this->buyer('HR', null, null), 'HR');
        self::assertSame(VatTreatment::DOMESTIC_B2C, $t->code);
        self::assertTrue($t->isRetail);
    }

    public function testEuB2bReverseCharge(): void
    {
        $t = VatResolver::resolve($this->buyer('SI', 'Podjetje d.o.o.', 'si 29865174'), 'HR');
        self::assertSame(VatTreatment::EU_B2B, $t->code);
        self::assertSame('16', $t->vatTransactionType);
        self::assertSame('vatID', $t->taxIdField);
        self::assertSame('SI29865174', $t->taxIdValue);
    }

    public function testEuCompanyWithoutVatIdIsRetail(): void
    {
        $t = VatResolver::resolve($this->buyer('DE', 'GmbH', null), 'HR');
        self::assertSame(VatTreatment::EU_B2C, $t->code);
        self::assertTrue($t->isRetail);
        self::assertNull($t->vatTransactionType);
    }

    public function testVatIdPrefixMustMatchCountry(): void
    {
        $t = VatResolver::resolve($this->buyer('DE', 'GmbH', 'SI29865174'), 'HR');
        self::assertSame(VatTreatment::EU_B2C, $t->code);
    }

    public function testThirdCountryB2b(): void
    {
        $t = VatResolver::resolve($this->buyer('CH', 'AG', null), 'HR');
        self::assertSame(VatTreatment::EXPORT_B2B, $t->code);
        self::assertSame('17', $t->vatTransactionType);
        self::assertFalse($t->isRetail);
    }

    public function testThirdCountryB2c(): void
    {
        $t = VatResolver::resolve($this->buyer('US', null, null), 'HR');
        self::assertSame(VatTreatment::EXPORT_B2C, $t->code);
        self::assertSame('3', $t->vatTransactionType);
        self::assertTrue($t->isRetail);
    }

    public function testRateFromAmountsSnapsToKnownRate(): void
    {
        $rates = [25.0, 13.0, 5.0, 0.0];
        self::assertSame(25.0, VatResolver::rateFromAmounts(100.0, 20.0, true, $rates));  // gross 100 incl 20 tax
        self::assertSame(25.0, VatResolver::rateFromAmounts(80.0, 20.0, false, $rates));  // net 80 + 20
        self::assertSame(13.0, VatResolver::rateFromAmounts(113.0, 13.0, true, $rates));
        self::assertSame(0.0, VatResolver::rateFromAmounts(50.0, 0.0, true, $rates));
    }

    public function testGreekVatIdUsesElPrefix(): void
    {
        $t = VatResolver::resolve($this->buyer('GR', 'Etaireia AE', 'EL123456789'), 'HR');
        self::assertSame(VatTreatment::EU_B2B, $t->code);
        self::assertSame('EL123456789', $t->taxIdValue);
        self::assertSame('vatID', $t->taxIdField);
        // A GR-prefixed id is not a valid Greek VAT number.
        self::assertSame(VatTreatment::EU_B2C, VatResolver::resolve($this->buyer('GR', 'Etaireia AE', 'GR123456789'), 'HR')->code);
    }
}
