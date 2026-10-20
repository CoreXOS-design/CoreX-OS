<?php

declare(strict_types=1);

namespace App\Services\Activity;

use InvalidArgumentException;

/**
 * The ONE statement of the daily-activity points rule
 * (agency-tracker-rehome.md §5.6, §14 step 1).
 *
 *     points = count × the agency's weight for that activity
 *
 * `daily_activity_entries.value` is a COUNT of how many times the activity
 * happened. The weight (`activity_definitions.weight`) is the agency's points
 * per occurrence. Nothing else multiplies in. In particular an auto-credit
 * mapping's `value_per_event` is a count per event (normally 1), never a points
 * amount — storing points there is what made calendar credit score weight².
 *
 * SKELETON: this class only states the rule. It reads no table and changes no
 * stored number. The nine places that currently re-implement `value × weight`
 * move onto it in step 3 of the re-home; until then they are unchanged.
 */
final class ActivityPointsService
{
    /**
     * Points for `$count` occurrences of an activity worth `$weight` each.
     *
     * Absorbs, never throws, on odd stored data: a negative count is treated as
     * zero (a reversed credit is expressed through point_state, not a negative
     * value), and the result is rounded to the 2 decimals of the weight column.
     */
    public static function pointsFor(int $count, int|float|string $weight): float
    {
        if (! is_numeric($weight)) {
            return 0.0;
        }

        return round(max(0, $count) * (float) $weight, 2);
    }

    /**
     * The same rule as a SQL fragment for aggregate queries, e.g.
     * `SUM(` . ActivityPointsService::sqlPoints('e', 'd') . `)`.
     *
     * Aliases are the table aliases of daily_activity_entries and
     * activity_definitions in the caller's query. They are interpolated into
     * SQL, so anything that is not a plain identifier is refused.
     */
    public static function sqlPoints(string $entryAlias, string $definitionAlias): string
    {
        foreach ([$entryAlias, $definitionAlias] as $alias) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
                throw new InvalidArgumentException("Not a plain SQL table alias: {$alias}");
            }
        }

        return "(GREATEST({$entryAlias}.`value`, 0) * {$definitionAlias}.`weight`)";
    }
}
