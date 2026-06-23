<?php

namespace App\Services\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\User;
use Illuminate\Support\Collection;

class CalendarEventService
{
    /**
     * Create a manual calendar event.
     */
    public function createManual(array $data, User $user): CalendarEvent
    {
        return CalendarEvent::create(array_merge($data, [
            'user_id'       => $data['user_id'] ?? $user->id,
            'created_by_id' => $user->id,
            'event_type'    => $data['event_type'] ?? 'manual',
            // category MUST be set — both web and mobile GETs apply
            // whereIn('category', $visibleClassKeys) and NULL is never
            // in a whereIn list. Default to 'manual' so manual events
            // are visible on both surfaces.
            'category'      => $data['category'] ?? 'manual',
            'status'        => 'pending',
            'colour'        => $data['colour'] ?? null,
        ]));
    }

    /**
     * Create an auto-generated event from a source model.
     */
    public function createFromSource(
        string $eventType,
        string $category,
        string $title,
        \DateTime $eventDate,
        $source,
        array $extra = []
    ): CalendarEvent {
        return CalendarEvent::create(array_merge([
            'event_type'  => $eventType,
            'category'    => $category,
            'title'       => $title,
            'event_date'  => $eventDate,
            'source_type' => get_class($source),
            'source_id'   => $source->getKey(),
            'status'      => 'pending',
        ], $extra));
    }

    /**
     * Get events for a user in a date range.
     *
     * Scope controls the user filter:
     *   'own'    — only events assigned to this user (user_id = $user->id)
     *   'branch' — events in the user's branch (downstream VisibilityResolver handles per-event checks)
     *   'all'    — no user filter (downstream VisibilityResolver handles per-event checks)
     */
    public function getEventsForRange(User $user, string $start, string $end, array $filters = [], string $scope = 'all'): Collection
    {
        $query = CalendarEvent::query()->inDateRange($start, $end);

        // Role-driven visibility (own / branch / all). 'all' applies no user
        // filter here — the VisibilityResolver handles per-event access.
        $query->visibleTo($user, $scope);

        if (!empty($filters['event_type'])) {
            $query->ofType($filters['event_type']);
        }

        // CAL-8 Part 1 — status filtering.
        //
        // The calendar spec (.ai/specs/spec-calendar-module.md L162)
        // treats `status` as a filterable enum (pending / completed /
        // overdue / dismissed). The implicit contract — confirmed by
        // ConflictDetectionService::checkUserConflicts (excludes
        // ['completed', 'dismissed']) and CalendarThresholdResolver
        // (short-circuits colour resolution on the same pair) — is that
        // "dismissed" means "inactive, do not surface on the active
        // grid by default." Prior to CAL-8 this exclusion was missing
        // here, so dismissing an event from the panel left it visible
        // on the grid with no visual cue.
        //
        // Behaviour:
        //   - filters['status'] = "*"            -> no filter (admin view)
        //   - filters['status'] = "dismissed"    -> show ONLY dismissed
        //                                            (or any other single
        //                                            value the user picks)
        //   - filters['status'] empty / not set  -> exclude dismissed by
        //                                            default. Completed
        //                                            events are kept on
        //                                            the grid (they're
        //                                            visually distinct
        //                                            via line-through —
        //                                            users want to see
        //                                            what they finished).
        if (!empty($filters['status'])) {
            // Explicit status filter — operator opted in. '*' means
            // "no status filter at all" (admin "show everything" mode);
            // any other value matches exactly.
            if ($filters['status'] !== '*') {
                $query->where('status', $filters['status']);
            }
        } else {
            // No filter passed → exclude dismissed by default. Completed
            // events stay on the grid (visually distinct via line-through
            // at index.blade.php — users want to see what they finished).
            $query->where('status', '!=', 'dismissed');
        }

        if (!empty($filters['property_id'])) {
            $query->where('property_id', $filters['property_id']);
        }

        return $query->orderBy('event_date')->get();
    }

    /**
     * Get today's events for a user.
     */
    public function getTodayEvents(User $user, int $limit = 10, string $scope = 'own'): Collection
    {
        return CalendarEvent::query()->visibleTo($user, $scope)
            ->today()
            ->orderBy('all_day', 'desc')
            ->orderBy('event_date')
            ->limit($limit)
            ->get();
    }

