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
 * WHERE THE ROWS ARE (Road to Sold v2 phase 3, road-to-sold.md section 7): an agency that has been cut over (an rts_cutovers row; the
 * daily-activity history was moved into the task completions and everything the old detectors credit afterwards is mirrored in by
 * LegacyEntryBridge) is read from `rts_task_completions`; every other agency is read from `daily_activity_entries` exactly as before.
 * Both are handed back as the SAME shape - alias `e` carries user_id, branch_id, period, activity_date and the points, alias `d` the
 * activity or task name - so a caller does not know which one it got. A completion's points are its own snapshot (points_awarded); the
 * old rows' are count x weight. The manual / automatic split is preserved: a completion is manual when it was captured (or copied from
 * a manual entry) and automatic otherwise.
 *
 * Callers add their own who/when filters (user, branch, period, date) to the
 * query `counted()` returns, then turn it into a number with `sum()` /
 * `sumByDate()`, or group it themselves with `sqlPoints()`.
 */
final class ActivityPointsService
{
    /** @var array<int,bool> agency id => has been cut over */
    private static array $cut = [];
    private static ?array $cutIds = null;

    /** Forget what was learned about cut-overs (after one is made, and between tests). */
    public static function forgetCutovers(): void
    {
        self::$cut = [];
        self::$cutIds = null;
    }

