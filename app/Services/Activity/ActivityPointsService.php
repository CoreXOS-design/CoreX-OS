<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\DailyActivityEntry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONE statement of the daily-activity points rule, and the ONE place that
 * decides which stored rows count toward it
 * (agency-tracker-rehome.md §5.6, §14 steps 1 and 3).
 *
 *     points = count × the agency's weight for that activity
 *
 * `daily_activity_entries.value` is a COUNT of how many times the activity
 * happened. The weight (`activity_definitions.weight`) is the agency's points
 * per occurrence. Nothing else multiplies in. In particular an auto-credit
 * mapping's `value_per_event` is a count per event (normally 1), never a points
 * amount — storing points there is what made calendar credit score weight².
 *
 * WHAT COUNTS (written once, here):
 *   - the entry's `point_state` is confirmed or overridden, and
 *   - its `source` is manual, auto_calendar or auto_instant
 *     (DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES / _SOURCES), and
 *   - it belongs to the asker's agency (the raw query builder bypasses the
 *     BelongsToAgency scope, so the filter is explicit).
 *   There is NO filter on the activity definition (enabled / scope / branch):
 *   a credit that was legitimately earned stays earned when an agency later
 *   switches the activity off or hides it from the capture picker. Those flags
 *   decide what an agent may PICK to capture, not whether earned points count.
 *   Every screen, from the agent's own daily page to the company rollup, uses
 *   this rule, so the same agent and month cannot show two different totals.
 *
 * Callers add their own who/when filters (user, branch, period, date) to the
 * query `counted()` returns, then turn it into a number with `sum()` /
 * `sumByDate()`, or group it themselves with `sqlPoints()`.
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
        self::assertAliases($entryAlias, $definitionAlias);

        return "(GREATEST({$entryAlias}.`value`, 0) * {$definitionAlias}.`weight`)";
    }

    /**
     * Every entry that counts toward an achievement total, joined to its
     * definition so the weight is at hand. See the class note for what counts.
     *
     * `$agencyId` null means "no agency filter" — only for an owner-role user
     * with no agency switcher override, mirroring AgencyScope's read bypass.
     */
    public static function counted(?int $agencyId, string $entryAlias = 'e', string $definitionAlias = 'd'): Builder
    {
        self::assertAliases($entryAlias, $definitionAlias);

        return DB::table("daily_activity_entries as {$entryAlias}")
            ->join("activity_definitions as {$definitionAlias}", "{$definitionAlias}.id", '=', "{$entryAlias}.activity_definition_id")
            ->whereIn("{$entryAlias}.point_state", DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn("{$entryAlias}.source", DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->when($agencyId, fn (Builder $q) => $q->where("{$entryAlias}.agency_id", $agencyId));
    }

    /** Total points of whatever `counted()` query the caller narrowed down. */
    public static function sum(Builder $counted, string $entryAlias = 'e', string $definitionAlias = 'd'): float
    {
        return round((float) $counted->sum(DB::raw(self::sqlPoints($entryAlias, $definitionAlias))), 2);
    }

    /**
     * Points per calendar day (Y-m-d => points) of a `counted()` query the
     * caller narrowed down (user / branch / date range).
     *
     * @return array<string, float>
     */
    public static function sumByDate(Builder $counted, string $entryAlias = 'e', string $definitionAlias = 'd'): array
    {
        $pts = self::sqlPoints($entryAlias, $definitionAlias);

        return $counted
            ->groupBy("{$entryAlias}.activity_date")
            ->selectRaw("DATE({$entryAlias}.activity_date) as d, COALESCE(SUM({$pts}), 0) as pts")
            ->pluck('pts', 'd')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    /**
     * Counted entries per calendar day (Y-m-d => number of entry rows), for the
     * "activity was captured on this day" markers. Replaces the abandoned
     * `daily_activities` table, which holds no rows.
     *
     * @return array<string, int>
     */
    public static function entryRowsByDate(Builder $counted, string $entryAlias = 'e'): array
    {
        self::assertAliases($entryAlias);

        return $counted
            ->groupBy("{$entryAlias}.activity_date")
            ->selectRaw("DATE({$entryAlias}.activity_date) as d, COUNT(*) as n")
            ->pluck('n', 'd')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * A per-activity breakdown (name => count) of a `counted()` query, biggest
     * first — "today's breakdown" on the performance screens.
     *
     * @return array<string, int>
     */
    public static function countsByActivityName(Builder $counted, string $entryAlias = 'e', string $definitionAlias = 'd'): array
    {
        self::assertAliases($entryAlias, $definitionAlias);

        return $counted
            ->groupBy("{$definitionAlias}.id", "{$definitionAlias}.name")
            ->selectRaw("{$definitionAlias}.name as name, SUM(GREATEST({$entryAlias}.`value`, 0)) as n")
            ->havingRaw("SUM(GREATEST({$entryAlias}.`value`, 0)) > 0")
            ->orderByDesc('n')
            ->orderBy("{$definitionAlias}.name")
            ->pluck('n', 'name')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * The summary screens list the activities offered to the viewer (zero rows
     * included), so an empty period still shows the menu of activities. Credit
     * that was earned on an activity no longer offered (switched off, hidden)
     * must still appear, or the grand total would not match the other screens.
     * This appends those activities to the offered list.
     *
     * @param  Collection  $offered   activity_definitions rows (objects with id)
     * @param  Collection  $credited  aggregate rows keyed by activity_definition id
     * @param  string[]    $columns   activity_definitions columns to load for the extras
     */
    public static function withCreditedDefinitions(Collection $offered, Collection $credited, array $columns): Collection
    {
        $have = $offered->pluck('id')->map(fn ($v) => (int) $v)->all();
        $missing = $credited->keys()->map(fn ($v) => (int) $v)->diff($have)->values()->all();

        if ($missing === []) {
            return $offered;
        }

        $extra = DB::table('activity_definitions')
            ->whereIn('id', $missing)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get($columns);

        return $offered->concat($extra)->values();
    }

    /**
     * The activity a drill-down page is about: one the viewer is offered (`$offered` adds the
     * caller's own where-conditions on the alias `ad`), OR any activity that has counted credit in
     * the viewer's agency — so a row that appears on a summary because of earned credit always opens.
     * Null (the caller answers 404) when neither holds.
     */
    public static function drilldownDefinition(int $definitionId, ?int $agencyId, \Closure $offered): ?object
    {
        return DB::table('activity_definitions as ad')
            ->where('ad.id', $definitionId)
            ->where(function ($w) use ($offered, $agencyId) {
                $w->where(function ($o) use ($offered) {
                    $offered($o);
                })->orWhereExists(function ($x) use ($agencyId) {
                    $x->select(DB::raw(1))
                        ->from('daily_activity_entries as e')
                        ->whereColumn('e.activity_definition_id', 'ad.id')
                        ->whereIn('e.point_state', DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
                        ->whereIn('e.source', DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
                        ->when($agencyId, fn ($q) => $q->where('e.agency_id', $agencyId));
                });
            })
            ->first(['ad.id', 'ad.name', 'ad.weight']);
    }

    private static function assertAliases(string ...$aliases): void
    {
        foreach ($aliases as $alias) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
                throw new InvalidArgumentException("Not a plain SQL table alias: {$alias}");
            }
        }
    }
}
