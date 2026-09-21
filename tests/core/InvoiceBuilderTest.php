<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\TestCase;
use wmd\commerceeracuni\core\BuilderConfig;
use wmd\commerceeracuni\core\Buyer;
use wmd\commerceeracuni\core\EracuniException;
use wmd\commerceeracuni\core\InvoiceBuilder;
use wmd\commerceeracuni\core\Line;
use wmd\commerceeracuni\core\OrderSnapshot;

final class InvoiceBuilderTest extends TestCase
{
    private function config(array $over = []): BuilderConfig
    {
        return new BuilderConfig(...array_merge([
            'sellerCountry' => 'HR', 'businessUnit' => 'WEB', 'cashRegisterCode' => '1', 'dueDays' => 8,
            'invoiceDateSource' => 'now', 'today' => '2026-09-21',
            'paymentMap' => ['corvusPay' => ['method' => 'CorvusPay', 'fiscalised' => true, 'paymentMethodForInvoice' => 'CorvusPay'],
                             'uplatnica' => ['method' => 'BankTransfer', 'fiscalised' => false, 'paymentMethodForInvoice' => 'BankPaymentOrder']],
        ], $over));
    }

    private function hrBuyer(?string $org = null, ?string $tax = null): Buyer
    {
        return new Buyer('Ana', 'Anić', $org, $tax, 'Ilica 1', '10000', 'Zagreb', 'HR', 'ana@example.com', '+385911111111');
    }

    private function snapshot(array $lines, float $total, Buyer $buyer, string $gateway = 'corvusPay', float $shipping = 0.0, float $discount = 0.0): OrderSnapshot
    {
        return new OrderSnapshot(42, '1001', '2026-09-20', '2026-09-20', true, 'EUR', $gateway, $total, $shipping, $discount, $lines, $buyer);
    }

    public function testRetailShapeForDomesticB2c(): void
    {
        $line = new Line('Majica', 2, 'kom', 25.00, 20.00, 25.0, '141400');
        $r = InvoiceBuilder::build($this->snapshot([$line], 50.00, $this->hrBuyer()), $this->config(), 'P1', null);
        $p = $r->payload;
        self::assertSame('Retail', $p['type']);
        self::assertSame('WEB', $p['businessUnit']);
        self::assertSame('2026-09-21', $p['date']);
        self::assertSame('2026-09-29', $p['dueDate']);
        self::assertSame('EUR', $p['currencyCode']);
        self::assertSame('1001', $p['reference']);
        self::assertSame('CorvusPay', $p['methodOfPayment']);
        self::assertSame('1', $p['cashRegisterCode']);
        self::assertSame('P1', $p['partnerID']);
        self::assertSame('Ana Anić', $p['buyerName']);
        self::assertSame('HR', $p['buyerCountry']);
        self::assertArrayHasKey('Items', $p);
        self::assertArrayNotHasKey('items', $p);
        $i = $p['Items'][0];
        self::assertSame(1, $i['lineNumber']);
        self::assertSame(25.0, $i['price']);
        self::assertSame(25.0, $i['vatPercentage']);
        self::assertSame(2.0, $i['quantity']);
        self::assertSame('kom', $i['unit']);
        self::assertSame('141400', $i['classificationCode']);
        self::assertSame('141400', $i['classificationOfProductsByActivity']);
        self::assertArrayNotHasKey('netPrice', $i);
        self::assertArrayNotHasKey('vatTransactionType', $i);
        self::assertTrue($r->fiscalised);
        self::assertSame(50.0, $r->computedTotal);
    }

    public function testB2bShapeForDomesticCompany(): void
    {
        $line = new Line('Usluga', 1, 'kom', 125.00, 100.00, 25.0, '620100');
        $r = InvoiceBuilder::build($this->snapshot([$line], 125.00, $this->hrBuyer('Tvrtka d.o.o.', '67272246049'), 'uplatnica'), $this->config(), 'P9', 'B2B-67272246049');
        $p = $r->payload;
        self::assertArrayNotHasKey('type', $p);
        self::assertArrayNotHasKey('buyerName', $p);
        self::assertArrayNotHasKey('cashRegisterCode', $p);
        self::assertSame('P9', $p['partnerID']);
        self::assertSame('B2B-67272246049', $p['buyerCode']);
        self::assertSame('BankTransfer', $p['methodOfPayment']);
        self::assertArrayNotHasKey('buyerTaxNumber', $p);
        self::assertSame(100.0, $p['Items'][0]['netPrice']);
        self::assertSame(25.0, $p['Items'][0]['vatRate']);
        self::assertArrayNotHasKey('price', $p['Items'][0]);
        self::assertFalse($r->fiscalised);
    }

