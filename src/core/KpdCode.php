<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

/**
 * One six-digit KPD 2025 code with its title as on the day it was looked up for, and the days it is in force.
 */
final readonly class KpdCode
{
    public function __construct(
        /** Dotted, as the list writes it: 53.20.09. */
        public string $code,
        /** The section letter: H for 53.20.09. */
        public string $section,
        public string $title,
        /** First day in force (Y-m-d). */
        public string $from,
        /** Last day in force (Y-m-d), null while no amendment ends it. */
        public ?string $to,
    ) {
    }

    /** The form e-računi takes: section letter and dotted code, H53.20.09. */
    public function eracuni(): string
    {
        return $this->section . $this->code;
    }

    public function validOn(string $date): bool
    {
        return $this->from <= $date && ($this->to === null || $date <= $this->to);
    }
}
