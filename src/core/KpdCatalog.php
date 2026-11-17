<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

/**
 * KPD 2025, the product classification every Croatian B2B eRačun line carries: six digits written with dots
 * (53.20.09), sent to e-računi with the section letter in front (H53.20.09).
 *
 * Reads resources/kpd/kpd-2025.json, a byte-for-byte copy of the file WMD's Business Suite builds from the DZS list
 * and the amendments (see resources/kpd/README.md). Every code knows the days it is in force and its title on each:
 * NN 119/26 ends, starts and renames codes from 1.1.2027. Dates are Y-m-d strings.
 *
 * Same rules as the Suite's catalog: 621011, 62.10.11 and K62.10.11 all read as 62.10.11, and a section letter that
 * was typed is ignored in favour of the list's own.
 */
final class KpdCatalog
{
    /** The shipping line's default: Ostale poštanske i kurirske usluge, d. n. */
    public const DEFAULT_SHIPPING = '53.20.09';
    /** How many valid codes a refusal or a warning suggests. */
    public const SUGGESTIONS = 3;

    private static ?self $shared = null;

    /** @var array<string, string|array{t: string, from?: string, to?: string, titles?: array<string, string>}>|null */
    private ?array $items = null;
    /** @var array<string, string> division => section letter */
    private array $sections = [];
    private string $validFrom = '2025-01-01';

    public function __construct(private readonly string $file = __DIR__ . '/../../resources/kpd/kpd-2025.json')
    {
    }

    /** One catalog per process: the file is about 400 KB and never changes at runtime. */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * A six-digit code as the list writes it (62.10.11), from what a person typed: 621011, 62.10.11, K62.10.11,
     * k 62 10 11. Null for anything else; whether the code exists is find()'s question.
     */
    public static function normalize(?string $input): ?string
    {
        $value = preg_replace('/\s+/u', '', strtoupper(trim((string) $input))) ?? '';
        $value = preg_replace('/^[A-Z]/', '', $value) ?? '';
        if (preg_match('/^(\d{2})\.?(\d{2})\.?(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return $m[1] . '.' . $m[2] . '.' . $m[3];
    }

    /** A six-digit code of the list with its title on $date, whether or not it is in force then. */
    public function find(string $code, string $date): ?KpdCode
    {
        $item = strlen($code) === 8 ? ($this->items()[$code] ?? null) : null;
        if ($item === null) {
            return null;
        }
        $item = is_string($item) ? ['t' => $item] : $item;
        $title = $item['t'];
        foreach ($item['titles'] ?? [] as $from => $later) {
            if ((string) $from <= $date) {
                $title = $later;
            }
        }

        return new KpdCode($code, $this->sections[substr($code, 0, 2)] ?? '', $title, $item['from'] ?? $this->validFrom, $item['to'] ?? null);
    }

    /** What $input is on $date: the code to send, or why it cannot go on an invoice of that day and what is near. */
    public function check(?string $input, string $date): KpdCheck
    {
        $typed = trim((string) $input);
        if ($typed === '') {
            return new KpdCheck('', KpdCheck::EMPTY, null, null, [], $date);
        }
        $code = self::normalize($typed);
        if ($code === null) {
            return new KpdCheck($typed, KpdCheck::FORMAT, null, null, [], $date);
        }
        $found = $this->find($code, $date);
        $status = match (true) {
            $found === null => KpdCheck::UNKNOWN,
            $found->to !== null && $found->to < $date => KpdCheck::ENDED,
            $found->from > $date => KpdCheck::NOT_YET,
            default => KpdCheck::OK,
        };

        return new KpdCheck($typed, $status, $code, $found, $status === KpdCheck::OK ? [] : $this->nearest($code, $date), $date);
    }

    /**
     * Six-digit codes in force on $date nearest to $code: those of the same category (53.20.0), then of the same
     * class (53.20), then of the same division (53); within each, the closest number first.
     *
     * @return KpdCode[]
     */
    public function nearest(string $code, string $date, int $limit = self::SUGGESTIONS): array
    {
        $digits = str_replace('.', '', (string) self::normalize($code));
        if ($digits === '') {
            return [];
        }
        $ranked = [];
        foreach (array_keys($this->items()) as $candidate) {
            $candidate = (string) $candidate;
            $other = str_replace('.', '', $candidate);
            if (strlen($candidate) !== 8 || $other === $digits) {
                continue;
            }
            $tier = match (true) {
                substr($other, 0, 5) === substr($digits, 0, 5) => 0,
                substr($other, 0, 4) === substr($digits, 0, 4) => 1,
                substr($other, 0, 2) === substr($digits, 0, 2) => 2,
                default => null,
            };
            $found = $tier !== null ? $this->find($candidate, $date) : null;
            if ($found !== null && $found->validOn($date)) {
                $ranked[] = [$tier, abs((int) $other - (int) $digits), $candidate, $found];
            }
        }
        sort($ranked);

        return array_map(static fn(array $r) => $r[3], array_slice($ranked, 0, $limit));
    }

    /** @return array<string, string|array{t: string, from?: string, to?: string, titles?: array<string, string>}> */
    private function items(): array
    {
        if ($this->items === null) {
            $raw = is_file($this->file) ? file_get_contents($this->file) : false;
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($data) || !is_array($data['items'] ?? null)) {
                throw new \RuntimeException("The KPD catalog {$this->file} is missing or unreadable.");
            }
            $this->sections = $data['sections'] ?? [];
            $this->validFrom = (string) ($data['header']['validFrom'] ?? $this->validFrom);
            $this->items = $data['items'];
        }

        return $this->items;
    }
}
