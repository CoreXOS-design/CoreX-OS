<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Admin\TargetController;
use Illuminate\Http\Request;

class DailyActivityController extends Controller
{
// === AGENT_DAILY_UI_PATCH:WEEK_AND_STRIP ===
    /**
     * Patch: Week range (Mon–Sun) + 7-day strip metadata.
     */
    private function agentDailyWeekMeta(\Illuminate\Http\Request $request): array
    {
        $tz = config('app.timezone') ?: 'UTC';

        $today = \Carbon\Carbon::now($tz)->startOfDay();

        // Requested date (from query string) but NEVER allow future dates.
        $selected = $request->query('date');
        $requested = $selected
            ? \Carbon\Carbon::parse($selected, $tz)->startOfDay()
            : $today->copy();

        // Rolling window: today + previous 13 days (14 total). Clamp selection into this window.
        $windowStart = $today->copy()->subDays(13);

        $selectedDate = $requested->greaterThan($today)
            ? $today->copy()
            : $requested->copy();

        if ($selectedDate->lessThan($windowStart)) {
            $selectedDate = $windowStart->copy();
        }

        // Build the rolling strip from oldest -> newest (13 days back .. today)
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $days[] = [
                'date' => $d->toDateString(),
                'label' => $d->format('D j'),
                'is_selected' => $d->toDateString() === $selectedDate->toDateString(),
                'is_today' => false,
            ];
        }

