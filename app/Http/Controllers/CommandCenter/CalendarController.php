<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\CalendarEvent;
use App\Services\CommandCenter\CalendarEventService;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    protected CalendarEventService $service;

    public function __construct(CalendarEventService $service)
    {
        $this->service = $service;
    }

    /**
     * Full calendar page.
     */
    public function index(Request $request)
    {
        $user  = $request->user();
        $year  = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);
        $view  = $request->get('view', 'month');
        $range = $request->get('range', 'month');

        $grid = $this->service->getMonthGrid($user, $year, $month);

        // Agenda view uses a separate, configurable date range — independent of
        // the month-grid spillover events. Supports past / future presets and a
        // fully custom from/to range.
        $rangeGroups = [
            'Current'  => [
                'month' => 'This month',
                'year'  => 'This year',
            ],
            'Past'     => [
                'last30'       => 'Last 30 days',
                'last3months'  => 'Last 3 months',
                'last6months'  => 'Last 6 months',
                'lastyear'     => 'Last year',
                'allpast'      => 'All past events',
            ],
            'Upcoming' => [
                'next30'       => 'Next 30 days',
                '3months'      => 'Next 3 months',
                '6months'      => 'Next 6 months',
                'allupcoming'  => 'All upcoming',
            ],
            'Custom'   => [
                'custom' => 'Custom range',
            ],
        ];

        $rangeFlat = [];
        foreach ($rangeGroups as $group) {
            $rangeFlat += $group;
        }

        if (!array_key_exists($range, $rangeFlat)) {
            $range = 'month';
        }

        $base  = \Carbon\Carbon::create($year, $month, 1)->startOfMonth();
        $today = now()->startOfDay();

        $customFrom = $request->get('from');
        $customTo   = $request->get('to');

        $parseDate = function ($value, \Carbon\Carbon $fallback): \Carbon\Carbon {
            if (!$value) return $fallback;
            try {
                return \Carbon\Carbon::parse($value);
            } catch (\Throwable $e) {
                return $fallback;
            }
        };

        switch ($range) {
            case 'last30':
                $rangeStart = $today->copy()->subDays(30);
                $rangeEnd   = $today->copy()->endOfDay();
                break;
            case 'last3months':
                $rangeStart = $base->copy()->subMonthsNoOverflow(3)->startOfMonth();
                $rangeEnd   = $base->copy()->endOfMonth();
                break;
            case 'last6months':
                $rangeStart = $base->copy()->subMonthsNoOverflow(6)->startOfMonth();
                $rangeEnd   = $base->copy()->endOfMonth();
                break;
            case 'lastyear':
                $rangeStart = $today->copy()->subYearNoOverflow();
                $rangeEnd   = $today->copy()->endOfDay();
                break;
            case 'allpast':
                $rangeStart = \Carbon\Carbon::create(2000, 1, 1)->startOfDay();
                $rangeEnd   = $today->copy()->endOfDay();
                break;
            case 'next30':
                $rangeStart = $today->copy()->startOfDay();
                $rangeEnd   = $today->copy()->addDays(30)->endOfDay();
                break;
            case '3months':
                $rangeStart = $base->copy()->startOfMonth();
                $rangeEnd   = $base->copy()->addMonthsNoOverflow(3)->endOfMonth();
                break;
            case '6months':
                $rangeStart = $base->copy()->startOfMonth();
                $rangeEnd   = $base->copy()->addMonthsNoOverflow(6)->endOfMonth();
                break;
            case 'year':
                $rangeStart = $base->copy()->startOfYear();
                $rangeEnd   = $base->copy()->endOfYear();
                break;
            case 'allupcoming':
                $rangeStart = $today->copy()->startOfDay();
                $rangeEnd   = $today->copy()->addYearsNoOverflow(5)->endOfDay();
                break;
            case 'custom':
                $rangeStart = $parseDate($customFrom, $base->copy()->startOfMonth())->startOfDay();
                $rangeEnd   = $parseDate($customTo,   $base->copy()->endOfMonth())->endOfDay();
                if ($rangeEnd->lt($rangeStart)) {
                    // Swap if user entered them backwards so results still make sense.
                    [$rangeStart, $rangeEnd] = [$rangeEnd->copy()->startOfDay(), $rangeStart->copy()->endOfDay()];
                }
                break;
            case 'month':
            default:
                $range      = 'month';
                $rangeStart = $base->copy()->startOfMonth();
                $rangeEnd   = $base->copy()->endOfMonth();
                break;
        }

        $agendaEvents = $this->service->getEventsForRange(
            $user,
            $rangeStart->toDateString(),
            $rangeEnd->toDateString()
        );

        return view('command-center.calendar.index', [
            'user'             => $user,
            'year'             => $year,
            'month'            => $month,
            'currentView'      => $view,
            'grid'             => $grid,
            'events'           => $grid['events'],
            'byDate'           => $grid['byDate'],
            'agendaEvents'     => $agendaEvents,
            'agendaRange'      => $range,
            'agendaRangeLabel' => $rangeFlat[$range],
            'agendaFrom'       => $rangeStart->toDateString(),
            'agendaTo'         => $rangeEnd->toDateString(),
            'rangeGroups'      => $rangeGroups,
        ]);
    }

    /**
     * Get events for a date range (AJAX).
     */
    public function events(Request $request)
    {
        $user    = $request->user();
        $start   = $request->get('start', now()->startOfMonth()->toDateString());
        $end     = $request->get('end', now()->endOfMonth()->toDateString());
        $filters = $request->only(['event_type', 'status', 'property_id']);

        $events = $this->service->getEventsForRange($user, $start, $end, $filters);

        return response()->json($events->map(fn (CalendarEvent $e) => [
            'id'         => $e->id,
            'title'      => $e->title,
            'start'      => $e->event_date->toIso8601String(),
            'end'        => $e->end_date?->toIso8601String(),
            'allDay'     => $e->all_day,
            'color'      => $e->colour,
            'type'       => $e->event_type,
            'category'   => $e->category,
            'priority'   => $e->priority,
            'status'     => $e->status,
            'propertyId' => $e->property_id,
            'contactId'  => $e->contact_id,
        ]));
    }

    /**
     * Store a new manual event.
     */
    public function store(Request $request)
    {
        $request->validate([
            'title'      => 'required|string|max:255',
            'event_date' => 'required|date',
            'end_date'   => 'nullable|date|after_or_equal:event_date',
            'event_type' => 'nullable|string|max:50',
            'priority'   => 'nullable|in:low,normal,high,critical',
            'all_day'        => 'nullable|boolean',
            'description'    => 'nullable|string',
            'property_id'    => 'nullable|exists:properties,id',
            'contact_id'     => 'nullable|exists:contacts,id',
            'send_reminder'  => 'nullable|boolean',
        ]);

        $data = $request->all();
        $data['send_reminder'] = $request->boolean('send_reminder');

        $event = $this->service->createManual($data, $request->user());

        if ($request->wantsJson()) {
            return response()->json($event, 201);
        }

        return back()->with('success', 'Event created.');
    }

    /**
     * Update an event.
     */
    public function update(Request $request, CalendarEvent $calendarEvent)
    {
        $request->validate([
            'title'      => 'sometimes|required|string|max:255',
            'event_date' => 'sometimes|required|date',
            'end_date'   => 'nullable|date|after_or_equal:event_date',
            'status'     => 'nullable|in:pending,completed,overdue,dismissed',
            'priority'   => 'nullable|in:low,normal,high,critical',
        ]);

        $event = $this->service->update($calendarEvent, $request->all());

        if ($request->wantsJson()) {
            return response()->json($event);
        }

        return back()->with('success', 'Event updated.');
    }

    /**
     * Soft-delete an event.
     */
    public function destroy(Request $request, CalendarEvent $calendarEvent)
    {
        $this->service->delete($calendarEvent);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Event removed.');
    }

    /**
     * Mark event completed.
     */
    public function complete(CalendarEvent $calendarEvent)
    {
        $calendarEvent->markCompleted();
        return back()->with('success', 'Event completed.');
    }

    /**
     * Dismiss event.
     */
    public function dismiss(CalendarEvent $calendarEvent)
    {
        $calendarEvent->markDismissed();
        return back()->with('success', 'Event dismissed.');
    }
}
