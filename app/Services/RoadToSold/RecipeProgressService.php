<?php

namespace App\Services\RoadToSold;

use App\Models\Agency;
use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\RoadToSold\RtsUserTaskOverride;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Activity\ActivityPointsService;
use App\Support\AppDate;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AT-454 v2 phase 2 - where an agent stands on the recipe right now (road-to-sold.md sections 5.2, 10.1, 11.1).
 *
 * Read only: it never writes (STANDARDS Rule 17). For the agent's level it returns every task with its target for the current
 * period, what is done, where the agent should be by now and the motivation state:
 *
 *   achieved   the period's target is met
 *   ahead      at or above the agency's "ahead" line of where they should be by now
 *   on_track   at or above the green line
 *   amber      between the red and the green line
 *   behind     below the red line
 *   open       a day or hour target that is not met yet - there is no pace line inside a day (no working hours exist to measure it
 *              against), so it carries no verdict until it is met
 *   none       no target set (0 = nothing required this period)
 *
 * "Where they should be by now" for a week or a month is the share of the period's WORKING days (WorkdayService: holidays and leave
 * carry no target) that have passed, today included - the same convention as the summary bar's calls figure.
 *
 * The personal target of a user override beats the level's (spec 5.2). Completions are counted from the stored period start, so
 * archived rows (soft deleted) never count.
 */
class RecipeProgressService
{
    public const STATE_ACHIEVED = 'achieved';
    public const STATE_AHEAD    = 'ahead';
    public const STATE_ON_TRACK = 'on_track';
    public const STATE_AMBER    = 'amber';
    public const STATE_BEHIND   = 'behind';
    public const STATE_OPEN     = 'open';
    public const STATE_NONE     = 'none';

    public const STATE_LABELS = [
        self::STATE_ACHIEVED => 'Achieved',
        self::STATE_AHEAD    => 'Ahead',
        self::STATE_ON_TRACK => 'On track',
        self::STATE_AMBER    => 'Needs a push',
        self::STATE_BEHIND   => 'Behind',
        self::STATE_OPEN     => 'To do',
        self::STATE_NONE     => '',
    ];

    /** Worst first, for rolling several tasks up into one step. */
    private const SEVERITY = [
        self::STATE_BEHIND => 5, self::STATE_AMBER => 4, self::STATE_OPEN => 3, self::STATE_ON_TRACK => 2, self::STATE_AHEAD => 1, self::STATE_ACHIEVED => 0,
    ];

    public function __construct(
        private readonly TaskCompletionService $engine,
        private readonly WorkdayService $workdays,
    ) {
    }

