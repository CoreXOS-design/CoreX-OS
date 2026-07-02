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
use App\Services\CommandCenter\Calendar\CalendarEventCreator;
use App\Services\CommandCenter\CalendarEventService;
use App\Services\CommandCenter\CommandCentreService;
use App\Services\CommandCenter\PropertyHealthCalculator;
use App\Services\CommandCenter\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommandCenterApiController extends Controller
{
    /**
     * Classes an agent may create manually from the mobile app — mirrors
     * CalendarController::MANUAL_CREATABLE_CLASSES. Surfaced verbatim by
     * calendarOptions() so the app never offers (or POSTs) a class the web
     * cockpit would reject.
     */
    private const MANUAL_CREATABLE_CLASSES = [
        'viewing', 'property_evaluation', 'listing_presentation',
        'meeting', 'task', 'other',
    ];

    // ── Today (full card stream — mirrors web /command-center/today) ──

    public function today(Request $request): JsonResponse
    {
        $user    = $request->user();
        $service = new CommandCentreService();
        $cards   = $service->assembleForUser($user);

        return response()->json([
            'user'  => ['id' => $user->id, 'name' => $user->name, 'role' => $user->effectiveRole()],
            'cards' => $cards,
        ]);
    }

    public function todayRefresh(Request $request): JsonResponse
    {
        // Bust the 5-min cache used by assembleForUser() then return fresh cards
        \Illuminate\Support\Facades\Cache::forget("command_centre_{$request->user()->id}");
        return $this->today($request);
    }

    // ── Dashboard ──────────────────────────────────────────────────

    public function dashboard(Request $request): JsonResponse
    {
        $user   = $request->user();
        $period = now()->format('Y-m');

        // Activity points
        $defIds = DB::table('activity_definitions')
            ->where('is_enabled', 1)->where('scope', 'system')->pluck('id');

        // M6.5 — achievement-total filter.
        $mtdPoints = (int) DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.user_id', $user->id)->where('e.period', $period)
            ->whereIn('e.activity_definition_id', $defIds)
            ->whereIn('e.point_state', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('e.source', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
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

        // Inbox items (overdue tasks + events + candidate docs)
        $overduePopupTasks = CommandTask::forUser($user->id)->overdue()->whereNull('resolution')
            ->with(['property', 'contact'])->orderBy('due_date')->limit(20)->get();
        $overduePopupEvents = CalendarEvent::forUser($user->id)->where('status', 'overdue')->whereNull('resolution')
            ->with(['property', 'contact'])->orderBy('event_date')->limit(20)->get();

        // Candidate documents awaiting authorisation (supervisors only)
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

        $inboxTotal = $overduePopupTasks->count() + $overduePopupEvents->count() + $candidateDocs->count();

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
            // Legacy keys (kept for backwards compatibility; same data as inbox_*)
            'overdue_popup_tasks'  => $this->formatTasks($overduePopupTasks),
            'overdue_popup_events' => $this->formatEvents($overduePopupEvents),
            // Cockpit Inbox payload (preferred for new mobile cockpit)
            'inbox_overdue_tasks'  => $this->formatTasks($overduePopupTasks),
            'inbox_overdue_events' => $this->formatEvents($overduePopupEvents),
            'inbox_candidate_docs' => $candidateDocs->map(fn ($d) => [
                'id'              => $d->id,
                'document_id'     => $d->document_id,
                'document_name'   => $d->document->name ?? 'Untitled Document',
                'creator_name'    => $d->creator->name ?? 'Unknown',
                'status'          => $d->status,
                'review_url'      => route('docuperfect.signatures.review', $d->document_id),
                'created_at'      => $d->created_at?->toIso8601String(),
            ])->values(),
            'inbox_total'          => $inboxTotal,
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

    /**
     * Create a calendar event from the mobile app.
     *
     * Full parity with the web cockpit create: accepts multi-property
     * (property_ids[]) and multi-attendee (attendees[]) payloads and routes
     * them through the shared CalendarEventCreator so property/contact links
     * are filed and agent invitations are sent. The previous implementation
     * validated only singular property_id/contact_id and dropped attendees[]
     * / property_ids[] silently — a 201 with no invitations and no links.
     */
    public function calendarStore(Request $request, CalendarEventCreator $creator): JsonResponse
    {
        // The app historically sends the class under `category`; tolerate
        // `event_type` as the class too so an older build still resolves. The
        // resolved value must be a manually-creatable class — identical
        // whitelist to the web cockpit.
        $category = $request->input('category') ?: $request->input('event_type');
        $request->merge(['category' => $category]);

        $data = $request->validate([
            'title'             => 'required|string|max:255',
            'category'          => 'required|string|in:' . implode(',', self::MANUAL_CREATABLE_CLASSES),
            'event_date'        => 'required|date',
            'end_date'          => 'nullable|date|after_or_equal:event_date',
            'description'       => 'nullable|string|max:2000',
            'priority'          => 'nullable|in:low,normal,high,critical',
            'all_day'           => 'nullable|boolean',
            'send_reminder'     => 'nullable|boolean',
            'event_type'        => 'nullable|string|max:50',
            'property_id'       => 'nullable|integer|exists:properties,id',
            'property_ids'      => 'nullable|array',
            'property_ids.*'    => 'integer|exists:properties,id',
            'contact_id'        => 'nullable|integer|exists:contacts,id',
            'contact_ids'       => 'nullable|array',
            'contact_ids.*'     => 'integer|exists:contacts,id',
            'attendees'         => 'nullable|array',
            'attendees.*.id'    => 'required_with:attendees|integer',
            'attendees.*.type'  => 'required_with:attendees|string|in:contact,agent',
            'attendees.*.role'  => 'nullable|string|in:attendee,buyer_contact,seller_contact,agent_contact',
            'deal_id'           => 'nullable|integer',
        ]);

        $event = $creator->create($data, $request->user());

        return response()->json($this->formatEvent($event->fresh()->load(['property', 'contact'])), 201);
    }

    /**
     * Form options for the mobile calendar create screen — the manually-
     * creatable classes (with labels + multi-property capability) and the
     * priority enum. No web equivalent existed; the app needs this to build a
     * create form that only ever POSTs values the cockpit accepts.
     */
    public function calendarOptions(Request $request): JsonResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $settings = \App\Models\CommandCenter\CalendarEventClassSetting::withoutGlobalScopes()
            ->whereIn('event_class', self::MANUAL_CREATABLE_CLASSES)
            ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereNull('agency_id'))
            ->orderByRaw('agency_id IS NULL')
            ->get()
            ->keyBy('event_class');

        $categories = collect(self::MANUAL_CREATABLE_CLASSES)->map(function ($class) use ($settings) {
            $cfg = $settings->get($class);
            return [
                'value'                     => $class,
                'label'                     => $cfg?->label ?? ucwords(str_replace('_', ' ', $class)),
                'allow_multiple_properties' => (bool) ($cfg?->allow_multiple_properties ?? false),
                'actor_role'                => $cfg?->actor_role ?? 'both',
            ];
        })->values();

        // Newer Flutter builds read `classes` (identical to `categories` but
        // with the class value under `event_class`, plus `completion_behaviour`
        // so the app can decide whether completing an event requires feedback
        // capture). `categories` is retained verbatim above for older builds
        // still on the pre-alignment contract — do NOT remove it.
        $classes = collect(self::MANUAL_CREATABLE_CLASSES)->map(function ($class) use ($settings) {
            $cfg = $settings->get($class);
            return [
                'event_class'               => $class,
                'label'                     => $cfg?->label ?? ucwords(str_replace('_', ' ', $class)),
                'allow_multiple_properties' => (bool) ($cfg?->allow_multiple_properties ?? false),
                'actor_role'                => $cfg?->actor_role ?? 'both',
                'completion_behaviour'      => $cfg?->completion_behaviour,
            ];
        })->values();

        return response()->json([
            'categories'     => $categories,
            'classes'        => $classes,
            'priorities'     => ['low', 'normal', 'high', 'critical'],
            // Attendee-role picker options. Keys are the create validator's
            // attendee role enum MINUS agent_contact — that role is reserved
            // for agents, which the backend auto-assigns (attendees[].type =
            // agent → agent_contact link), so it must never be a user-pickable
            // option for a contact attendee.
            'attendee_roles' => [
                ['key' => 'attendee',        'label' => 'Attendee'],
                ['key' => 'buyer_contact',   'label' => 'Buyer'],
                ['key' => 'seller_contact',  'label' => 'Seller'],
            ],
        ]);
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

    public function calendarUpdate(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        $data = $request->validate([
            'title'         => 'sometimes|required|string|max:255',
            'event_date'    => 'sometimes|required|date',
            'end_date'      => 'nullable|date|after_or_equal:event_date',
            'description'   => 'nullable|string|max:2000',
            'priority'      => 'nullable|in:low,normal,high,critical',
            'status'        => 'nullable|in:pending,completed,overdue,dismissed',
            'category'      => 'nullable|string|max:50',
            'property_id'   => 'nullable|integer|exists:properties,id',
            'contact_id'    => 'nullable|integer|exists:contacts,id',
        ]);

        $oldValues = $calendarEvent->only(['title', 'event_date', 'end_date', 'description', 'category', 'priority', 'status', 'property_id', 'contact_id']);

        $calendarEvent->update(collect($data)->only([
            'title', 'event_date', 'end_date', 'description', 'category', 'priority', 'status', 'property_id', 'contact_id',
        ])->all());

        $newValues = $calendarEvent->fresh()->only(array_keys($oldValues));
        $changed   = array_filter($newValues, fn ($v, $k) => ($oldValues[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH);

        if (!empty($changed)) {
            \App\Models\CommandCenter\CalendarEventAuditEntry::create([
                'calendar_event_id'    => $calendarEvent->id,
                'action'               => 'updated',
                'old_values'           => array_intersect_key($oldValues, $changed),
                'new_values'           => $changed,
                'performed_by_user_id' => $request->user()->id,
                'performed_at'         => now(),
                'notes'                => 'Edited via mobile API',
            ]);
        }

        // Re-notify accepted attendees on time change
        if (array_key_exists('event_date', $changed) || array_key_exists('end_date', $changed)) {
            $invitations = \App\Models\CommandCenter\CalendarEventInvitation::where('event_id', $calendarEvent->id)
                ->whereIn('status', ['accepted', 'tentative'])->get();
            foreach ($invitations as $inv) {
                DB::table('notifications')->insert([
                    'id'              => \Illuminate\Support\Str::uuid(),
                    'type'            => 'event_time_changed',
                    'notifiable_type' => 'App\\Models\\User',
                    'notifiable_id'   => $inv->invitee_user_id,
                    'data'            => json_encode([
                        'message'   => 'Time changed: ' . $calendarEvent->title . ' is now ' . $calendarEvent->fresh()->event_date->format('D d M, H:i'),
                        'event_id'  => $calendarEvent->id,
                        'old_start' => $oldValues['event_date'] ?? null,
                        'new_start' => $changed['event_date'] ?? null,
                    ]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return response()->json($this->formatEvent($calendarEvent->fresh()->load(['property', 'contact'])));
    }

    public function calendarDestroy(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        // Cancel cascade — notify attendees + cancel invitations (parity with web destroy)
        $invitations = \App\Models\CommandCenter\CalendarEventInvitation::where('event_id', $calendarEvent->id)
            ->whereIn('status', ['pending', 'accepted', 'tentative'])->get();
        foreach ($invitations as $inv) {
            $inv->update(['status' => 'cancelled']);
            DB::table('notifications')->insert([
                'id'              => \Illuminate\Support\Str::uuid(),
                'type'            => 'event_cancelled',
                'notifiable_type' => 'App\\Models\\User',
                'notifiable_id'   => $inv->invitee_user_id,
                'data'            => json_encode([
                    'message'      => 'Event cancelled: ' . $calendarEvent->title,
                    'event_id'     => $calendarEvent->id,
                    'cancelled_by' => $request->user()->name ?? 'Unknown',
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $calendarEvent->delete();
        return response()->json(['ok' => true]);
    }

    public function calendarConflicts(Request $request): JsonResponse
    {
        $request->validate([
            'start'            => 'required|date',
            'end'              => 'required|date|after_or_equal:start',
            'exclude_event_id' => 'nullable|integer',
        ]);
        $svc = app(\App\Services\CommandCenter\Calendar\ConflictDetectionService::class);
        return response()->json($svc->checkUserConflicts(
            (int) $request->user()->id,
            $request->get('start'),
            $request->get('end'),
            $request->get('exclude_event_id')
        ));
    }

    // ── Calendar Invitations ──────────────────────────────────────

    public function invitationsIndex(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $invitations = \App\Models\CommandCenter\CalendarEventInvitation::forUser($userId)
            ->with(['event' => fn ($q) => $q->withoutGlobalScopes(), 'inviter'])
            ->whereIn('status', ['pending', 'tentative'])
            ->orderByDesc('created_at')->limit(50)->get();

        $conflictSvc = app(\App\Services\CommandCenter\Calendar\ConflictDetectionService::class);

        $payload = $invitations->map(function ($inv) use ($userId, $conflictSvc) {
            $conflicts = [];
            if ($inv->event && $inv->event->event_date && $inv->event->end_date) {
                try {
                    $conflicts = $conflictSvc->checkUserConflicts(
                        $userId,
                        $inv->event->event_date->toIso8601String(),
                        $inv->event->end_date->toIso8601String(),
                        $inv->event_id
                    );
                } catch (\Throwable $e) {}
            }
            return [
                'id'             => $inv->id,
                'event_id'       => $inv->event_id,
                'status'         => $inv->status,
                'inviter_name'   => $inv->inviter?->name,
                'created_at'     => $inv->created_at?->toIso8601String(),
                'response_at'    => $inv->response_at?->toIso8601String(),
                'acknowledged_at'=> $inv->acknowledged_at?->toIso8601String(),
                'event'          => $inv->event ? $this->formatEvent($inv->event) : null,
                'live_conflicts' => $conflicts,
            ];
        })->values();

        return response()->json(['invitations' => $payload]);
    }

    public function invitationRespond(Request $request, \App\Models\CommandCenter\CalendarEventInvitation $invitation): JsonResponse
    {
        if ((int) $invitation->invitee_user_id !== (int) $request->user()->id) {
            abort(403);
        }
        $data = $request->validate([
            'action' => 'required|in:accepted,tentative,declined',
            'notes'  => 'nullable|string|max:500',
        ]);
        $invitation->update([
            'status'         => $data['action'],
            'response_at'    => now(),
            'response_notes' => $data['notes'] ?? null,
        ]);
        DB::table('notifications')->insert([
            'id'              => \Illuminate\Support\Str::uuid(),
            'type'            => 'invitation_response',
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id'   => $invitation->inviter_user_id,
            'data'            => json_encode([
                'message'  => $request->user()->name . ' ' . $data['action'] . ': ' . ($invitation->event?->title ?? 'Event'),
                'event_id' => $invitation->event_id,
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['ok' => true, 'invitation_id' => $invitation->id, 'status' => $invitation->fresh()->status]);
    }

    public function invitationAcknowledge(Request $request, \App\Models\CommandCenter\CalendarEventInvitation $invitation): JsonResponse
    {
        $event = $invitation->event;
        if (!$event) abort(404);
        $user = $request->user();
        if ((int) $event->user_id !== (int) $user->id && !in_array($user->role, ['super_admin', 'owner'])) {
            abort(403);
        }
        $invitation->update(['acknowledged_at' => now()]);
        return response()->json([
            'ok' => true,
            'invitation_id'   => $invitation->id,
            'acknowledged_at' => $invitation->fresh()->acknowledged_at->toIso8601String(),
        ]);
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

    public function tasksUpdate(Request $request, CommandTask $task): JsonResponse
    {
        $data = $request->validate([
            'title'         => 'sometimes|required|string|max:255',
            'task_type'     => 'nullable|string|max:50',
            'priority'      => 'nullable|in:low,normal,high,critical',
            'status'        => 'nullable|in:todo,in_progress,awaiting,done,dismissed',
            'due_date'      => 'nullable|date',
            'send_reminder' => 'nullable|boolean',
            'description'   => 'nullable|string',
            'property_id'   => 'nullable|exists:properties,id',
            'contact_id'    => 'nullable|exists:contacts,id',
            'deal_id'       => 'nullable|integer',
            'assigned_to'   => 'nullable|integer|exists:users,id',
        ]);

        $task->update(collect($data)->only([
            'title', 'task_type', 'priority', 'status', 'due_date', 'send_reminder',
            'description', 'property_id', 'contact_id', 'deal_id', 'assigned_to',
        ])->all());

        return response()->json($this->formatTask($task->fresh()->load(['property', 'contact'])));
    }

    /**
     * Archive a single task (soft-delete).
     */
    public function tasksDestroy(CommandTask $task): JsonResponse
    {
        $task->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * Archive all Done tasks for the user (bulk).
     */
    public function tasksArchiveDone(Request $request): JsonResponse
    {
        $tasks = CommandTask::forUser($request->user()->id)
            ->where('status', CommandTask::STATUS_DONE)
            ->get();

        $count = $tasks->count();
        $tasks->each(fn ($t) => $t->delete());

        return response()->json(['ok' => true, 'archived' => $count]);
    }

    /**
     * List archived (soft-deleted) tasks for the user, grouped by the day archived.
     */
    public function tasksArchived(Request $request): JsonResponse
    {
        $tasks = CommandTask::onlyTrashed()
            ->where('assigned_to', $request->user()->id)
            ->with(['property', 'contact'])
            ->orderByDesc('deleted_at')
            ->get();

        $groups = $tasks->groupBy(fn ($t) => optional($t->deleted_at)->toDateString())
            ->map(fn ($day, $date) => [
                'date'  => $date,
                'tasks' => $this->formatTasks($day),
            ])
            ->values();

        return response()->json([
            'total'  => $tasks->count(),
            'groups' => $groups,
        ]);
    }

    /**
     * Restore a soft-deleted task back to the Done column.
     */
    public function tasksRestore(int $taskId): JsonResponse
    {
        $task = CommandTask::onlyTrashed()->findOrFail($taskId);
        $task->restore();
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
            'auto_archive_done_days',
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
            'auto_archive_done_days'      => 'nullable|integer|min:0|max:365',
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

        if (array_key_exists('auto_archive_done_days', $validated) && $validated['auto_archive_done_days'] === '') {
            $validated['auto_archive_done_days'] = null;
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
            'contact_name'     => $e->contact ? trim("{$e->contact->first_name} {$e->contact->last_name}") : null,
            'pillar_tag'       => $e->pillarTag(),
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
            'deleted_at'       => $t->deleted_at?->toIso8601String(),
            'resolution'       => $t->resolution,
            'property_id'      => $t->property_id,
            'property_address' => $t->property?->buildDisplayAddress() ?? null,
            'contact_id'       => $t->contact_id,
            'contact_name'     => $t->contact ? "{$t->contact->first_name} {$t->contact->last_name}" : null,
            'deal_id'          => $t->deal_id,
            'pillar_tag'       => $t->pillarTag(),
            'is_overdue'       => $t->isOverdue(),
        ];
    }
}
