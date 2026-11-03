<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsManagerAction;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\RoadToSold\RtsUserTaskOverride;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AT-454 v2 phase 5 - suggested next targets (road-to-sold.md section 9.4). Deterministic, read only, and NOTHING here writes a target:
 * the manager sees the suggestion with the data behind it and accepts, edits or ignores it (TaskTargetService writes, audited).
 *
 * The rule, for each task in the agent's level that has a target:
 *   rate       = what the agent really did over the trailing `suggest_window_weeks` (confirmed, not archived) / the working days in that
 *                window (WorkdayService: holidays and leave carry no target)
 *   capacity   = the working days in the NEXT period of the task's unit (a day = 1, the next calendar week, the next calendar month)
 *   suggested  = max(current target, round(rate x (1 + growth) x capacity)),  growth = `suggest_growth_pct`
 *   capped by    the next level's target for the same task, when the agency has set one (never below the current target)
 * An hourly task is a timeboxed challenge and is never suggested. With no activity in the window, or fewer than five working days to
 * learn from, there is "not enough data yet" and no suggestion. An ignored suggestion stays hidden until the figure changes.
 */
class SuggestionService
{
    /** Fewest working days in the window before a rate means anything. */
    public const MIN_WORKING_DAYS = 5;

    public function __construct(
        private readonly WorkdayService $workdays,
        private readonly AgentLevelService $levels,
    ) {
    }

