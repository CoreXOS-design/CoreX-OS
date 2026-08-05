<?php

namespace App\Services\Performance;

use Carbon\CarbonImmutable;

/**
 * AT-366 — an immutable reporting window [start, end] with a label, and the
 * comparable preceding window of equal length (for trend arrows).
 */
class Period
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $label,
        public readonly string $preset,
    ) {}

    /** The equal-length window immediately before this one. */
    public function previous(): self
    {
        $seconds  = $this->start->diffInSeconds($this->end);
        $prevEnd  = $this->start->subSecond();
        $prevStart = $prevEnd->subSeconds($seconds);

        return new self($prevStart, $prevEnd, 'Previous: ' . $this->label, $this->preset);
    }

    public function toArray(): array
    {
        return [
            'start'  => $this->start->toDateTimeString(),
            'end'    => $this->end->toDateTimeString(),
            'label'  => $this->label,
            'preset' => $this->preset,
        ];
    }
}
