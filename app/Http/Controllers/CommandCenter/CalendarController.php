<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarEventLink;
use App\Models\Contact;
use App\Models\Property;
use App\Services\CommandCenter\Calendar\CalendarThresholdResolver;
use App\Services\CommandCenter\Calendar\CalendarVisibilityResolver;
use App\Services\CommandCenter\CalendarEventService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CalendarController extends Controller
{
    /** Classes that users may create manually (not system-driven). */
    private const MANUAL_CREATABLE_CLASSES = [
        'viewing', 'property_evaluation', 'listing_presentation',
        'meeting', 'task', 'other',
    ];

    public function __construct(
        private CalendarEventService $service,
        private CalendarThresholdResolver $thresholdResolver,
        private CalendarVisibilityResolver $visibilityResolver,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $view = $request->get('view', 'month');

        // Filter params (shared across all views)
        $typeFilter     = $request->input('types', []);
        $categoryFilter = $request->input('categories', []);
        $scope          = $request->input('scope', 'all');

        $shared = $this->sharedViewData($user, $view, $typeFilter, $categoryFilter, $scope);
        $shared['autoOpenFeedbackEventId'] = $request->input('capture_feedback');

        // ── Week view ──
        if ($view === 'week') {
            return $this->renderWeek($request, $user, $shared, $typeFilter, $categoryFilter, $scope);
        }

        // ── Day view ──
        if ($view === 'day') {
            return $this->renderDay($request, $user, $shared, $typeFilter, $categoryFilter, $scope);
        }

        // ── Month + Agenda (existing) ──
        return $this->renderMonthAgenda($request, $user, $shared, $view, $typeFilter, $categoryFilter, $scope);
    }

    // ── View renderers ──

    private function renderWeek(Request $request, $user, array $shared, array $typeFilter, array $categoryFilter, string $scope)
    {
        $anchor = $this->anchorDate($request);
        $weekStart = $anchor->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd   = $weekStart->copy()->addDays(6)->endOfDay();

        $raw = $this->service->getEventsForRange($user, $weekStart->toDateString(), $weekEnd->toDateString(), [], $scope);
        $filtered = $this->applyFilters($raw, $user, $typeFilter, $categoryFilter, $scope);

        $weekDays = collect();
        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->copy()->addDays($i);
            $weekDays->push([
                'date'     => $day,
                'is_today' => $day->isSameDay(Carbon::today()),
                'events'   => $filtered->filter(function ($e) use ($day) {
                    $start = $e->event_date->copy()->startOfDay();
                    $end = $e->end_date ? $e->end_date->copy()->startOfDay() : $start;
                    return $day->between($start, $end);
                })->values(),
            ]);
        }

        return view('command-center.calendar.index', $shared + [
            'weekStart'   => $weekStart,
            'weekEnd'     => $weekEnd,
            'weekDays'    => $weekDays,
            'anchorDate'  => $anchor,
            'prevAnchor'  => $weekStart->copy()->subWeek()->toDateString(),
            'nextAnchor'  => $weekStart->copy()->addWeek()->toDateString(),
        ]);
    }

    private function renderDay(Request $request, $user, array $shared, array $typeFilter, array $categoryFilter, string $scope)
    {
        $anchor = $this->anchorDate($request);
        $dayStart = $anchor->copy()->startOfDay();
        $dayEnd   = $anchor->copy()->endOfDay();

        $raw = $this->service->getEventsForRange($user, $dayStart->toDateTimeString(), $dayEnd->toDateTimeString(), [], $scope);
        $dayEvents = $this->applyFilters($raw, $user, $typeFilter, $categoryFilter, $scope)
            ->sortBy('event_date')
            ->values();

        return view('command-center.calendar.index', $shared + [
            'dayEvents'   => $dayEvents,
            'anchorDate'  => $anchor,
            'prevAnchor'  => $anchor->copy()->subDay()->toDateString(),
            'nextAnchor'  => $anchor->copy()->addDay()->toDateString(),
        ]);
    }

    private function renderMonthAgenda(Request $request, $user, array $shared, string $view, array $typeFilter, array $categoryFilter, string $scope)
    {
        // Canonical ?date= param takes priority, derive year/month from it
        if ($request->filled('date')) {
            try {
                $anchor = Carbon::parse($request->input('date'))->startOfDay();
                $year  = $anchor->year;
                $month = $anchor->month;
            } catch (\Throwable $e) {
                $year  = (int) $request->get('year', now()->year);
                $month = (int) $request->get('month', now()->month);
            }
        } else {
            $year  = (int) $request->get('year', now()->year);
            $month = (int) $request->get('month', now()->month);
        }
        $range = $request->get('range', 'month');

        $grid = $this->service->getMonthGrid($user, $year, $month, [], $scope);

        $filteredByDate = [];
        foreach ($grid['byDate'] as $dateKey => $dayEvents) {
            $resolved = $this->applyFilters(collect($dayEvents), $user, $typeFilter, $categoryFilter, $scope);
            if ($resolved->isNotEmpty()) {
                $filteredByDate[$dateKey] = $resolved->all();
            }
        }
        $filteredEvents = collect($filteredByDate)->flatten(1);

        // Filter spanning bars (multi-day events) through same filter logic
        $filteredSpanningBars = [];
        foreach ($grid['spanningBars'] ?? [] as $bar) {
            $filtered = $this->applyFilters(collect([$bar['event']]), $user, $typeFilter, $categoryFilter, $scope);
            if ($filtered->isNotEmpty()) {
                $bar['event'] = $filtered->first();
                $filteredSpanningBars[] = $bar;
            }
        }

        // Agenda range logic
        $rangeGroups = [
            'Current'  => ['month' => 'This month', 'year' => 'This year'],
            'Past'     => ['last30' => 'Last 30 days', 'last3months' => 'Last 3 months', 'last6months' => 'Last 6 months', 'lastyear' => 'Last year', 'allpast' => 'All past events'],
            'Upcoming' => ['next30' => 'Next 30 days', '3months' => 'Next 3 months', '6months' => 'Next 6 months', 'allupcoming' => 'All upcoming'],
            'Custom'   => ['custom' => 'Custom range'],
        ];
        $rangeFlat = [];
        foreach ($rangeGroups as $group) { $rangeFlat += $group; }
        if (!array_key_exists($range, $rangeFlat)) { $range = 'month'; }

        $base  = Carbon::create($year, $month, 1)->startOfMonth();
        $today = now()->startOfDay();
        $parseDate = function ($v, Carbon $fb): Carbon { if (!$v) return $fb; try { return Carbon::parse($v); } catch (\Throwable $e) { return $fb; } };

        switch ($range) {
            case 'last30':       $rangeStart = $today->copy()->subDays(30); $rangeEnd = $today->copy()->endOfDay(); break;
            case 'last3months':  $rangeStart = $base->copy()->subMonthsNoOverflow(3)->startOfMonth(); $rangeEnd = $base->copy()->endOfMonth(); break;
            case 'last6months':  $rangeStart = $base->copy()->subMonthsNoOverflow(6)->startOfMonth(); $rangeEnd = $base->copy()->endOfMonth(); break;
            case 'lastyear':     $rangeStart = $today->copy()->subYearNoOverflow(); $rangeEnd = $today->copy()->endOfDay(); break;
            case 'allpast':      $rangeStart = Carbon::create(2000, 1, 1)->startOfDay(); $rangeEnd = $today->copy()->endOfDay(); break;
            case 'next30':       $rangeStart = $today->copy(); $rangeEnd = $today->copy()->addDays(30)->endOfDay(); break;
            case '3months':      $rangeStart = $base->copy()->startOfMonth(); $rangeEnd = $base->copy()->addMonthsNoOverflow(3)->endOfMonth(); break;
            case '6months':      $rangeStart = $base->copy()->startOfMonth(); $rangeEnd = $base->copy()->addMonthsNoOverflow(6)->endOfMonth(); break;
            case 'year':         $rangeStart = $base->copy()->startOfYear(); $rangeEnd = $base->copy()->endOfYear(); break;
            case 'allupcoming':  $rangeStart = $today->copy(); $rangeEnd = $today->copy()->addYearsNoOverflow(5)->endOfDay(); break;
            case 'custom':
                $rangeStart = $parseDate($request->get('from'), $base->copy()->startOfMonth())->startOfDay();
                $rangeEnd   = $parseDate($request->get('to'),   $base->copy()->endOfMonth())->endOfDay();
                if ($rangeEnd->lt($rangeStart)) { [$rangeStart, $rangeEnd] = [$rangeEnd->copy()->startOfDay(), $rangeStart->copy()->endOfDay()]; }
                break;
            default: $range = 'month'; $rangeStart = $base->copy()->startOfMonth(); $rangeEnd = $base->copy()->endOfMonth(); break;
        }

        $agendaEvents = $this->applyFilters(
            $this->service->getEventsForRange($user, $rangeStart->toDateString(), $rangeEnd->toDateString(), [], $scope),
            $user, $typeFilter, $categoryFilter, $scope
        );

        $prevMonth = $base->copy()->subMonth();
        $nextMonth = $base->copy()->addMonth();

        // Build colour metadata for front-end color-by switching
        $allVisibleEvents = $filteredEvents->merge(
            collect($filteredSpanningBars)->pluck('event')
        );
        $colourMap = $this->buildColourMap($allVisibleEvents);
        $colourPalettes = $this->buildColourPalettes($allVisibleEvents);

        // Build labels for legend
        $classLabels = [];
        foreach ($this->sharedViewData($user, $view, $typeFilter, $categoryFilter, $scope)['availableCategories'] as $cat) {
            $classLabels[$cat->event_class] = $cat->label;
        }
        $branchLabels = \App\Models\Branch::withoutGlobalScopes()
            ->whereIn('id', $allVisibleEvents->pluck('branch_id')->unique()->filter())
            ->pluck('name', 'id')->toArray();
        $agentLabels = \App\Models\User::withoutGlobalScopes()
            ->whereIn('id', $allVisibleEvents->pluck('user_id')->unique()->filter())
            ->pluck('name', 'id')->toArray();

        return view('command-center.calendar.index', $shared + [
            'year'             => $year,
            'month'            => $month,
            'anchorDate'       => Carbon::create($year, $month, 1)->startOfDay(),
            'grid'             => $grid,
            'events'           => $filteredEvents,
            'byDate'           => $filteredByDate,
            'spanningBars'     => $filteredSpanningBars,
            'colourMap'        => $colourMap,
            'colourPalettes'   => $colourPalettes,
            'classLabels'      => $classLabels,
            'branchLabels'     => $branchLabels,
            'agentLabels'      => $agentLabels,
            'agendaEvents'     => $agendaEvents,
            'agendaRange'      => $range,
            'agendaRangeLabel' => $rangeFlat[$range],
            'agendaFrom'       => $rangeStart->toDateString(),
            'agendaTo'         => $rangeEnd->toDateString(),
            'rangeGroups'      => $rangeGroups,
            'prevMonth'        => $prevMonth,
            'nextMonth'        => $nextMonth,
        ]);
    }

    // ── Shared data ──

    private function sharedViewData($user, string $view, array $typeFilter, array $categoryFilter, string $scope): array
    {
        return [
            'user'                => $user,
            'currentView'         => $view,
            'typeFilter'          => $typeFilter,
            'categoryFilter'      => $categoryFilter,
            'scope'               => $scope,
            'availableTypes'      => ['compliance', 'deal', 'document', 'lease', 'leave', 'payroll', 'people', 'property', 'recurring'],
            'availableCategories' => CalendarEventClassSetting::withoutGlobalScopes()
                ->where('is_active', true)->orderBy('label')
                ->get(['event_class', 'label'])->unique('event_class')->values(),
            'manualCreatableClasses' => CalendarEventClassSetting::withoutGlobalScopes()
                ->whereNull('agency_id')
                ->where('is_active', true)
                ->whereIn('event_class', self::MANUAL_CREATABLE_CLASSES)
                ->orderBy('label')
                ->get(['event_class', 'label']),
        ];
    }

    private function anchorDate(Request $request): Carbon
    {
        return $request->filled('date')
            ? Carbon::parse($request->input('date'))->startOfDay()
            : Carbon::today();
    }

    // ── AJAX + CRUD (unchanged) ──

    public function events(Request $request)
    {
        $user = $request->user();
        $start = $request->get('start', now()->startOfMonth()->toDateString());
        $end   = $request->get('end', now()->endOfMonth()->toDateString());
        $filters = $request->only(['event_type', 'status', 'property_id']);

        $scope = $request->input('scope', 'all');
        $resolved = $this->applyFilters(
            $this->service->getEventsForRange($user, $start, $end, $filters, $scope),
            $user,
            $request->input('types', []),
            $request->input('categories', []),
            $scope,
        );

        return response()->json($resolved->map(fn (CalendarEvent $e) => [
            'id' => $e->id, 'title' => $e->title,
            'start' => $e->event_date->toIso8601String(),
            'end' => $e->end_date?->toIso8601String(),
            'allDay' => $e->all_day, 'colour' => $e->resolved_colour,
            'type' => $e->event_type, 'category' => $e->category,
            'priority' => $e->priority, 'status' => $e->status,
            'propertyId' => $e->property_id, 'contactId' => $e->contact_id,
        ])->values());
    }

    public function show(Request $request, CalendarEvent $calendarEvent)
    {
        $user = $request->user();
        if (!$this->visibilityResolver->canSee($calendarEvent, $user)) { abort(403); }

        $colour = $this->thresholdResolver->resolveForEvent($calendarEvent);
        $cfg = CalendarEventClassSetting::forAgencyAndClass($calendarEvent->agency_id, $calendarEvent->category);

        $isManual = in_array($calendarEvent->source_type, ['manual', 'manual:demo']);

        return response()->json([
            'id' => $calendarEvent->id, 'title' => $calendarEvent->title,
            'description' => $calendarEvent->description,
            'event_date' => $calendarEvent->event_date->toIso8601String(),
            'end_date' => $calendarEvent->end_date?->toIso8601String(),
            'event_date_h' => $calendarEvent->event_date->format('D, d M Y'),
            'days_diff' => (int) now()->startOfDay()->diffInDays($calendarEvent->event_date->copy()->startOfDay(), false),
            'colour' => $colour, 'category' => $calendarEvent->category,
            'class_label' => $cfg?->label ?? $calendarEvent->category,
            'event_type' => $calendarEvent->event_type, 'status' => $calendarEvent->status,
            'source_type' => $calendarEvent->source_type,
            'source_link' => $this->resolveSourceLink($calendarEvent),
            'metadata' => $calendarEvent->metadata,
            'is_past' => $calendarEvent->event_date->isPast(),
            'has_contacts' => $calendarEvent->linkedContacts()->exists(),
            'is_editable' => $isManual,
            'is_actionable' => ($cfg->event_nature ?? 'actionable') === 'actionable',
            'is_draggable' => $isManual,
            'linked_property' => $isManual && $calendarEvent->property_id ? [
                'id' => $calendarEvent->property_id,
                'address' => $calendarEvent->property?->address ?? ('Property #' . $calendarEvent->property_id),
            ] : null,
            'attendees' => $isManual ? $calendarEvent->links()
                ->where('role', 'attendee')
                ->get()
                ->map(fn ($l) => [
                    'id'   => $l->linkable_id,
                    'type' => $l->linkable_type === \App\Models\User::class ? 'agent' : 'contact',
                    'name' => $l->linkable_type === \App\Models\User::class
                        ? optional(\App\Models\User::find($l->linkable_id))->name
                        : optional(\App\Models\Contact::withoutGlobalScopes()->find($l->linkable_id), fn ($c) => trim($c->first_name . ' ' . $c->last_name)) ?? ('Contact #' . $l->linkable_id),
                ]) : [],
            'audit_log' => $calendarEvent->auditEntries()
                ->orderBy('performed_at', 'desc')
                ->limit(10)
                ->get()
                ->map(fn ($a) => [
                    'action' => $a->action,
                    'old'    => $a->old_values,
                    'new'    => $a->new_values,
                    'when'   => $a->performed_at->format('j M Y, H:i'),
                    'by'     => optional($a->performer)->name,
                ]),
        ]);
    }

    public function showFeedback(Request $request, CalendarEvent $calendarEvent)
    {
        $user = $request->user();
        if (!$this->visibilityResolver->canSee($calendarEvent, $user)) {
            abort(403);
        }

        $contacts = $calendarEvent->linkedContacts;
        $existing = \App\Models\CommandCenter\CalendarEventFeedback::query()
            ->where('calendar_event_id', $calendarEvent->id)
            ->get()
            ->keyBy('contact_id');

        $agencyId = $calendarEvent->agency_id;

        $outcomes = \App\Models\CommandCenter\AgencyFeedbackOption::withoutGlobalScopes()
            ->where('category', 'outcome')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('agency_id')->orWhere('agency_id', $agencyId))
            ->orderBy('sort_order')
            ->get(['id', 'label']);

        $concerns = \App\Models\CommandCenter\AgencyFeedbackOption::withoutGlobalScopes()
            ->where('category', 'concern')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('agency_id')->orWhere('agency_id', $agencyId))
            ->orderBy('sort_order')
            ->get(['id', 'label']);

        return response()->json([
            'event' => [
                'id'    => $calendarEvent->id,
                'title' => $calendarEvent->title,
                'date'  => $calendarEvent->event_date->format('D, j M Y H:i'),
            ],
            'contacts' => $contacts->map(fn ($c) => [
                'id'             => $c->id,
                'label'          => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: ('Contact #' . $c->id),
                'feedback_id'    => optional($existing->get($c->id))->id,
                'outcome_id'     => optional($existing->get($c->id))->outcome_option_id,
                'concerns'       => optional($existing->get($c->id))->concern_option_ids ?? [],
                'seller_notes'   => optional($existing->get($c->id))->seller_visible_notes,
                'internal_notes' => optional($existing->get($c->id))->internal_notes,
                'next_action'    => optional($existing->get($c->id))->next_action_notes,
            ]),
            'outcomes' => $outcomes,
            'concerns' => $concerns,
        ]);
    }

    public function storeFeedback(Request $request, CalendarEvent $calendarEvent)
    {
        $user = $request->user();
        if (!$this->visibilityResolver->canSee($calendarEvent, $user)) {
            abort(403);
        }

        $data = $request->validate([
            'feedback'                        => 'required|array',
            'feedback.*.contact_id'           => 'required|integer|exists:contacts,id',
            'feedback.*.outcome_id'           => 'nullable|integer|exists:agency_feedback_options,id',
            'feedback.*.concern_ids'          => 'nullable|array',
            'feedback.*.concern_ids.*'        => 'integer|exists:agency_feedback_options,id',
            'feedback.*.seller_visible_notes' => 'nullable|string|max:5000',
            'feedback.*.internal_notes'       => 'nullable|string|max:5000',
            'feedback.*.next_action_notes'    => 'nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($data, $calendarEvent, $user) {
            foreach ($data['feedback'] as $row) {
                \App\Models\CommandCenter\CalendarEventFeedback::updateOrCreate(
                    [
                        'calendar_event_id' => $calendarEvent->id,
                        'contact_id'        => $row['contact_id'],
                    ],
                    [
                        'outcome_option_id'    => $row['outcome_id'] ?? null,
                        'concern_option_ids'   => $row['concern_ids'] ?? [],
                        'seller_visible_notes' => $row['seller_visible_notes'] ?? null,
                        'internal_notes'       => $row['internal_notes'] ?? null,
                        'next_action_notes'    => $row['next_action_notes'] ?? null,
                        'captured_by_user_id'  => $user->id,
                        'captured_at'          => now(),
                        'agency_id'            => $calendarEvent->agency_id,
                        'branch_id'            => $calendarEvent->branch_id,
                    ]
                );
            }

            \App\Models\CommandCenter\CalendarEventAuditEntry::create([
                'calendar_event_id'    => $calendarEvent->id,
                'action'               => 'feedback_captured',
                'new_values'           => ['contact_count' => count($data['feedback'])],
                'performed_by_user_id' => $user->id,
                'performed_at'         => now(),
            ]);

            // Close any open missed-feedback tasks for this event
            \App\Models\CommandCenter\CommandTask::query()
                ->where('source_type', 'calendar:missed_feedback')
                ->where('calendar_event_id', $calendarEvent->id)
                ->whereIn('status', ['todo', 'in_progress', 'awaiting'])
                ->update([
                    'status'       => 'done',
                    'completed_at' => now(),
                ]);

            if ($calendarEvent->status !== 'completed') {
                $calendarEvent->update(['status' => 'completed']);
            }
        });

        return response()->json(['success' => true]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'title'             => 'required|string|max:255',
            'category'          => 'required|string|in:' . implode(',', self::MANUAL_CREATABLE_CLASSES),
            'event_date'        => 'required|date',
            'end_date'          => 'nullable|date|after_or_equal:event_date',
            'description'       => 'nullable|string|max:2000',
            'property_id'       => 'nullable|integer|exists:properties,id',
            'contact_ids'       => 'nullable|array',
            'contact_ids.*'     => 'integer|exists:contacts,id',
            'attendees'         => 'nullable|array',
            'attendees.*.id'    => 'required_with:attendees|integer',
            'attendees.*.type'  => 'required_with:attendees|string|in:contact,agent',
            'deal_id'           => 'nullable|integer',
        ]);

        $event = DB::transaction(function () use ($data, $user) {
            $event = CalendarEvent::create([
                'event_type'    => 'manual',
                'category'      => $data['category'],
                'title'         => $data['title'],
                'description'   => ($data['description'] ?? '') ?: null,
                'event_date'    => $data['event_date'],
                'end_date'      => $data['end_date'] ?: null,
                'all_day'       => Carbon::parse($data['event_date'])->format('H:i:s') === '00:00:00',
                'status'        => 'pending',
                'priority'      => 'normal',
                'source_type'   => 'manual',
                'user_id'       => $user->id,
                'created_by_id' => $user->id,
                'agency_id'     => $user->agency_id ?: 1,
                'branch_id'     => $user->branch_id,
                'property_id'   => $data['property_id'] ?: null,
                'contact_id'    => ($data['contact_ids'] ?? [])[0] ?? null,
            ]);

            $this->syncEventLinks($event, $data, $user);

            return $event;
        });

        if ($request->wantsJson()) {
            return response()->json($event, 201);
        }

        return redirect()
            ->route('command-center.calendar', ['view' => 'day', 'date' => Carbon::parse($event->event_date)->toDateString()])
            ->with('success', 'Event created.');
    }

    public function reschedule(Request $request, CalendarEvent $calendarEvent)
    {
        if (!$this->visibilityResolver->canSee($calendarEvent, $request->user())) {
            abort(403);
        }

        if (!in_array($calendarEvent->source_type, ['manual', 'manual:demo'])) {
            return response()->json(['error' => 'Source-driven events cannot be rescheduled.'], 422);
        }

        $data = $request->validate([
            'event_date' => 'required|date',
            'end_date'   => 'nullable|date|after_or_equal:event_date',
        ]);

        $old = [
            'event_date' => $calendarEvent->event_date->toIso8601String(),
            'end_date'   => $calendarEvent->end_date?->toIso8601String(),
        ];

        DB::transaction(function () use ($calendarEvent, $data, $old, $request) {
            $calendarEvent->update([
                'event_date' => $data['event_date'],
                'end_date'   => $data['end_date'] ?? $calendarEvent->end_date,
            ]);

            \App\Models\CommandCenter\CalendarEventAuditEntry::create([
                'calendar_event_id'    => $calendarEvent->id,
                'action'               => 'rescheduled',
                'old_values'           => $old,
                'new_values'           => [
                    'event_date' => Carbon::parse($data['event_date'])->toIso8601String(),
                    'end_date'   => isset($data['end_date']) ? Carbon::parse($data['end_date'])->toIso8601String() : null,
                ],
                'performed_by_user_id' => $request->user()->id,
                'performed_at'         => now(),
                'notes'                => 'Drag-to-reschedule via calendar UI',
            ]);
        });

        return response()->json([
            'success'    => true,
            'event_date' => $calendarEvent->fresh()->event_date->toIso8601String(),
        ]);
    }

    public function update(Request $request, CalendarEvent $calendarEvent)
    {
        $user = $request->user();

        $data = $request->validate([
            'title'             => 'sometimes|required|string|max:255',
            'category'          => 'nullable|string|in:' . implode(',', self::MANUAL_CREATABLE_CLASSES),
            'event_date'        => 'sometimes|required|date',
            'end_date'          => 'nullable|date|after_or_equal:event_date',
            'description'       => 'nullable|string|max:2000',
            'status'            => 'nullable|in:pending,completed,overdue,dismissed',
            'priority'          => 'nullable|in:low,normal,high,critical',
            'property_id'       => 'nullable|integer|exists:properties,id',
            'contact_ids'       => 'nullable|array',
            'contact_ids.*'     => 'integer|exists:contacts,id',
            'attendees'         => 'nullable|array',
            'attendees.*.id'    => 'required_with:attendees|integer',
            'attendees.*.type'  => 'required_with:attendees|string|in:contact,agent',
            'deal_id'           => 'nullable|integer',
        ]);

        $oldValues = $calendarEvent->only(['title', 'category', 'event_date', 'end_date', 'description', 'property_id']);

        DB::transaction(function () use ($calendarEvent, $data, $user, $oldValues) {
            $calendarEvent->update(collect($data)->only([
                'title', 'category', 'event_date', 'end_date', 'description',
                'status', 'priority', 'property_id',
            ])->filter(fn ($v, $k) => $v !== null || in_array($k, ['end_date', 'description', 'property_id']))->all());

            // Update direct contact FK from attendees
            if (array_key_exists('attendees', $data)) {
                $firstContact = collect($data['attendees'] ?? [])->firstWhere('type', 'contact');
                $calendarEvent->update(['contact_id' => $firstContact['id'] ?? null]);
            } elseif (array_key_exists('contact_ids', $data)) {
                $calendarEvent->update(['contact_id' => ($data['contact_ids'] ?? [])[0] ?? null]);
            }

            // Re-sync pivot links
            if (array_key_exists('property_id', $data) || array_key_exists('contact_ids', $data) || array_key_exists('attendees', $data) || array_key_exists('deal_id', $data)) {
                $this->syncEventLinks($calendarEvent, $data, $user);
            }

            // Audit log for non-reschedule edits
            $newValues = $calendarEvent->fresh()->only(['title', 'category', 'event_date', 'end_date', 'description', 'property_id']);
            $changed = array_filter($newValues, fn ($v, $k) => ($oldValues[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH);
            if (!empty($changed)) {
                \App\Models\CommandCenter\CalendarEventAuditEntry::create([
                    'calendar_event_id'    => $calendarEvent->id,
                    'action'               => 'updated',
                    'old_values'           => array_intersect_key($oldValues, $changed),
                    'new_values'           => $changed,
                    'performed_by_user_id' => $user->id,
                    'performed_at'         => now(),
                ]);
            }
        });

        $event = $calendarEvent->fresh();
        return $request->wantsJson() ? response()->json($event) : back()->with('success', 'Event updated.');
    }

    public function destroy(Request $request, CalendarEvent $calendarEvent)
    {
        $this->service->delete($calendarEvent);
        return $request->wantsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Event removed.');
    }

    public function complete(CalendarEvent $calendarEvent)
    {
        $calendarEvent->markCompleted();
        return back()->with('success', 'Event completed.');
    }

    public function dismiss(CalendarEvent $calendarEvent)
    {
        $calendarEvent->markDismissed();
        return back()->with('success', 'Event dismissed.');
    }

    // ── Private helpers ──

    private function applyFilters(Collection $events, $user, array $typeFilter, array $categoryFilter, string $scope): Collection
    {
        $filtered = $events
            ->when(!empty($typeFilter), fn ($c) => $c->whereIn('event_type', $typeFilter))
            ->when(!empty($categoryFilter), fn ($c) => $c->whereIn('category', $categoryFilter))
            ->when($scope === 'own', fn ($c) => $c->where('user_id', $user->id))
            ->when($scope === 'branch' && $user->branch_id, fn ($c) => $c->where('branch_id', $user->branch_id));

        $visible = $this->visibilityResolver->filterVisible($filtered, $user);
        return collect($visible)->map(function ($event) {
            $event->resolved_colour = $this->thresholdResolver->resolveForEvent($event);
            return $event;
        })->filter(fn ($e) => $e->resolved_colour !== null)->values();
    }

    /**
     * Build a colour metadata map for all events on the page.
     * Keyed by event ID, each entry has: rag, class, branch, agent colours.
     */
    private function buildColourMap(Collection $events): array
    {
        $map = [];
        foreach ($events as $event) {
            $map[$event->id] = [
                'rag'    => $event->resolved_colour ?? 'neutral',
                'class'  => $event->category ?? 'unknown',
                'branch' => $event->branch_id ?? 0,
                'agent'  => $event->user_id ?? 0,
            ];
        }
        return $map;
    }

    /**
     * Generate deterministic colour palettes for class/branch/agent colour-by modes.
     */
    private function buildColourPalettes(Collection $events): array
    {
        // Hue-spread palette generator (12 distinct hues)
        $palette = ['#0d9488','#2563eb','#7c3aed','#db2777','#ea580c','#65a30d','#0891b2','#4f46e5','#c026d3','#d97706','#059669','#dc2626'];

        // Class colours — deterministic from class name
        $classes = $events->pluck('category')->unique()->filter()->values();
        $classColours = [];
        foreach ($classes as $i => $cls) {
            $classColours[$cls] = $palette[$i % count($palette)];
        }

        // Branch colours — deterministic from branch_id
        $branches = $events->pluck('branch_id')->unique()->filter()->values();
        $branchColours = [];
        foreach ($branches as $i => $bid) {
            $branchColours[$bid] = $palette[$i % count($palette)];
        }

        // Agent colours — deterministic from user_id
        $agents = $events->pluck('user_id')->unique()->filter()->values();
        $agentColours = [];
        foreach ($agents as $i => $uid) {
            $agentColours[$uid] = $palette[$i % count($palette)];
        }

        return [
            'class'  => $classColours,
            'branch' => $branchColours,
            'agent'  => $agentColours,
        ];
    }

    private function resolveSourceLink(CalendarEvent $event): ?array
    {
        if (!$event->source_type || !$event->source_id) return null;
        if (str_starts_with($event->source_type, 'synthetic:')) return null;
        $routeMap = [
            \App\Models\Property::class => ['route' => 'corex.properties.show', 'label' => 'View property'],
            \App\Models\FicaSubmission::class => ['route' => 'compliance.fica.show', 'label' => 'View FICA submission'],
            \App\Models\Compliance\RmcpVersion::class => ['route' => 'compliance.rmcp.show', 'label' => 'View RMCP version'],
            \App\Models\Compliance\EmployeeScreening::class => ['route' => 'compliance.screenings.show', 'label' => 'View screening'],
            \App\Models\Payroll\PayrollRun::class => ['route' => 'payroll.runs.show', 'label' => 'View payroll run'],
            \App\Models\Payroll\PayrollEmployee::class => ['route' => 'payroll.employees.show', 'label' => 'View employee'],
        ];
        $entry = $routeMap[$event->source_type] ?? null;
        if (!$entry) return null;
        try { return ['url' => route($entry['route'], $event->source_id), 'label' => $entry['label']]; }
        catch (\Throwable $e) { return null; }
    }

    /**
     * Sync calendar_event_links for a manual event.
     * Deletes existing user-created links and re-inserts from provided data.
     */
    private function syncEventLinks(CalendarEvent $event, array $data, $user): void
    {
        // Remove existing user-created links (hard delete — soft delete leaves
        // rows that violate the unique constraint on re-insert)
        DB::table('calendar_event_links')
            ->where('calendar_event_id', $event->id)
            ->whereNotNull('created_by_user_id')
            ->delete();

        $links = [];
        $now = now();

        if (!empty($data['property_id'])) {
            $links[] = [
                'calendar_event_id'  => $event->id,
                'linkable_type'      => Property::class,
                'linkable_id'        => $data['property_id'],
                'role'               => CalendarEventLink::ROLE_SUBJECT_PROPERTY,
                'created_by_user_id' => $user->id,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        foreach (($data['attendees'] ?? $data['contact_ids'] ?? []) as $attendee) {
            // Support both old format (plain IDs) and new format ({id, type})
            if (is_array($attendee)) {
                $type = ($attendee['type'] ?? 'contact') === 'agent' ? \App\Models\User::class : Contact::class;
                $id = $attendee['id'];
            } else {
                $type = Contact::class;
                $id = $attendee;
            }
            $links[] = [
                'calendar_event_id'  => $event->id,
                'linkable_type'      => $type,
                'linkable_id'        => $id,
                'role'               => CalendarEventLink::ROLE_ATTENDEE,
                'created_by_user_id' => $user->id,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        if (!empty($data['deal_id'])) {
            $links[] = [
                'calendar_event_id'  => $event->id,
                'linkable_type'      => \App\Models\DealV2\DealV2::class,
                'linkable_id'        => $data['deal_id'],
                'role'               => CalendarEventLink::ROLE_RELATED_DEAL,
                'created_by_user_id' => $user->id,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        if (!empty($links)) {
            DB::table('calendar_event_links')->insert($links);
        }
    }

    /**
     * Return owner/seller contacts for a property (used by auto-populate in create modal).
     */
    public function propertyOwners(Request $request, int $propertyId)
    {
        $property = \App\Models\Property::find($propertyId);
        if (!$property) {
            return response()->json([]);
        }

        $owners = $property->contacts()
            ->wherePivotIn('role', ['owner', 'seller', 'landlord', 'lessor'])
            ->get(['contacts.id', 'contacts.first_name', 'contacts.last_name', 'contacts.phone', 'contacts.email'])
            ->map(fn ($c) => [
                'id'    => $c->id,
                'name'  => trim($c->first_name . ' ' . $c->last_name) ?: ('Contact #' . $c->id),
                'phone' => $c->phone,
                'email' => $c->email,
                'type'  => 'contact',
            ]);

        return response()->json($owners);
    }

    /**
     * Search attendees — returns both contacts AND agency users (agents).
     */
    public function searchAttendees(Request $request)
    {
        $user = $request->user();
        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $agencyId = $user->agency_id ?: 1;

        // Search contacts
        $contacts = \App\Models\Contact::query()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($q) {
                $query->where('first_name', 'like', "%{$q}%")
                      ->orWhere('last_name', 'like', "%{$q}%")
                      ->orWhere('phone', 'like', "%{$q}%")
                      ->orWhere('email', 'like', "%{$q}%");
            })
            ->limit(7)
            ->get(['id', 'first_name', 'last_name', 'phone', 'email'])
            ->map(fn ($c) => [
                'id'    => $c->id,
                'name'  => trim($c->first_name . ' ' . $c->last_name) ?: ('Contact #' . $c->id),
                'phone' => $c->phone,
                'email' => $c->email,
                'type'  => 'contact',
            ]);

        // Search users (agents) — exclude the current user
        $users = \App\Models\User::query()
            ->where('agency_id', $agencyId)
            ->where('id', '!=', $user->id)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                      ->orWhere('email', 'like', "%{$q}%");
            })
            ->limit(5)
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'phone' => null,
                'email' => $u->email,
                'type'  => 'agent',
            ]);

        return response()->json($contacts->concat($users)->values());
    }
}
