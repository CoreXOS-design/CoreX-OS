<?php

namespace App\Services\RoadToSold;

use App\Models\DailyActivityEntry;
use App\Services\Activity\ActivityPointsService;
use App\Models\RoadToSold\RtsCutover;
use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\Scopes\AgencyScope;
use Illuminate\Support\Facades\DB;

/**
 * AT-454 v2 phase 3 - move an agency's daily-activity setup and history into the Road to Sold task recipe
 * (road-to-sold.md section 7, 14 phase 3). Nothing is dropped, rewritten or deleted: the old tables stay exactly as they are.
 *
 *   plan()   read only: what would move, with the counts and points before and after
 *   apply()  one transaction per agency, safe to run again (every row has a fixed key, so a second run adds nothing):
 *              1. the recipe's steps and levels (no neutral default tasks - they would credit the same events a second time)
 *              2. one task per activity the agency actually uses (in-use definitions, and any with an active automatic rule):
 *                 a captured task for what agents record, a legacy-fed core task (no event bound) for what CoreX credits;
 *                 each in Level 1, target 0 (the old system had none), points = the activity's weight
 *              3. every entry becomes one completion: same date, user, branch, subject and state, source `migrated`, the old source kept
 *                 in legacy_source (so manual vs automatic stays exact), points_legacy = points_awarded = value x weight at this moment
 *                 (section 7.4: past months keep their totals exactly)
 *              4. the records each completion is about
 *              5. rts_cutovers: when, and what moved. From then the agency's points are read from the completions.
 *            Refuses (and rolls back) unless the entry count and the counted points match to the cent before and after.
 *
 * Whatever the old detectors credit afterwards is mirrored into the completions by LegacyEntryBridge, so no credit is lost.
 */
class DailyActivityMigrationService
{
    /** Where a task is placed. Generic words only - an agency moves any task to another step in the Recipe editor. */
    private const AUTO_STEP = [
        'property.captured' => 'find', 'contact.captured' => 'find_owner', 'outreach.' => 'contact_outcome', 'mic.' => 'find',
        'tracked_property.' => 'mandate', 'presentation.' => 'presentation', 'fica.' => 'mandate', 'property.published' => 'listing_live',
        'property.compliance' => 'listing_live', 'marketing.' => 'marketing', 'mandate.' => 'mandate', 'deal.' => 'deal', 'viewing' => 'viewings',
    ];

    private const NAME_STEP = [
        'present' => 'presentation', 'mandate' => 'mandate', 'otp' => 'otp', 'buyer' => 'buyers', 'viewing' => 'viewings',
        'appoint' => 'evaluation_booked', 'evaluat' => 'evaluation_booked', 'prospect' => 'contact_outcome', 'canvass' => 'find',
        'drive' => 'find', 'door' => 'find', 'card' => 'find', 'list' => 'listing_live', 'photo' => 'marketing', 'board' => 'marketing',
        'facebook' => 'marketing', 'advert' => 'marketing', 'virtual' => 'marketing', 'article' => 'marketing', 'blog' => 'marketing',
        'attorney' => 'deal', 'bond' => 'deal', 'contract' => 'deal', 'renewal' => 'deal',
    ];

    public function __construct(private readonly RecipeService $recipe)
    {
    }

    /**
     * Agencies that have daily-activity entries. An agency with only automatic credit rules and no history is NOT moved (spec 7.3: it gets
     * the neutral default recipe, and starting its recipe here would take that offer away).
     *
     * @return int[]
     */
    public function agenciesInUse(): array
    {
        return DB::table('daily_activity_entries')->whereNotNull('agency_id')->distinct()->orderBy('agency_id')
            ->pluck('agency_id')->map(fn ($v) => (int) $v)->all();
    }

    public function isCutOver(int $agencyId, string $track = 'sales'): bool
    {
        return RtsCutover::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('track', $track)->exists();
    }