    /** @return int[] every agency that has been cut over */
    private static function cutIds(): array
    {
        return self::$cutIds ??= DB::table('rts_cutovers')->distinct()->pluck('agency_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Are this agency's points read from the task completions? Yes once it has been cut over. For no agency at all (a platform owner
     * with no agency chosen) yes only when EVERY agency that has daily-activity entries has been cut over - otherwise the old entries,
     * which are still written for everyone, are the complete record.
     */
    public static function usesCompletions(?int $agencyId): bool
    {
        if ($agencyId) {
            return self::$cut[$agencyId] ??= in_array($agencyId, self::cutIds(), true);
        }
        $cut = self::cutIds();

        return $cut !== [] && ! DB::table('daily_activity_entries')->whereNotIn('agency_id', $cut)->exists();
    }

    /** Is this a query counted() built over the completions? */
    private static function overCompletions(Builder $counted): bool
    {
        return str_starts_with((string) $counted->from, 'rts_task_completions');
    }

    /** SQL: this completion is MANUAL (captured by the agent, or copied from a manual entry); everything else is automatic. */
    public static function manualCompletionSql(string $alias = 'e'): string
    {
        self::assertAliases($alias);

        return "({$alias}.source IN ('captured','manual') OR COALESCE({$alias}.legacy_source, '') = 'manual')";
    }

    /** The points of ONE row of whichever source `$counted` reads. */
    public static function pointsExpr(Builder $counted, string $entryAlias = 'e', string $definitionAlias = 'd'): string
    {
        return self::overCompletions($counted) ? self::completionPoints($entryAlias) : self::sqlPoints($entryAlias, $definitionAlias);
    }

    /** @return array{0: string, 1: string} manual-only and automatic-only points SQL for whichever source `$counted` reads */
    public static function splitExprs(Builder $counted, string $entryAlias = 'e', string $definitionAlias = 'd'): array
    {
        if (! self::overCompletions($counted)) {
            return self::splitExpressions($entryAlias, $definitionAlias);
        }
        $pts = self::completionPoints($entryAlias);
        $man = self::manualCompletionSql($entryAlias);

        return ["(CASE WHEN {$man} THEN {$pts} ELSE 0 END)", "(CASE WHEN {$man} THEN 0 ELSE {$pts} END)"];
    }

    private static function completionPoints(string $entryAlias): string
    {
        self::assertAliases($entryAlias);

        return "{$entryAlias}.`points_awarded`";
    }

    /** SQL: how many times, per row of whichever source `$counted` reads. */
    private static function countExpr(Builder $counted, string $entryAlias): string
    {
        return self::overCompletions($counted) ? "{$entryAlias}.`quantity`" : "GREATEST({$entryAlias}.`value`, 0)";
    }

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
        return self::usesCompletions($agencyId)
            ? self::countedCompletions($agencyId, $entryAlias, $definitionAlias)
            : self::countedLegacy($agencyId, $entryAlias, $definitionAlias);
    }

    /** The old engine's rows (what counted() returns for an agency that has not been cut over). */
    public static function countedLegacy(?int $agencyId, string $entryAlias = 'e', string $definitionAlias = 'd'): Builder
    {
        self::assertAliases($entryAlias, $definitionAlias);

        return DB::table("daily_activity_entries as {$entryAlias}")
            ->join("activity_definitions as {$definitionAlias}", "{$definitionAlias}.id", '=', "{$entryAlias}.activity_definition_id")
            ->whereIn("{$entryAlias}.point_state", DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn("{$entryAlias}.source", DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->when($agencyId, fn (Builder $q) => $q->where("{$entryAlias}.agency_id", $agencyId));
    }

    /**
     * The same rule over the task completions: confirmed or overridden, not archived, and never a row copied from an `auto_other` entry
     * (the old rule's own exclusion). Joined to its task so the name is at hand; the points are the completion's snapshot.
     */
    public static function countedCompletions(?int $agencyId, string $entryAlias = 'e', string $definitionAlias = 'd'): Builder
    {
        self::assertAliases($entryAlias, $definitionAlias);

        return DB::table("rts_task_completions as {$entryAlias}")
            ->join("rts_tasks as {$definitionAlias}", "{$definitionAlias}.id", '=', "{$entryAlias}.task_id")
            ->whereNull("{$entryAlias}.deleted_at")
            ->whereIn("{$entryAlias}.state", DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->where(fn (Builder $w) => $w->whereNull("{$entryAlias}.legacy_source")->orWhere("{$entryAlias}.legacy_source", '<>', DailyActivityEntry::SOURCE_AUTO_OTHER))
            ->when($agencyId, fn (Builder $q) => $q->where("{$entryAlias}.agency_id", $agencyId));
    }

    /** Total points of whatever `counted()` query the caller narrowed down. */
    public static function sum(Builder $counted, string $entryAlias = 'e', string $definitionAlias = 'd'): float
    {
        return round((float) $counted->sum(DB::raw(self::pointsExpr($counted, $entryAlias, $definitionAlias))), 2);
    }

    /**
     * Points per calendar day (Y-m-d => points) of a `counted()` query the
     * caller narrowed down (user / branch / date range).
     *
     * @return array<string, float>
     */
    public static function sumByDate(Builder $counted, string $entryAlias = 'e', string $definitionAlias = 'd'): array
    {
        $pts = self::pointsExpr($counted, $entryAlias, $definitionAlias);

        return $counted
            ->groupBy("{$entryAlias}.activity_date")
            ->selectRaw("DATE({$entryAlias}.activity_date) as d, COALESCE(SUM({$pts}), 0) as pts")
            ->pluck('pts', 'd')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    /**
     * Points split into what the agent captured (manual) and what CoreX credited (automatic), plus the combined total -
     * from the SAME rows and the SAME rule as sum(): manual + automatic always equals sum(). Not a second calculation.
     *
     * @return array{manual: float, automatic: float, total: float}
     */
    public static function sumSplit(Builder $counted, string $entryAlias = 'e', string $definitionAlias = 'd'): array
    {
        [$man, $auto] = self::splitExprs($counted, $entryAlias, $definitionAlias);
        $row = $counted->selectRaw("COALESCE(SUM({$man}), 0) as manual_pts, COALESCE(SUM({$auto}), 0) as auto_pts")->first();

        return self::splitPair((float) ($row->manual_pts ?? 0), (float) ($row->auto_pts ?? 0));
    }

    /**
     * Points per calendar day, split: Y-m-d => {manual, automatic, total}.
     *
     * @return array<string, array{manual: float, automatic: float, total: float}>
     */
    public static function sumByDateSplit(Builder $counted, string $entryAlias = 'e', string $definitionAlias = 'd'): array
    {
        [$man, $auto] = self::splitExprs($counted, $entryAlias, $definitionAlias);
        $out = [];
        $counted->groupBy("{$entryAlias}.activity_date")
            ->selectRaw("DATE({$entryAlias}.activity_date) as d, COALESCE(SUM({$man}), 0) as manual_pts, COALESCE(SUM({$auto}), 0) as auto_pts")
            ->get()
            ->each(function ($r) use (&$out) {
                $out[(string) $r->d] = self::splitPair((float) $r->manual_pts, (float) $r->auto_pts);
            });

        return $out;
    }

    /**
     * Counted entry rows per calendar day, split manual / automatic: Y-m-d => {manual, automatic} (calendar markers).
     *
     * @return array<string, array{manual: int, automatic: int}>
     */
    public static function entryRowsByDateSplit(Builder $counted, string $entryAlias = 'e'): array
    {
        self::assertAliases($entryAlias);
        if (self::overCompletions($counted)) {
            $isManual = self::manualCompletionSql($entryAlias);
            $manRows = "SUM({$isManual})";
            $autoRows = "SUM(NOT {$isManual})";
        } else {
            $man = self::sourceList(DailyActivityEntry::MANUAL_SOURCES);
            $auto = self::sourceList(DailyActivityEntry::AUTOMATIC_SOURCES);
            $manRows = "SUM({$entryAlias}.source IN ({$man}))";
            $autoRows = "SUM({$entryAlias}.source IN ({$auto}))";
        }
        $out = [];
        $counted->groupBy("{$entryAlias}.activity_date")
            ->selectRaw("DATE({$entryAlias}.activity_date) as d, {$manRows} as manual_rows, {$autoRows} as auto_rows")
            ->get()
            ->each(function ($r) use (&$out) {
                $out[(string) $r->d] = ['manual' => (int) $r->manual_rows, 'automatic' => (int) $r->auto_rows];
            });

        return $out;
    }

    /** A per-activity count pair (manual count, automatic count) as points at the activity's weight, through the one rule. */
    public static function splitPointsFor(int $manualCount, int $automaticCount, int|float|string $weight): array
    {
        return self::splitPair(self::pointsFor($manualCount, $weight), self::pointsFor($automaticCount, $weight));
    }

    /** @return array{manual: float, automatic: float, total: float} */
    private static function splitPair(float $manual, float $automatic): array
    {
        $manual = round($manual, 2);
        $automatic = round($automatic, 2);

        return ['manual' => $manual, 'automatic' => $automatic, 'total' => round($manual + $automatic, 2)];
    }

    /** @return array{0: string, 1: string} SQL points for manual rows only, and for automatic rows only (for callers that group themselves) */
    public static function splitExpressions(string $entryAlias, string $definitionAlias): array
    {
        $pts = self::sqlPoints($entryAlias, $definitionAlias);
        $man = self::sourceList(DailyActivityEntry::MANUAL_SOURCES);
        $auto = self::sourceList(DailyActivityEntry::AUTOMATIC_SOURCES);

        return ["(CASE WHEN {$entryAlias}.source IN ({$man}) THEN {$pts} ELSE 0 END)", "(CASE WHEN {$entryAlias}.source IN ({$auto}) THEN {$pts} ELSE 0 END)"];
    }

    /** @param string[] $sources constants of the model, never user input */
    private static function sourceList(array $sources): string
    {
        return implode(',', array_map(fn ($s) => "'" . str_replace("'", '', $s) . "'", $sources));
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

        $count = self::countExpr($counted, $entryAlias);

        return $counted
            ->groupBy("{$definitionAlias}.id", self::overCompletions($counted) ? "{$definitionAlias}.label" : "{$definitionAlias}.name")
            ->selectRaw((self::overCompletions($counted) ? "{$definitionAlias}.label" : "{$definitionAlias}.name") . " as name, SUM({$count}) as n")
            ->havingRaw("SUM({$count}) > 0")
            ->orderByDesc('n')
            ->orderBy(self::overCompletions($counted) ? "{$definitionAlias}.label" : "{$definitionAlias}.name")
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
