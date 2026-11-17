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

    private function snapshot(array $lines, float $total, Buyer $buyer, string $gateway = 'corvusPay', float $shipping = 0.0, float $discount = 0.0, float $shippingVatRate = 25.0): OrderSnapshot
    {
        return new OrderSnapshot(42, '1001', '2026-09-20', '2026-09-20', true, 'EUR', $gateway, $total, $shipping, $discount, $lines, $buyer, $shippingVatRate);
    }

    public function testRetailShapeForDomesticB2c(): void
    {
        $line = new Line('Majica', 2, 'kom', 25.00, 20.00, 25.0, '14.10.23');
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
        self::assertSame('C14.10.23', $i['classificationCode']);
        self::assertSame('C14.10.23', $i['classificationOfProductsByActivity']);
        self::assertArrayNotHasKey('netPrice', $i);
        self::assertArrayNotHasKey('vatTransactionType', $i);
        self::assertTrue($r->fiscalised);
        self::assertSame(50.0, $r->computedTotal);
    }

    public function testB2bShapeForDomesticCompany(): void
    {
        $line = new Line('Usluga', 1, 'kom', 125.00, 100.00, 25.0, '62.10.11');
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
        $line = new Line('Majica', 1, 'kom', 100.00, 80.00, 25.0, '14.10.23');
        $snap = $this->snapshot([$line], 95.00, $this->hrBuyer(), 'corvusPay', shipping: 5.00, discount: 10.00, shippingVatRate: 25.0);
        $p = InvoiceBuilder::build($snap, $this->config(), 'P1', null)->payload;
        self::assertCount(3, $p['Items']);
        self::assertSame('Dostava', $p['Items'][1]['description']);
        self::assertSame(5.0, $p['Items'][1]['price']);
        self::assertSame('H53.20.09', $p['Items'][1]['classificationCode'], 'Default shipping KPD, in the form e-računi takes.');
        self::assertArrayNotHasKey('classificationCode', $p['Items'][2], 'Discount lines carry no code.');
        self::assertSame('Popust', $p['Items'][2]['description']);
        self::assertSame(-10.0, $p['Items'][2]['price']);
        self::assertSame(25.0, $p['Items'][2]['vatPercentage']);
    }

    public function testShippingVatRateComesFromTheSnapshotNotTheFirstLine(): void
    {
        $lines = [
            new Line('Knjiga', 1, 'kom', 105.00, 100.00, 5.0, '58.11.13'),
            new Line('Majica', 1, 'kom', 125.00, 100.00, 25.0, '14.10.23'),
        ];
        $snap = $this->snapshot($lines, 236.25, $this->hrBuyer(), 'corvusPay', shipping: 6.25, shippingVatRate: 25.0);
        $p = InvoiceBuilder::build($snap, $this->config(), 'P1', null)->payload;
        self::assertCount(3, $p['Items']);
        self::assertSame('Dostava', $p['Items'][2]['description']);
        self::assertSame(25.0, $p['Items'][2]['vatPercentage'], 'Shipping VAT must not be copied from the first line (5%).');
        self::assertSame(6.25, $p['Items'][2]['price']);
    }

    public function testUnmappedGatewayIsAWarning(): void
    {
        $line = new Line('X', 1, 'kom', 10.0, 8.0, 25.0, '14.10.23');
        $cfg = $this->config([
            'paymentMap' => ['newGateway' => ['method' => 'Visa', 'fiscalised' => true, 'paymentMethodForInvoice' => 'Card']],
            'unmappedGateways' => ['newGateway'],
        ]);
        $r = InvoiceBuilder::build($this->snapshot([$line], 10.0, $this->hrBuyer(), 'newGateway'), $cfg, 'P1', null);
        self::assertContains('Gateway newGateway has no saved payment mapping; using suggested Visa.', $r->warnings);
    }

    public function testMappedGatewayIsNotWarnedAbout(): void
    {
        $line = new Line('X', 1, 'kom', 10.0, 8.0, 25.0, '14.10.23');
        $r = InvoiceBuilder::build($this->snapshot([$line], 10.0, $this->hrBuyer()), $this->config(['unmappedGateways' => ['somethingElse']]), 'P1', null);
        self::assertSame([], $r->warnings);
    }

    public function testZeroVatOnADomesticLineIsAWarning(): void
    {
        $line = new Line('Usluga', 1, 'kom', 100.0, 100.0, 0.0, '62.10.11');
        $r = InvoiceBuilder::build($this->snapshot([$line], 100.0, $this->hrBuyer()), $this->config(), 'P1', null);
        self::assertContains('Line 1 (Usluga) is at 0% VAT on a domestic invoice.', $r->warnings);
    }

    public function testZeroVatOutsideDomesticTreatmentsIsNotAWarning(): void
    {
        $b = new Buyer('J', 'N', 'Podjetje d.o.o.', 'SI29865174', 'Cesta 1', '1000', 'Ljubljana', 'SI', 'j@example.com', null);
        $line = new Line('Usluga', 1, 'kom', 100.00, 100.00, 0.0, '62.10.11');
        $r = InvoiceBuilder::build($this->snapshot([$line], 100.00, $b, 'uplatnica'), $this->config(), 'P2', 'B2B-SI29865174');
        self::assertSame([], $r->warnings);
    }

    public function testTotalsToleranceScalesWithQuantity(): void
    {
        // 40 pieces: per-piece rounding can drift by more than a flat one-cent tolerance allows.
        $line = new Line('Vijak', 40, 'kom', 1.00, 0.80, 25.0, '14.10.23');
        $r = InvoiceBuilder::build($this->snapshot([$line], 40.15, $this->hrBuyer()), $this->config(), 'P1', null);
        self::assertSame(40.0, $r->computedTotal);

        $this->expectException(EracuniException::class);
        $this->expectExceptionMessageMatches('/Totals mismatch/');
        InvoiceBuilder::build($this->snapshot([$line], 40.25, $this->hrBuyer()), $this->config(), 'P1', null);
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

    public function testCodesAreSentInTheFormEracuniTakes(): void
    {
        $lines = [new Line('Usluga', 1, 'kom', 125.00, 100.00, 25.0, '621011'), new Line('Hosting', 1, 'kom', 125.00, 100.00, 25.0, ' k63.10.12 ')];
        $snap = $this->snapshot($lines, 256.25, $this->hrBuyer('Tvrtka d.o.o.', '67272246049'), 'uplatnica', shipping: 6.25);
        $r = InvoiceBuilder::build($snap, $this->config(), 'P9', 'B2B-67272246049');
        self::assertSame(['K62.10.11', 'K63.10.12', 'H53.20.09'], array_column($r->payload['Items'], 'classificationCode'));
        self::assertSame(['K62.10.11', 'K63.10.12', 'H53.20.09'], array_column($r->payload['Items'], 'classificationOfProductsByActivity'));
        self::assertSame([], $r->warnings);
    }

    public function testDomesticB2bIsNotSentWithAnInvalidOrMissingCode(): void
    {
        $lines = [
            new Line('Usluga', 1, 'kom', 125.00, 100.00, 25.0, '62.01.11'),
            new Line('Majica', 1, 'kom', 25.00, 20.00, 25.0, null),
            new Line('Hosting', 1, 'kom', 125.00, 100.00, 25.0, '63.10.12'),
        ];
        $snap = $this->snapshot($lines, 281.25, $this->hrBuyer('Tvrtka d.o.o.', '67272246049'), 'uplatnica', shipping: 6.25);
        try {
            InvoiceBuilder::build($snap, $this->config(['shippingKpd' => '532000']), 'P9', 'B2B-67272246049');
            self::fail('A domestic B2B invoice with bad KPD codes was built.');
        } catch (EracuniException $e) {
            self::assertFalse($e->isTransport());
            self::assertFalse($e->isOutcomeUnknown());
            $m = $e->getMessage();
            self::assertStringStartsWith('Not sent: every line of a domestic B2B eRačun needs a KPD 2025 code valid on 2026-09-21.', $m);
            self::assertStringContainsString('Line 1 (Usluga): KPD 62.01.11 is not a KPD 2025 code. Nearest valid codes: ', $m);
            self::assertStringContainsString('Line 2 (Majica) has no KPD code.', $m);
            self::assertStringNotContainsString('Line 3', $m);
            self::assertStringContainsString('Line 4 (Dostava): KPD 53.20.00 is not a KPD 2025 code.', $m);
        }
    }

    public function testDomesticB2bTakesTheDefaultKpdForItemsWithout(): void
    {
        $line = new Line('Majica', 1, 'kom', 25.00, 20.00, 25.0, null);
        $snap = $this->snapshot([$line], 25.00, $this->hrBuyer('Tvrtka d.o.o.', '67272246049'), 'uplatnica');
        $p = InvoiceBuilder::build($snap, $this->config(['defaultKpd' => '141023']), 'P9', 'B2B-67272246049')->payload;
        self::assertSame('C14.10.23', $p['Items'][0]['classificationCode']);
    }

    public function testDomesticB2bChecksCodesOnTheInvoiceDate(): void
    {
        $line = new Line('Daske', 1, 'kom', 125.00, 100.00, 25.0, '16.12.00');
        $snap = $this->snapshot([$line], 125.00, $this->hrBuyer('Tvrtka d.o.o.', '67272246049'), 'uplatnica');
        $p = InvoiceBuilder::build($snap, $this->config(['today' => '2026-12-31']), 'P9', 'B2B-67272246049')->payload;
        self::assertSame('C16.12.00', $p['Items'][0]['classificationCode']);

        $this->expectException(EracuniException::class);
        $this->expectExceptionMessageMatches('/Line 1 \(Daske\): KPD 16\.12\.00 was valid until 2026-12-31\. Nearest valid codes: .*16\.12\.10/');
        InvoiceBuilder::build($snap, $this->config(['today' => '2027-01-04']), 'P9', 'B2B-67272246049');
    }

    public function testDomesticB2bDiscountLineNeedsNoCode(): void
    {
        $line = new Line('Usluga', 1, 'kom', 125.00, 100.00, 25.0, '62.10.11');
        $snap = $this->snapshot([$line], 112.50, $this->hrBuyer('Tvrtka d.o.o.', '67272246049'), 'uplatnica', discount: 12.50);
        $p = InvoiceBuilder::build($snap, $this->config(), 'P9', 'B2B-67272246049')->payload;
        self::assertCount(2, $p['Items']);
        self::assertArrayNotHasKey('classificationCode', $p['Items'][1]);
    }

    public function testRetailLeavesABadCodeOffTheLineAndSaysSo(): void
    {
        $line = new Line('Majica', 1, 'kom', 25.00, 20.00, 25.0, '141400');
        $snap = $this->snapshot([$line], 30.00, $this->hrBuyer(), shipping: 5.00);
        $r = InvoiceBuilder::build($snap, $this->config(['shippingKpd' => '532000']), 'P1', null);
        self::assertArrayNotHasKey('classificationCode', $r->payload['Items'][0]);
        self::assertArrayNotHasKey('classificationOfProductsByActivity', $r->payload['Items'][1]);
        self::assertStringStartsWith('Line 1 (Majica): KPD 14.14.00 is not a KPD 2025 code. Nearest valid codes: 14.10.99 (', $r->warnings[0]);
        self::assertStringEndsWith('Sent without a KPD code.', $r->warnings[0]);
        self::assertStringStartsWith('Line 2 (Dostava): KPD 53.20.00 is not a KPD 2025 code. Nearest valid codes: 53.20.01 (Multimodalne kurirske usluge)', $r->warnings[1]);
        self::assertCount(2, $r->warnings);
    }

    public function testForeignB2bLeavesABadCodeOffTheLine(): void
    {
        $b = new Buyer('J', 'N', 'Podjetje d.o.o.', 'SI29865174', 'Cesta 1', '1000', 'Ljubljana', 'SI', 'j@example.com', null);
        $line = new Line('Usluga', 1, 'kom', 100.00, 100.00, 0.0, '62.01.11');
        $r = InvoiceBuilder::build($this->snapshot([$line], 100.00, $b, 'uplatnica'), $this->config(), 'P2', 'B2B-SI29865174');
        self::assertArrayNotHasKey('classificationCode', $r->payload['Items'][0]);
        self::assertStringEndsWith('Sent without a KPD code.', $r->warnings[0]);
    }

    public function testZeroTotalUsesOther(): void
    {
        $line = new Line('Gratis', 1, 'kom', 0.0, 0.0, 25.0, null);
        $r = InvoiceBuilder::build($this->snapshot([$line], 0.0, $this->hrBuyer()), $this->config(), 'P1', null);
        self::assertSame('Other', $r->payload['methodOfPayment']);
        self::assertFalse($r->fiscalised);
    }
}
