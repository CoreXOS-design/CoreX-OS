<?php

namespace App\Services\RoadToSold;

use App\Models\Agency;
use App\Models\RoadToSold\RtsAgentLevel;
use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelSignoff;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\RoadToSold\RtsUserTaskOverride;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\AppDate;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * AT-454 v2 phase 4 - levels and sign-off (road-to-sold.md sections 8, 10.4, 13.1, 15 answer 8).
 *
 * The rules, in one place:
 *  - A level is a set of tasks. An agent works the tasks of THEIR level. A new agent starts on the lowest active level; their
 *    rts_agent_levels row is created the first time it is needed for a WRITE (ensure()), never on read (STANDARDS Rule 17).
 *  - Levels apply to agents and candidates only - never to an owner or principal, never to an assistant, and never to anyone who signs
 *    off others (eligible()). Such a user has no row, no request and no queue line.
 *  - Progress is measured in WEEKS (Monday to Sunday, agency timezone): a week is "on target" when every task the level marks
 *    "needed for sign-off" is met for that week. A week with no working day (leave, public holiday) carries no target and neither
 *    counts nor breaks the run. A week still running that is not yet met has not failed. Only whole weeks that start after the agent
 *    began the level (or after the manager last sent them back) count. The run needed is the agency's `level_periods_required`.
 *  - Completing tasks NEVER promotes. When the run is met the agent is put forward (awaiting_signoff) and their managers are told; only
 *    approve() moves them up. With the agency setting `levels_signoff_required` off the agent advances by themselves, and the ledger
 *    says so (automatic).
 *  - Nobody signs off themselves; a manager acts only on agents inside their own breadth (404 outside it); the ledger
 *    (rts_level_signoffs) is append-only; every decision runs in one transaction on a row re-read under a lock.
 */
class AgentLevelService
{
    /** How many weeks of history the screens draw. */
    public const HISTORY_WEEKS = 12;
    /** The furthest back a run is counted (the setting maximum plus slack). */
    private const LOOKBACK_WEEKS = 60;

    public function __construct(private readonly WorkdayService $workdays)
    {
    }

    // ── who ──────────────────────────────────────────────────────────────────────────────────

    /** Do levels apply to this person? Agents and candidates only (spec section 15, answer 8). */
    public function eligible(User $user): bool
    {
        if ($user->trashed() || ! $user->is_active || $user->is_assistant) {
            return false;
        }
        $designation = (string) $user->designation;
        $principal   = (bool) $user->is_principal_practitioner
            || (stripos($designation, 'Principal') !== false && stripos($designation, 'Candidate') === false);
        if ($principal || $user->isOwnerRole()) {
            return false;
        }
        // Whoever signs off other people's levels is a manager, not someone working up through them.
        if ($user->hasPermission('road_to_sold.signoff_levels')) {
            return false;
        }

        return $user->hasPermission('access_road_to_sold');
    }

    // ── the level an agent works at ──────────────────────────────────────────────────────────

    /** The lowest active level of the agency - where a new agent starts. */
    public function firstLevel(int $agencyId, string $track): ?RtsLevel
    {
        return RtsLevel::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('track', $track)->where('is_active', true)
            ->orderBy('number')->first();
    }

    /** The row for an agent, if they have one (read only). */
    public function rowFor(User $agent, string $track, ?int $agencyId = null): ?RtsAgentLevel
    {
        $agencyId ??= (int) ($agent->agency_id ?: 0);

        return RtsAgentLevel::query()->withoutGlobalScopes()->with('level')
            ->where('agency_id', $agencyId)->where('track', $track)->where('user_id', $agent->id)->first();
    }

    /** The level this agent works at right now (read only): their own, else the lowest active level. */
    public function levelOf(User $agent, string $track, ?int $agencyId = null): ?RtsLevel
    {
        $agencyId ??= (int) ($agent->agency_id ?: 0);
        $row = $this->rowFor($agent, $track, $agencyId);
        if ($row && $row->level && $row->level->is_active && ! $row->level->trashed()) {
            return $row->level;
        }

        return $this->firstLevel($agencyId, $track);
    }