    public function testEuB2bAddsReverseChargeAndTaxNumber(): void
    {
        $b = new Buyer('J', 'N', 'Podjetje d.o.o.', 'SI29865174', 'Cesta 1', '1000', 'Ljubljana', 'SI', 'j@example.com', null);
        $line = new Line('Usluga', 1, 'kom', 100.00, 100.00, 0.0, null);
        $p = InvoiceBuilder::build($this->snapshot([$line], 100.00, $b, 'uplatnica'), $this->config(), 'P2', 'B2B-SI29865174')->payload;
        self::assertSame('16', $p['Items'][0]['vatTransactionType']);
        self::assertSame('SI29865174', $p['buyerTaxNumber']);
    }

    public function testThirdCountryB2cIsRetailWithType3(): void
    {
        $b = new Buyer('John', 'Doe', null, null, '', '10001', 'NYC', 'US', 'j@example.com', null);
        $line = new Line('Majica', 1, 'kom', 20.00, 20.00, 0.0, null);
        $p = InvoiceBuilder::build($this->snapshot([$line], 20.00, $b), $this->config(), 'P3', null)->payload;
        self::assertSame('Retail', $p['type']);
        self::assertSame('3', $p['Items'][0]['vatTransactionType']);
        self::assertSame('-', $p['buyerStreet']);
    }

    public function testShippingAndDiscountBecomeLines(): void
    {
        $line = new Line('Majica', 1, 'kom', 100.00, 80.00, 25.0, '141400');
        $snap = $this->snapshot([$line], 95.00, $this->hrBuyer(), 'corvusPay', shipping: 5.00, discount: 10.00);
        $p = InvoiceBuilder::build($snap, $this->config(['shippingKpd' => '532000']), 'P1', null)->payload;
        self::assertCount(3, $p['Items']);
        self::assertSame('Dostava', $p['Items'][1]['description']);
        self::assertSame(5.0, $p['Items'][1]['price']);
        self::assertSame('532000', $p['Items'][1]['classificationCode']);
        self::assertSame('Popust', $p['Items'][2]['description']);
        self::assertSame(-10.0, $p['Items'][2]['price']);
        self::assertSame(25.0, $p['Items'][2]['vatPercentage']);
    }

    public function testTotalsMismatchThrowsDomainError(): void
    {
        $line = new Line('Majica', 1, 'kom', 100.00, 80.00, 25.0, null);
        $this->expectException(EracuniException::class);
        $this->expectExceptionMessageMatches('/Totals mismatch/');
        InvoiceBuilder::build($this->snapshot([$line], 120.00, $this->hrBuyer()), $this->config(), 'P1', null);
    }

    public function testDateMovesForwardToLastInvoiceDate(): void
    {
        $line = new Line('X', 1, 'kom', 10.0, 8.0, 25.0, null);
        $p = InvoiceBuilder::build($this->snapshot([$line], 10.0, $this->hrBuyer()), $this->config(['invoiceDateSource' => 'orderDate', 'lastInvoiceDate' => '2026-09-25']), 'P1', null)->payload;
        self::assertSame('2026-09-25', $p['date']);
        self::assertSame('2026-10-03', $p['dueDate']);
    }

    public function testMissingKpdIsAWarningNotAnError(): void
    {
        $line = new Line('X', 1, 'kom', 10.0, 8.0, 25.0, null);
        $r = InvoiceBuilder::build($this->snapshot([$line], 10.0, $this->hrBuyer()), $this->config(), 'P1', null);
        self::assertArrayNotHasKey('classificationCode', $r->payload['Items'][0]);
        self::assertNotEmpty($r->warnings);
    }

    public function testZeroTotalUsesOther(): void
    {
        $line = new Line('Gratis', 1, 'kom', 0.0, 0.0, 25.0, null);
        $r = InvoiceBuilder::build($this->snapshot([$line], 0.0, $this->hrBuyer()), $this->config(), 'P1', null);
        self::assertSame('Other', $r->payload['methodOfPayment']);
        self::assertFalse($r->fiscalised);
    }
}