    /**
     * @return array{
     *   started:bool, level:?array{id:int,number:int,name:string}, tasks:array<int,array<string,mixed>>, steps:array<int,array<string,mixed>>,
     *   met:int, counted:int, points:float, points_label:string
     * }
     */
    public function forAgent(User $agent, string $track = 'sales', ?CarbonInterface $now = null): array
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: $agent->agency_id ?: 0);
        $empty = ['started' => false, 'level' => null, 'tasks' => [], 'steps' => [], 'met' => 0, 'counted' => 0, 'points' => 0.0, 'points_split' => ['manual' => 0.0, 'automatic' => 0.0, 'total' => 0.0], 'points_label' => ''];
        if ($agencyId <= 0) {
            return $empty;
        }
        $started = RtsStep::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('track', $track)->exists();
        if (! $started) {
            return $empty;
        }

        $level = $this->engine->levelFor($agencyId, $track, $agent);
        if (! $level) {
            return ['started' => true] + $empty;
        }

        $now ??= now();
        $settings = SettingsService::forAgency($agencyId, $track);
        $tz = AppDate::agencyTimezone(Agency::query()->withoutGlobalScopes()->find($agencyId));
        $local = Carbon::instance($now)->setTimezone($tz);

        $levelTasks = RtsLevelTask::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('level_id', $level->id)->get()->keyBy('task_id');
        $tasks = RtsTask::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('track', $track)->where('is_active', true)
            ->whereIn('id', $levelTasks->keys())->get();
        $steps = RtsStep::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('track', $track)->where('is_enabled', true)->orderBy('position')->get()->keyBy('id');
        $overrides = RtsUserTaskOverride::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('user_id', $agent->id)->whereIn('task_id', $tasks->pluck('id'))
            ->whereIn('level_key', [(int) $level->id, 0])->whereNotNull('target')->orderByDesc('level_key')->get()->groupBy('task_id');

        // One read for every actual: the four period starts of "now", summed per task.
        $starts = [];
        foreach (array_keys(RtsTask::UNITS) as $unit) {
            $starts[$unit] = $this->engine->periodStart($agencyId, $unit, $now);
        }
        $done = RtsTaskCompletion::query()->withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('user_id', $agent->id)->where('track', $track)
            ->whereNull('deleted_at')->whereIn('state', [RtsTaskCompletion::STATE_CONFIRMED, RtsTaskCompletion::STATE_OVERRIDDEN])
            ->whereIn('task_id', $tasks->pluck('id'))
            ->whereIn('period_start', collect($starts)->map(fn ($c) => $c->format('Y-m-d H:i:s'))->unique()->values())
            ->groupBy('task_id', 'period_start')
            ->get(['task_id', 'period_start', DB::raw('SUM(quantity) as n'), DB::raw('SUM(points_awarded) as pts')]);

        $rows = [];
        foreach ($tasks as $task) {
            $step = $steps->get($task->step_id);
            if (! $step) {
                continue; // its step is switched off or archived
            }
            $lt = $levelTasks->get($task->id);
            $unit = array_key_exists((string) $task->recurrence_unit, RtsTask::UNITS) ? (string) $task->recurrence_unit : 'day';
            $start = $starts[$unit]->format('Y-m-d H:i:s');
            $actual = (int) $done->filter(fn ($d) => (int) $d->task_id === (int) $task->id && Carbon::parse($d->period_start)->format('Y-m-d H:i:s') === $start)->sum('n');
            $target = (int) ($overrides->get($task->id)?->first()?->target ?? $lt->target);
            $expected = $this->expectedByNow($agent, $unit, $local, $track, $target);

            $rows[] = [
                'task_id'     => (int) $task->id,
                'key'         => $task->key,
                'label'       => $task->label,
                'type'        => $task->type,
                'is_custom'   => $task->type === RtsTask::TYPE_CUSTOM,
                'capture_kind' => $task->capture_kind,
                'evidence'    => $task->evidence_required,
                'event_key'   => $task->event_key,
                'step_id'     => (int) $step->id,
                'step_key'    => $step->key,
                'step_label'  => $step->label,
                'step_group'  => $step->group,
                'step_pos'    => (int) $step->position,
                'unit'        => $unit,
                'unit_label'  => strtolower(RtsTask::UNITS[$unit]),
                'target'      => $target,
                'actual'      => $actual,
                'expected'    => $expected,
                'pct'         => $target > 0 ? (int) min(100, round($actual / $target * 100)) : null,
                'state'       => $this->state($settings, $unit, $target, $actual, $expected),
                'period_end'  => $this->periodEnd($starts[$unit], $unit, $tz),
                'sort'        => (int) $task->sort_order,
            ];
        }
        usort($rows, fn ($a, $b) => [$a['step_pos'], $a['sort'], $a['task_id']] <=> [$b['step_pos'], $b['sort'], $b['task_id']]);

        $counted = collect($rows)->where('target', '>', 0);
        $split = $this->monthPoints($agencyId, $agent, $local);

        return [
            'started'  => true,
            'level'    => ['id' => (int) $level->id, 'number' => (int) $level->number, 'name' => (string) $level->name],
            'tasks'    => $rows,
            'steps'    => $this->rollUp($rows, $steps),
            'met'      => $counted->where('state', self::STATE_ACHIEVED)->count(),
            'counted'  => $counted->count(),
            'points'   => $split['total'],
            'points_split' => $split,
            'points_label' => $local->format('F'),
        ];
    }

    /** Every step that has a task with a target, with how many of its tasks are met and its worst state. @return array<int,array<string,mixed>> */
    private function rollUp(array $rows, $steps): array
    {
        $out = [];
        foreach (collect($rows)->where('target', '>', 0)->groupBy('step_id') as $stepId => $group) {
            $step = $steps->get($stepId);
            $worst = $group->sortByDesc(fn ($r) => self::SEVERITY[$r['state']] ?? 0)->first()['state'];
            $out[] = [
                'step_id'  => (int) $stepId,
                'key'      => $step->key,
                'label'    => $step->label,
                'metric'   => null,
                'met'      => $group->where('state', self::STATE_ACHIEVED)->count(),
                'total'    => $group->count(),
                'pct'      => (int) round($group->avg(fn ($r) => min(100, $r['actual'] / $r['target'] * 100))),
                'state'    => $group->every(fn ($r) => $r['state'] === self::STATE_ACHIEVED) ? self::STATE_ACHIEVED : $worst,
            ];
        }

        return $out;
    }

    /**
     * Points this month, split manual / automatic, from the ONE shared rule (ActivityPointsService) - the same figure every other screen
     * shows, for an agency still on the old rows and for one that has been cut over.
     *
     * @return array{manual:float,automatic:float,total:float}
     */
    private function monthPoints(int $agencyId, User $agent, CarbonInterface $local): array
    {
        return ActivityPointsService::sumSplit(
            ActivityPointsService::counted($agencyId)->where('e.user_id', $agent->id)->where('e.period', $local->format('Y-m'))
        );
    }

    /** The target's share that should be done by now: a week or month by working days passed, a day or hour = the whole target (no pace line). */
    private function expectedByNow(User $agent, string $unit, CarbonInterface $local, string $track, int $target): float
    {
        if ($target <= 0) {
            return 0.0;
        }
        if ($unit !== 'week' && $unit !== 'month') {
            return (float) $target;
        }
        $from = $unit === 'week' ? $local->copy()->startOfWeek(Carbon::MONDAY) : $local->copy()->startOfMonth();
        $to   = $unit === 'week' ? $from->copy()->addDays(6) : $local->copy()->endOfMonth();
        $total = 0;
        $passed = 0;
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            if (! $this->workdays->isWorkday($agent, $d, $track)) {
                continue;
            }
            $total++;
            if ($d->lte($local)) {
                $passed++;
            }
        }
        if ($total === 0) {
            // an agent with no working day this period: nothing is due yet, and calendar days are the honest fallback
            $days = $from->diffInDays($to) + 1;

            return round($target * (min($days, $from->diffInDays($local) + 1) / $days), 2);
        }

        return round($target * ($passed / $total), 2);
    }

    private function state(SettingsService $settings, string $unit, int $target, int $actual, float $expected): string
    {
        if ($target <= 0) {
            return self::STATE_NONE;
        }
        if ($actual >= $target) {
            return self::STATE_ACHIEVED;
        }
        if ($unit !== 'week' && $unit !== 'month') {
            return self::STATE_OPEN;
        }
        if ($expected <= 0) {
            return self::STATE_ON_TRACK; // the period has no working day behind it yet
        }
        $pct = $actual / $expected * 100;
        if ($pct >= $settings->summaryAheadFromPct()) {
            return self::STATE_AHEAD;
        }
        if ($pct >= $settings->summaryGreenFromPct()) {
            return self::STATE_ON_TRACK;
        }

        return $pct < $settings->summaryRedBelowPct() ? self::STATE_BEHIND : self::STATE_AMBER;
    }

    private function periodEnd(CarbonInterface $start, string $unit, $tz): Carbon
    {
        $local = Carbon::instance($start)->setTimezone($tz);

        return match ($unit) {
            'hour'  => $local->copy()->addHour(),
            'week'  => $local->copy()->addWeek(),
            'month' => $local->copy()->addMonth(),
            default => $local->copy()->addDay(),
        };
    }
}