        return [
            'selectedDate' => $selectedDate,
            'weekStart' => $windowStart, // kept for backwards compatibility with existing index() merge keys
            'weekEnd' => $today->copy()->endOfDay(),
            'days' => $days,
        ];
    }




    public function index(Request $request)
    {
        // === AGENT_DAILY_UI_PATCH:INDEX_INJECT ===
        // Week range (Mon–Sun) + 7-day strip metadata
        $meta = $this->agentDailyWeekMeta($request);
        $request->merge([
            'date' => $meta['selectedDate']->toDateString(),
            'week_start' => $meta['weekStart']->toDateString(),
            'week_end' => $meta['weekEnd']->toDateString(),
        ]);
        // === AGENT_DAILY_UI_PATCH:VIEW_SHARE ===
        \Illuminate\Support\Facades\View::share('agentDailyWeek', $meta);


        $u = $request->user();
        abort_unless($u && $u->hasPermission('daily_activity.view'), 403);
        // === V2 DAILY ACTIVITY ===
        $user = $request->user();
        $branchId = $user->branch_id ?? null;
        $date = $request->get('date');

        $period = substr($date, 0, 7);

        // Monthly points target for this user + period (default 0)
        $monthlyTarget = (int) (\DB::table('targets')
            ->where('user_id', $user->id)
            ->where('period', $period)
            ->value('points_target') ?? 0);

        // MTD points: sum(value * weight) for this user in this period,
        // limited to enabled definitions visible to their branch (global + branch)

        $definitions = \DB::table('activity_definitions')
            ->where('is_enabled', 1)
            ->where('scope', 'system')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();


        $defIds = collect($definitions)->pluck('id')->map(fn($v) => (int)$v)->all();

        // M6.5 — achievement total: confirmed/overridden + manual/auto_calendar/
        // auto_instant only. Provisional + revoked + auto_other excluded
        // (anti-gaming: a provisional viewing cannot inflate the target).
        //
        // The defIds filter (enabled definitions visible to this branch) is
        // deliberately DROPPED here: spine auto-credits write to "[Auto] *"
        // definitions seeded with is_enabled=false to hide them from the
        // manual-capture picker. Restricting to defIds would exclude every
        // auto credit from the achievement total. The state + source filter
        // already guarantees the row is a valid scoreable credit.
        $mtdPoints = (int) \DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.user_id', $user->id)
            ->where('e.period', $period)
            ->whereIn('e.point_state', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('e.source', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->sum(\DB::raw('e.value * d.weight'));

        $remainingPoints = max($monthlyTarget - $mtdPoints, 0);

        // Today's MANUAL entries (legacy bookkeeping — same shape as before,
        // but explicitly scoped to source='manual' so auto rows for the
        // same activity_definition + day don't shadow the manual cell).
        $manualEntries = \DB::table('daily_activity_entries')
            ->where('user_id', $user->id)
            ->where('activity_date', $date)
            ->where('source', \App\Models\DailyActivityEntry::SOURCE_MANUAL)
            ->get()
            ->keyBy('activity_definition_id');

        $values = [];
        $totalPoints = 0;  // manual points for THIS day (display only — MTD is separate)

        foreach ($definitions as $def) {
            $val = (int)($manualEntries[$def->id]->value ?? 0);
            $values[$def->id] = $val;
            $totalPoints += $val * (int)$def->weight;
        }

        // M6.5 — Today's AUTO entries (calendar + instant). Each row is
        // surfaced individually with its human-readable label, a points
        // figure, and its point_state so the view can group into
        // Acquired (counts) vs Provisional (does not count).
        $autoEntries = \DB::table('daily_activity_entries as e')
            ->leftJoin('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->leftJoin('activity_definition_calendar_classes as m', function ($j) use ($user) {
                $j->on('m.activity_definition_id', '=', 'e.activity_definition_id')
                  ->where('m.agency_id', $user->agency_id);
            })
            ->leftJoin('calendar_events as ce', 'ce.id', '=', 'e.calendar_event_id')
            ->where('e.user_id', $user->id)
            ->where('e.activity_date', $date)
            ->whereIn('e.source', [
                \App\Models\DailyActivityEntry::SOURCE_AUTO_CALENDAR,
                \App\Models\DailyActivityEntry::SOURCE_AUTO_INSTANT,
                \App\Models\DailyActivityEntry::SOURCE_AUTO_OTHER,
            ])
            ->select(
                'e.id',
                'e.value',
                'e.point_state',
                'e.source',
                'e.calendar_event_id',
                'e.subject_type',
                'e.subject_id',
                'd.name as def_name',
                'd.weight',
                'm.slug as instant_slug',
                'ce.category as calendar_event_class',
                'ce.title as calendar_title'
            )
            ->orderBy('e.point_state')   // confirmed first, provisional next, revoked last
            ->orderBy('e.id')
            ->get();

        // Decorate each row with its human-readable label + computed
        // points (value * weight). The view consumes this flat list.
        $todayAutoAcquired = [];
        $todayAutoProvisional = [];
        $todayAcquiredPoints = 0;
        $todayProvisionalPoints = 0;
        foreach ($autoEntries as $e) {
            $label = $e->source === \App\Models\DailyActivityEntry::SOURCE_AUTO_CALENDAR
                ? \App\Support\Activity\ActivityLabelResolver::forEventClass($e->calendar_event_class)
                : \App\Support\Activity\ActivityLabelResolver::forSlug($e->instant_slug);
            $points = (int) $e->value * (int) ($e->weight ?? 0);
            $row = [
                'id'         => (int) $e->id,
                'label'      => $label,
                'source'     => $e->source,
                'state'      => $e->point_state,
                'points'     => $points,
                'context'    => $e->calendar_title ?: null,
            ];
            // M6.5 — revoked rows are hidden by default on the daily
            // screen (audit lives on summary); confirmed/overridden go
            // into Acquired (count), provisional into Provisional (don't).
            if (in_array($e->point_state, \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES, true)) {
                $todayAutoAcquired[] = $row;
                $todayAcquiredPoints += $points;
            } elseif ($e->point_state === \App\Models\DailyActivityEntry::STATE_PROVISIONAL) {
                $todayAutoProvisional[] = $row;
                $todayProvisionalPoints += $points;
            }
        }

        $todayAchievementTotal = $totalPoints + $todayAcquiredPoints;

        return view('agent.daily-v2', [
            'definitions'  => $definitions,
            'values'       => $values,
            'totalPoints'  => $totalPoints,
            'selectedDate' => $date,
            'period'       => $period,
            'monthlyTarget'=> $monthlyTarget,
            'mtdPoints'    => $mtdPoints,
            'remainingPoints' => $remainingPoints,
            // M6.5 — auto display + integrity totals
            'todayAutoAcquired'      => $todayAutoAcquired,
            'todayAutoProvisional'   => $todayAutoProvisional,
            'todayAcquiredPoints'    => $todayAcquiredPoints,
            'todayProvisionalPoints' => $todayProvisionalPoints,
            'todayAchievementTotal'  => $todayAchievementTotal,
        ]);

    }

    public function printSheet(Request $request)
    {
        // Reuse EXACT same date/window logic as index()
        $meta = $this->agentDailyWeekMeta($request);
        $request->merge([
            'date' => $meta['selectedDate']->toDateString(),
            'week_start' => $meta['weekStart']->toDateString(),
            'week_end' => $meta['weekEnd']->toDateString(),
        ]);
        \Illuminate\Support\Facades\View::share('agentDailyWeek', $meta);

        $u = $request->user();
        abort_unless($u && $u->hasPermission('daily_activity.view'), 403);

        // === V2 DAILY ACTIVITY (same as index) ===
        $user = $request->user();
        $branchId = $user->branch_id ?? null;
        $date = $request->get('date');

        $period = substr($date, 0, 7);

        // Monthly points target for this user + period (default 0)
        $monthlyTarget = (int) (\DB::table('targets')
            ->where('user_id', $user->id)
            ->where('period', $period)
            ->value('points_target') ?? 0);

        // Definitions (EXACT same query as index)
        $definitions = \DB::table('activity_definitions')
            ->where('is_enabled', 1)
            ->where('scope', 'system')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $defIds = collect($definitions)->pluck('id')->map(fn($v) => (int)$v)->all();

        // M6.5 — achievement total: confirmed/overridden + manual/auto_calendar/
        // auto_instant only. Provisional + revoked + auto_other excluded
        // (anti-gaming: a provisional viewing cannot inflate the target).
        //
        // The defIds filter (enabled definitions visible to this branch) is
        // deliberately DROPPED here: spine auto-credits write to "[Auto] *"
        // definitions seeded with is_enabled=false to hide them from the
        // manual-capture picker. Restricting to defIds would exclude every
        // auto credit from the achievement total. The state + source filter
        // already guarantees the row is a valid scoreable credit.
        $mtdPoints = (int) \DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.user_id', $user->id)
            ->where('e.period', $period)
            ->whereIn('e.point_state', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('e.source', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->sum(\DB::raw('e.value * d.weight'));

        $remainingPoints = max($monthlyTarget - $mtdPoints, 0);

        $entries = \DB::table('daily_activity_entries')
            ->where('user_id', $user->id)
            ->where('activity_date', $date)
            ->get()
            ->keyBy('activity_definition_id');

        $values = [];
        $totalPoints = 0;

        foreach ($definitions as $def) {
            $val = (int)($entries[$def->id]->value ?? 0);
            $values[$def->id] = $val;
            $totalPoints += $val * (int)$def->weight;
        }

        // Optional: branch name (safe check)
        $branchName = null;
        if ($branchId && \Illuminate\Support\Facades\Schema::hasTable('branches')) {
            $branchName = \DB::table('branches')->where('id', $branchId)->value('name');
        }

        return view('agent.daily-v2-print', [
            'definitions'  => $definitions,
            'values'       => $values,
            'totalPoints'  => $totalPoints,
            'selectedDate' => $date,
            'period'       => $period,
            'monthlyTarget'=> $monthlyTarget,
            'mtdPoints'    => $mtdPoints,
            'remainingPoints' => $remainingPoints,
            'user'         => $user,
            'branchName'   => $branchName,
        ]);

    }
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->hasPermission('daily_activity.view'), 403);

        $data = $request->validate([
            'activity_date' => ['required', 'date'],
            'values' => ['array'],
            'values.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $date = $data['activity_date'];
        $period = substr($date, 0, 7);
        $branchId = $user->branch_id ?? null;

        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        if (!$agencyId && $branchId) {
            $agencyId = (int) \DB::table('branches')->where('id', $branchId)->value('agency_id');
        }
        if (!$agencyId) {
            $agencyId = (int) \DB::table('agencies')->orderBy('id')->value('id');
        }
        abort_if(!$agencyId, 422, 'Your account is not linked to an agency. Contact your administrator.');

        // Allowed enabled definitions for this user (global + branch)
        $definitions = \DB::table('activity_definitions')
            ->where('is_enabled', 1)
            ->where(function ($q) use ($branchId) {
                $q->where('scope', 'system');
                if ($branchId) {
                    $q->orWhere(function ($qq) use ($branchId) {
                        $qq->where('scope', 'branch')
                           ->where('branch_id', $branchId);
                    });
                }
            })
            ->get();

        $allowedIds = $definitions->pluck('id')->map(fn($v) => (int)$v)->all();
        $posted = (array)($data['values'] ?? []);

        // Save entries (0 => delete, >0 => upsert)
        foreach ($allowedIds as $defId) {
            $val = (int)($posted[(string)$defId] ?? ($posted[$defId] ?? 0));
            if ($val <= 0) {
                \DB::table('daily_activity_entries')
                    ->where('activity_definition_id', $defId)
                    ->where('user_id', $user->id)
                    ->where('activity_date', $date)
                    ->delete();
                continue;
            }

            \DB::table('daily_activity_entries')->updateOrInsert(
                [
                    'activity_definition_id' => $defId,
                    'user_id' => $user->id,
                    'activity_date' => $date,
                    'period' => $period,
                ],
                [
                    'agency_id' => $agencyId,
                    'branch_id' => $branchId,
                    'period' => $period,
                    'value' => $val,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        return redirect()->route('agent.daily', ['date' => $date])->with('status', 'Daily activity saved.');
    }

}
