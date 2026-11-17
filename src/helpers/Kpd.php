<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\helpers;

use Craft;
use wmd\commerceeracuni\core\KpdCatalog;
use wmd\commerceeracuni\core\KpdCheck;

/**
 * KPD codes in the control panel and the console: today's date in the site's time zone, and the catalog's verdicts
 * as translated messages.
 *
 * @since 1.1.0
 */
final class Kpd
{
    /** Today (Y-m-d) in the system time zone, the day settings are checked against. */
    public static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(Craft::$app->getTimeZone())))->format('Y-m-d');
    }

    /** The code to store: canonical (53.20.09) when it reads as a code, otherwise as typed so an error can quote it. */
    public static function canonical(?string $value): string
    {
        $typed = trim((string) $value);

        return KpdCatalog::normalize($typed) ?? $typed;
    }

    public static function check(?string $value, ?string $date = null): KpdCheck
    {
        return KpdCatalog::shared()->check($value, $date ?? self::today());
    }

    /**
     * Why $check's code cannot be saved: not six digits, not in KPD 2025, or no longer in force. Null when it can:
     * empty, in force, or starting later (invoices get it from that day; notice() says so).
     */
    public static function error(KpdCheck $check): ?string
    {
        $problem = match ($check->status) {
            KpdCheck::FORMAT => Craft::t('commerce-eracuni', '{value} is not a KPD code: a KPD code has six digits, such as 62.10.11.', ['value' => $check->input]),
            KpdCheck::UNKNOWN => Craft::t('commerce-eracuni', 'KPD {code} is not a KPD 2025 code.', ['code' => $check->code]),
            KpdCheck::ENDED => Craft::t('commerce-eracuni', 'KPD {code} was valid until {date}.', ['code' => $check->code, 'date' => self::date((string) $check->kpd?->to)]),
            default => null,
        };

        return $problem !== null ? self::withSuggestions($problem, $check) : null;
    }

    /** The title of $check's code, and when it starts or stops being valid; null for an empty or invalid code. */
    public static function notice(KpdCheck $check): ?string
    {
        $kpd = $check->kpd;
        if ($kpd === null || !in_array($check->status, [KpdCheck::OK, KpdCheck::NOT_YET], true)) {
            return null;
        }
        if ($check->status === KpdCheck::NOT_YET) {
            return Craft::t('commerce-eracuni', '{title}. Valid from {date}: invoices dated earlier cannot carry it.', ['title' => $kpd->title, 'date' => self::date($kpd->from)]);
        }
        if ($kpd->to !== null) {
            return Craft::t('commerce-eracuni', '{title}. Valid until {date}: choose its successor before then.', ['title' => $kpd->title, 'date' => self::date($kpd->to)]);
        }

        return $kpd->title;
    }

    /** Whether notice() is a warning (the code stops or has not started being valid) rather than just its title. */
    public static function needsAttention(KpdCheck $check): bool
    {
        return $check->status === KpdCheck::NOT_YET || ($check->status === KpdCheck::OK && $check->kpd?->to !== null);
    }

    private static function withSuggestions(string $problem, KpdCheck $check): string
    {
        $near = $check->suggestionList();

        return $near !== '' ? $problem . ' ' . Craft::t('commerce-eracuni', 'Nearest valid codes: {codes}.', ['codes' => $near]) : $problem;
    }

    private static function date(string $ymd): string
    {
        return Craft::$app->getFormatter()->asDate($ymd, 'short');
    }
}
