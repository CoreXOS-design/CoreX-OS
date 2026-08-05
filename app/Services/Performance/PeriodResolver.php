<?php

namespace App\Services\Performance;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * AT-366 — maps a UI preset (or an arbitrary custom range) to a concrete Period.
 * Supports day / week / month / year / last-7 / custom [start,end].
 */
class PeriodResolver
{
    public const PRESETS = ['today', 'yesterday', 'this_week', 'last_7_days', 'this_month', 'this_year', 'custom'];

    public function resolve(string $preset, ?string $start = null, ?string $end = null, ?CarbonImmutable $now = null): Period
    {
        $now = $now ?? CarbonImmutable::now();

        return match ($preset) {
            'today'       => new Period($now->startOfDay(), $now->endOfDay(), 'Today', $preset),
            'yesterday'   => (function () use ($now, $preset) {
                $y = $now->subDay();
                return new Period($y->startOfDay(), $y->endOfDay(), 'Yesterday', $preset);
            })(),
            'this_week'   => new Period($now->startOfWeek(), $now->endOfWeek(), 'This week', $preset),
            'last_7_days' => new Period($now->subDays(6)->startOfDay(), $now->endOfDay(), 'Last 7 days', $preset),
            'this_month'  => new Period($now->startOfMonth(), $now->endOfMonth(), 'This month', $preset),
            'this_year'   => new Period($now->startOfYear(), $now->endOfYear(), 'This year', $preset),
            'custom'      => $this->custom($start, $end),
            default       => throw new InvalidArgumentException("Unknown period preset: {$preset}"),
        };
    }

    private function custom(?string $start, ?string $end): Period
    {
        if (!$start || !$end) {
            throw new InvalidArgumentException('A custom period requires both a start and an end date.');
        }
        $s = CarbonImmutable::parse($start)->startOfDay();
        $e = CarbonImmutable::parse($end)->endOfDay();
        if ($e->lt($s)) {
            throw new InvalidArgumentException('The custom period end must be on or after the start.');
        }

        return new Period($s, $e, $s->toDateString() . ' → ' . $e->toDateString(), 'custom');
    }
}
