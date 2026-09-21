<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\core;

final class VatResolver
{
    private const EU = ['AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR','HU','IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK'];

    public static function isEuCountry(string $iso2): bool
    {
        return in_array(strtoupper($iso2), self::EU, true);
    }

    public static function normaliseTaxId(?string $s): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $s) ?? '');
    }

    /** ISO 7064 MOD 11,10 as used by Croatian OIB. Accepts an optional HR prefix. */
    public static function isValidOib(string $s): bool
    {
        $s = self::normaliseTaxId($s);
        if (str_starts_with($s, 'HR')) {
            $s = substr($s, 2);
        }
        if (!preg_match('/^\d{11}$/', $s)) {
            return false;
        }
        $a = 10;
        for ($i = 0; $i < 10; $i++) {
            $a = ($a + (int) $s[$i]) % 10;
            if ($a === 0) {
                $a = 10;
            }
            $a = ($a * 2) % 11;
        }
        $check = (11 - $a) % 10;
        return $check === (int) $s[10];
    }

    public static function euVatId(string $taxId, string $countryCode): ?string
    {
        $n = self::normaliseTaxId($taxId);
        $cc = strtoupper($countryCode);
        $prefix = $cc === 'GR' ? 'EL' : $cc;
        if (!self::isEuCountry($cc) || !preg_match('/^' . preg_quote($prefix, '/') . '[A-Z0-9]{2,13}$/', $n)) {
            return null;
        }
        return $n;
    }

    public static function resolve(Buyer $buyer, string $sellerCountry): VatTreatment
    {
        $cc = strtoupper($buyer->countryCode);
        $seller = strtoupper($sellerCountry);
        $isBusiness = $buyer->isBusiness();

        if ($cc === $seller) {
            if ($isBusiness && self::isValidOib((string) $buyer->taxId)) {
                $oib = self::normaliseTaxId($buyer->taxId);
                $oib = str_starts_with($oib, 'HR') ? substr($oib, 2) : $oib;
                return new VatTreatment(VatTreatment::DOMESTIC_B2B, true, false, null, 'personalID', $oib);
            }
            return new VatTreatment(VatTreatment::DOMESTIC_B2C, false, true, null, null, null);
        }

        if (self::isEuCountry($cc)) {
            $vat = $isBusiness ? self::euVatId((string) $buyer->taxId, $cc) : null;
            if ($vat !== null) {
                return new VatTreatment(VatTreatment::EU_B2B, true, false, '16', 'vatID', $vat);
            }
            return new VatTreatment(VatTreatment::EU_B2C, false, true, null, null, null);
        }

        if ($isBusiness) {
            return new VatTreatment(VatTreatment::EXPORT_B2B, true, false, '17', null, null);
        }
        return new VatTreatment(VatTreatment::EXPORT_B2C, false, true, '3', null, null);
    }

    /** @param float[] $knownRates */
    public static function rateFromAmounts(float $base, float $tax, bool $taxIncluded, array $knownRates): float
    {
        $net = $taxIncluded ? $base - $tax : $base;
        if ($net <= 0.0) {
            return 0.0;
        }
        $raw = $tax / $net * 100;
        $best = $knownRates[0] ?? 0.0;
        foreach ($knownRates as $r) {
            if (abs($r - $raw) < abs($best - $raw)) {
                $best = $r;
            }
        }
        return $best;
    }
}