    /**
     * Give an eligible agent their level row if they have none (a WRITE moment - the caller is about to write something for the agent).
     * Idempotent; a trashed row is restored, never duplicated (BUILD_STANDARD 5a). Null = not eligible, or the agency has no level yet.
     */
    public function ensure(User $agent, string $track): ?RtsAgentLevel
    {
        $agencyId = (int) ($agent->agency_id ?: 0);
        if ($agencyId <= 0) {
            return null;
        }
        $existing = RtsAgentLevel::query()->withoutGlobalScopes()->withTrashed()
            ->where('agency_id', $agencyId)->where('track', $track)->where('user_id', $agent->id)->first();
        if ($existing && ! $existing->trashed()) {
            return $existing;
        }
        if (! $this->eligible($agent)) {
            return null;
        }
        $level = $this->firstLevel($agencyId, $track);
        if (! $level) {
            return null;
        }
        if ($existing) {
            $existing->restore();

            return $existing;
        }

        return RtsAgentLevel::withoutAgencyStamping(fn () => RtsAgentLevel::create([
            'agency_id' => $agencyId, 'branch_id' => $agent->branch_id, 'track' => $track, 'user_id' => $agent->id,
            'level_id' => $level->id, 'status' => RtsAgentLevel::STATUS_ACTIVE, 'started_at' => now(),
        ]));
    }