    /**
     * @param  Collection<int,User>  $agents
     * @return array<int,array<int,array<string,mixed>>>  agent id => rows (one per task that has data), each:
     *   task_id, label, step_label, unit, current, personal (bool), total, weeks, working_days, rate, next_days, suggested, capped_by, change (bool), ignored (bool), reason
     */
    public function forAgents(Collection $agents, string $track = 'sales', ?CarbonInterface $now = null): array
    {
        $out = [];
        foreach ($agents as $agent) {
            $out[(int) $agent->id] = $this->forAgent($agent, $track, $now);
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function forAgent(User $agent, string $track = 'sales', ?CarbonInterface $now = null): array
    {
        $agencyId = (int) ($agent->agency_id ?: 0);
        $level = $agencyId > 0 ? $this->levels->levelOf($agent, $track, $agencyId) : null;
        if (! $level) {
            return [];
        }
        $now ??= now();
        $settings = SettingsService::forAgency($agencyId, $track);
        $weeks = $settings->suggestWindowWeeks();
        $growth = $settings->suggestGrowthPct();

        $levelTasks = RtsLevelTask::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('level_id', $level->id)->where('target', '>', 0)->get()->keyBy('task_id');
        if ($levelTasks->isEmpty()) {
            return [];
        }
        $steps = RtsStep::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('track', $track)->where('is_enabled', true)->get()->keyBy('id');
        $tasks = RtsTask::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('track', $track)->where('is_active', true)->whereIn('id', $levelTasks->keys())
            ->whereIn('step_id', $steps->keys())->orderBy('sort_order')->get();
        $overrides = RtsUserTaskOverride::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('user_id', $agent->id)->whereIn('task_id', $tasks->pluck('id'))
            ->where('level_key', (int) $level->id)->whereNotNull('target')->get()->keyBy('task_id');
        $next = $this->levels->nextLevel($level);
        $nextTargets = $next ? RtsLevelTask::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('level_id', $next->id)->where('target', '>', 0)->pluck('target', 'task_id') : collect();

        $to = Carbon::instance($now)->startOfDay();
        $from = $to->copy()->subWeeks($weeks)->addDay();
        $workingDays = $this->workingDays($agent, $from, $to, $track);

        $done = RtsTaskCompletion::query()->withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('user_id', $agent->id)->where('track', $track)->whereNull('deleted_at')
            ->whereIn('state', [RtsTaskCompletion::STATE_CONFIRMED, RtsTaskCompletion::STATE_OVERRIDDEN])
            ->whereIn('task_id', $tasks->pluck('id'))
            ->whereBetween('activity_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('task_id')->pluck(DB::raw('SUM(quantity)'), 'task_id');
        $ignored = $this->ignored($agent, $track);

        $rows = [];
        foreach ($tasks as $task) {
            $unit = (string) $task->recurrence_unit;
            if ($unit === 'hour') {
                continue;
            }
            $current = (int) ($overrides->get($task->id)?->target ?? $levelTasks->get($task->id)->target);
            $total = (int) ($done[$task->id] ?? 0);
            $row = [
                'task_id' => (int) $task->id, 'label' => (string) $task->label, 'step_label' => (string) $steps->get($task->step_id)?->label,
                'unit' => $unit, 'current' => $current, 'personal' => $overrides->has($task->id), 'total' => $total, 'weeks' => $weeks,
                'working_days' => $workingDays, 'rate' => null, 'next_days' => null, 'suggested' => null, 'capped_by' => null,
                'change' => false, 'ignored' => false, 'reason' => null,
            ];
            if ($workingDays < self::MIN_WORKING_DAYS || $total <= 0) {
                $row['reason'] = 'Not enough data yet';
                $rows[] = $row;
                continue;
            }
            $rate = $total / $workingDays;
            $nextDays = $this->nextPeriodDays($agent, $unit, $to, $track);
            $suggested = max($current, (int) round($rate * (1 + $growth / 100) * $nextDays));
            $cap = (int) ($nextTargets[$task->id] ?? 0);
            if ($cap > 0 && $suggested > $cap) {
                $suggested = max($current, $cap);
                $row['capped_by'] = $next->name;
            }
            $row['rate'] = round($rate, 2);
            $row['next_days'] = $nextDays;
            $row['suggested'] = min($suggested, RtsLevelTask::MAX_TARGET);
            $row['change'] = $row['suggested'] > $current;
            $row['ignored'] = $row['change'] && ($ignored[$task->id] ?? null) === $row['suggested'];
            $rows[] = $row;
        }

        return $rows;
    }

    /** Working days of the agent in [from, to]. */
    private function workingDays(User $agent, Carbon $from, Carbon $to, string $track): int
    {
        $n = 0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if ($this->workdays->isWorkday($agent, $d, $track)) {
                $n++;
            }
        }

        return $n;
    }

    /** Working days in the next period of a unit after $today: a day is 1; the next calendar week; the next calendar month. */
    private function nextPeriodDays(User $agent, string $unit, Carbon $today, string $track): int
    {
        if ($unit === 'day') {
            return 1;
        }
        $from = $unit === 'week' ? $today->copy()->startOfWeek(Carbon::MONDAY)->addWeek() : $today->copy()->startOfMonth()->addMonth();
        $to = $unit === 'week' ? $from->copy()->endOfWeek(Carbon::SUNDAY) : $from->copy()->endOfMonth();

        return max(1, $this->workingDays($agent, $from, $to, $track));
    }

    /** task id => the suggestion the manager last ignored for this agent (null when they have not, or since acted). @return array<int,int> */
    private function ignored(User $agent, string $track): array
    {
        $out = [];
        $rows = RtsManagerAction::query()->withoutGlobalScopes()
            ->where('agency_id', $agent->agency_id)->where('track', $track)->where('user_id', $agent->id)
            ->whereIn('kind', [RtsManagerAction::SUGGESTION_IGNORED, RtsManagerAction::TARGET_SET, RtsManagerAction::TARGET_CLEARED])
            ->orderBy('id')->get(['kind', 'subject_key', 'value_to']);
        foreach ($rows as $r) {
            $task = (int) $r->subject_key;
            if ($r->kind === RtsManagerAction::SUGGESTION_IGNORED) {
                $out[$task] = (int) $r->value_to;
            } else {
                unset($out[$task]);   // a later target change makes an older "ignore" moot
            }
        }

        return $out;
    }
}
