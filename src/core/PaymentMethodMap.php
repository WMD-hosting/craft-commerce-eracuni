<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

/** Ported from mojwmd invoice_api_eracuni_rest.php ($paymentMethodMap, $fiscalizationMethods, $b2cFallbackMethods, $paymentMethodForInvoiceMap). */
final class PaymentMethodMap
{
    /**
     * The key is the invoice's methodOfPayment (fiscalisation); paymentMethodForInvoice is what
     * SalesInvoicePaymentRecordAdd records. That call refuses 'Card' with HTTP 500 and records nothing
     * ("Value 'Card' is not allowed", wmdhosting/novine#152), so card payments are recorded as
     * BankPaymentOrder while the invoice keeps the card. Live calls have accepted BankPaymentOrder,
     * PayPal and Compensation. Stripe, CorvusPay and KeksPay are in the API's documented value list,
     * but no call has confirmed them yet.
     */
    public const METHODS = [
        'Cash' => ['fiscalised' => true,  'paymentMethodForInvoice' => 'Cash'],
        'Visa' => ['fiscalised' => true,  'paymentMethodForInvoice' => 'BankPaymentOrder'],
        'EurocardMastercard' => ['fiscalised' => true,  'paymentMethodForInvoice' => 'BankPaymentOrder'],
        'Diners' => ['fiscalised' => true,  'paymentMethodForInvoice' => 'BankPaymentOrder'],
        'Amex' => ['fiscalised' => true,  'paymentMethodForInvoice' => 'BankPaymentOrder'],
        'Stripe' => ['fiscalised' => true,  'paymentMethodForInvoice' => 'Stripe'],
        'PayPal' => ['fiscalised' => true,  'paymentMethodForInvoice' => 'PayPal'],
        'CorvusPay' => ['fiscalised' => true,  'paymentMethodForInvoice' => 'CorvusPay'],
        'KeksPay' => ['fiscalised' => true,  'paymentMethodForInvoice' => 'KeksPay'],
        'BankTransfer' => ['fiscalised' => false, 'paymentMethodForInvoice' => 'BankPaymentOrder'],
        'Compensation' => ['fiscalised' => false, 'paymentMethodForInvoice' => 'Compensation'],
        'Other' => ['fiscalised' => false, 'paymentMethodForInvoice' => 'BankPaymentOrder'],
    ];

    private const RETAIL_FALLBACKS = ['Visa', 'EurocardMastercard', 'Diners', 'Amex', 'Stripe', 'PayPal', 'BankTransfer'];

    /** Payment-record values the API refuses, as earlier releases saved them into gateway mappings. */
    private const REFUSED_RECORD_METHODS = ['Card' => 'BankPaymentOrder'];

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
            $saved = (string) ($paymentMap[$handle]['paymentMethodForInvoice'] ?? $e['paymentMethodForInvoice']);
            $e['paymentMethodForInvoice'] = self::REFUSED_RECORD_METHODS[$saved] ?? $saved;
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
