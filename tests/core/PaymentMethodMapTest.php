<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use wmd\commerceeracuni\core\PaymentMethodMap;

final class PaymentMethodMapTest extends TestCase
{
    public function testEnumTableMatchesMojwmd(): void
    {
        $expected = [
            'Cash'               => ['fiscalised' => true,  'paymentMethodForInvoice' => 'Cash'],
            'Visa'               => ['fiscalised' => true,  'paymentMethodForInvoice' => 'Card'],
            'EurocardMastercard' => ['fiscalised' => true,  'paymentMethodForInvoice' => 'Card'],
            'Diners'             => ['fiscalised' => true,  'paymentMethodForInvoice' => 'Card'],
            'Amex'               => ['fiscalised' => true,  'paymentMethodForInvoice' => 'Card'],
            'Stripe'             => ['fiscalised' => true,  'paymentMethodForInvoice' => 'Stripe'],
            'PayPal'             => ['fiscalised' => true,  'paymentMethodForInvoice' => 'PayPal'],
            'CorvusPay'          => ['fiscalised' => true,  'paymentMethodForInvoice' => 'CorvusPay'],
            'KeksPay'            => ['fiscalised' => true,  'paymentMethodForInvoice' => 'KeksPay'],
            'BankTransfer'       => ['fiscalised' => false, 'paymentMethodForInvoice' => 'BankPaymentOrder'],
            'Compensation'       => ['fiscalised' => false, 'paymentMethodForInvoice' => 'Compensation'],
            'Other'              => ['fiscalised' => false, 'paymentMethodForInvoice' => 'BankPaymentOrder'],
        ];
        self::assertSame($expected, PaymentMethodMap::METHODS);
    }

    #[DataProvider('suggestions')]
    public function testSuggestFromHandleAndClass(string $handle, string $class, string $expected): void
    {
        self::assertSame($expected, PaymentMethodMap::suggest($handle, $class)['method']);
    }

    public static function suggestions(): array
    {
        return [
            ['corvusPay', 'wmd\corvuspay\CorvusGateway', 'CorvusPay'],
            ['keksPayment', 'wmd\keks\KeksGateway', 'KeksPay'],
            ['paypal', 'x\PayPalRest', 'PayPal'],
            ['stripe', 'craft\commerce\stripe\gateways\PaymentIntents', 'Stripe'],
            ['pouzecem', 'craft\commerce\gateways\Manual', 'Cash'],
            ['cod', 'x\Y', 'Cash'],
            ['uplatnica', 'craft\commerce\gateways\Manual', 'BankTransfer'],
            ['dummy', 'craft\commerce\gateways\Dummy', 'BankTransfer'],
            ['wsPay', 'wmd\wspay\WsPayGateway', 'Visa'],
            ['bankart', 'ww\bankart\BankartGateway', 'Visa'],
            ['monri', 'x\MonriGateway', 'Visa'],
            ['mollie', 'craft\commerce\mollie\gateways\Gateway', 'Visa'],
            ['something', 'x\Whatever', 'BankTransfer'],
        ];
    }

    public function testForGatewayPrefersSavedMap(): void
    {
        $map = ['wsPay' => ['method' => 'EurocardMastercard', 'fiscalised' => true, 'paymentMethodForInvoice' => 'Card']];
        self::assertSame('EurocardMastercard', PaymentMethodMap::forGateway('wsPay', $map)['method']);
        self::assertSame('BankTransfer', PaymentMethodMap::forGateway('unknown', $map)['method']);
    }

    public function testRetailFallbacksExcludeTried(): void
    {
        self::assertSame(
            ['EurocardMastercard', 'Diners', 'Amex', 'Stripe', 'PayPal', 'BankTransfer'],
            PaymentMethodMap::retailFallbacks('Visa'),
        );
        self::assertSame(['Visa', 'EurocardMastercard', 'Diners', 'Amex', 'Stripe', 'PayPal'], PaymentMethodMap::retailFallbacks('BankTransfer'));
    }
}