    /**
     * Read only. @return array<string,mixed>
     */
    public function plan(int $agencyId, string $track = 'sales'): array
    {
        $definitions = $this->definitionsInUse($agencyId);
        $existing = RtsTask::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
            ->where('agency_id', $agencyId)->where('track', $track)->whereNotNull('legacy_activity_definition_id')->pluck('id', 'legacy_activity_definition_id');
        $mirrored = DB::table('rts_task_completions')->where('agency_id', $agencyId)->whereNotNull('legacy_entry_id')->count();

        $entries = DB::table('daily_activity_entries')->where('agency_id', $agencyId)->count();
        $before = $this->countedPoints($agencyId);

        return [
            'agency_id'         => $agencyId,
            'track'             => $track,
            'already_cut_over'  => $this->isCutOver($agencyId, $track),
            'definitions'       => $definitions->count(),
            'tasks_to_create'   => $definitions->filter(fn ($d) => ! isset($existing[$d->id]))->count(),
            'captured_tasks'    => $definitions->filter(fn ($d) => $this->isCaptured($d))->count(),
            'legacy_fed_tasks'  => $definitions->reject(fn ($d) => $this->isCaptured($d))->count(),
            'entries'           => $entries,
            'entries_already_copied' => $mirrored,
            'entries_by_state_source' => DB::table('daily_activity_entries')->where('agency_id', $agencyId)
                ->selectRaw('point_state, source, COUNT(*) as n')->groupBy('point_state', 'source')->orderBy('point_state')->orderBy('source')
                ->get()->map(fn ($r) => ['point_state' => $r->point_state, 'source' => $r->source, 'n' => (int) $r->n])->all(),
            'points_before'     => $before,
            'corrected_rule'    => $this->correctedPoints($agencyId),
            'road_to_sold_on'   => $this->featureOn($agencyId),
            'roles_cut_off'     => $this->rolesWithOldAccessOnly($agencyId),
        ];
    }

    /** Is Road to Sold switched on for the agency? The old screens redirect there, so a cut-over agency must have it. */
    private function featureOn(int $agencyId): bool
    {
        $agency = \App\Models\Agency::query()->withoutGlobalScopes()->find($agencyId);

        return $agency !== null && app(\App\Services\Features\AgencyFeatureService::class)->enabled('road-to-sold', $agency);
    }

    /**
     * Roles that can open the old daily-activity screens but not Road to Sold - after the move they would be redirected to a page that
     * refuses them. For the agency (its own grants, or the platform default when it has none). Reported so a grant can be added first.
     *
     * @return string[]
     */
    private function rolesWithOldAccessOnly(int $agencyId): array
    {
        $roles = fn (string $key) => DB::table('role_permissions')->where('permission_key', $key)->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereNull('agency_id'))->pluck('role')->unique();

