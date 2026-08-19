<?php

namespace App\Services\Performance;

/**
 * 2026-08-19 (Johan, period-comparison) — the ONE place delta/percent/
 * direction-of-good math happens. Every comparison figure on the Agency
 * Performance report goes through this, server-side, so the view/Alpine
 * layer never computes a percentage or picks a colour from a raw number —
 * it only reads the fields below and renders.
 *
 * See .ai/specs/at366-period-comparison.md §3-4 for the direction-of-good
 * and edge-case rules this implements.
 */
class PeriodComparison
{
    public const DIRECTIONS = ['higher_is_better', 'lower_is_better', 'neutral'];

    /**
     * @return array{
     *   value: float, previous: float, delta: float, delta_pct: ?float,
     *   direction: string, good: ?bool
     * }
     *
     * delta_pct is null whenever $previous is zero — including when $current
     * is also zero — so a comparison-is-zero case is NEVER rendered as
     * "∞"/"100%"; the view distinguishes "both zero" (value==0 && previous==0
     * → render "—") from "previous zero only" (→ render the absolute delta,
     * no percentage) purely from these three fields, no extra flag needed.
     *
     * good is null for a zero delta or a 'neutral' direction (e.g. Pending
     * deals — not intrinsically good or bad going up).
     */
    public static function compute(float $current, float $previous, string $direction = 'higher_is_better'): array
    {
        $delta    = $current - $previous;
        $deltaPct = $previous != 0.0 ? round(($delta / abs($previous)) * 100, 1) : null;

        $good = match (true) {
            $delta === 0.0                        => null,
            $direction === 'higher_is_better'      => $delta > 0,
            $direction === 'lower_is_better'       => $delta < 0,
            default                                => null, // 'neutral'
        };

        return [
            'value'     => $current,
            'previous'  => $previous,
            'delta'     => $delta,
            'delta_pct' => $deltaPct,
            'direction' => $direction,
            'good'      => $good,
        ];
    }
}