    /**
     * Get overdue events for a user.
     */
    public function getOverdueEvents(User $user, int $limit = 10, string $scope = 'own'): Collection
    {
        return CalendarEvent::query()->visibleTo($user, $scope)
            ->overdue()
            ->orderBy('event_date')
            ->limit($limit)
            ->get();
    }

    /**
     * Count events by category for this week.
     */
    public function getWeekSummary(User $user): array
    {
        $events = CalendarEvent::forUser($user->id)
            ->thisWeek()
            ->where('status', 'pending')
            ->get();

        return [
            'total'  => $events->count(),
            'byType' => $events->groupBy('event_type')->map->count()->toArray(),
        ];
    }

    /**
     * Update an event.
     */
    public function update(CalendarEvent $event, array $data): CalendarEvent
    {
        $event->update($data);
        return $event->fresh();
    }

    /**
     * Soft-delete an event.
     */
    public function delete(CalendarEvent $event): void
    {
        $event->delete();
    }

    /**
     * Remove all auto-generated events for a source model.
     */
    public function deleteForSource($source): void
    {
        CalendarEvent::where('source_type', get_class($source))
            ->where('source_id', $source->getKey())
            ->delete();
    }

    /**
     * Get events for a month calendar grid (includes surrounding weeks).
     * Returns single-day events grouped by date AND spanning bars for multi-day events.
     */
    public function getMonthGrid(User $user, int $year, int $month, array $filters = [], string $scope = 'all'): array
    {
        $start = \Carbon\Carbon::create($year, $month, 1)->startOfWeek();
        $end   = \Carbon\Carbon::create($year, $month, 1)->endOfMonth()->endOfWeek();

        $events = $this->getEventsForRange($user, $start, $end, $filters, $scope);

        $grouped = [];
        $spanningBars = [];

        foreach ($events as $event) {
            $eventStart = $event->event_date->copy()->startOfDay();
            $eventEnd = $event->end_date ? $event->end_date->copy()->startOfDay() : $eventStart;

            // Multi-day: end_date exists and is a different day from event_date
            $isMultiDay = $event->end_date && $eventEnd->gt($eventStart);

            if (!$isMultiDay) {
                // Single-day event — place in its day bucket
                $grouped[$eventStart->toDateString()][] = $event;
            } else {
                // Multi-day event — build spanning bar segments (split per week row)
                $from = $eventStart->lt($start) ? $start->copy() : $eventStart->copy();
                $to = $eventEnd->gt($end) ? $end->copy()->startOfDay() : $eventEnd->copy();

                $segments = $this->buildSpanningSegments($from, $to, $start, $event);
                foreach ($segments as $seg) {
                    $spanningBars[] = $seg;
                }
            }
        }

        return [
            'start'        => $start,
            'end'          => $end,
            'month'        => $month,
            'year'         => $year,
            'events'       => $events,
            'byDate'       => $grouped,
            'spanningBars' => $spanningBars,
        ];
    }

    /**
     * Split a multi-day event into per-week-row spanning segments.
     * Each segment has: event, startCol (1-7), endCol (1-7), weekRow (0-based).
     */
    private function buildSpanningSegments(\Carbon\Carbon $from, \Carbon\Carbon $to, \Carbon\Carbon $gridStart, $event): array
    {
        $segments = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            // Week row index (0-based) relative to grid start
            $weekRow = (int) floor($gridStart->diffInDays($cursor) / 7);

            // Column within the week (1-7, Mon=1)
            $startCol = $cursor->dayOfWeekIso;

            // End of this segment = min(end_date, end of this week row)
            $weekEnd = $cursor->copy()->endOfWeek()->startOfDay(); // Sunday
            $segEnd = $to->lt($weekEnd) ? $to->copy() : $weekEnd->copy();
            $endCol = $segEnd->dayOfWeekIso;

            // Span = number of columns this segment covers
            $span = $startCol <= $endCol ? ($endCol - $startCol + 1) : 1;

            $segments[] = [
                'event'     => $event,
                'event_id'  => $event->id,
                'title'     => $event->title,
                'start_date' => $cursor->toDateString(),
                'end_date'  => $segEnd->toDateString(),
                'week_row'  => $weekRow,
                'start_col' => $startCol,
                'end_col'   => $endCol,
                'span'      => $span,
            ];

            // Move cursor to start of next week
            $cursor = $weekEnd->copy()->addDay();
        }

        return $segments;
    }
}