        return $roles('access_daily_activity')->diff($roles('access_road_to_sold'))->values()->all();
    }

    /**
     * Move the agency. Idempotent. Throws (and rolls back) when the move does not reconcile.
     *
     * @return array<string,mixed> the report, also stored on the rts_cutovers row
     */
    public function apply(int $agencyId, string $track = 'sales'): array
    {
        if (! $this->featureOn($agencyId)) {
            throw new \RuntimeException("Switch Road to Sold on for agency {$agencyId} first: the old daily-activity screens will send people there.");
        }

        return DB::transaction(function () use ($agencyId, $track) {
            $before = $this->countedPoints($agencyId);
            $entries = DB::table('daily_activity_entries')->where('agency_id', $agencyId)->count();

            $this->recipe->start($agencyId, $track, withDefaultTasks: false);
            $level = RtsLevel::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('track', $track)->where('number', 1)->firstOrFail();
            $tasks = $this->ensureTasks($agencyId, $track, (int) $level->id);
            $copied = $this->copyEntries($agencyId, $track, (int) $level->id);
            $this->copyRecords($agencyId);

            $completions = DB::table('rts_task_completions')->where('agency_id', $agencyId)->whereNotNull('legacy_entry_id')->count();
            $after = $this->completionPoints($agencyId);
            if ($completions !== $entries) {
                throw new \RuntimeException("Daily activity migration refused: {$entries} entries but {$completions} completions for agency {$agencyId}.");
            }
            if (abs($after['total'] - $before['total']) > 0.004 || abs($after['manual'] - $before['manual']) > 0.004 || abs($after['automatic'] - $before['automatic']) > 0.004) {
                throw new \RuntimeException("Daily activity migration refused: counted points differ (before {$before['total']}, after {$after['total']}) for agency {$agencyId}.");
            }

            $report = [
                'entries' => $entries, 'completions' => $completions, 'copied_now' => $copied, 'tasks' => $tasks,
                'points_before' => $before, 'points_after' => $after, 'corrected_rule' => $this->correctedPoints($agencyId),
            ];
            RtsCutover::query()->withoutGlobalScope(AgencyScope::class)->firstOrCreate(
                ['agency_id' => $agencyId, 'track' => $track],
                ['cut_over_at' => now(), 'report' => $report]
            );
            ActivityPointsService::forgetCutovers();

            return $report;
        });
    }

    /**
     * Per agent and month: what the screens show, and what history would be under the corrected rule (one event x the points, no
     * weight squared). For Johan's information only - it changes no number anywhere (section 7.4).
     *
     * @return array<int,array{user_id:int,period:string,shown:float,corrected:float}>
     */
    public function beforeAfterByAgentMonth(int $agencyId): array
    {
        $rows = DB::table('daily_activity_entries as e')->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.agency_id', $agencyId)
            ->whereIn('e.point_state', DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)->whereIn('e.source', DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->selectRaw('e.user_id, e.period, '
                . 'SUM(GREATEST(e.value,0) * d.weight) as shown, '
                . "SUM(CASE WHEN e.source = 'auto_calendar' AND e.point_state = 'confirmed' AND e.value > 1 AND e.value = d.weight THEN d.weight ELSE GREATEST(e.value,0) * d.weight END) as corrected")
            ->groupBy('e.user_id', 'e.period')->orderBy('e.period')->orderBy('e.user_id')->get();

        return $rows->filter(fn ($r) => abs((float) $r->shown - (float) $r->corrected) > 0.004)
            ->map(fn ($r) => ['user_id' => (int) $r->user_id, 'period' => (string) $r->period, 'shown' => round((float) $r->shown, 2), 'corrected' => round((float) $r->corrected, 2)])
            ->values()->all();
    }

    // ── internals ─────────────────────────────────────────────────────────

    /** Definitions this agency actually uses: any with an entry of the agency's, and any with an active automatic rule of the agency's. */
    private function definitionsInUse(int $agencyId)
    {
        $ids = DB::table('daily_activity_entries')->where('agency_id', $agencyId)->distinct()->pluck('activity_definition_id')
            ->merge(DB::table('activity_definition_calendar_classes')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('is_active', 1)->pluck('activity_definition_id'))
            ->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $bySource = DB::table('daily_activity_entries')->where('agency_id', $agencyId)->whereIn('activity_definition_id', $ids)
            ->selectRaw('activity_definition_id, SUM(source = ?) as manual_n', [DailyActivityEntry::SOURCE_MANUAL])->groupBy('activity_definition_id')->pluck('manual_n', 'activity_definition_id');

        return DB::table('activity_definitions')->whereIn('id', $ids)->orderBy('sort_order')->orderBy('id')->get()
            ->each(fn ($d) => $d->manual_entries = (int) ($bySource[$d->id] ?? 0));
    }

    /** A captured task: agents record it by hand (it has manual entries, or is not one of CoreX's own "[Auto]" credits). */
    private function isCaptured(object $d): bool
    {
        return ((int) ($d->manual_entries ?? 0)) > 0 || ! str_starts_with((string) $d->name, '[Auto] ');
    }

    /** @return array{created:int,captured:int,legacy_fed:int} */
    private function ensureTasks(int $agencyId, string $track, int $levelId): array
    {
        $steps = RtsStep::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('track', $track)->pluck('id', 'key');
        $made = ['created' => 0, 'captured' => 0, 'legacy_fed' => 0];

        foreach ($this->definitionsInUse($agencyId) as $d) {
            [$task, $created] = $this->ensureTask($d, $agencyId, $track, $levelId, $steps);
            if ($created) {
                $made['created']++;
                $made[$this->isCaptured($d) ? 'captured' : 'legacy_fed']++;
            }
        }

        return $made;
    }

    /**
     * The task standing for one activity (created, with its Level 1 line, only when missing). Also called by LegacyEntryBridge when an
     * activity first earns credit after the agency was cut over, so that credit is never dropped.
     *
     * @return array{0:RtsTask,1:bool} the task, and whether it was just created
     */
    private function ensureTask(object $d, int $agencyId, string $track, int $levelId, $steps): array
    {
        $created = false;
        $task = RtsTask::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
            ->where('agency_id', $agencyId)->where('track', $track)->where('legacy_activity_definition_id', $d->id)->first();
        if (! $task) {
            $captured = $this->isCaptured($d);
            $task = RtsTask::create([
                'agency_id' => $agencyId, 'track' => $track, 'step_id' => $steps[$this->stepFor($d)] ?? $steps['find'],
                'key' => 'legacy-' . $d->id, 'label' => $this->labelFor($d), 'type' => $captured ? RtsTask::TYPE_CUSTOM : RtsTask::TYPE_CORE,
                'event_key' => null, 'event_filter' => null, 'recurrence_unit' => 'day', 'capture_kind' => 'none', 'evidence_required' => 'none',
                'is_system' => false, 'is_active' => (bool) $d->is_enabled, 'sort_order' => (int) $d->sort_order, 'legacy_activity_definition_id' => (int) $d->id,
            ]);
            $created = true;
        }
        RtsLevelTask::withTrashed()->firstOrCreate(
            ['level_id' => $levelId, 'task_id' => $task->id],
            ['agency_id' => $agencyId, 'target' => 0, 'points' => round((float) $d->weight, 2), 'required_for_signoff' => false]
        );

        return [$task, $created];
    }

    /** The task for an activity that has no task yet in a cut-over agency (its credit must not be dropped). */
    public function taskForNewActivity(int $agencyId, int $definitionId, string $track = 'sales'): ?RtsTask
    {
        $d = DB::table('activity_definitions')->where('id', $definitionId)->first();
        $level = RtsLevel::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('track', $track)->where('number', 1)->first();
        if (! $d || ! $level) {
            return null;
        }
        $d->manual_entries = (int) DB::table('daily_activity_entries')->where('agency_id', $agencyId)->where('activity_definition_id', $definitionId)->where('source', DailyActivityEntry::SOURCE_MANUAL)->count();
        $steps = RtsStep::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('track', $track)->pluck('id', 'key');

        return $this->ensureTask($d, $agencyId, $track, (int) $level->id, $steps)[0];
    }

    /** One completion per entry, set-based and keyed so a second run adds nothing. @return int rows added */
    private function copyEntries(int $agencyId, string $track, int $levelId): int
    {
        return (int) DB::affectingStatement(<<<'SQL'
            INSERT IGNORE INTO rts_task_completions
                (agency_id, branch_id, user_id, recorded_by_user_id, recorded_by_kind, track, task_id, step_id, level_id, occurred_at, period_unit,
                 period_start, activity_date, period, quantity, points_awarded, points_legacy, state, source, legacy_source, legacy_entry_id,
                 subject_type, subject_id, idempotency_key, evidence, confirmed_at, revoked_at, revoke_reason, override_audit, created_at, updated_at)
            SELECT
                e.agency_id, e.branch_id, e.user_id, e.user_id, 'agent', ?, t.id, t.step_id, ?, COALESCE(e.created_at, TIMESTAMP(e.activity_date)), 'day',
                TIMESTAMP(e.activity_date), e.activity_date, e.period, GREATEST(e.`value`, 0),
                ROUND(GREATEST(e.`value`, 0) * d.weight, 2), ROUND(GREATEST(e.`value`, 0) * d.weight, 2), e.point_state, 'migrated', e.source, e.id,
                e.subject_type, e.subject_id, SHA1(CONCAT('legacy-entry:', e.id)),
                IF(e.calendar_event_id IS NULL, NULL, JSON_OBJECT('calendar_event_id', e.calendar_event_id)),
                e.confirmed_at, e.revoked_at, LEFT(e.revoke_reason, 255), e.override_audit_json, e.created_at, e.updated_at
            FROM daily_activity_entries e
            JOIN activity_definitions d ON d.id = e.activity_definition_id
            JOIN rts_tasks t ON t.legacy_activity_definition_id = d.id AND t.agency_id = e.agency_id AND t.track = ?
            WHERE e.agency_id = ?
            SQL, [$track, $levelId, $track, $agencyId]);
    }

    private function copyRecords(int $agencyId): void
    {
        DB::statement(<<<'SQL'
            INSERT IGNORE INTO rts_task_completion_records (agency_id, completion_id, record_type, record_id, created_at, updated_at)
            SELECT c.agency_id, c.id, c.subject_type, c.subject_id, NOW(), NOW()
            FROM rts_task_completions c
            WHERE c.agency_id = ? AND c.source = 'migrated' AND c.subject_type IS NOT NULL AND c.subject_id IS NOT NULL
            SQL, [$agencyId]);
    }

    /** What the old engine counts today for the agency (confirmed + overridden, manual / calendar / instant), split manual and automatic. */
    public function countedPoints(int $agencyId): array
    {
        return $this->splitOf(ActivityPointsService::countedLegacy($agencyId));
    }

    /** The same figure read from the completions - must equal countedPoints() the moment the copy is done. */
    public function completionPoints(int $agencyId): array
    {
        return $this->splitOf(ActivityPointsService::countedCompletions($agencyId));
    }

    /** @return array{manual:float,automatic:float,total:float} */
    private function splitOf(\Illuminate\Database\Query\Builder $counted): array
    {
        [$man, $auto] = ActivityPointsService::splitExprs($counted);
        $row = $counted->selectRaw("COALESCE(SUM({$man}),0) as manual_pts, COALESCE(SUM({$auto}),0) as auto_pts")->first();

        return $this->pair((float) $row->manual_pts, (float) $row->auto_pts);
    }

    private function correctedPoints(int $agencyId): float
    {
        return round((float) DB::table('daily_activity_entries as e')->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.agency_id', $agencyId)
            ->whereIn('e.point_state', DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)->whereIn('e.source', DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->sum(DB::raw("CASE WHEN e.source = 'auto_calendar' AND e.point_state = 'confirmed' AND e.value > 1 AND e.value = d.weight THEN d.weight ELSE GREATEST(e.value,0) * d.weight END")), 2);
    }

    private function stepFor(object $d): string
    {
        $name = strtolower((string) $d->name);
        if (str_starts_with($name, '[auto] ')) {
            $slug = substr($name, 7);
            foreach (self::AUTO_STEP as $prefix => $step) {
                if (str_starts_with($slug, $prefix)) {
                    return $step;
                }
            }

            return 'find';
        }
        foreach (self::NAME_STEP as $word => $step) {
            if (str_contains($name, $word)) {
                return $step;
            }
        }

        return 'find';
    }

    /** "[Auto] property.captured" reads "Property captured"; an agency's own wording is kept as it is. */
    private function labelFor(object $d): string
    {
        $name = (string) $d->name;
        if (! str_starts_with($name, '[Auto] ')) {
            return mb_substr($name, 0, 160);
        }

        return mb_substr(ucfirst(str_replace(['.', '_'], ' ', substr($name, 7))), 0, 160);
    }

    /** @return array{manual:float,automatic:float,total:float} */
    private function pair(float $manual, float $automatic): array
    {
        return ['manual' => round($manual, 2), 'automatic' => round($automatic, 2), 'total' => round($manual + $automatic, 2)];
    }
}
