<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\core;

/** Ported from mojwmd invoice_api_eracuni_rest.php ($paymentMethodMap, $fiscalizationMethods, $b2cFallbackMethods, $paymentMethodForInvoiceMap). */
final class PaymentMethodMap
{
    public const METHODS = [
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

    private const RETAIL_FALLBACKS = ['Visa', 'EurocardMastercard', 'Diners', 'Amex', 'Stripe', 'PayPal', 'BankTransfer'];

    /** Order matters: first keyword hit wins. */
    private const KEYWORDS = [
        'corvus' => 'CorvusPay', 'keks' => 'KeksPay', 'paypal' => 'PayPal', 'stripe' => 'Stripe',
        'cash' => 'Cash', 'cod' => 'Cash', 'pouze' => 'Cash', 'gotovin' => 'Cash',
        'transfer' => 'BankTransfer', 'uplat' => 'BankTransfer', 'virman' => 'BankTransfer', 'manual' => 'BankTransfer', 'dummy' => 'BankTransfer',
        'wspay' => 'Visa', 'bankart' => 'Visa', 'monri' => 'Visa', 'mollie' => 'Visa', 'card' => 'Visa', 'kartic' => 'Visa', 'pay' => 'Visa',
    ];

    /** @return array{method:string, fiscalised:bool, paymentMethodForInvoice:string} */
    public static function entry(string $method): array
    {
        $m = self::METHODS[$method] ?? self::METHODS['BankTransfer'];
        return ['method' => isset(self::METHODS[$method]) ? $method : 'BankTransfer'] + $m;
    }

    /** @return array{method:string, fiscalised:bool, paymentMethodForInvoice:string} */
    public static function suggest(string $handle, string $className): array
    {
        $hay = strtolower($handle . ' ' . $className);
        foreach (self::KEYWORDS as $kw => $method) {
            if (str_contains($hay, $kw)) {
                return self::entry($method);
            }
        }
        return self::entry('BankTransfer');
    }

    /**
     * @param array<string, array{method:string, fiscalised?:bool, paymentMethodForInvoice?:string}> $paymentMap
     * @return array{method:string, fiscalised:bool, paymentMethodForInvoice:string}
     */
    public static function forGateway(string $handle, array $paymentMap): array
    {
        if (isset($paymentMap[$handle]['method'])) {
            $e = self::entry($paymentMap[$handle]['method']);
            $e['fiscalised'] = (bool) ($paymentMap[$handle]['fiscalised'] ?? $e['fiscalised']);
            $e['paymentMethodForInvoice'] = (string) ($paymentMap[$handle]['paymentMethodForInvoice'] ?? $e['paymentMethodForInvoice']);
            return $e;
        }
        return self::entry('BankTransfer');
    }

    /** @return string[] */
    public static function retailFallbacks(string $tried): array
    {
        return array_values(array_filter(self::RETAIL_FALLBACKS, fn(string $m) => $m !== $tried));
    }
}
