<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarEventLink;
use App\Models\Contact;
use App\Models\Property;
use App\Services\CommandCenter\Calendar\CalendarEventCreator;
use App\Services\CommandCenter\Calendar\CalendarThresholdResolver;
use App\Services\CommandCenter\Calendar\CalendarVisibilityResolver;
use App\Services\CommandCenter\CalendarEventService;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CalendarController extends Controller
{
    /** Classes that users may create manually (not system-driven). */
    private const MANUAL_CREATABLE_CLASSES = [
        'viewing', 'property_evaluation', 'listing_presentation',
        'meeting', 'task', 'private', 'other',
    ];

    public function __construct(
        private CalendarEventService $service,
        private CalendarThresholdResolver $thresholdResolver,
        private CalendarVisibilityResolver $visibilityResolver,
        private CalendarEventCreator $creator,
        private \App\Services\CommandCenter\CalendarTileService $tiles,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $view = $request->get('view', 'month');

        // Filter params (shared across all views)
        $typeFilter     = $request->input('types', []);
        $categoryFilter = $request->input('categories', []);

        // Role-driven visibility ceiling (own | branch | all). The page's
        // My/Branch/All toggle can request a scope, but never wider than the
        // ceiling Role Manager grants for command_center.calendar.view.
        $ceiling = PermissionService::calendarScope($user);
        $scope   = PermissionService::clampScope($request->input('scope'), $ceiling);

        $shared = $this->sharedViewData($user, $view, $typeFilter, $categoryFilter, $scope);
        $shared['scopeCeiling'] = $ceiling;
        $shared['autoOpenFeedbackEventId'] = $request->input('capture_feedback');

        // AT-164 Gate 4 — the Tile Deck (below the grid, all views).
        $shared['deck']        = $this->tiles->buildDeck($user);
        $shared['deckCatalog'] = $this->tiles->catalog($user);
        $shared['deckLayout']  = $this->tiles->resolveLayout($user);
        $shared['deckSlots']   = $this->tiles->slotCount($user);
        // AT-164 Gate 7 — live-RAG light-poll interval (agency-configurable).
        $shared['pollSeconds'] = \App\Models\AgencyContactSettings::forAgency($user->effectiveAgencyId() ?? 1)->calendarPollSeconds();
        // AT-164 Gate 6 — layer toggles: the layer catalogue + the user's active set.
        $shared['layerCatalog'] = \App\Services\CommandCenter\Calendar\CalendarLayers::LAYERS;
        $shared['activeLayers'] = \App\Services\CommandCenter\Calendar\CalendarLayers::resolveActive($user, $request->input('layers'));

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

        // Separate multi-day events (spanning bars) from single-day events
        $weekSpanningBars = [];
        $singleDayEvents = collect();

        foreach ($filtered as $event) {
            $eventStart = $event->event_date->copy()->startOfDay();
            $eventEnd = $event->end_date ? $event->end_date->copy()->startOfDay() : $eventStart;
            $isMultiDay = $event->end_date && $eventEnd->gt($eventStart);

            if ($isMultiDay) {
                // Clamp to visible week
                $from = $eventStart->lt($weekStart) ? $weekStart->copy() : $eventStart->copy();
                $to = $eventEnd->gt($weekEnd->copy()->startOfDay()) ? $weekEnd->copy()->startOfDay() : $eventEnd->copy();
                $startCol = $from->dayOfWeekIso; // 1=Mon, 7=Sun
                $endCol = $to->dayOfWeekIso;
                $span = $endCol - $startCol + 1;
                if ($span < 1) $span = 1;
                $weekSpanningBars[] = [
                    'event' => $event,
                    'event_id' => $event->id,
                    'title' => $event->title,
                    'start_col' => $startCol,
                    'end_col' => $endCol,
                    'span' => $span,
                ];
            } else {
                $singleDayEvents->push($event);
            }
        }

        // Interval-partition spanning bars into slots (avoid overlap)
        usort($weekSpanningBars, function ($a, $b) {
            if ($a['start_col'] !== $b['start_col']) return $a['start_col'] - $b['start_col'];
            return $b['span'] - $a['span'];
        });
        $weekBarSlots = [];
        foreach ($weekSpanningBars as &$bar) {
            $placed = false;
            foreach ($weekBarSlots as $si => &$slotBars) {
                $conflict = false;
                foreach ($slotBars as $existing) {
                    if ($bar['start_col'] <= $existing['end_col'] && $bar['end_col'] >= $existing['start_col']) {
                        $conflict = true;
                        break;
                    }
                }
                if (!$conflict) {
                    $bar['slot'] = $si;
                    $slotBars[] = $bar;
                    $placed = true;
                    break;
                }
            }
            unset($slotBars);
            if (!$placed) {
                $bar['slot'] = count($weekBarSlots);
                $weekBarSlots[] = [$bar];
            }
        }
        unset($bar);

        $weekDays = collect();
        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->copy()->addDays($i);
            $weekDays->push([
                'date'     => $day,
                'is_today' => $day->isSameDay(Carbon::today()),
                'events'   => $singleDayEvents->filter(function ($e) use ($day) {
                    return $e->event_date->copy()->startOfDay()->isSameDay($day);
                })->values(),
            ]);
        }

        // Build colour data for week view (same as month)
        $allVisibleEvents = $filtered;
        $colourMap = $this->buildColourMap($allVisibleEvents);
        $colourPalettes = $this->buildColourPalettes($allVisibleEvents);
        $classLabels = [];
        foreach ($shared['availableCategories'] as $cat) {
            $classLabels[$cat->event_class] = $cat->label;
        }
        $branchLabels = \App\Models\Branch::withoutGlobalScopes()
            ->whereIn('id', $allVisibleEvents->pluck('branch_id')->unique()->filter())
            ->pluck('name', 'id')->toArray();
        $agentLabels = \App\Models\User::withoutGlobalScopes()
            ->whereIn('id', $allVisibleEvents->pluck('user_id')->unique()->filter())
            ->pluck('name', 'id')->toArray();

        return view('command-center.calendar.index', $shared + [
            'weekStart'       => $weekStart,
            'weekEnd'         => $weekEnd,
            'weekDays'        => $weekDays,
            'weekSpanningBars' => $weekSpanningBars,
            'weekBarSlots'    => $weekBarSlots,
            'anchorDate'      => $anchor,
            'prevAnchor'      => $weekStart->copy()->subWeek()->toDateString(),
            'nextAnchor'      => $weekStart->copy()->addWeek()->toDateString(),
            'colourMap'       => $colourMap,
            'colourPalettes'  => $colourPalettes,
            'classLabels'     => $classLabels,
            'branchLabels'    => $branchLabels,
            'agentLabels'     => $agentLabels,
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

        // Colour data for Color By mode
        $colourMap = $this->buildColourMap($dayEvents);
        $colourPalettes = $this->buildColourPalettes($dayEvents);
        $classLabels = [];
        foreach ($shared['availableCategories'] as $cat) {
            $classLabels[$cat->event_class] = $cat->label;
        }
        $branchLabels = \App\Models\Branch::withoutGlobalScopes()
            ->whereIn('id', $dayEvents->pluck('branch_id')->unique()->filter())
            ->pluck('name', 'id')->toArray();
        $agentLabels = \App\Models\User::withoutGlobalScopes()
            ->whereIn('id', $dayEvents->pluck('user_id')->unique()->filter())
            ->pluck('name', 'id')->toArray();

        return view('command-center.calendar.index', $shared + [
            'dayEvents'     => $dayEvents,
            'anchorDate'    => $anchor,
            'prevAnchor'    => $anchor->copy()->subDay()->toDateString(),
            'nextAnchor'    => $anchor->copy()->addDay()->toDateString(),
            'colourMap'     => $colourMap,
            'colourPalettes' => $colourPalettes,
            'classLabels'   => $classLabels,
            'branchLabels'  => $branchLabels,
            'agentLabels'   => $agentLabels,
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

        // AT-164 Gate 5 — the per-month block (grid + species split + spanning bars) is
        // now built by one reusable method so the continuous-scroll endpoint renders
        // through the identical pipeline.
        $block = $this->monthBlockData($user, $year, $month, $typeFilter, $categoryFilter, $scope);
        $grid                 = $block['grid'];
        $appointmentByDate    = $block['byDate'];
        $deadlineGroupsByDate = $block['deadlineGroups'];
        $filteredSpanningBars = $block['spanningBars'];
        $filteredEvents       = $block['filteredEvents'];

        // AT-164 cockpit (Johan QA) — PRELOAD prev + current + next month blocks so the
        // grid frame has more content than its height on first paint → wheel scrolling
        // engages immediately and lazy-load continues from there (a single short month
        // never overflowed, so it read as "static"). Ordered [prev, current, next]; the
        // client scrolls to the current month on init.
        $curMonth  = Carbon::create($year, $month, 1)->startOfMonth();
        $prevM     = $curMonth->copy()->subMonthNoOverflow();
        $nextM     = $curMonth->copy()->addMonthNoOverflow();
        $prevBlock = $this->monthBlockData($user, $prevM->year, $prevM->month, $typeFilter, $categoryFilter, $scope);
        $nextBlock = $this->monthBlockData($user, $nextM->year, $nextM->month, $typeFilter, $categoryFilter, $scope);
        $monthBlocks = [
            ['year' => $prevM->year, 'month' => $prevM->month, 'grid' => $prevBlock['grid'], 'byDate' => $prevBlock['byDate'], 'deadlineGroups' => $prevBlock['deadlineGroups'], 'spanningBars' => $prevBlock['spanningBars']],
            ['year' => $year,        'month' => $month,        'grid' => $grid,               'byDate' => $appointmentByDate,  'deadlineGroups' => $deadlineGroupsByDate,     'spanningBars' => $filteredSpanningBars],
            ['year' => $nextM->year, 'month' => $nextM->month, 'grid' => $nextBlock['grid'], 'byDate' => $nextBlock['byDate'], 'deadlineGroups' => $nextBlock['deadlineGroups'], 'spanningBars' => $nextBlock['spanningBars']],
        ];

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
            'byDate'           => $appointmentByDate,   // AT-164 Gate 1 — appointments only in cells
            'deadlineGroups'   => $deadlineGroupsByDate, // AT-164 Gate 1 — aggregate deadline chips
            'spanningBars'     => $filteredSpanningBars,
            'monthBlocks'      => $monthBlocks,         // AT-164 cockpit — prev+current+next preloaded
            'anchorMonth'      => sprintf('%04d-%02d', $year, $month),
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
        $allClasses = CalendarEventClassSetting::withoutGlobalScopes()
            ->where('is_active', true)->orderBy('label')
            ->get()->unique('event_class');

        // Filter to classes the current user can see (super_admin/admin see all)
        $userRole = $user->role ?? 'agent';
        $isBypass = in_array($userRole, ['super_admin', 'admin', 'owner']);

        $visibleClasses = $isBypass ? $allClasses : $allClasses->filter(function ($cls) use ($userRole) {
            // Check if user's role appears in ANY colour visibility list
            $allVisibleRoles = array_merge(
                $cls->green_visibility ?? [],
                $cls->amber_visibility ?? [],
                $cls->red_visibility ?? []
            );
            // Role widening: 'bm' matches 'branch_manager'
            if (in_array('all', $allVisibleRoles)) return true;
            if (in_array($userRole, $allVisibleRoles)) return true;
            if ($userRole === 'branch_manager' && in_array('bm', $allVisibleRoles)) return true;
            return false;
        });

        // Derive available event types from visible classes (only show types that have visible classes)
        $visibleClassKeys = $visibleClasses->pluck('event_class')->toArray();
        $availableTypes = $isBypass
            ? ['compliance', 'deal', 'document', 'lease', 'leave', 'payroll', 'people', 'property', 'recurring', 'manual']
            : \App\Models\CommandCenter\CalendarEvent::withoutGlobalScopes()
                ->whereIn('category', $visibleClassKeys)
                ->whereNotNull('event_type')
                ->distinct()
                ->pluck('event_type')
                ->merge(['manual']) // manual events always visible to creator
                ->unique()
                ->sort()
                ->values()
                ->toArray();

        return [
            'user'                => $user,
            'currentView'         => $view,
            'typeFilter'          => $typeFilter,
            'categoryFilter'      => $categoryFilter,
            'scope'               => $scope,
            'availableTypes'      => $availableTypes,
            'availableCategories' => $visibleClasses->map(fn($c) => (object)['event_class' => $c->event_class, 'label' => $c->label])->values(),
            'manualCreatableClasses' => CalendarEventClassSetting::withoutGlobalScopes()
                ->whereNull('agency_id')
                ->where('is_active', true)
                ->whereIn('event_class', self::MANUAL_CREATABLE_CLASSES)
                ->orderBy('label')
                ->get(['event_class', 'label', 'allow_multiple_properties', 'actor_role', 'completion_behaviour', 'event_nature', 'autofill_buyers']),
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

        $scope = PermissionService::clampScope($request->input('scope'), PermissionService::calendarScope($user));
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

    // ── AT-164 Gate 4/7 — Tile Deck (JSON) ──

    /**
     * The resolved Deck for this user — cards (built through the same builders as the
     * server render), the pickable catalogue, the current ordered layout, and the
     * slot count. Also fired by the live-RAG loop (Gate 7) to refresh tile RAG in place.
     */
    public function deck(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'cards'   => $this->tiles->buildDeck($user),
            'catalog' => $this->tiles->catalog($user),
            'layout'  => $this->tiles->resolveLayout($user),
            'slots'   => $this->tiles->slotCount($user),
        ]);
    }

    /** Persist this user's Deck layout (ordered tile-ids). Server clamps to slots + catalogue. */
    public function saveDeck(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'tiles'   => ['present', 'array'],
            'tiles.*' => ['string', 'max:64'],
        ]);
        $layout = $this->tiles->saveLayout($user, $data['tiles']);

        return response()->json([
            'ok'     => true,
            'layout' => $layout,
            'cards'  => $this->tiles->buildDeck($user),
        ]);
    }

    /** Reset this user's Deck to the role/agency/code default. */
    public function resetDeck(Request $request)
    {
        $user = $request->user();
        $layout = $this->tiles->resetLayout($user);

        return response()->json([
            'ok'     => true,
            'layout' => $layout,
            'cards'  => $this->tiles->buildDeck($user),
        ]);
    }

    /** AT-164 Gate 6 — persist this user's active layer set (cross-device). */
    public function saveLayers(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'layers'   => ['present', 'array'],
            'layers.*' => ['string', 'max:32'],
        ]);
        $active = \App\Services\CommandCenter\Calendar\CalendarLayers::save($user, $data['layers']);

        return response()->json(['ok' => true, 'layers' => $active]);
    }

    public function show(Request $request, CalendarEvent $calendarEvent)
    {
        $user = $request->user();
        if (!$this->visibilityResolver->canSee($calendarEvent, $user)) { abort(403); }

        // Recurring occurrence view: the client decodes a synthetic occurrence id
        // → /calendar/{parent}?occurrence=Y-m-d. Substitute the occurrence's
        // date/time onto the (in-memory) parent so the panel shows THIS occurrence,
        // and expose recurrence markers so the UI can offer the edit-scope prompt.
        $occurrenceDate = $request->query('occurrence');
        $isOccurrence = false;
        if ($occurrenceDate && $calendarEvent->is_recurring) {
            try {
                $od = \Carbon\Carbon::parse($occurrenceDate);
                $t = $calendarEvent->event_date;
                $dur = $calendarEvent->end_date ? $calendarEvent->event_date->diffInSeconds($calendarEvent->end_date) : null;
                $start = $od->copy()->setTime($t->hour, $t->minute, $t->second);
                $calendarEvent->event_date = $start;
                $calendarEvent->end_date = $dur !== null ? $start->copy()->addSeconds($dur) : null;
                $isOccurrence = true;
                $occurrenceDate = $od->toDateString();
            } catch (\Throwable $e) { $isOccurrence = false; }
        }
        $recurrenceLabel = $calendarEvent->is_recurring
            ? optional(\App\Services\CommandCenter\Calendar\RecurrenceRule::parse($calendarEvent->recurrence_rule))->humanLabel()
            : null;
        // Parsed rule parts so the edit form can pre-fill its recurrence controls
        // (freq / interval / end condition), letting an "edit all" round-trip the
        // series settings instead of silently dropping recurrence.
        $recurrenceParts = null;
        if ($calendarEvent->is_recurring) {
            $pr = \App\Services\CommandCenter\Calendar\RecurrenceRule::parse($calendarEvent->recurrence_rule);
            if ($pr) {
                $recurrenceParts = [
                    'freq'     => $pr->freq,
                    'interval' => $pr->interval,
                    'end_type' => $pr->count !== null ? 'count' : ($pr->until !== null ? 'until' : 'never'),
                    'until'    => $pr->until?->toDateString(),
                    'count'    => $pr->count,
                ];
            }
        }

        // ITEM 4 — a private event opened by anyone but its creator returns ONLY
        // the busy-slot placeholder: "Private" + the time block, no detail, not
        // editable. Role-blind (no admin/owner override).
        if ($calendarEvent->isPrivateHiddenFrom($user)) {
            return response()->json([
                'id'                => $calendarEvent->id,
                'title'             => 'Private',
                'description'       => null,
                'event_date'        => $calendarEvent->event_date->toIso8601String(),
                'end_date'          => $calendarEvent->end_date?->toIso8601String(),
                'event_date_h'      => $calendarEvent->event_date->format('D, d M Y'),
                'days_diff'         => (int) now()->startOfDay()->diffInDays($calendarEvent->event_date->copy()->startOfDay(), false),
                'colour'            => $this->thresholdResolver->resolveForEvent($calendarEvent),
                'category'          => 'private',
                'class_label'       => 'Private',
                'event_type'        => $calendarEvent->event_type,
                'status'            => $calendarEvent->status,
                'source_type'       => $calendarEvent->source_type,
                'source_link'       => null,
                'linked_records'    => [],
                'metadata'          => null,
                'is_past'           => $calendarEvent->event_date->isPast(),
                'has_contacts'      => false,
                'is_editable'       => false,
                'is_actionable'     => false,
                'is_draggable'      => false,
                'is_private'        => true,
                'linked_property'   => null,
                'linked_properties' => [],
                'attendees'         => [],
                'is_recurring'      => (bool) $calendarEvent->is_recurring,
                'is_occurrence'     => $isOccurrence,
                'occurrence_date'   => $isOccurrence ? $occurrenceDate : null,
                'recurrence_label'  => $recurrenceLabel,
                'recurrence'        => $recurrenceParts,
                'recurrence_parent_id' => $calendarEvent->is_recurring ? (int) $calendarEvent->id : null,
            ]);
        }

        $colour = $this->thresholdResolver->resolveForEvent($calendarEvent);
        $cfg = CalendarEventClassSetting::forAgencyAndClass($calendarEvent->agency_id, $calendarEvent->category);

        $isManual = in_array($calendarEvent->source_type, ['manual', 'manual:demo']);

        // Check current user's invitation status for this event
        $userInvitation = \App\Models\CommandCenter\CalendarEventInvitation::where('event_id', $calendarEvent->id)
            ->where('invitee_user_id', $user->id)->first();
        $isOrganizer = (int) ($calendarEvent->user_id ?? 0) === (int) $user->id;

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
            'linked_records' => $this->buildLinkedRecords($calendarEvent, $user),
            'metadata' => $calendarEvent->metadata,
            'is_past' => $calendarEvent->event_date->isPast(),
            'has_contacts' => $calendarEvent->linkedContacts()->exists(),
            'is_editable' => $isManual,
            // CAL-7 Class 1 — explicit null-safe with SENSIBLE ACTIVE defaults.
            // Locally the seeder populates every event_class row; staging
            // (live-copy DB pre-dating SEED-GUARD) often has zero rows so
            // forAgencyAndClass() returns null. PHP 8's `??` already prevents
            // the throw, but the previous defaults silently DISABLED features
            // (actor_role='neither' -> autoPopulateOwners never fires;
            // completion_behaviour='freeform' -> no "Require feedback" gate).
            // The new defaults keep the calendar usable when reference data
            // is missing: 'both' covers buyer + seller auto-fill; the
            // deploy-verify hard-fails if calendar_event_class_settings is
            // empty so this branch is the runtime safety-net, not the
            // expected steady state.
            // is_actionable drives the feedback CTA + completion gates. Reads the
            // EFFECTIVE per-event nature (metadata override ?? class default), so a
            // user's "No feedback needed" choice is honoured. event_nature is
            // returned so the edit form can pre-select the current value.
            'is_actionable' => !$calendarEvent->isInformational(),
            'event_nature' => $calendarEvent->effectiveEventNature(),
            // Recurrence markers — the panel offers the this/future/all edit-scope
            // prompt when is_recurring (occurrence carries the specific date).
            'is_recurring' => (bool) $calendarEvent->is_recurring,
            'is_occurrence' => $isOccurrence,
            'occurrence_date' => $isOccurrence ? $occurrenceDate : null,
            'recurrence_label' => $recurrenceLabel,
            'recurrence' => $recurrenceParts,
            'recurrence_parent_id' => $calendarEvent->is_recurring ? (int) $calendarEvent->id : null,
            'actor_role' => $cfg?->actor_role ?? 'both',
            'completion_behaviour' => $cfg?->completion_behaviour ?? 'freeform',
            'is_draggable' => $isManual && !$calendarEvent->is_recurring,
            'linked_property' => $calendarEvent->property_id ? [
                'id' => $calendarEvent->property_id,
                'address' => $calendarEvent->property?->address ?? ('Property #' . $calendarEvent->property_id),
            ] : null,
            'linked_properties' => $calendarEvent->linkedProperties->map(fn ($p) => [
                'id' => $p->id,
                'address' => method_exists($p, 'buildDisplayAddress') ? $p->buildDisplayAddress() : ($p->title ?? "Property #{$p->id}"),
            ])->values(),
            'attendees' => $isManual ? $calendarEvent->links()
                ->whereIn('role', ['attendee', 'buyer_contact', 'seller_contact', 'agent_contact'])
                ->get()
                ->map(function ($l) use ($calendarEvent) {
                    // CAL-5 — id, name, first_name, last_name MUST all come
                    // from the SAME found row. Previously this used
                    // optional(Contact::find($id), fn ($c) => $c->first_name
                    // . ' ' . $c->last_name) and returned only the
                    // precomputed `name`; if the find ever resolved to a
                    // different row (cache, scope side-effect, a deleted
                    // row's primary-key collision against a re-allocated
                    // value), the chip would render the wrong contact's
                    // name beside the right linkable_id. Resolve the
                    // Contact / User once, derive every field from it,
                    // and expose first_name + last_name so the client
                    // can rebuild the displayed name from this object's
                    // own data — no positional zipping, no separate
                    // arrays, no precomputed-name substitution.
                    $inv = null;
                    if ($l->linkable_type === \App\Models\User::class) {
                        $inv = \App\Models\CommandCenter\CalendarEventInvitation::where('event_id', $calendarEvent->id)
                            ->where('invitee_user_id', $l->linkable_id)->first();
                    }

                    $type = $l->linkable_type === \App\Models\User::class ? 'agent' : 'contact';
                    $first = null;
                    $last  = null;
                    $name  = 'Contact #' . $l->linkable_id;

                    if ($type === 'agent') {
                        $u = \App\Models\User::withoutGlobalScopes()->find($l->linkable_id);
                        if ($u && (int) $u->id === (int) $l->linkable_id) {
                            $name = $u->name ?? $name;
                        }
                    } else {
                        $c = \App\Models\Contact::withoutGlobalScopes()->find($l->linkable_id);
                        // Defence-in-depth: verify the row we got back IS the row
                        // we asked for. If Eloquent ever returned a model whose
                        // id doesn't equal the requested id (which should never
                        // happen, but is the structural assumption the CAL-5
                        // bug report violated), refuse to substitute that
                        // contact's name. Fall back to the neutral "Contact #N"
                        // label — better to render a placeholder than to
                        // display the wrong person's identity.
                        if ($c && (int) $c->id === (int) $l->linkable_id) {
                            $first = $c->first_name;
                            $last  = $c->last_name;
                            $built = trim(($first ?? '') . ' ' . ($last ?? ''));
                            if ($built !== '') {
                                $name = $built;
                            }
                        }
                    }

                    return [
                        'id'                => (int) $l->linkable_id,
                        'type'              => $type,
                        'role'              => $l->role,
                        'invitation_status' => $inv?->status,
                        'invitation_id'     => $inv?->id,
                        'response_notes'    => $inv?->response_notes,
                        'first_name'        => $first,
                        'last_name'         => $last,
                        'name'              => $name,
                    ];
                }) : [],
            'unack_declines' => $isOrganizer ? \App\Models\CommandCenter\CalendarEventInvitation::where('event_id', $calendarEvent->id)
                ->where('status', 'declined')
                ->whereNull('acknowledged_at')
                ->get()
                ->map(fn ($inv) => [
                    'invitation_id' => $inv->id,
                    'invitee_name' => optional(\App\Models\User::withoutGlobalScopes()->find($inv->invitee_user_id))->name ?? 'Unknown',
                    'reason' => $inv->response_notes ?: 'Not provided',
                    'acknowledge_url' => route('command-center.calendar.invitations.acknowledge', $inv->id),
                ])->values() : [],
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
            'is_organizer' => $isOrganizer,
            'invitation' => $userInvitation ? [
                'id' => $userInvitation->id,
                'status' => $userInvitation->status,
                'response_at' => $userInvitation->response_at?->format('j M Y'),
                'inviter_name' => \App\Models\User::withoutGlobalScopes()->find($userInvitation->inviter_user_id)?->name ?? 'Unknown',
                'respond_url' => route('command-center.calendar.invitations.respond', $userInvitation->id),
            ] : null,
        ]);
    }

    public function showFeedback(Request $request, CalendarEvent $calendarEvent)
    {
        $user = $request->user();
        if (!$this->visibilityResolver->canSee($calendarEvent, $user)) {
            abort(403);
        }

        $agencyId = $calendarEvent->agency_id;
        $cfg = CalendarEventClassSetting::forAgencyAndClass($agencyId, $calendarEvent->category);
        // CAL-7 Class 1 — null-safe. Default 'per_contact' is the historically
        // correct mode for the most common event (viewings); the heuristic
        // below catches the one known case where the class is by definition
        // per_property (listing presentations) so a missing-config staging
        // doesn't degrade their feedback flow.
        $feedbackMode = $cfg?->feedback_mode
            ?? ($calendarEvent->category === 'listing_presentation' ? 'per_property' : 'per_contact');

        $properties = $calendarEvent->linkedProperties;

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

        // Per-property mode (listing_presentation): iterate properties, not contacts
        if ($feedbackMode === 'per_property') {
            $existing = \App\Models\CommandCenter\CalendarEventFeedback::query()
                ->where('calendar_event_id', $calendarEvent->id)
                ->get()
                ->keyBy('property_id');

            return response()->json([
                'event' => [
                    'id'    => $calendarEvent->id,
                    'title' => $calendarEvent->title,
                    'date'  => $calendarEvent->event_date->format('D, j M Y H:i'),
                ],
                'feedback_mode' => 'per_property',
                'feedback_kind' => 'listing_presentation',
                'items' => $properties->map(fn ($p) => [
                    'property_id'    => $p->id,
                    'label'          => method_exists($p, 'buildDisplayAddress') ? $p->buildDisplayAddress() : ($p->title ?? "Property #{$p->id}"),
                    'feedback_id'    => optional($existing->get($p->id))->id,
                    'kind_data'      => optional($existing->get($p->id))->kind_specific_data ?? [],
                    'internal_notes' => optional($existing->get($p->id))->internal_notes,
                    'next_action'    => optional($existing->get($p->id))->next_action_notes,
                ]),
                // CAL-7 Class 4 — read lp_outcome + lp_mandate_type from the
                // same agency_feedback_options table the per_contact mode
                // uses (the seeder now seeds them). Empty seed -> empty
                // array -> CAL-6 empty-state banner fires consistently
                // across both feedback modes.
                'lp_outcomes' => \App\Models\CommandCenter\AgencyFeedbackOption::withoutGlobalScopes()
                    ->where('category', 'lp_outcome')
                    ->where('is_active', true)
                    ->where(fn ($q) => $q->whereNull('agency_id')->orWhere('agency_id', $agencyId))
                    ->orderBy('sort_order')
                    ->pluck('label')
                    ->values(),
                'lp_mandate_types' => \App\Models\CommandCenter\AgencyFeedbackOption::withoutGlobalScopes()
                    ->where('category', 'lp_mandate_type')
                    ->where('is_active', true)
                    ->where(fn ($q) => $q->whereNull('agency_id')->orWhere('agency_id', $agencyId))
                    ->orderBy('sort_order')
                    ->pluck('label')
                    ->values(),
                'lp_concerns' => $concerns,
                'outcomes' => $outcomes,
                'concerns' => $concerns,
            ]);
        }

        // Default: per-contact mode (viewings)
        $contacts = $calendarEvent->linkedContacts;
        $existingRows = \App\Models\CommandCenter\CalendarEventFeedback::query()
            ->where('calendar_event_id', $calendarEvent->id)
            ->get();
        // Per-contact key (collapses to the last row for a contact) — what the
        // calendar modal's single-property prefill consumes. Unchanged.
        $existing = $existingRows->keyBy('contact_id');

        return response()->json([
            'event' => [
                'id'    => $calendarEvent->id,
                'title' => $calendarEvent->title,
                'date'  => $calendarEvent->event_date->format('D, j M Y H:i'),
            ],
            'feedback_mode' => 'per_contact',
            'feedback_kind' => 'viewing',
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
            'properties' => $properties->map(fn ($p) => [
                'id'      => $p->id,
                'address' => method_exists($p, 'buildDisplayAddress') ? $p->buildDisplayAddress() : ($p->title ?? "Property #{$p->id}"),
            ]),
            // ADDITIVE (AT-114 pt2): every existing viewing-feedback row keyed by
            // its true (contact, property) pair, so a surface that renders one
            // block PER PROPERTY (the reusable from-anywhere modal) can pre-fill
            // each block independently instead of collapsing to one row per
            // contact. The calendar modal ignores this field — it steps one
            // property at a time and prefills from `contacts[].*` above.
            'existing_feedback' => $existingRows
                ->filter(fn ($r) => !is_null($r->contact_id))
                ->map(fn ($r) => [
                    'contact_id'           => $r->contact_id,
                    'property_id'          => $r->property_id,
                    'outcome_id'           => $r->outcome_option_id,
                    'concern_ids'          => $r->concern_option_ids ?? [],
                    'seller_visible_notes' => $r->seller_visible_notes,
                    'internal_notes'       => $r->internal_notes,
                    'next_action_notes'    => $r->next_action_notes,
                ])
                ->values(),
            'is_multi_property' => $properties->count() > 1,
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

        $feedbackKind = $request->input('feedback_kind', 'viewing');

        // Listing presentation mode: per-property feedback
        if ($feedbackKind === 'listing_presentation') {
            $data = $request->validate([
                'feedback'                        => 'required|array',
                'feedback.*.property_id'          => 'required|integer|exists:properties,id',
                'feedback.*.kind_specific_data'   => 'nullable|array',
                'feedback.*.internal_notes'       => 'nullable|string|max:5000',
                'feedback.*.next_action_notes'    => 'nullable|string|max:2000',
            ]);
        } else {
            $data = $request->validate([
                'feedback'                        => 'required|array',
                'feedback.*.contact_id'           => 'required|integer|exists:contacts,id',
                'feedback.*.property_id'          => 'nullable|integer|exists:properties,id',
                'feedback.*.outcome_id'           => 'nullable|integer|exists:agency_feedback_options,id',
                'feedback.*.concern_ids'          => 'nullable|array',
                'feedback.*.concern_ids.*'        => 'integer|exists:agency_feedback_options,id',
                'feedback.*.seller_visible_notes' => 'nullable|string|max:5000',
                'feedback.*.internal_notes'       => 'nullable|string|max:5000',
                'feedback.*.next_action_notes'    => 'nullable|string|max:2000',
            ]);
        }

        DB::transaction(function () use ($data, $calendarEvent, $user, $feedbackKind) {
            // Cross-agent feedback notification (Defect 3): collect the properties
            // whose feedback was actually created or changed in this capture, so a
            // no-op re-save (which only bumps captured_at) does NOT notify. Purely
            // additive — the writes below are unchanged.
            $notifyTouched   = [];
            $eventPropertyIds = null;
            $metaOnlyKeys = ['captured_at', 'captured_by_user_id', 'updated_at'];
            foreach ($data['feedback'] as $row) {
                if ($feedbackKind === 'listing_presentation') {
                    $fb = \App\Models\CommandCenter\CalendarEventFeedback::updateOrCreate(
                        [
                            'calendar_event_id' => $calendarEvent->id,
                            'property_id'       => $row['property_id'],
                            'feedback_kind'     => 'listing_presentation',
                        ],
                        [
                            'contact_id'         => null,
                            'visibility'         => 'internal_only',
                            'kind_specific_data' => $row['kind_specific_data'] ?? [],
                            'internal_notes'     => $row['internal_notes'] ?? null,
                            'next_action_notes'  => $row['next_action_notes'] ?? null,
                            'captured_by_user_id' => $user->id,
                            'captured_at'        => now(),
                            'agency_id'          => $calendarEvent->agency_id,
                            'branch_id'          => $calendarEvent->branch_id,
                        ]
                    );
                    if ($fb->wasRecentlyCreated || !empty(array_diff_key($fb->getChanges(), array_flip($metaOnlyKeys)))) {
                        $notifyTouched[$row['property_id']] = true;
                    }
                } else {
                    $fb = \App\Models\CommandCenter\CalendarEventFeedback::updateOrCreate(
                        [
                            'calendar_event_id' => $calendarEvent->id,
                            'contact_id'        => $row['contact_id'],
                            'property_id'       => $row['property_id'] ?? null,
                        ],
                        [
                            'feedback_kind'        => 'viewing',
                            'visibility'           => 'public_to_seller',
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
                    if ($fb->wasRecentlyCreated || !empty(array_diff_key($fb->getChanges(), array_flip($metaOnlyKeys)))) {
                        if (!empty($row['property_id'])) {
                            $notifyTouched[$row['property_id']] = true;
                        } else {
                            // Single-property viewing with no per-row property → attribute
                            // to the event's linked property/properties.
                            $eventPropertyIds ??= $calendarEvent->linkedProperties()->pluck('properties.id')->all();
                            foreach ($eventPropertyIds as $epid) {
                                $notifyTouched[$epid] = true;
                            }
                        }
                    }
                }
            }

            \App\Models\CommandCenter\CalendarEventAuditEntry::create([
                'calendar_event_id'    => $calendarEvent->id,
                'action'               => 'feedback_captured',
                'new_values'           => ['contact_count' => count($data['feedback'])],
                'performed_by_user_id' => $user->id,
                'performed_at'         => now(),
            ]);

            // Fan-out: log feedback_captured to buyer activity timelines.
            // Only meaningful for per-contact feedback (viewings). For
            // listing_presentation the rows are keyed by property_id, not
            // contact_id, so there's nothing to fan out to here.
            if ($feedbackKind !== 'listing_presentation') {
            $linkedPropertyIds = $calendarEvent->linkedProperties()->pluck('properties.id')->toArray();
            // Dedup buyer_property_views writes across the whole save: with
            // per-property feedback (AT-114 pt2) the payload carries one row per
            // (contact, property), so without this a single save would increment
            // each property's view_count once PER row — N× inflation. Each
            // (contact, property) pair is touched at most once per save.
            $seenViewPairs = [];
            foreach ($data['feedback'] as $row) {
                $contactId = $row['contact_id'];
                $contact = \App\Models\Contact::withoutGlobalScopes()->find($contactId);
                if ($contact && $contact->is_buyer) {
                    \App\Models\BuyerActivityLog::create([
                        'contact_id' => $contactId,
                        'agency_id' => $calendarEvent->agency_id ?? 1,
                        'activity_type' => 'feedback_captured',
                        'activity_date' => now(),
                        'related_event_id' => $calendarEvent->id,
                        'related_property_id' => $row['property_id'] ?? ($linkedPropertyIds[0] ?? null),
                        'metadata' => [
                            'event_title' => $calendarEvent->title,
                            'outcome_id' => $row['outcome_id'] ?? null,
                            'captured_by' => $user->name,
                        ],
                        'logged_by_user_id' => $user->id,
                    ]);

                    // Sync buyer_property_views. Per-property rows record their own
                    // property; a property-less row (legacy single viewing / meeting)
                    // falls back to every linked property. This is a RAW upsert
                    // (atomic COALESCE increment) so it bypasses the model's
                    // BelongsToAgency auto-stamp — agency_id MUST be set explicitly
                    // (event's agency = contact's & property's agency) or the INSERT
                    // 1364s on the NOT-NULL column.
                    $rowPropertyIds = !empty($row['property_id']) ? [$row['property_id']] : $linkedPropertyIds;
                    foreach ($rowPropertyIds as $propId) {
                        $pairKey = $contactId . ':' . $propId;
                        if (isset($seenViewPairs[$pairKey])) {
                            continue;
                        }
                        $seenViewPairs[$pairKey] = true;
                        DB::table('buyer_property_views')->updateOrInsert(
                            ['contact_id' => $contactId, 'property_id' => $propId],
                            [
                                'agency_id' => $calendarEvent->agency_id,
                                'last_viewed_at' => $calendarEvent->event_date,
                                'view_count' => DB::raw('COALESCE(view_count, 0) + 1'),
                                'updated_at' => now(),
                                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
                            ]
                        );
                    }

                    $contact->updateQuietly(['last_activity_at' => now()]);
                }
            }
            } // end if feedbackKind !== listing_presentation

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

            // Cross-agent feedback notification (Defect 3): for each property whose
            // feedback was created/changed above, notify its listing agent when that
            // agent is NOT the capturing agent. Per-property, so each agent hears only
            // about their own listing(s). Guarded so a dispatch failure can never roll
            // back the feedback capture. Contact-facing notice is out of scope (live
            // links cover that).
            if (!empty($notifyTouched)) {
                $dispatcher = app(\App\Services\CommandCenter\NotificationDispatcher::class);
                // First captured buyer (contact) per property, for the notification body.
                $buyerByProperty = [];
                foreach ($data['feedback'] as $row) {
                    $pid = $row['property_id'] ?? null;
                    if ($pid && !array_key_exists($pid, $buyerByProperty) && !empty($row['contact_id'])) {
                        $c = \App\Models\Contact::withoutGlobalScopes()->find($row['contact_id']);
                        $buyerByProperty[$pid] = $c ? (trim($c->first_name . ' ' . $c->last_name) ?: null) : null;
                    }
                }
                $kindLabel = $feedbackKind === 'listing_presentation' ? 'listing presentation' : 'viewing';
                foreach (array_keys($notifyTouched) as $pid) {
                    try {
                        $property = \App\Models\Property::withoutGlobalScopes()->with('agent')->find($pid);
                        // Skip silently: missing property, no listing agent, self-capture,
                        // or the agent record is gone.
                        if (!$property || empty($property->agent_id)
                            || (int) $property->agent_id === (int) $user->id
                            || !$property->agent) {
                            continue;
                        }
                        $addr = $property->address;
                        if (blank($addr)) {
                            $addr = trim(trim(($property->street_number ?? '') . ' ' . ($property->street_name ?? '')) . ', ' . ($property->suburb ?? ''), ' ,');
                        }
                        $addr = $addr ?: ($property->title ?: ('Property #' . $property->id));
                        $buyer = $buyerByProperty[$pid] ?? null;
                        $dispatcher->fire(
                            $property->agent,
                            'property.feedback_captured',
                            $property,
                            [
                                'title'      => $user->name . ' captured ' . $kindLabel . ' feedback',
                                'body'       => $addr . ($buyer ? ' — ' . $buyer : ''),
                                'action_url' => route('corex.properties.show', $property->id) . '#recent-viewings-feedback',
                                'severity'   => 'info',
                            ]
                        );
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('Cross-agent feedback notification failed', [
                            'property' => $pid, 'capturer' => $user->id, 'error' => $e->getMessage(),
                        ]);
                    }
                }
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
            'property_ids'      => 'nullable|array',
            'property_ids.*'    => 'integer|exists:properties,id',
            'contact_ids'       => 'nullable|array',
            'contact_ids.*'     => 'integer|exists:contacts,id',
            'attendees'         => 'nullable|array',
            'attendees.*.id'    => 'required_with:attendees|integer',
            'attendees.*.type'  => 'required_with:attendees|string|in:contact,agent',
            // Whitelist the per-attendee role so $request->validate() does NOT
            // strip it. Without this the role never reached syncEventLinks
            // (which honours $attendee['role']) → it fell back to the class
            // default (attendee/seller) and a "Schedule Viewing" buyer was
            // mis-bucketed instead of landing under BUYERS. buildLinkedRecords
            // already maps buyer_contact => buyers correctly.
            'attendees.*.role'  => 'nullable|string|in:attendee,buyer_contact,seller_contact,agent_contact',
            'deal_id'           => 'nullable|integer',
            // Per-event "requires feedback" choice (actionable) vs "no feedback
            // needed" (informational). Stored in metadata; overrides the class default.
            'event_nature'      => 'nullable|in:actionable,informational',
            // Recurrence (optional): none|DAILY|WEEKLY|MONTHLY + interval + end.
            'recur_freq'        => 'nullable|in:DAILY,WEEKLY,MONTHLY',
            'recur_interval'    => 'nullable|integer|min:1|max:99',
            'recur_end_type'    => 'nullable|in:never,until,count',
            'recur_until'       => 'nullable|date',
            'recur_count'       => 'nullable|integer|min:1|max:1000',
        ]);

        // Build the RRULE from the recurrence inputs (if any) → is_recurring +
        // recurrence_rule on the parent. The creator writes them onto the event.
        $data = $this->applyRecurrenceInputs($data);

        // Create + link + invite through the shared CalendarEventCreator so the
        // web cockpit and the v1 mobile API build events identically.
        $event = $this->creator->create($data, $user);

        if ($request->wantsJson()) {
            return response()->json($event, 201);
        }

        return redirect()
            ->route('command-center.calendar', ['view' => 'day', 'date' => Carbon::parse($event->event_date)->toDateString()])
            ->with('success', 'Event created.');
    }

    /** Build recurrence_rule + is_recurring from the form's recur_* inputs. */
    private function applyRecurrenceInputs(array $data): array
    {
        if (empty($data['recur_freq'])) {
            $data['is_recurring'] = false;
            $data['recurrence_rule'] = null;
            return $data;
        }
        $rule = \App\Services\CommandCenter\Calendar\RecurrenceRule::build(
            $data['recur_freq'],
            (int) ($data['recur_interval'] ?? 1),
            $data['recur_end_type'] ?? 'never',
            $data['recur_until'] ?? null,
            isset($data['recur_count']) ? (int) $data['recur_count'] : null,
        );
        $data['recurrence_rule'] = $rule;
        $data['is_recurring'] = $rule !== null;
        return $data;
    }

    public function reschedule(Request $request, CalendarEvent $calendarEvent)
    {
        if (!$this->visibilityResolver->canSee($calendarEvent, $request->user())) {
            abort(403);
        }

        // ITEM 4 — a private event may only be moved by its creator (role-blind).
        if ($calendarEvent->isPrivateHiddenFrom($request->user())) { abort(403); }

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

        // ITEM 4 — a private event may only be edited by its creator (role-blind).
        if ($calendarEvent->isPrivateHiddenFrom($user)) { abort(403); }

        $data = $request->validate([
            'title'             => 'sometimes|required|string|max:255',
            'category'          => 'nullable|string|in:' . implode(',', self::MANUAL_CREATABLE_CLASSES),
            'event_date'        => 'sometimes|required|date',
            'end_date'          => 'nullable|date|after_or_equal:event_date',
            'description'       => 'nullable|string|max:2000',
            'status'            => 'nullable|in:pending,completed,overdue,dismissed',
            'priority'          => 'nullable|in:low,normal,high,critical',
            'property_id'       => 'nullable|integer|exists:properties,id',
            'property_ids'      => 'nullable|array',
            'property_ids.*'    => 'integer|exists:properties,id',
            'contact_ids'       => 'nullable|array',
            'contact_ids.*'     => 'integer|exists:contacts,id',
            'attendees'         => 'nullable|array',
            'attendees.*.id'    => 'required_with:attendees|integer',
            'attendees.*.type'  => 'required_with:attendees|string|in:contact,agent',
            'attendees.*.role'  => 'nullable|string',
            'deal_id'           => 'nullable|integer',
            // Per-event "requires feedback" choice (actionable) vs "no feedback
            // needed" (informational). Stored in metadata; overrides the class default.
            'event_nature'      => 'nullable|in:actionable,informational',
            // Recurrence + edit-scope.
            'recur_freq'        => 'nullable|in:DAILY,WEEKLY,MONTHLY',
            'recur_interval'    => 'nullable|integer|min:1|max:99',
            'recur_end_type'    => 'nullable|in:never,until,count',
            'recur_until'       => 'nullable|date',
            'recur_count'       => 'nullable|integer|min:1|max:1000',
            'recur_scope'       => 'nullable|in:this,future,all',
            'occurrence_date'   => 'nullable|date',
        ]);

        // Recurring series edit with an explicit scope. "this"/"future" fork into
        // the RecurrenceEditService (exception child / series split); "all" and
        // non-recurring fall through to the normal parent update below.
        $scope = $data['recur_scope'] ?? null;
        $occ   = $data['occurrence_date'] ?? null;
        if ($calendarEvent->is_recurring && $occ && in_array($scope, ['this', 'future'], true)) {
            $svc = app(\App\Services\CommandCenter\Calendar\RecurrenceEditService::class);
            $result = $scope === 'this'
                ? $svc->editOccurrence($calendarEvent, $occ, $data, $user)
                : $svc->editFuture($calendarEvent, $occ, $data, $user);
            return $request->wantsJson()
                ? response()->json(['success' => true, 'id' => $result->id])
                : back()->with('success', 'Occurrence updated.');
        }

        // "all" / non-recurring / add-recurrence-on-edit: rebuild is_recurring +
        // recurrence_rule from the recur_* inputs, then update the parent in place.
        $data = $this->applyRecurrenceInputs($data);

        $oldValues = $calendarEvent->only(['title', 'category', 'event_date', 'end_date', 'description', 'property_id']);

        DB::transaction(function () use ($calendarEvent, $data, $user, $oldValues) {
            $calendarEvent->update(collect($data)->only([
                'title', 'category', 'event_date', 'end_date', 'description',
                'status', 'priority', 'property_id',
                'is_recurring', 'recurrence_rule',
            ])->filter(fn ($v, $k) => $v !== null || in_array($k, ['end_date', 'description', 'property_id', 'recurrence_rule']))->all());

            // Per-event "requires feedback" choice → metadata['event_nature'].
            // Merge (don't clobber other metadata keys); absent input leaves it as-is.
            if (in_array($data['event_nature'] ?? null, ['actionable', 'informational'], true)) {
                $meta = $calendarEvent->metadata ?? [];
                $meta['event_nature'] = $data['event_nature'];
                $calendarEvent->update(['metadata' => $meta]);
            }

            // Update direct contact FK from attendees
            if (array_key_exists('attendees', $data)) {
                $firstContact = collect($data['attendees'] ?? [])->firstWhere('type', 'contact');
                $calendarEvent->update(['contact_id' => $firstContact['id'] ?? null]);
            } elseif (array_key_exists('contact_ids', $data)) {
                $calendarEvent->update(['contact_id' => ($data['contact_ids'] ?? [])[0] ?? null]);
            }

            // Re-sync pivot links
            if (array_key_exists('property_id', $data) || array_key_exists('contact_ids', $data) || array_key_exists('attendees', $data) || array_key_exists('deal_id', $data)) {
                $this->creator->syncEventLinks($calendarEvent, $data, $user);
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

            // Fix 5: Time-change re-notification to accepted attendees
            if (isset($changed['event_date']) || isset($changed['end_date'])) {
                $invitations = \App\Models\CommandCenter\CalendarEventInvitation::where('event_id', $calendarEvent->id)
                    ->whereIn('status', ['accepted', 'tentative'])->get();
                foreach ($invitations as $inv) {
                    DB::table('notifications')->insert([
                        'id' => \Illuminate\Support\Str::uuid(),
                        'type' => 'event_time_changed',
                        'notifiable_type' => 'App\\Models\\User',
                        'notifiable_id' => $inv->invitee_user_id,
                        'data' => json_encode([
                            'message' => 'Time changed: ' . $calendarEvent->title . ' is now ' . $calendarEvent->fresh()->event_date->format('D d M, H:i'),
                            'event_id' => $calendarEvent->id,
                            'old_start' => $oldValues['event_date'] ?? null,
                            'new_start' => $changed['event_date'] ?? null,
                        ]),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });

        $event = $calendarEvent->fresh();
        return $request->wantsJson() ? response()->json($event) : back()->with('success', 'Event updated.');
    }

    public function destroy(Request $request, CalendarEvent $calendarEvent)
    {
        // ITEM 4 — a private event may only be deleted by its creator (role-blind).
        if ($calendarEvent->isPrivateHiddenFrom($request->user())) { abort(403); }

        // Only user-created (manual) events are deletable from the calendar —
        // source-driven events (deal steps, birthdays) are removed at their source,
        // not here. Mirrors is_editable, and guards the endpoint against a crafted
        // request soft-deleting a system-generated row.
        if (!in_array($calendarEvent->source_type, ['manual', 'manual:demo'], true)) {
            return $request->wantsJson()
                ? response()->json(['error' => 'This event cannot be deleted from the calendar.'], 422)
                : back()->with('error', 'This event cannot be deleted from the calendar.');
        }

        // Recurring series delete with an explicit scope. "this" tombstones one
        // occurrence, "future" truncates the series, "all" soft-deletes the parent
        // (+ its exception children). No hard deletes on any path.
        $scope = $request->input('recur_scope');
        $occ   = $request->input('occurrence_date');
        if ($calendarEvent->is_recurring && in_array($scope, ['this', 'future', 'all'], true)) {
            $svc = app(\App\Services\CommandCenter\Calendar\RecurrenceEditService::class);
            if ($scope === 'this' && $occ) {
                $svc->deleteOccurrence($calendarEvent, $occ, $request->user());
            } elseif ($scope === 'future' && $occ) {
                $svc->deleteFuture($calendarEvent, $occ);
            } else {
                $svc->deleteAll($calendarEvent);
            }
            // Audit the scoped delete on the series parent.
            \App\Models\CommandCenter\CalendarEventAuditEntry::create([
                'calendar_event_id'    => $calendarEvent->id,
                'action'               => 'deleted',
                'old_values'           => ['recur_scope' => $scope, 'occurrence_date' => $occ, 'title' => $calendarEvent->title],
                'new_values'           => ['deleted' => true, 'scope' => $scope],
                'performed_by_user_id' => $request->user()->id,
                'performed_at'         => now(),
                'notes'                => "Recurring event deleted (scope: {$scope})",
            ]);
            return $request->wantsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Event removed.');
        }

        // Fix 6: Cancel cascade — notify attendees + cancel invitations
        $invitations = \App\Models\CommandCenter\CalendarEventInvitation::where('event_id', $calendarEvent->id)
            ->whereIn('status', ['pending', 'accepted', 'tentative'])->get();
        foreach ($invitations as $inv) {
            $inv->update(['status' => 'cancelled']);
            DB::table('notifications')->insert([
                'id' => \Illuminate\Support\Str::uuid(),
                'type' => 'event_cancelled',
                'notifiable_type' => 'App\\Models\\User',
                'notifiable_id' => $inv->invitee_user_id,
                'data' => json_encode([
                    'message' => 'Event cancelled: ' . $calendarEvent->title,
                    'event_id' => $calendarEvent->id,
                    'cancelled_by' => auth()->user()->name ?? 'Unknown',
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Audit the soft-delete before it happens (captures the pre-delete state).
        \App\Models\CommandCenter\CalendarEventAuditEntry::create([
            'calendar_event_id'    => $calendarEvent->id,
            'action'               => 'deleted',
            'old_values'           => $calendarEvent->only(['title', 'event_date', 'status', 'category']),
            'new_values'           => ['deleted' => true],
            'performed_by_user_id' => $request->user()->id,
            'performed_at'         => now(),
            'notes'                => 'Event soft-deleted from calendar panel',
        ]);

        $this->service->delete($calendarEvent);
        return $request->wantsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Event removed.');
    }

    public function complete(Request $request, CalendarEvent $calendarEvent)
    {
        // Deal step bridge: if this calendar event is linked to a DealStepInstance,
        // complete the deal step instead (observer will cascade to calendar event)
        if ($calendarEvent->source_type === \App\Models\DealV2\DealStepInstance::class && $calendarEvent->source_id) {
            $step = \App\Models\DealV2\DealStepInstance::find($calendarEvent->source_id);
            if ($step && in_array($step->status, ['active', 'not_started'])) {
                $pipelineService = app(\App\Services\DealV2\DealPipelineService::class);
                $pipelineService->completeStep($step, $request->user(), [
                    'outcome' => 'positive',
                    'notes' => $request->input('notes', 'Completed from calendar'),
                ]);

                $msg = "Deal step \"{$step->name}\" completed — deal pipeline advanced.";
                return $request->wantsJson()
                    ? response()->json(['ok' => true, 'message' => $msg])
                    : back()->with('success', $msg);
            }
        }

        // Default: mark calendar event complete directly (non-deal events)
        $calendarEvent->markCompleted();
        return $request->wantsJson()
            ? response()->json(['ok' => true])
            : back()->with('success', 'Event completed.');
    }

    public function dismiss(CalendarEvent $calendarEvent)
    {
        $calendarEvent->markDismissed();
        return back()->with('success', 'Event dismissed.');
    }

    // ── Private helpers ──

    /**
     * Birthday / anniversary markers are hidden from the calendar by default —
     * they cluttered the grid. They remain available when the user explicitly
     * selects one of these categories in the filter.
     */
    private const HIDDEN_BY_DEFAULT_CATEGORIES = ['agent_birthday', 'contact_birthday', 'employment_anniversary'];

    /**
     * AT-164 Gate 1 — species split + server-side deadline aggregation.
     *
     * Every calendar row is either an APPOINTMENT (a real timed thing —
     * occupies_time=true — that keeps its bar/chip and can conflict) or a SYSTEM
     * DEADLINE (a point-in-time all-day marker — occupies_time=false — the
     * portal-expiry / compliance / rent noise). Deadlines no longer render one bar
     * each; they collapse to ONE compact chip per (day × group), coloured by the
     * WORST RAG in the group. `occupies_time` (per class, the conflict-detection
     * source of truth) is the classifier — no feed change, no hardcoded class list.
     *
     * @param  array<string,array>  $filteredByDate  date => resolved-event[]
     * @return array{0:array<string,array>,1:array<string,array>} [appointmentByDate, deadlineGroupsByDate]
     */
    /**
     * AT-164 Gate 5 — build ONE month's block data: the 6-week grid, the appointment
     * species split by date, the aggregate deadline groups, and the filtered spanning
     * bars. Shared by the full-page render and the /calendar/month-block endpoint so
     * the continuous-scroll windows render through the identical pipeline.
     *
     * @return array{grid:array,byDate:array,deadlineGroups:array,spanningBars:array,filteredEvents:\Illuminate\Support\Collection}
     */
    private function monthBlockData($user, int $year, int $month, array $typeFilter, array $categoryFilter, string $scope): array
    {
        $grid = $this->service->getMonthGrid($user, $year, $month, [], $scope);

        $filteredByDate = [];
        foreach ($grid['byDate'] as $dateKey => $dayEvents) {
            $resolved = $this->applyFilters(collect($dayEvents), $user, $typeFilter, $categoryFilter, $scope);
            if ($resolved->isNotEmpty()) {
                $filteredByDate[$dateKey] = $resolved->all();
            }
        }
        $filteredEvents = collect($filteredByDate)->flatten(1);

        // Gate 1 — appointments keep their chips/bars; deadlines collapse to one chip per group.
        [$appointmentByDate, $deadlineGroupsByDate] = $this->splitSpeciesForGrid($filteredByDate, $user);

        // Filter spanning bars (multi-day events) through the same filter logic.
        $filteredSpanningBars = [];
        foreach ($grid['spanningBars'] ?? [] as $bar) {
            $filtered = $this->applyFilters(collect([$bar['event']]), $user, $typeFilter, $categoryFilter, $scope);
            if ($filtered->isNotEmpty()) {
                $bar['event'] = $filtered->first();
                // ITEM 4 — re-sync title so a redacted private bar never leaks its real title.
                $bar['title'] = $bar['event']->title;
                // AT-164 Gate 6 — tag the bar with its layer so the client can show/hide it.
                $bar['layer'] = \App\Services\CommandCenter\Calendar\CalendarLayers::layerFor(
                    $bar['event'], $this->isAppointmentEvent($bar['event'], $user)
                );
                $filteredSpanningBars[] = $bar;
            }
        }

        return [
            'grid'           => $grid,
            'byDate'         => $appointmentByDate,
            'deadlineGroups' => $deadlineGroupsByDate,
            'spanningBars'   => $filteredSpanningBars,
            'filteredEvents' => $filteredEvents,
        ];
    }

    /**
     * AT-164 Gate 5 — render ONE month block (HTML) for the continuous-scroll month
     * view. The Alpine windowing controller lazy-appends/prepends these as the user
     * scrolls, so every window uses the SAME _month-block partial as the initial render.
     */
    public function monthBlock(Request $request)
    {
        $user  = $request->user();
        $year  = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);
        // Clamp to a sane span so a crafted query can't expand thousands of months.
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            abort(422, 'Invalid month.');
        }

        $typeFilter     = $request->input('types', []);
        $categoryFilter = $request->input('categories', []);
        $scope = PermissionService::clampScope($request->input('scope'), PermissionService::calendarScope($user));

        $block = $this->monthBlockData($user, $year, $month, $typeFilter, $categoryFilter, $scope);

        return view('command-center.calendar.partials._month-block', [
            'year'           => $year,
            'month'          => $month,
            'grid'           => $block['grid'],
            'byDate'         => $block['byDate'],
            'deadlineGroups' => $block['deadlineGroups'],
            'spanningBars'   => $block['spanningBars'],
        ]);
    }

    /**
     * AT-164 Gate 5/7 — JSON range endpoint returning the aggregated grid shape
     * ({byDate, deadlineGroups, spanningBars}) for an arbitrary date range. Feeds the
     * live-RAG refresh loop (Gate 7) and any programmatic windowing.
     */
    public function gridRange(Request $request)
    {
        $user  = $request->user();
        $start = $request->get('start');
        $end   = $request->get('end');
        try {
            $rangeStart = $start ? Carbon::parse($start)->startOfDay() : now()->startOfMonth();
            $rangeEnd   = $end ? Carbon::parse($end)->endOfDay() : now()->endOfMonth();
        } catch (\Throwable $e) {
            abort(422, 'Invalid range.');
        }
        // Cap the window (agency expansion limit) so the endpoint can't be asked for years.
        $maxDays = \App\Models\AgencyContactSettings::forAgency($user->effectiveAgencyId() ?? 1)->calendarMaxExpansionDays();
        if ($rangeStart->diffInDays($rangeEnd) > $maxDays) {
            $rangeEnd = $rangeStart->copy()->addDays($maxDays)->endOfDay();
        }

        $typeFilter     = $request->input('types', []);
        $categoryFilter = $request->input('categories', []);
        $scope = PermissionService::clampScope($request->input('scope'), PermissionService::calendarScope($user));

        $resolved = $this->applyFilters(
            $this->service->getEventsForRange($user, $rangeStart->toDateString(), $rangeEnd->toDateString(), [], $scope),
            $user, $typeFilter, $categoryFilter, $scope
        );

        // Species split by date (same aggregation the grid uses).
        $byDateRaw = [];
        foreach ($resolved as $e) {
            if (! $e->event_date) continue;
            $byDateRaw[$e->event_date->toDateString()][] = $e;
        }
        [$appointmentByDate, $deadlineGroupsByDate] = $this->splitSpeciesForGrid($byDateRaw, $user);

        // Shape appointments to a lean JSON payload.
        $byDate = [];
        foreach ($appointmentByDate as $date => $events) {
            $byDate[$date] = array_map(fn ($e) => [
                'id' => $e->id, 'title' => $e->title,
                'colour' => $e->resolved_colour, 'category' => $e->category,
                'event_type' => $e->event_type, 'status' => $e->status,
                'all_day' => (bool) $e->all_day,
                'time' => $e->all_day ? null : $e->event_date->format('H:i'),
            ], $events);
        }

        return response()->json([
            'byDate'         => $byDate,
            'deadlineGroups' => $deadlineGroupsByDate,
            'start'          => $rangeStart->toDateString(),
            'end'            => $rangeEnd->toDateString(),
        ]);
    }

    /** Memo of occupies_time by class (per request). */
    private array $occByClass = [];

    /** AT-164 Gate 6 — is this event an appointment species (occupies_time=true)? */
    private function isAppointmentEvent($event, $user): bool
    {
        $agencyId = method_exists($user, 'effectiveAgencyId') ? $user->effectiveAgencyId() : ($user->agency_id ?? null);
        $class = (string) $event->category;
        if (! array_key_exists($class, $this->occByClass)) {
            $cfg = CalendarEventClassSetting::forAgencyAndClass($agencyId, $class);
            $this->occByClass[$class] = $cfg ? (bool) $cfg->occupies_time : true; // unknown → appointment
        }
        return $this->occByClass[$class] === true;
    }

    private function splitSpeciesForGrid(array $filteredByDate, $user): array
    {
        $agencyId = method_exists($user, 'effectiveAgencyId') ? $user->effectiveAgencyId() : ($user->agency_id ?? null);

        // occupies_time per class, memoised. A class the settings don't cover is
        // treated as an appointment (never silently aggregate an unknown class).
        $occ = [];
        $isDeadline = function ($event) use (&$occ, $agencyId): bool {
            $class = (string) $event->category;
            if (! array_key_exists($class, $occ)) {
                $cfg = CalendarEventClassSetting::forAgencyAndClass($agencyId, $class);
                $occ[$class] = $cfg ? (bool) $cfg->occupies_time : true;
            }
            return $occ[$class] === false;
        };

        $rank = ['red' => 3, 'amber' => 2, 'green' => 1, 'neutral' => 0];
        $groupLabels = [
            'deal' => 'Deals', 'document' => 'Documents', 'lease' => 'Rent & Lease',
            'property' => 'Listings', 'people' => 'People', 'compliance' => 'Compliance',
            'payroll' => 'Payroll', 'recurring' => 'Recurring', 'personal' => 'Personal',
        ];

        $appointmentByDate = [];
        $deadlineGroupsByDate = [];

        foreach ($filteredByDate as $dateStr => $events) {
            $groups = []; // group key => aggregate
            foreach ($events as $event) {
                if (! $isDeadline($event)) {
                    $appointmentByDate[$dateStr][] = $event;
                    continue;
                }
                $type   = (string) ($event->event_type ?: 'other');
                $colour = $event->resolved_colour ?? 'neutral';
                if (! isset($groups[$type])) {
                    $groups[$type] = [
                        'group' => $type,
                        'label' => $groupLabels[$type] ?? \Illuminate\Support\Str::headline($type),
                        'count' => 0,
                        'worst' => 'neutral',
                        'items' => [], // AT-164 Gate 2 — popover rows (title + RAG + due + new-tab link)
                    ];
                }
                $groups[$type]['count']++;
                // Gate 2 — per-item drill-down: a deep link where the source resolves
                // (new tab), else null → the client opens the event's in-page panel.
                $link = $this->resolveSourceLink($event);
                $groups[$type]['items'][] = [
                    'id'    => $event->id,
                    'title' => (string) $event->title,
                    'rag'   => $colour,
                    'due'   => $event->event_date ? $event->event_date->format('d M') : null,
                    'url'   => $link['url'] ?? null,
                ];
                if (($rank[$colour] ?? 0) > ($rank[$groups[$type]['worst']] ?? 0)) {
                    $groups[$type]['worst'] = $colour;
                }
            }
            if ($groups) {
                $list = array_values($groups);
                usort($list, fn ($a, $b) => ($rank[$b['worst']] ?? 0) <=> ($rank[$a['worst']] ?? 0));
                $deadlineGroupsByDate[$dateStr] = $list;
            }
        }

        return [$appointmentByDate, $deadlineGroupsByDate];
    }

    private function applyFilters(Collection $events, $user, array $typeFilter, array $categoryFilter, string $scope): Collection
    {
        $filtered = $events
            ->when(!empty($typeFilter), fn ($c) => $c->whereIn('event_type', $typeFilter))
            ->when(!empty($categoryFilter), fn ($c) => $c->whereIn('category', $categoryFilter))
            // No explicit category filter → suppress birthdays/anniversaries by default.
            ->when(empty($categoryFilter), fn ($c) => $c->whereNotIn('category', self::HIDDEN_BY_DEFAULT_CATEGORIES))
            ->when($scope === 'own', fn ($c) => $c->where('user_id', $user->id))
            ->when($scope === 'branch' && $user->branch_id, fn ($c) => $c->where('branch_id', $user->branch_id));

        $visible = $this->visibilityResolver->filterVisible($filtered, $user);
        $result = collect($visible)->map(function ($event) {
            $event->resolved_colour = $this->thresholdResolver->resolveForEvent($event);
            return $event;
        })->filter(fn ($e) => $e->resolved_colour !== null)->values();

        // Batch invitation status lookup (Fix 4 — single query, not N+1)
        $eventIds = $result->pluck('id')->toArray();
        if (!empty($eventIds)) {
            $invitationStatuses = DB::table('calendar_event_invitations')
                ->where('invitee_user_id', $user->id)
                ->whereIn('event_id', $eventIds)
                ->pluck('status', 'event_id');
            foreach ($result as $event) {
                $event->user_invitation_status = $invitationStatuses[$event->id] ?? null;
            }
        }

        // Conflict markers: mark events that overlap another appointment-type event for this user.
        // Single sweep — no additional queries.
        // Markers/reminders (occupies_time=false) never count as conflicts —
        // reads the explicit flag (decoupled from actor_role). A category with no
        // settings row is treated as an appointment (unchanged behaviour).
        $nonOccupyingClasses = CalendarEventClassSetting::withoutGlobalScopes()
            ->where('occupies_time', false)->pluck('event_class')->toArray();
        $appointments = $result->filter(fn($e) => !in_array($e->category, $nonOccupyingClasses))
            ->sortBy('event_date')->values();
        $conflictIds = [];
        for ($i = 0; $i < $appointments->count(); $i++) {
            for ($j = $i + 1; $j < $appointments->count(); $j++) {
                $a = $appointments[$i];
                $b = $appointments[$j];
                if ($b->event_date < ($a->end_date ?? $a->event_date)) {
                    $conflictIds[$a->id] = true;
                    $conflictIds[$b->id] = true;
                } else {
                    break; // sorted, no further overlaps for $i
                }
            }
        }
        // Unacknowledged decline markers (batch lookup)
        $unackDeclines = [];
        if (!empty($eventIds)) {
            $unackDeclines = DB::table('calendar_event_invitations')
                ->where('status', 'declined')
                ->whereNull('acknowledged_at')
                ->whereIn('event_id', $eventIds)
                ->select('event_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('event_id')
                ->pluck('cnt', 'event_id')
                ->toArray();
        }

        foreach ($result as $event) {
            $event->has_conflict = isset($conflictIds[$event->id]);
            $event->has_unack_decline = isset($unackDeclines[$event->id]);
            $event->unack_decline_count = $unackDeclines[$event->id] ?? 0;
        }

        // ITEM 4 — redact private events for everyone but their creator. Done as
        // the LAST step so all internal computation (conflicts, scope, colour)
        // ran on real data; only the display payload is stripped. Role-blind —
        // no admin/owner override. This single pass covers EVERY server-rendered
        // view (day/week/month/agenda) AND the events() JSON, because both funnel
        // through applyFilters(); the busy block still shows (title "Private",
        // time, colour) so others can see the slot is taken.
        foreach ($result as $event) {
            $event->applyPrivacyFor($user);
        }

        return $result;
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

    /**
     * Build linked records array for the detail panel deep-links.
     * Returns all navigable entities linked to this event.
     */
    private function buildLinkedRecords(CalendarEvent $event, $user): array
    {
        $records = [];

        // Linked properties
        $properties = $event->linkedProperties;
        foreach ($properties as $p) {
            try {
                $records[] = [
                    'type' => 'property', 'group' => 'properties', 'icon' => 'building',
                    'label' => 'Property', 'name' => method_exists($p, 'buildDisplayAddress') ? $p->buildDisplayAddress() : ($p->title ?? "Property #{$p->id}"),
                    'url' => route('corex.properties.show', $p->id),
                ];
            } catch (\Throwable $e) {}
        }

        // Linked contacts with role grouping
        if ($user->hasPermission('access_contacts')) {
            $links = $event->links()->where('linkable_type', \App\Models\Contact::class)->get();
            foreach ($links as $link) {
                $c = \App\Models\Contact::withoutGlobalScopes()->find($link->linkable_id);
                if (!$c) continue;
                $role = $link->role ?? 'attendee';
                $group = match ($role) {
                    'buyer_contact' => 'buyers',
                    'seller_contact' => 'sellers',
                    default => 'attendees',
                };
                $badge = match ($role) {
                    'buyer_contact' => 'Buyer',
                    'seller_contact' => 'Seller',
                    default => null,
                };
                try {
                    $records[] = [
                        'type' => 'contact', 'group' => $group, 'icon' => 'person',
                        'id' => $c->id,
                        'label' => $badge ?? 'Attendee',
                        'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: "Contact #{$c->id}",
                        'url' => $c->is_buyer ? route('command-center.buyers.show', $c->id) : route('corex.contacts.show', $c->id),
                        'badge' => $badge,
                    ];
                } catch (\Throwable $e) {}
            }

            // Auto-derive sellers from linked properties (even if not on attendee list)
            // Dedup by contact ID across ALL groups (sellers, attendees, buyers)
            $seenContactIds = collect($records)
                ->where('type', 'contact')
                ->pluck('id')
                ->filter()
                ->toArray();
            foreach ($properties as $p) {
                $owners = $p->contacts()->wherePivotIn('role', ['owner', 'seller', 'landlord', 'lessor'])->get();
                foreach ($owners as $owner) {
                    if (in_array($owner->id, $seenContactIds)) continue;
                    $seenContactIds[] = $owner->id;
                    $records[] = [
                        'type' => 'contact', 'group' => 'sellers', 'icon' => 'person',
                        'id' => $owner->id,
                        'label' => 'Seller', 'name' => $owner->full_name,
                        'url' => route('corex.contacts.show', $owner->id), 'badge' => 'Seller',
                    ];
                }
            }
        }

        // Agent on event
        if ($event->user_id) {
            $agent = \App\Models\User::withoutGlobalScopes()->find($event->user_id);
            if ($agent) {
                $records[] = [
                    'type' => 'agent', 'group' => 'agents', 'icon' => 'person',
                    'label' => 'Agent', 'name' => $agent->name,
                    'url' => '#', 'badge' => 'Agent',
                ];
            }
        }

        // Linked deals
        $deals = $event->linkedDeals;
        foreach ($deals as $d) {
            try {
                $records[] = [
                    'type' => 'deal', 'group' => 'deals', 'icon' => 'briefcase',
                    'label' => 'Deal', 'name' => $d->reference ?? "Deal #{$d->id}",
                    'url' => route('deals-v2.show', $d->id),
                ];
            } catch (\Throwable $e) {}
        }

        // Source entity (if different from above and has a resolvable link)
        $sourceLink = $this->resolveSourceLink($event);
        if ($sourceLink && !collect($records)->contains('url', $sourceLink['url'])) {
            $records[] = [
                'type' => 'source',
                'icon' => 'link',
                'label' => $sourceLink['label'],
                'name' => $event->title,
                'url' => $sourceLink['url'],
            ];
        }

        return $records;
    }

    private function resolveSourceLink(CalendarEvent $event): ?array
    {
        // AT-164 — delegated to the shared resolver so the chip popover (Gate 2)
        // and the Deck's Notifications tile (Gate 4) never diverge on the route map.
        return \App\Services\CommandCenter\Calendar\CalendarSourceLinkResolver::resolve($event);
    }

    /**
     * Return ALL linked contacts for a property — used by the create-event
     * panel's property-select auto-fill.
     *
     * CAL-6 — rewritten as a raw DB::table join (no Eloquent BelongsToMany).
     * The previous Eloquent version was correct in isolation but relied on
     * the BelongsToMany relation to apply the pivot WHERE clause implicitly,
     * AND on Contact's global scopes (BelongsToAgency + ContactScope) to
     * NOT widen the result at scale. Both of those depend on layers above
     * the SQL — any scope override (sub-class, side-effect inside another
     * scope, a future relationship rewrite) could re-introduce the
     * "returns contacts not in the pivot" failure mode. The raw join
     * makes the WHERE clauses inarguable:
     *
     *   FROM contact_property cp
     *   JOIN contacts c ON c.id = cp.contact_id
     *   WHERE cp.property_id = ?       — explicit pivot filter
     *     AND c.deleted_at IS NULL     — explicit soft-delete filter
     *     AND c.agency_id = ?          — cross-agency belt-and-braces
     *                                    (the property's own agency_id)
     *
     * No global scopes apply to DB::table queries, so the surface area
     * for "scale-dependent widening" is reduced to the SQL above — and
     * that SQL has no way to return a contact whose pivot row does not
     * point at this property.
     */
    public function propertyOwners(Request $request, int $propertyId)
    {
        $property = \App\Models\Property::find($propertyId);
        if (!$property) {
            return response()->json([]);
        }

        // Map a free-form pivot.role to the attendee_role enum we save into
        // calendar_event_links. The enum values are validated server-side in
        // store()/update() (see attendees.*.role validation). Anything that
        // doesn't fall into the seller-side or buyer-side bucket is the
        // neutral 'attendee' — never excluded.
        $toAttendeeRole = static function (?string $pivotRole): string {
            $r = strtolower(trim((string) $pivotRole));
            return match (true) {
                in_array($r, ['seller', 'owner', 'landlord', 'lessor'], true) => 'seller_contact',
                in_array($r, ['buyer', 'tenant', 'lessee'], true)             => 'buyer_contact',
                default                                                       => 'attendee',
            };
        };

        // Human-readable label for the chip. Capitalises the raw pivot.role
        // ('owner' -> 'Owner'). Blank pivots get null so the chip can render
        // the neutral default rather than a fabricated label.
        $toRoleLabel = static function (?string $pivotRole): ?string {
            $r = trim((string) $pivotRole);
            return $r === '' ? null : ucfirst(strtolower($r));
        };

        // Raw join — every WHERE clause visible in one SELECT statement.
        // No global scopes apply (DB::table bypasses Eloquent). The cross-
        // agency check (c.agency_id = property.agency_id) is belt-and-
        // braces — a single agency owns both sides of the link by
        // construction, but the explicit predicate kills any future
        // cross-agency pivot rows dead.
        $rows = DB::table('contact_property as cp')
            ->join('contacts as c', 'c.id', '=', 'cp.contact_id')
            ->where('cp.property_id', $property->id)
            ->whereNull('c.deleted_at')
            ->where('c.agency_id', $property->agency_id)
            ->orderBy('c.id')
            ->get(['c.id', 'c.first_name', 'c.last_name', 'c.phone', 'c.email', 'cp.role']);

        $owners = $rows->map(fn ($r) => [
            'id'         => (int) $r->id,
            'first_name' => $r->first_name,
            'last_name'  => $r->last_name,
            'name'       => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: ('Contact #' . $r->id),
            'phone'      => $r->phone,
            'email'      => $r->email,
            'type'       => 'contact',
            'role'       => $toAttendeeRole($r->role ?? null),
            'role_label' => $toRoleLabel($r->role ?? null),
        ]);

        // AT-154 — SELLER-always / BUYER-conditional auto-fill. Sellers (and the
        // neutral attendee bucket) auto-fill for every property appointment; the
        // linked property's BUYER auto-fills ONLY for classes that opt in
        // (autofill_buyers — viewing / buyer-driven). So a listing_presentation /
        // property_evaluation / meeting / other never pulls the buyer as an
        // attendee. The buyer-CONTEXT override (scheduling FROM a buyer) is a
        // separate explicit prefill and is unaffected. When no category is passed
        // (older callers) everyone is returned — back-compat.
        $category = trim((string) $request->query('category', ''));
        if ($category !== '' && ! $this->classAutofillsBuyers($category, (int) $property->agency_id)) {
            $owners = $owners->reject(fn ($o) => $o['role'] === 'buyer_contact')->values();
        }

        return response()->json($owners->values());
    }

    /**
     * AT-154 — does this event class auto-fill the linked property's BUYER?
     * Agency row (if any) overrides the global template; an unknown class defaults
     * to false (never auto-fill a buyer for a class we can't classify).
     */
    private function classAutofillsBuyers(string $eventClass, int $agencyId): bool
    {
        $cfg = \App\Models\CommandCenter\CalendarEventClassSetting::withoutGlobalScopes()
            ->where('event_class', $eventClass)
            ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereNull('agency_id'))
            ->orderByRaw('agency_id IS NULL') // agency-specific row first, global last
            ->first();

        return $cfg ? (bool) $cfg->autofill_buyers : false;
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

        // Search contacts — AT-131 canonical (all identifiers via child tables +
        // relevance + newest-first). 'type'=>'contact' is the attendee KIND
        // (contact vs agent); the contact's classification is 'contact_type'.
        $contacts = \App\Models\Contact::query()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->with(['phones', 'emails', 'type', 'agent'])
            ->search($q)
            ->limit(7)
            ->get()
            ->map(fn ($c) => [
                'id'           => $c->id,
                'name'         => trim($c->first_name . ' ' . $c->last_name) ?: ('Contact #' . $c->id),
                'phone'        => $c->phone,
                'email'        => $c->email,
                'identifier'   => $c->matchedIdentifier($q),
                'contact_type' => $c->type?->name,
                'type'         => 'contact',
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
                'id'           => $u->id,
                'name'         => $u->name,
                'phone'        => null,
                'email'        => $u->email,
                // Agents have no contact classification, but the mobile
                // contract lists `contact_type` on every attendee row — emit
                // it as null so the app can read a uniform shape for contacts
                // and agents alike rather than a missing-key branch.
                'contact_type' => null,
                'type'         => 'agent',
            ]);

        return response()->json($contacts->concat($users)->values());
    }
}
