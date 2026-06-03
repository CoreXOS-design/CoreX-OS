<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CommandTask;
use App\Models\CommandCenter\PropertyHealthScore;
use App\Models\CommandCenter\AgentScorecard;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\CandidatePractitionerService;
use App\Services\CommandCenter\Calendar\CalendarThresholdResolver;
use App\Services\CommandCenter\Calendar\CalendarVisibilityResolver;
use App\Services\CommandCenter\CalendarEventService;
use App\Services\CommandCenter\CommandCentreService;
use App\Services\CommandCenter\PropertyHealthCalculator;
use App\Services\CommandCenter\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Personal Command Centre — the "What should I do now?" landing page.
     */
    public function today(Request $request)
    {
        $user = $request->user();
        $service = app(CommandCentreService::class);
        $cards = $service->assembleForUser($user);

        if ($request->wantsJson()) {
            return response()->json(['cards' => $cards]);
        }

        return view('command-center.today', [
            'user' => $user,
            'cards' => $cards,
        ]);
    }

    /**
     * AJAX refresh endpoint — returns fresh card data.
     */
    public function todayCards(Request $request)
    {
        $user = $request->user();
        Cache::forget("command_centre_{$user->id}");
        $service = app(CommandCentreService::class);
        return response()->json(['cards' => $service->assembleForUser($user)]);
    }

    public function index(Request $request)
    {
        $user   = $request->user();
        $period = now()->format('Y-m');

        // ── Activity Points (for footer strip) ──
        $defIds = DB::table('activity_definitions')
            ->where('is_enabled', 1)
            ->where('scope', 'system')
            ->pluck('id');

        // M6.5 — achievement-total filter.
        $mtdPoints = (int) DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.user_id', $user->id)
            ->where('e.period', $period)
            ->whereIn('e.activity_definition_id', $defIds)
            ->whereIn('e.point_state', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('e.source', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->sum(DB::raw('e.value * d.weight'));

        $monthlyTarget = (int) (DB::table('targets')
            ->where('user_id', $user->id)
            ->where('period', $period)
            ->value('points_target') ?? 0);

        // ── Candidate Documents (moved into Inbox as action cards) ──
        $candidateService = new CandidatePractitionerService();
        $candidateDocs    = collect();
        if ($candidateService->canAuthorise($user)) {
            $candidateDocs = SignatureTemplate::with(['document', 'creator'])
                ->where('is_candidate_flow', true)
                ->whereIn('status', [
                    SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
                    SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL,
                ])
                ->orderBy('created_at', 'desc')
                ->get();
        }

        // ── Calendar Events ──
        $calendarService = new CalendarEventService();
        $todayEvents     = $calendarService->getTodayEvents($user);
        $overdueEvents   = $calendarService->getOverdueEvents($user);

        // ── Tasks ──
        $taskService   = new TaskService();
        $overdueTasks  = $taskService->getOverdueTasks($user, 20);
        $taskSummary   = $taskService->getSummary($user);

        // ── Tasks due today (for Timeline) ──
        $tasksToday = CommandTask::forUser($user->id)
            ->open()
            ->dueToday()
            ->with(['property', 'contact'])
            ->orderBy('due_date')
            ->get();

        // ── Scorecard summary (footer strip) ──
        $scorecard = AgentScorecard::forUser($user->id)->currentWeek()->first();

        // ── Inbox items: overdue tasks + overdue events + candidate docs ──
        // Unresolved overdue, ordered by urgency (oldest first)
        $inboxOverdueTasks = CommandTask::forUser($user->id)
            ->overdue()
            ->whereNull('resolution')
            ->with(['property', 'contact'])
            ->orderBy('due_date')
            ->limit(20)
            ->get();

        $inboxOverdueEvents = CalendarEvent::forUser($user->id)
            ->where('status', 'overdue')
            ->whereNull('resolution')
            ->with(['property', 'contact'])
            ->orderBy('event_date')
            ->limit(20)
            ->get();

        $inboxTotal = $inboxOverdueTasks->count() + $inboxOverdueEvents->count() + $candidateDocs->count();

        // ── Calendar widget — Coming up (next 7 days) ──
        $thresholdResolver = app(CalendarThresholdResolver::class);
        $visibilityResolver = app(CalendarVisibilityResolver::class);

        $widgetRaw = $calendarService->getEventsForRange(
            $user,
            now()->startOfDay()->toDateString(),
            now()->copy()->addDays(7)->endOfDay()->toDateString()
        );

        $upcomingEvents = collect($visibilityResolver->filterVisible($widgetRaw, $user))
            ->map(function ($e) use ($thresholdResolver) {
                $e->resolved_colour = $thresholdResolver->resolveForEvent($e);
                return $e;
            })
            ->filter(fn ($e) => $e->resolved_colour !== null)
            ->sortBy('event_date')
            ->take(12)
            ->values();

        $upcomingClassLabels = \App\Models\CommandCenter\CalendarEventClassSetting::withoutGlobalScopes()
            ->whereIn('event_class', $upcomingEvents->pluck('category')->unique()->all())
            ->whereNull('agency_id')
            ->pluck('label', 'event_class');

        $upcomingByDate = $upcomingEvents->groupBy(fn ($e) => $e->event_date->toDateString());

        return view('command-center.dashboard', [
            'user'                => $user,
            'period'              => $period,
            'mtdPoints'           => $mtdPoints,
            'monthlyTarget'       => $monthlyTarget,
            'candidateDocs'       => $candidateDocs,
            'todayEvents'         => $todayEvents,
            'tasksToday'          => $tasksToday,
            'overdueEvents'       => $overdueEvents,
            'overdueTasks'        => $overdueTasks,
            'taskSummary'         => $taskSummary,
            'scorecard'           => $scorecard,
            'inboxOverdueTasks'   => $inboxOverdueTasks,
            'inboxOverdueEvents'  => $inboxOverdueEvents,
            'inboxTotal'          => $inboxTotal,
            'upcomingEvents'      => $upcomingEvents,
            'upcomingByDate'      => $upcomingByDate,
            'upcomingClassLabels' => $upcomingClassLabels,
        ]);
    }

    /**
     * Performance page — scorecard, property health, candidate docs review.
     * Moved here from the dashboard to keep the cockpit action-first.
     */
    public function performance(Request $request)
    {
        $user   = $request->user();
        $period = now()->format('Y-m');

        // Activity points + target
        $defIds = DB::table('activity_definitions')
            ->where('is_enabled', 1)
            ->where('scope', 'system')
            ->pluck('id');

        // M6.5 — achievement-total filter.
        $mtdPoints = (int) DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.user_id', $user->id)
            ->where('e.period', $period)
            ->whereIn('e.activity_definition_id', $defIds)
            ->whereIn('e.point_state', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('e.source', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->sum(DB::raw('e.value * d.weight'));

        $monthlyTarget = (int) (DB::table('targets')
            ->where('user_id', $user->id)
            ->where('period', $period)
            ->value('points_target') ?? 0);

        // Scorecard
        $scorecard = AgentScorecard::forUser($user->id)->currentWeek()->first();

        // Property health
        $healthCalc   = new PropertyHealthCalculator();
        $propsNeedingAttention = $healthCalc->getNeedingAttention($user->id, null, 20);
        $propHealthSummary = [
            'critical'  => PropertyHealthScore::critical()->whereHas('property', fn ($q) => $q->where('agent_id', $user->id))->count(),
            'attention' => PropertyHealthScore::where('grade', 'attention')->whereHas('property', fn ($q) => $q->where('agent_id', $user->id))->count(),
            'good'      => PropertyHealthScore::whereIn('grade', ['good', 'excellent'])->whereHas('property', fn ($q) => $q->where('agent_id', $user->id))->count(),
        ];

        // Candidate docs (supervisors only)
        $candidateService = new CandidatePractitionerService();
        $candidateDocs    = collect();
        if ($candidateService->canAuthorise($user)) {
            $candidateDocs = SignatureTemplate::with(['document', 'creator'])
                ->where('is_candidate_flow', true)
                ->whereIn('status', [
                    SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
                    SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL,
                ])
                ->orderBy('created_at', 'desc')
                ->get();
        }

        return view('command-center.performance', [
            'user'                  => $user,
            'period'                => $period,
            'mtdPoints'             => $mtdPoints,
            'monthlyTarget'         => $monthlyTarget,
            'scorecard'             => $scorecard,
            'propsNeedingAttention' => $propsNeedingAttention,
            'propHealthSummary'     => $propHealthSummary,
            'candidateDocs'         => $candidateDocs,
        ]);
    }

    /**
     * Resolve an overdue task: completed, extend, or did_not_happen.
     */
    public function resolveTask(Request $request, CommandTask $task)
    {
        $request->validate([
            'resolution'      => 'required|in:completed,extended,did_not_happen',
            'extend_days'     => 'nullable|integer|min:1|max:90',
            'resolution_note' => 'nullable|string|max:500',
        ]);

        $resolution = $request->resolution;

        if ($resolution === 'completed') {
            $task->update([
                'status'          => CommandTask::STATUS_DONE,
                'completed_at'    => now(),
                'resolution'      => 'completed',
                'resolution_note' => $request->resolution_note,
            ]);
        } elseif ($resolution === 'extended') {
            $days = $request->extend_days ?? 7;
            $task->update([
                'due_date'        => now()->addDays($days),
                'resolution'      => 'extended',
                'resolution_note' => $request->resolution_note ?? "Extended by {$days} day(s)",
                'metadata'        => array_merge($task->metadata ?? [], ['reminder_sent' => null]),
            ]);
            // Clear resolution so it can be resolved again if it goes overdue
            $task->update(['resolution' => null]);
        } elseif ($resolution === 'did_not_happen') {
            $task->update([
                'status'          => CommandTask::STATUS_DISMISSED,
                'resolution'      => 'did_not_happen',
                'resolution_note' => $request->resolution_note ?? 'Did not take place',
            ]);
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Task resolved.');
    }

    /**
     * Resolve an overdue event: completed, extend, or did_not_happen.
     */
    public function resolveEvent(Request $request, CalendarEvent $calendarEvent)
    {
        $request->validate([
            'resolution'      => 'required|in:completed,extended,did_not_happen',
            'extend_days'     => 'nullable|integer|min:1|max:90',
            'resolution_note' => 'nullable|string|max:500',
        ]);

        $resolution = $request->resolution;

        if ($resolution === 'completed') {
            $calendarEvent->update([
                'status'          => 'completed',
                'resolution'      => 'completed',
                'resolution_note' => $request->resolution_note,
            ]);
        } elseif ($resolution === 'extended') {
            $days = $request->extend_days ?? 7;
            $calendarEvent->update([
                'event_date'      => now()->addDays($days),
                'status'          => 'pending',
                'resolution'      => null,
                'resolution_note' => $request->resolution_note ?? "Rescheduled by {$days} day(s)",
                'metadata'        => array_merge($calendarEvent->metadata ?? [], ['reminder_sent' => null]),
            ]);
        } elseif ($resolution === 'did_not_happen') {
            $calendarEvent->update([
                'status'          => 'dismissed',
                'resolution'      => 'did_not_happen',
                'resolution_note' => $request->resolution_note ?? 'Did not take place',
            ]);
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Event resolved.');
    }
}
