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
    public const PRESETS = ['today', 'yesterday', 'this_week', 'last_7_days', 'this_month', 'this_quarter', 'this_year', 'custom'];

    /** 2026-08-19 — the "Compare to" selector's modes, independent of the primary-period presets above. */
    public const COMPARE_MODES = ['off', 'previous', 'same_last_year', 'custom'];

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
            'this_quarter' => new Period($now->startOfQuarter(), $now->endOfQuarter(), 'This quarter', $preset),
            'this_year'   => new Period($now->startOfYear(), $now->endOfYear(), 'This year', $preset),
            'custom'      => $this->custom($start, $end),
            default       => throw new InvalidArgumentException("Unknown period preset: {$preset}"),
        };
    }

    /**
     * 2026-08-19 (Johan, period-comparison) — resolves the SECOND ("compare to")
     * period, independent of and orthogonal to the primary period preset above.
     * Returns null for 'off' (no comparison — the caller renders exactly as
     * before). 'previous' and 'same_last_year' derive from the ALREADY-RESOLVED
     * primary $period, which is how "this month vs last month"/"this quarter vs
     * last quarter"/"this year vs last year" all fall out of ONE mechanism
     * ('previous') rather than three separate hardcoded preset pairs — see
     * .ai/specs/at366-period-comparison.md §2.
     */
    public function resolveComparison(string $mode, Period $period, ?string $start = null, ?string $end = null): ?Period
    {
        return match ($mode) {
            'off'            => null,
            'previous'       => $period->previous(),
            'same_last_year' => $period->sameLastYear(),
            'custom'         => $this->custom($start, $end),
            default          => throw new InvalidArgumentException("Unknown comparison mode: {$mode}"),
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
