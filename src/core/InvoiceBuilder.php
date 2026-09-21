<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

final class InvoiceBuilder
{
    public static function build(OrderSnapshot $o, BuilderConfig $c, ?string $partnerId, ?string $buyerCode): BuildResult
    {
        $treatment = VatResolver::resolve($o->buyer, $c->sellerCountry);
        $warnings = [];

        // Payment method.
        $pm = PaymentMethodMap::forGateway($o->gatewayHandle, $c->paymentMap);
        $method = $pm['method'];
        $fiscalised = $pm['fiscalised'];
        if (round($o->totalPrice, 2) === 0.0) {
            $method = 'Other';
            $fiscalised = false;
        }

        // Dates.
        $date = $c->invoiceDateSource === 'orderDate' ? $o->dateOrdered : ($c->today ?: date('Y-m-d'));
        if ($c->lastInvoiceDate !== null && $c->lastInvoiceDate > $date) {
            $date = $c->lastInvoiceDate;
        }
        $dueDate = (new \DateTimeImmutable($date))->modify("+{$c->dueDays} days")->format('Y-m-d');

        // Lines: items, shipping, discounts.
        $lines = $o->lines;
        if ($o->totalShipping > 0.0) {
            $rate = $lines[0]->vatRate ?? 25.0;
            $divisor = 1 + $rate / 100;
            if ($divisor <= 0.0) {
                throw EracuniException::domain('Invalid VAT rate for shipping line.');
            }
            $lines[] = new Line($c->shippingDescription, 1.0, 'kom', round($o->totalShipping, 2), round($o->totalShipping / $divisor, 2), $rate, $c->shippingKpd, Line::KIND_SHIPPING);
        }
        if ($o->totalDiscount > 0.0) {
            foreach (self::splitDiscountByRate($o->lines, $o->totalDiscount) as $rate => $amount) {
                $divisor = 1 + (float) $rate / 100;
                if ($divisor <= 0.0) {
                    throw EracuniException::domain('Invalid VAT rate for discount line.');
                }
                $lines[] = new Line($c->discountDescription, 1.0, 'kom', -round($amount, 2), -round($amount / $divisor, 2), (float) $rate, null, Line::KIND_DISCOUNT);
            }
        }

        // Items.
        $items = [];
        $computed = 0.0;
        foreach ($lines as $n => $line) {
            $item = [
                'lineNumber' => $n + 1,
                'description' => $line->description,
                'quantity' => round($line->quantity, 2),
                'unit' => $line->unit !== '' ? $line->unit : 'kom',
            ];
            if ($treatment->isRetail) {
                $item['price'] = round($line->grossUnitPrice, 2);
                $item['vatPercentage'] = round($line->vatRate, 2);
                $computed += $line->grossTotal();
            } else {
                $item['netPrice'] = round($line->netUnitPrice, 2);
                $item['vatRate'] = round($line->vatRate, 2);
                $computed += round($line->netTotal() * (1 + $line->vatRate / 100), 2);
            }
            if ($treatment->vatTransactionType !== null) {
                $item['vatTransactionType'] = $treatment->vatTransactionType;
            }
            $kpd = $line->kpd ?? ($line->kind === Line::KIND_ITEM ? $c->defaultKpd : null);
            if ($kpd !== null && $kpd !== '') {
                $item['classificationCode'] = $kpd;
                $item['classificationOfProductsByActivity'] = $kpd;
            } elseif ($line->kind !== Line::KIND_DISCOUNT) {
                $warnings[] = "Line {$item['lineNumber']} ({$line->description}) has no KPD code.";
            }
            $items[] = $item;
        }

        // Totals check.
        $computed = round($computed, 2);
        $tolerance = 0.01 + 0.005 * count($lines);
        if (abs($computed - round($o->totalPrice, 2)) > $tolerance) {
            throw EracuniException::domain(sprintf('Totals mismatch: computed %.2f vs order %.2f (tolerance %.3f).', $computed, $o->totalPrice, $tolerance));
        }

        $p = [
            'businessUnit' => $c->businessUnit,
            'date' => $date,
            'dueDate' => $dueDate,
            'currencyCode' => $o->currency,
            'Items' => $items,
            'reference' => $o->number,
            'methodOfPayment' => $method,
        ];

        if ($treatment->isRetail) {
            $b = $o->buyer;
            $p['type'] = 'Retail';
            $p['buyerName'] = $b->displayName();
            $p['buyerStreet'] = trim((string) $b->street) !== '' ? $b->street : '-';
            $p['buyerPostalCode'] = (string) $b->postalCode;
            $p['buyerCity'] = (string) $b->city;
            $p['buyerCountry'] = strtoupper($b->countryCode);
            $p['buyerEMail'] = (string) $b->email;
            if (trim((string) $b->phone) !== '') {
                $p['buyerPhone'] = $b->phone;
            }
            if ($partnerId !== null) {
                $p['partnerID'] = $partnerId;
            }
            $p['cashRegisterCode'] = $c->cashRegisterCode;
        } else {
            $p['partnerID'] = (string) $partnerId;
            $p['buyerCode'] = (string) $buyerCode;
            if (strtoupper($o->buyer->countryCode) !== strtoupper($c->sellerCountry) && $treatment->taxIdField === 'vatID') {
                $p['buyerTaxNumber'] = $treatment->taxIdValue;
            }
        }

        return new BuildResult($p, $treatment, $method, $fiscalised, $warnings, $computed);
    }

    /**
     * Split an order-level discount across the VAT rates present, proportionally to gross line totals.
     * @param Line[] $lines
     * @return array<string, float> rate (as string key) => gross discount amount
     */
    private static function splitDiscountByRate(array $lines, float $discount): array
    {
        $byRate = [];
        $sum = 0.0;
        foreach ($lines as $l) {
            $k = (string) $l->vatRate;
            $byRate[$k] = ($byRate[$k] ?? 0.0) + $l->grossTotal();
            $sum += $l->grossTotal();
        }
        if ($sum <= 0.0) {
            return [(string) ($lines[0]->vatRate ?? 25.0) => $discount];
        }
        $out = [];
        $allocated = 0.0;
        $keys = array_keys($byRate);
        foreach ($keys as $i => $k) {
            $part = $i === count($keys) - 1 ? $discount - $allocated : round($discount * $byRate[$k] / $sum, 2);
            $out[$k] = $part;
            $allocated += $part;
        }
        return $out;
    }
}
