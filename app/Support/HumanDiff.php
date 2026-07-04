<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * ONE humaniser for day-count rendering across tile feeds, to-do titles and
 * notification text.
 *
 * Why this exists: Carbon 3's `diffInDays()` returns a SIGNED FLOAT by default, so
 * `now()->diffInDays($past)` is e.g. `-0.6055…`. Rendered raw that produced the
 * "no activity in -0.605399…days" to-do tile; cast with `(int)` it truncates toward
 * zero and can go negative, silently corrupting "days overdue / on market / waiting"
 * counters AND threshold comparisons (`$daysSince > 30` is never true for a negative
 * float, so a genuinely idle property was mis-scored). Every elapsed/remaining
 * day-count in user-facing content routes through here: never negative, never
 * fractional, always the whole number of CALENDAR days a human would count.
 */
class HumanDiff
{
    /**
     * Absolute whole CALENDAR days between two moments (order-independent).
     * Never negative, never fractional. Both moments are floored to the start of
     * their day so "23:00 yesterday → 01:00 today" counts as 1 day and any two
     * times on the same date count as 0 — matching how a person counts days, and
     * stable across DST.
     *
     * @param CarbonInterface|string|null $from
     * @param CarbonInterface|string|null $to  defaults to now()
     */
    public static function daysBetween($from, $to = null): int
    {
        if (!$from) {
            return 0;
        }
        $from = self::toCarbon($from);
        $to   = $to !== null ? self::toCarbon($to) : Carbon::now();

        return (int) abs(
            $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay(), false)
        );
    }

    /**
     * Humanised elapsed/remaining magnitude for display: "today", "1 day", "13 days".
     *
     * @param CarbonInterface|string|null $from
     * @param CarbonInterface|string|null $to  defaults to now()
     */
    public static function days($from, $to = null): string
    {
        $d = self::daysBetween($from, $to);
        if ($d === 0) {
            return 'today';
        }
        return $d === 1 ? '1 day' : "{$d} days";
    }

    private static function toCarbon($value): CarbonInterface
    {
        return $value instanceof CarbonInterface ? $value : Carbon::parse($value);
    }
}