    /** The next active level above $level, or null at the top. */
    public function nextLevel(RtsLevel $level): ?RtsLevel
    {
        return RtsLevel::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $level->agency_id)->where('track', $level->track)->where('is_active', true)
            ->where('number', '>', $level->number)->orderBy('number')->first();
    }

    // ── progress ─────────────────────────────────────────────────────────────────────────────

    /**
     * Where an agent stands on their level (read only).
     *
     * @return array{
     *   eligible:bool, row:?RtsAgentLevel, level:?RtsLevel, next:?RtsLevel, status:string, needed:int, streak:int,
     *   required:array<int,array<string,mixed>>, weeks:array<int,array<string,mixed>>, baseline:?Carbon, requested_at:?Carbon
     * }
     */
    public function progress(User $agent, string $track = 'sales', ?CarbonInterface $now = null): array
    {
        $agencyId = (int) ($agent->agency_id ?: 0);
        $now ??= now();
        $empty = ['eligible' => false, 'row' => null, 'level' => null, 'next' => null, 'status' => RtsAgentLevel::STATUS_ACTIVE, 'needed' => 0,
            'streak' => 0, 'required' => [], 'weeks' => [], 'baseline' => null, 'requested_at' => null];
        if ($agencyId <= 0 || ! $this->eligible($agent)) {
            return $empty;
        }

        $row   = $this->rowFor($agent, $track, $agencyId);
        $level = $this->levelOf($agent, $track, $agencyId);
        if (! $level) {
            return ['eligible' => true] + $empty;
        }
        $settings = SettingsService::forAgency($agencyId, $track);
        $needed   = $settings->levelPeriodsRequired();
        $baseline = $this->baseline($agent, $track, $row, $level, $now);

        $required = $this->requiredTasks($agent, $agencyId, $level);
        [$weeks, $streak] = $required === []
            ? [[], 0]
            : $this->weeks($agent, $agencyId, $track, $required, $baseline, $now);

        return [
            'eligible'     => true,
            'row'          => $row,
            'level'        => $level,
            'next'         => $this->nextLevel($level),
            'status'       => $row?->status ?? RtsAgentLevel::STATUS_ACTIVE,
            'needed'       => $needed,
            'streak'       => $streak,
            'required'     => $required,
            'weeks'        => $weeks,
            'baseline'     => $baseline,
            'requested_at' => $row?->requested_signoff_at,
        ];
    }

    /** The moment counting starts: the level was begun, or the manager last sent the agent back - whichever is later. */
    private function baseline(User $agent, string $track, ?RtsAgentLevel $row, RtsLevel $level, CarbonInterface $now): Carbon
    {
        $start = $row?->started_at ? Carbon::instance($row->started_at) : Carbon::instance($now);
        $last = RtsLevelSignoff::query()->withoutGlobalScopes()
            ->where('agency_id', $agent->agency_id)->where('track', $track)->where('user_id', $agent->id)
            ->where('decision', RtsLevelSignoff::RETURNED)->where('from_level_id', $level->id)->max('decided_at');

        return $last && Carbon::parse($last)->gt($start) ? Carbon::parse($last) : $start;
    }

    /**
     * The tasks the level marks "needed for sign-off" with this agent's target for each (a personal target beats the level's).
     *
     * @return array<int,array{task_id:int,label:string,unit:string,target:int}>
     */
    private function requiredTasks(User $agent, int $agencyId, RtsLevel $level): array
    {
        $levelTasks = RtsLevelTask::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('level_id', $level->id)->where('required_for_signoff', true)->get()->keyBy('task_id');
        if ($levelTasks->isEmpty()) {
            return [];
        }
        $tasks = RtsTask::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('track', $level->track)->where('is_active', true)
            ->whereIn('id', $levelTasks->keys())
            ->whereIn('step_id', RtsStep::query()->withoutGlobalScope(AgencyScope::class)
                ->where('agency_id', $agencyId)->where('is_enabled', true)->select('id'))
            ->orderBy('sort_order')->get();
        $overrides = RtsUserTaskOverride::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('user_id', $agent->id)->whereIn('task_id', $tasks->pluck('id'))
            ->whereIn('level_key', [(int) $level->id, 0])->whereNotNull('target')->orderByDesc('level_key')->get()->groupBy('task_id');

        $out = [];
        foreach ($tasks as $task) {
            $target = (int) ($overrides->get($task->id)?->first()?->target ?? $levelTasks->get($task->id)->target);
            $unit   = array_key_exists((string) $task->recurrence_unit, RtsTask::UNITS) ? (string) $task->recurrence_unit : 'day';
            if ($target > 0) {   // 0 = nothing required this period (spec 10.5)
                $out[] = ['task_id' => (int) $task->id, 'label' => (string) $task->label, 'unit' => $unit, 'target' => $target];
            }
        }

        return $out;
    }

    /**
     * Each whole week from the baseline to now: on target, short, or neutral (no working day). Newest first, at most HISTORY_WEEKS drawn;
     * the run is counted back from the newest week that has been decided.
     *
     * @param  array<int,array{task_id:int,label:string,unit:string,target:int}>  $required
     * @return array{0:array<int,array<string,mixed>>,1:int}  [weeks newest first, run of weeks on target]
     */
    private function weeks(User $agent, int $agencyId, string $track, array $required, Carbon $baseline, CarbonInterface $now): array
    {
        $tz    = AppDate::agencyTimezone(Agency::query()->withoutGlobalScopes()->find($agencyId));
        $local = Carbon::instance($now)->setTimezone($tz);
        $thisWeek = $local->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $first = $thisWeek->copy()->subWeeks(self::LOOKBACK_WEEKS);
        $base  = Carbon::instance($baseline)->setTimezone($tz);

        // whole weeks that begin at or after the baseline
        $starts = [];
        for ($w = $first->copy(); $w->lte($thisWeek); $w->addWeek()) {
            if ($w->gte($base)) {
                $starts[] = $w->copy();
            }
        }
        if ($starts === []) {
            return [[], 0];
        }

        $range = [$starts[0]->copy()->startOfMonth(), $thisWeek->copy()->endOfWeek(Carbon::SUNDAY)];
        $byDay = $this->quantitiesByDay($agencyId, $agent->id, $track, array_column($required, 'task_id'), $range[0], $range[1], $tz);
        $hourHits = $this->hourPeriodsMet($agencyId, $agent->id, $track, $required, $starts[0], $thisWeek->copy()->endOfWeek(Carbon::SUNDAY), $tz);

        $weeks = [];
        foreach ($starts as $start) {
            $end   = $start->copy()->endOfWeek(Carbon::SUNDAY);
            $days  = $this->workingDays($agent, $start, $end, $track);
            $tasks = [];
            $allMet = true;
            foreach ($required as $r) {
                [$actual, $need, $met] = $this->taskInWeek($agent, $r, $start, $end, $days, $byDay, $hourHits, $track, $local);
                $tasks[] = ['task_id' => $r['task_id'], 'label' => $r['label'], 'unit' => $r['unit'], 'actual' => $actual, 'need' => $need, 'met' => $met];
                $allMet = $allMet && $met;
            }
            $weeks[] = [
                'start' => $start->copy(), 'end' => $end->copy(), 'working_days' => count($days),
                'state' => count($days) === 0 ? 'neutral' : ($allMet ? 'met' : 'short'),
                'current' => $start->equalTo($thisWeek), 'tasks' => $tasks,
            ];
        }
        $weeks = array_reverse($weeks);   // newest first

        // the run: back from the newest decided week; a running week that is not yet met has not failed
        $streak = 0;
        foreach ($weeks as $w) {
            if ($w['state'] === 'neutral') {
                continue;
            }
            if ($w['state'] === 'short') {
                if ($w['current']) {
                    continue;
                }
                break;
            }
            $streak++;
        }

        return [array_slice($weeks, 0, self::HISTORY_WEEKS), $streak];
    }

    /** @return string[] the working days (Y-m-d) of the agent in [from, to] */
    private function workingDays(User $agent, Carbon $from, Carbon $to, string $track): array
    {
        $days = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            if ($this->workdays->isWorkday($agent, $d, $track)) {
                $days[] = $d->toDateString();
            }
        }

        return $days;
    }

    /**
     * [actual, need, met] for one required task in one week.
     *  week   the week's total against the target
     *  day    the week's total against target x working days
     *  month  the month so far against its share of the month's working days that have passed by the end of this week
     *  hour   at least one hour of the week reached the target (an hourly task is a timeboxed challenge, not a weekly volume)
     *
     * @return array{0:int,1:int,2:bool}
     */
    private function taskInWeek(User $agent, array $r, Carbon $start, Carbon $end, array $days, array $byDay, array $hourHits, string $track, Carbon $local): array
    {
        $task = $r['task_id'];
        $inWeek = 0;
        foreach ($byDay[$task] ?? [] as $date => $qty) {
            if ($date >= $start->toDateString() && $date <= $end->toDateString()) {
                $inWeek += $qty;
            }
        }
        if (count($days) === 0) {
            return [$inWeek, 0, true];
        }

        switch ($r['unit']) {
            case 'week':
                return [$inWeek, $r['target'], $inWeek >= $r['target']];
            case 'day':
                $need = $r['target'] * count($days);

                return [$inWeek, $need, $inWeek >= $need];
            case 'hour':
                $hits = (int) ($hourHits[$task][$start->toDateString()] ?? 0);

                return [$hits, 1, $hits >= 1];
            default: // month
                $monthStart = $start->copy()->startOfMonth();
                $monthEnd   = $start->copy()->endOfMonth();
                $monthDays  = $this->workingDays($agent, $monthStart, $monthEnd, $track);
                if ($monthDays === []) {
                    return [$inWeek, 0, true];
                }
                $through = min($end->toDateString(), $monthEnd->toDateString());
                $passed  = count(array_filter($monthDays, fn ($d) => $d <= $through));
                $need    = (int) ceil($r['target'] * $passed / count($monthDays));
                $actual  = 0;
                foreach ($byDay[$task] ?? [] as $date => $qty) {
                    if ($date >= $monthStart->toDateString() && $date <= $through) {
                        $actual += $qty;
                    }
                }

                return [$actual, $need, $actual >= $need];
        }
    }

    /**
     * Counting completions per task per local day (confirmed or overridden, not archived) - the same rows the recipe progress counts.
     *
     * @return array<int,array<string,int>> task id => date => quantity
     */
    private function quantitiesByDay(int $agencyId, int $userId, string $track, array $taskIds, Carbon $from, Carbon $to, $tz): array
    {
        $rows = RtsTaskCompletion::query()->withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('user_id', $userId)->where('track', $track)->whereNull('deleted_at')
            ->whereIn('state', [RtsTaskCompletion::STATE_CONFIRMED, RtsTaskCompletion::STATE_OVERRIDDEN])
            ->whereIn('task_id', $taskIds)
            ->whereBetween('activity_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('task_id', 'activity_date')
            ->get(['task_id', 'activity_date', DB::raw('SUM(quantity) as n')]);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->task_id][Carbon::parse($row->activity_date)->toDateString()] = (int) $row->n;
        }

        return $out;
    }

    /** For hourly required tasks: how many hours of each week reached the target. @return array<int,array<string,int>> task id => week start => hours */
    private function hourPeriodsMet(int $agencyId, int $userId, string $track, array $required, Carbon $from, Carbon $to, $tz): array
    {
        $hourly = array_values(array_filter($required, fn ($r) => $r['unit'] === 'hour'));
        if ($hourly === []) {
            return [];
        }
        $targets = array_column($hourly, 'target', 'task_id');
        $rows = RtsTaskCompletion::query()->withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('user_id', $userId)->where('track', $track)->whereNull('deleted_at')
            ->whereIn('state', [RtsTaskCompletion::STATE_CONFIRMED, RtsTaskCompletion::STATE_OVERRIDDEN])
            ->whereIn('task_id', array_keys($targets))
            ->whereBetween('activity_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('task_id', 'period_start', 'activity_date')
            ->get(['task_id', 'period_start', 'activity_date', DB::raw('SUM(quantity) as n')]);
        $out = [];
        foreach ($rows as $row) {
            if ((int) $row->n < (int) $targets[(int) $row->task_id]) {
                continue;
            }
            $week = Carbon::parse($row->activity_date)->startOfWeek(Carbon::MONDAY)->toDateString();
            $out[(int) $row->task_id][$week] = ($out[(int) $row->task_id][$week] ?? 0) + 1;
        }

        return $out;
    }

    // ── putting an agent forward ─────────────────────────────────────────────────────────────

    /**
     * Check one agent and, when they have met their level for the weeks the agency asks for, put them forward (or advance them, when
     * the agency does not need a sign-off). Safe to run any number of times: an agent already waiting is not asked about twice.
     * Returns 'requested' | 'advanced' | null (nothing to do).
     */
    public function check(User $agent, string $track = 'sales', ?CarbonInterface $now = null): ?string
    {
        if (! $this->eligible($agent)) {
            return null;
        }
        $row = $this->ensure($agent, $track);
        if (! $row || $row->status !== RtsAgentLevel::STATUS_ACTIVE) {
            return null;
        }
        $progress = $this->progress($agent, $track, $now);
        if (! $progress['level'] || $progress['required'] === [] || $progress['streak'] < $progress['needed']) {
            return null;
        }
        $evidence = $this->evidence($progress);

        $result = DB::transaction(function () use ($row, $progress, $agent, $track, $evidence) {
            $locked = RtsAgentLevel::query()->withoutGlobalScopes()->whereKey($row->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== RtsAgentLevel::STATUS_ACTIVE) {
                return null;   // someone else got there first
            }
            if ((int) $locked->level_id !== (int) $progress['level']->id) {
                $locked->level_id = $progress['level']->id;   // the old level was archived: follow the level the agent really works at
            }
            if (! SettingsService::forAgency((int) $agent->agency_id, $track)->levelsSignoffRequired()) {
                $this->advance($locked, null, 'Moved up automatically - this agency does not ask for a sign-off.', true, $evidence);

                return ['advanced', null];
            }
            $locked->status = RtsAgentLevel::STATUS_AWAITING;
            $locked->requested_signoff_at = now();
            $locked->save();
            $entry = $this->ledger($locked, RtsLevelSignoff::REQUESTED, null, null, null, false, $evidence);

            return ['requested', $entry];
        });

        if ($result === null) {
            return null;
        }
        if ($result[0] === 'requested') {
            $this->tellManagers($agent, $result[1], (int) $row->id);
        } else {
            $this->tellAgent($agent, 'rts.level_signed_off', $this->latestEntry($agent, $track, RtsLevelSignoff::APPROVED), 'You moved up a level', 'You met your level and moved up to the next one.');
        }

        return $result[0];
    }

    /** Check every eligible agent of an agency. @return array{requested:int,advanced:int,checked:int} */
    public function checkAgency(int $agencyId, string $track = 'sales'): array
    {
        $counts = ['requested' => 0, 'advanced' => 0, 'checked' => 0];
        $agency = Agency::query()->withoutGlobalScopes()->find($agencyId);
        if (! $agency || ! app(\App\Services\Features\AgencyFeatureService::class)->enabled('road-to-sold', $agency)) {
            return $counts;
        }
        $users = User::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('is_assistant')->orWhere('is_assistant', false))->orderBy('id')->get();
        foreach ($users as $user) {
            if (! $this->eligible($user)) {
                continue;
            }
            $counts['checked']++;
            try {
                $outcome = $this->check($user, $track);
            } catch (\Throwable $e) {
                Log::warning('[rts] level check failed', ['agency' => $agencyId, 'user' => $user->id, 'error' => $e->getMessage()]);
                continue;
            }
            if ($outcome) {
                $counts[$outcome]++;
            }
        }

        return $counts;
    }

    // ── the manager's decisions ──────────────────────────────────────────────────────────────

    /** Sign an agent off: they move to the next level (or, at the top, are marked as having reached it). */
    public function approve(User $manager, int $rowId, ?string $comment = null): RtsAgentLevel
    {
        $comment = $this->cleanComment($comment, false);
        $row = $this->decide($manager, $rowId, function (RtsAgentLevel $locked) use ($manager, $comment) {
            $this->advance($locked, $manager, $comment, false, null);
        });
        $agent = User::query()->withoutGlobalScopes()->find($row->user_id);
        if ($agent) {
            $this->tellAgent($agent, 'rts.level_signed_off', $this->latestEntry($agent, $row->track, RtsLevelSignoff::APPROVED), 'You moved up a level', $comment ?: 'Your manager signed you off.');
        }

        return $row;
    }

    /** Send an agent back with a comment on what to work on; the weeks start counting again from now. */
    public function sendBack(User $manager, int $rowId, ?string $comment): RtsAgentLevel
    {
        $comment = $this->cleanComment($comment, true);
        $row = $this->decide($manager, $rowId, function (RtsAgentLevel $locked) use ($manager, $comment) {
            $locked->status = RtsAgentLevel::STATUS_ACTIVE;
            $locked->requested_signoff_at = null;
            $locked->save();
            $this->ledger($locked, RtsLevelSignoff::RETURNED, $manager, $comment, null, false, null);
        });
        $agent = User::query()->withoutGlobalScopes()->find($row->user_id);
        if ($agent) {
            $this->tellAgent($agent, 'rts.level_returned', $this->latestEntry($agent, $row->track, RtsLevelSignoff::RETURNED), 'Your level was sent back', $comment);
        }

        return $row;
    }

    /** @param  callable(RtsAgentLevel):void  $apply */
    private function decide(User $manager, int $rowId, callable $apply): RtsAgentLevel
    {
        abort_unless($manager->hasPermission('road_to_sold.signoff_levels'), 403);

        return DB::transaction(function () use ($manager, $rowId, $apply) {
            $row = RtsAgentLevel::query()->visibleTo($manager)->whereKey($rowId)->lockForUpdate()->firstOrFail();   // outside the breadth = 404
            if (in_array((int) $row->user_id, array_map('intval', $manager->dataIdentityIds()), true)) {
                abort(403, 'You cannot sign off your own level - another manager does that.');
            }
            if ($row->status !== RtsAgentLevel::STATUS_AWAITING) {
                throw ValidationException::withMessages(['level' => 'That agent is not waiting for a sign-off - refresh the list to see where they are now.']);
            }
            $apply($row);

            return $row->refresh();
        });
    }

    /** Move an agent up (or mark the top). Runs inside the caller's transaction on a locked row. */
    private function advance(RtsAgentLevel $row, ?User $by, ?string $comment, bool $automatic, ?array $evidence): void
    {
        $from = $row->level()->first();
        $next = $from ? $this->nextLevel($from) : null;
        $fromId = (int) $row->level_id;
        if ($next) {
            $row->level_id = $next->id;
            $row->status = RtsAgentLevel::STATUS_ACTIVE;
            $row->started_at = now();
        } else {
            $row->status = RtsAgentLevel::STATUS_SIGNED;
        }
        $row->requested_signoff_at = null;
        $row->save();
        RtsLevelSignoff::withoutAgencyStamping(fn () => RtsLevelSignoff::create([
            'agency_id' => $row->agency_id, 'branch_id' => $row->branch_id, 'track' => $row->track, 'user_id' => $row->user_id,
            'from_level_id' => $fromId, 'to_level_id' => $next?->id, 'decided_by_user_id' => $by?->id, 'decision' => RtsLevelSignoff::APPROVED,
            'automatic' => $automatic, 'comment' => $comment, 'evidence' => $evidence, 'decided_at' => now(),
        ]));
    }

    private function ledger(RtsAgentLevel $row, string $decision, ?User $by, ?string $comment, ?int $toLevelId, bool $automatic, ?array $evidence): RtsLevelSignoff
    {
        return RtsLevelSignoff::withoutAgencyStamping(fn () => RtsLevelSignoff::create([
            'agency_id' => $row->agency_id, 'branch_id' => $row->branch_id, 'track' => $row->track, 'user_id' => $row->user_id,
            'from_level_id' => $row->level_id, 'to_level_id' => $toLevelId, 'decided_by_user_id' => $by?->id, 'decision' => $decision,
            'automatic' => $automatic, 'comment' => $comment, 'evidence' => $evidence, 'decided_at' => now(),
        ]));
    }

    private function latestEntry(User $agent, string $track, string $decision): ?RtsLevelSignoff
    {
        return RtsLevelSignoff::query()->withoutGlobalScopes()->where('agency_id', $agent->agency_id)->where('track', $track)
            ->where('user_id', $agent->id)->where('decision', $decision)->orderByDesc('id')->first();
    }

    private function cleanComment(?string $comment, bool $required): ?string
    {
        $comment = trim((string) $comment);
        if ($required && $comment === '') {
            throw ValidationException::withMessages(['comment' => 'Write what the agent should work on - they see this.']);
        }
        if (mb_strlen($comment) > 2000) {
            throw ValidationException::withMessages(['comment' => 'Keep the comment under 2000 characters.']);
        }

        return $comment === '' ? null : $comment;
    }

    /** What a request was based on: the weeks of the run and the tasks they met. */
    private function evidence(array $progress): array
    {
        $run = [];
        foreach ($progress['weeks'] as $w) {
            if ($w['state'] === 'met') {
                $run[] = ['week' => $w['start']->toDateString(), 'tasks' => array_map(fn ($t) => ['task' => $t['label'], 'actual' => $t['actual'], 'need' => $t['need']], $w['tasks'])];
            }
        }

        return ['weeks_needed' => $progress['needed'], 'run' => $run];
    }

    // ── telling people ───────────────────────────────────────────────────────────────────────

    /** Managers whose breadth covers the agent: everyone with the sign-off key and `all`, or `branch` in the agent's branch. @return \Illuminate\Support\Collection<int,User> */
    public function managersFor(User $agent)
    {
        return User::query()->withoutGlobalScopes()->where('agency_id', $agent->agency_id)->where('is_active', true)
            ->where('id', '!=', $agent->id)->get()
            ->filter(function (User $u) use ($agent) {
                if (! $u->hasPermission('road_to_sold.signoff_levels')) {
                    return false;
                }
                $scope = PermissionService::getDataScope($u, Scope::MODULE) ?? 'own';

                return $scope === 'all' || ($scope === 'branch' && $u->branch_id && (int) $u->branch_id === (int) $agent->branch_id);
            })->values();
    }

    private function tellManagers(User $agent, ?RtsLevelSignoff $entry, int $rowId): void
    {
        if (! $entry) {
            return;
        }
        foreach ($this->managersFor($agent) as $manager) {
            $this->notify($manager, 'rts.level_signoff_requested', $entry, $agent->name . ' is ready for a level sign-off',
                'They have met their level for the weeks your agency asks for. Review and sign them off or send them back.',
                route('road-to-sold.signoffs.show', $rowId, false));
        }
    }

    private function tellAgent(User $agent, string $key, ?RtsLevelSignoff $entry, string $title, string $body): void
    {
        if (! $entry) {
            return;
        }
        $this->notify($agent, $key, $entry, $title, $body, route('road-to-sold.index', [], false));
    }

    /** Never let a notification fault undo or block a decision. A distinct subject per ledger line, so two decisions in one day both arrive. */
    private function notify(User $to, string $key, RtsLevelSignoff $subject, string $title, string $body, string $url): void
    {
        try {
            app(\App\Services\CommandCenter\NotificationDispatcher::class)->fire($to, $key, $subject, [
                'title' => $title, 'body' => $body, 'action_url' => $url, 'severity' => 'info',
                'subject_label' => 'Level sign-off', 'threshold_hit_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[rts] level notification failed', ['key' => $key, 'to' => $to->id, 'error' => $e->getMessage()]);
        }
    }
}
