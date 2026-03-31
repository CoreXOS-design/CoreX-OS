<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\AgentScorecard;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CommandTask;
use App\Models\CommandCenter\PropertyHealthScore;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\CandidatePractitionerService;
use App\Services\CommandCenter\CalendarEventService;
use App\Services\CommandCenter\PropertyHealthCalculator;
use App\Services\CommandCenter\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommandCenterApiController extends Controller
{
    // ── Dashboard ──────────────────────────────────────────────────

    public function dashboard(Request $request): JsonResponse
    {
        $user   = $request->user();
        $period = now()->format('Y-m');

        // Activity points
        $defIds = DB::table('activity_definitions')
            ->where('is_enabled', 1)->where('scope', 'global')->pluck('id');

        $mtdPoints = (int) DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.user_id', $user->id)->where('e.period', $period)
            ->whereIn('e.activity_definition_id', $defIds)
            ->sum(DB::raw('e.value * d.weight'));

        $monthlyTarget = (int) (DB::table('targets')
            ->where('user_id', $user->id)->where('period', $period)
            ->value('points_target') ?? 0);

        // Calendar
        $calendarService = new CalendarEventService();
        $todayEvents     = $calendarService->getTodayEvents($user);
        $overdueEvents   = $calendarService->getOverdueEvents($user);
        $weekSummary     = $calendarService->getWeekSummary($user);

        // Tasks
        $taskService  = new TaskService();
        $myTasks      = $taskService->getOpenTasks($user, 8);
        $overdueTasks = $taskService->getOverdueTasks($user, 5);
        $taskSummary  = $taskService->getSummary($user);

        // Property health
        $healthCalc = new PropertyHealthCalculator();
        $propsNeedingAttention = $healthCalc->getNeedingAttention($user->id, null, 5);
        $propHealthSummary = [
            'critical'  => PropertyHealthScore::critical()->whereHas('property', fn ($q) => $q->where('agent_id', $user->id))->count(),
            'attention' => PropertyHealthScore::where('grade', 'attention')->whereHas('property', fn ($q) => $q->where('agent_id', $user->id))->count(),
            'good'      => PropertyHealthScore::whereIn('grade', ['good', 'excellent'])->whereHas('property', fn ($q) => $q->where('agent_id', $user->id))->count(),
        ];

        // Scorecard
        $scorecard = AgentScorecard::forUser($user->id)->currentWeek()->first();

        // Overdue popup
        $overduePopupTasks = CommandTask::forUser($user->id)->overdue()->whereNull('resolution')
            ->with(['property', 'contact'])->orderBy('due_date')->limit(20)->get();
        $overduePopupEvents = CalendarEvent::forUser($user->id)->where('status', 'overdue')->whereNull('resolution')
            ->with(['property', 'contact'])->orderBy('event_date')->limit(20)->get();

        return response()->json([
            'user'                => ['id' => $user->id, 'name' => $user->name],
            'mtd_points'          => $mtdPoints,
            'monthly_target'      => $monthlyTarget,
            'today_events'        => $this->formatEvents($todayEvents),
            'overdue_events'      => $this->formatEvents($overdueEvents),
            'week_summary'        => $weekSummary,
            'my_tasks'            => $this->formatTasks($myTasks),
            'overdue_tasks'       => $this->formatTasks($overdueTasks),
            'task_summary'        => $taskSummary,
            'property_health'     => $propsNeedingAttention->map(fn ($h) => [
                'property_id' => $h->property_id,
                'address'     => $h->property?->buildDisplayAddress() ?? '',
                'score'       => $h->score,
                'grade'       => $h->grade,
                'top_issue'   => collect($h->factors ?? [])->filter(fn ($f) => ($f['penalty'] ?? 0) > 0)->pluck('label')->first() ?? '',
                'agent'       => $h->property?->agent?->name ?? 'Unassigned',
            ]),
            'health_summary'      => $propHealthSummary,
            'scorecard'           => $scorecard ? [
                'overall_score'       => $scorecard->overall_score,
                'tasks_completed'     => $scorecard->tasks_completed,
                'tasks_total'         => $scorecard->tasks_total,
                'tasks_overdue'       => $scorecard->tasks_overdue,
                'properties_attended' => $scorecard->properties_attended,
                'properties_total'    => $scorecard->properties_total,
                'events_completed'    => $scorecard->events_completed,
                'events_total'        => $scorecard->events_total,
                'documents_uploaded'  => $scorecard->documents_uploaded,
            ] : null,
            'overdue_popup_tasks'  => $this->formatTasks($overduePopupTasks),
            'overdue_popup_events' => $this->formatEvents($overduePopupEvents),
        ]);
    }

    // ── Calendar ──────────────────────────────────────────────────

    public function calendarIndex(Request $request): JsonResponse
    {
        $user  = $request->user();
        $year  = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);

        $service = new CalendarEventService();
        $grid    = $service->getMonthGrid($user, $year, $month);

        $byDate = [];
        foreach ($grid['byDate'] as $dateKey => $events) {
            $byDate[$dateKey] = $this->formatEvents(collect($events));
        }

        return response()->json([
            'year'   => $year,
            'month'  => $month,
            'events' => $this->formatEvents($grid['events']),
            'by_date' => $byDate,
        ]);
    }

    public function calendarStore(Request $request): JsonResponse
    {
        $request->validate([
            'title'         => 'required|string|max:255',
            'event_date'    => 'required|date',
            'end_date'      => 'nullable|date|after_or_equal:event_date',
            'event_type'    => 'nullable|string|max:50',
            'priority'      => 'nullable|in:low,normal,high,critical',
            'all_day'       => 'nullable|boolean',
            'send_reminder' => 'nullable|boolean',
            'description'   => 'nullable|string',
            'property_id'   => 'nullable|exists:properties,id',
            'contact_id'    => 'nullable|exists:contacts,id',
        ]);

        $data = $request->all();
        $data['send_reminder'] = $request->boolean('send_reminder', true);

        $service = new CalendarEventService();
        $event   = $service->createManual($data, $request->user());

        return response()->json($this->formatEvent($event->load('property')), 201);
    }

    public function calendarComplete(CalendarEvent $calendarEvent): JsonResponse
    {
        $calendarEvent->markCompleted();
        return response()->json(['ok' => true]);
    }

    public function calendarDismiss(CalendarEvent $calendarEvent): JsonResponse
    {
        $calendarEvent->markDismissed();
        return response()->json(['ok' => true]);
    }

    // ── Tasks ─────────────────────────────────────────────────────

    public function tasksIndex(Request $request): JsonResponse
    {
        $user    = $request->user();
        $service = new TaskService();
        $status  = $request->get('status');

        if ($status === 'overdue') {
            $tasks = $service->getOverdueTasks($user, 50);
        } elseif ($status && in_array($status, ['todo', 'in_progress', 'awaiting', 'done'])) {
            $tasks = CommandTask::forUser($user->id)->byStatus($status)
                ->with(['property', 'contact'])
                ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
                ->orderBy('due_date')->limit(50)->get();
        } else {
            $tasks = $service->getOpenTasks($user, 50);
        }

        return response()->json([
            'tasks'   => $this->formatTasks($tasks),
            'summary' => $service->getSummary($user),
        ]);
    }

    public function tasksStore(Request $request): JsonResponse
    {
        $request->validate([
            'title'         => 'required|string|max:255',
            'task_type'     => 'nullable|string|max:50',
            'priority'      => 'nullable|in:low,normal,high,critical',
            'due_date'      => 'nullable|date',
            'send_reminder' => 'nullable|boolean',
            'description'   => 'nullable|string',
            'property_id'   => 'nullable|exists:properties,id',
            'contact_id'    => 'nullable|exists:contacts,id',
        ]);

        $data = $request->all();
        $data['assigned_to']   = $request->user()->id;
        $data['task_type']     = $data['task_type'] ?? 'custom';
        $data['send_reminder'] = $request->boolean('send_reminder', true);

        $service = new TaskService();
        $task    = $service->create($data, $request->user());

        return response()->json($this->formatTask($task->load('property')), 201);
    }

    public function tasksComplete(CommandTask $task): JsonResponse
    {
        $task->markDone();
        return response()->json(['ok' => true]);
    }

    public function tasksUpdateStatus(Request $request, CommandTask $task): JsonResponse
    {
        $request->validate(['status' => 'required|in:todo,in_progress,awaiting,done,dismissed']);

        $service = new TaskService();
        $task    = $service->updateStatus($task, $request->status);

        return response()->json($this->formatTask($task->load('property')));
    }

    // ── Resolve Overdue ───────────────────────────────────────────

    public function resolveTask(Request $request, CommandTask $task): JsonResponse
    {
        $request->validate([
            'resolution'      => 'required|in:completed,extended,did_not_happen',
            'extend_days'     => 'nullable|integer|min:1|max:90',
            'resolution_note' => 'nullable|string|max:500',
        ]);

        $resolution = $request->resolution;

        if ($resolution === 'completed') {
            $task->update([
                'status' => CommandTask::STATUS_DONE, 'completed_at' => now(),
                'resolution' => 'completed', 'resolution_note' => $request->resolution_note,
            ]);
        } elseif ($resolution === 'extended') {
            $days = $request->extend_days ?? 7;
            $task->update([
                'due_date' => now()->addDays($days), 'resolution' => null,
                'resolution_note' => $request->resolution_note ?? "Extended by {$days} day(s)",
                'metadata' => array_merge($task->metadata ?? [], ['reminder_sent' => null]),
            ]);
        } elseif ($resolution === 'did_not_happen') {
            $task->update([
                'status' => CommandTask::STATUS_DISMISSED,
                'resolution' => 'did_not_happen',
                'resolution_note' => $request->resolution_note ?? 'Did not take place',
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function resolveEvent(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        $request->validate([
            'resolution'      => 'required|in:completed,extended,did_not_happen',
            'extend_days'     => 'nullable|integer|min:1|max:90',
            'resolution_note' => 'nullable|string|max:500',
        ]);

        $resolution = $request->resolution;

        if ($resolution === 'completed') {
            $calendarEvent->update([
                'status' => 'completed', 'resolution' => 'completed',
                'resolution_note' => $request->resolution_note,
            ]);
        } elseif ($resolution === 'extended') {
            $days = $request->extend_days ?? 7;
            $calendarEvent->update([
                'event_date' => now()->addDays($days), 'status' => 'pending', 'resolution' => null,
                'resolution_note' => $request->resolution_note ?? "Rescheduled by {$days} day(s)",
                'metadata' => array_merge($calendarEvent->metadata ?? [], ['reminder_sent' => null]),
            ]);
        } elseif ($resolution === 'did_not_happen') {
            $calendarEvent->update([
                'status' => 'dismissed', 'resolution' => 'did_not_happen',
                'resolution_note' => $request->resolution_note ?? 'Did not take place',
            ]);
        }

        return response()->json(['ok' => true]);
    }

    // ── User Settings ─────────────────────────────────────────────

    public function settingsIndex(Request $request): JsonResponse
    {
        $user     = $request->user();
        $settings = UserDashboardSetting::getEffective($user);

        $data = $settings->only([
            'idle_alerts_enabled', 'idle_threshold_days', 'idle_alert_day', 'idle_alert_time',
            'doc_reminders_enabled', 'doc_reminder_hours_before',
            'lease_expiry_reminders', 'lease_reminder_days_before',
            'fica_reminders', 'ffc_reminders',
            'task_due_reminders', 'task_reminder_hours_before', 'event_reminder_hours_before',
            'default_calendar_view', 'weekend_visible', 'working_hours_start', 'working_hours_end',
            'notify_in_app', 'notify_email',
        ]);

        $data['is_agency_controlled'] = $settings->getAttribute('is_agency_controlled') ?? false;

        return response()->json($data);
    }

    public function settingsUpdate(Request $request): JsonResponse
    {
        $user     = $request->user();
        $settings = UserDashboardSetting::getEffective($user);

        if ($settings->getAttribute('is_agency_controlled') ?? false) {
            return response()->json(['error' => 'Dashboard settings are managed by your agency administrator.'], 403);
        }

        $validated = $request->validate([
            'idle_alerts_enabled'         => 'nullable|boolean',
            'idle_threshold_days'         => 'required|integer|min:1|max:365',
            'idle_alert_day'              => 'nullable|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'idle_alert_time'             => 'required|date_format:H:i',
            'doc_reminders_enabled'       => 'nullable|boolean',
            'doc_reminder_hours_before'   => 'required|integer|min:1|max:168',
            'lease_expiry_reminders'      => 'nullable|boolean',
            'lease_reminder_days_before'  => 'required|integer|min:1|max:365',
            'fica_reminders'              => 'nullable|boolean',
            'ffc_reminders'               => 'nullable|boolean',
            'task_due_reminders'          => 'nullable|boolean',
            'task_reminder_hours_before'  => 'required|integer|min:1|max:168',
            'event_reminder_hours_before' => 'required|integer|min:1|max:168',
            'default_calendar_view'       => 'required|in:month,week,day,agenda',
            'weekend_visible'             => 'nullable|boolean',
            'working_hours_start'         => 'required|date_format:H:i',
            'working_hours_end'           => 'required|date_format:H:i',
            'notify_in_app'               => 'nullable|boolean',
            'notify_email'                => 'nullable|boolean',
        ]);

        foreach ([
            'idle_alerts_enabled', 'doc_reminders_enabled', 'lease_expiry_reminders',
            'fica_reminders', 'ffc_reminders', 'task_due_reminders',
            'weekend_visible', 'notify_in_app', 'notify_email',
        ] as $bf) {
            $validated[$bf] = $request->boolean($bf);
        }

        UserDashboardSetting::updateOrCreate(['user_id' => $user->id], $validated);

        return response()->json(['ok' => true, 'message' => 'Dashboard settings saved.']);
    }

    // ── Formatters ────────────────────────────────────────────────

    private function formatEvents($events): array
    {
        return $events->map(fn ($e) => $this->formatEvent($e))->values()->toArray();
    }

    private function formatEvent(CalendarEvent $e): array
    {
        return [
            'id'               => $e->id,
            'title'            => $e->title,
            'event_date'       => $e->event_date?->toIso8601String(),
            'end_date'         => $e->end_date?->toIso8601String(),
            'all_day'          => $e->all_day,
            'colour'           => $e->colour,
            'event_type'       => $e->event_type,
            'category'         => $e->category,
            'priority'         => $e->priority,
            'status'           => $e->status,
            'send_reminder'    => $e->send_reminder,
            'resolution'       => $e->resolution,
            'property_id'      => $e->property_id,
            'property_address' => $e->property?->buildDisplayAddress() ?? null,
            'contact_id'       => $e->contact_id,
        ];
    }

    private function formatTasks($tasks): array
    {
        return $tasks->map(fn ($t) => $this->formatTask($t))->values()->toArray();
    }

    private function formatTask(CommandTask $t): array
    {
        return [
            'id'               => $t->id,
            'title'            => $t->title,
            'task_type'        => $t->task_type,
            'status'           => $t->status,
            'priority'         => $t->priority,
            'send_reminder'    => $t->send_reminder,
            'due_date'         => $t->due_date?->toIso8601String(),
            'started_at'       => $t->started_at?->toIso8601String(),
            'completed_at'     => $t->completed_at?->toIso8601String(),
            'resolution'       => $t->resolution,
            'property_id'      => $t->property_id,
            'property_address' => $t->property?->buildDisplayAddress() ?? null,
            'contact_id'       => $t->contact_id,
            'contact_name'     => $t->contact ? "{$t->contact->first_name} {$t->contact->last_name}" : null,
            'is_overdue'       => $t->isOverdue(),
        ];
    }
}
