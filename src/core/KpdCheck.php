<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

/**
 * What KpdCatalog::check() found for a typed or stored code on one day. Only OK may go on an invoice of that day.
 */
final readonly class KpdCheck
{
    public const OK = 'ok';
    /** Nothing was given. */
    public const EMPTY = 'empty';
    /** Not six digits (62.10.11, 621011 or K62.10.11). */
    public const FORMAT = 'format';
    /** Six digits, but no such code in KPD 2025. */
    public const UNKNOWN = 'unknown';
    /** A KPD 2025 code that an amendment ended before the day. */
    public const ENDED = 'ended';
    /** A KPD 2025 code that an amendment starts after the day. */
    public const NOT_YET = 'notYet';

    /**
     * @param KpdCode[] $suggestions the nearest codes in force on $date, for every status but OK and EMPTY
     */
    public function __construct(
        public string $input,
        public string $status,
        /** The canonical code (53.20.09), or null when the input is empty or not six digits. */
        public ?string $code,
        public ?KpdCode $kpd,
        public array $suggestions,
        public string $date,
    ) {
    }

    public function isValid(): bool
    {
        return $this->status === self::OK;
    }

    /** The form e-računi takes (H53.20.09), when the code may go on an invoice of the day. */
    public function eracuni(): ?string
    {
        return $this->isValid() && $this->kpd !== null ? $this->kpd->eracuni() : null;
    }

    /** "53.20.01 (Multimodalne kurirske usluge), …", or '' when there is nothing near. */
    public function suggestionList(): string
    {
        return implode(', ', array_map(static fn(KpdCode $c) => $c->code . ' (' . $c->title . ')', $this->suggestions));
    }

    /** Why the code cannot go on an invoice of the day, in English, for warnings and errors; '' when it can. */
    public function message(): string
    {
        $problem = match ($this->status) {
            self::OK => '',
            self::EMPTY => 'No KPD code.',
            self::FORMAT => "KPD {$this->input} is not a KPD code: it has six digits, such as 62.10.11.",
            self::UNKNOWN => "KPD {$this->code} is not a KPD 2025 code.",
            self::ENDED => "KPD {$this->code} was valid until {$this->kpd?->to}.",
            self::NOT_YET => "KPD {$this->code} is valid from {$this->kpd?->from}.",
            default => '',
        };
        $near = $this->suggestionList();

        return $problem !== '' && $near !== '' ? "{$problem} Nearest valid codes: {$near}." : $problem;
    }
}
