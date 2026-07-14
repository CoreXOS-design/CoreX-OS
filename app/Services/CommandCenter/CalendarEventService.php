<?php

namespace App\Services\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarEventInvitation;
use App\Models\CommandCenter\CalendarEventLink;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Services\CommandCenter\Calendar\ConflictDetectionService;
use App\Services\CommandCenter\Calendar\RecurrenceExpander;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CalendarEventService
{
    /**
     * Event classes a user may create manually (not source-driven).
     * Single source of truth — the web CalendarController and the mobile
     * API both read this so the two surfaces never diverge on which
     * classes are user-creatable.
     */
    public const MANUAL_CREATABLE_CLASSES = [
        'viewing', 'property_evaluation', 'listing_presentation',
        'meeting', 'task', 'other',
    ];

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

        $base = $query->orderBy('event_date')->get();

        // ── Recurring events (materialise-on-view) ──────────────────────────
        // A recurring PARENT is never rendered as its own raw row — it is
        // expanded into virtual occurrences below. A cancel-tombstone child
        // (deleted single occurrence) is likewise hidden. Everything else
        // (non-recurring events + EDIT-exception children) renders as itself.
        $base = $base->reject(function ($e) {
            if ($e->is_recurring) {
                return true;
            }
            return is_array($e->metadata ?? null) && ($e->metadata['recurrence_cancelled'] ?? false);
        })->values();

        // Fetch recurring parents whose series could overlap [start, end] even
        // when the series START (event_date = DTSTART) is before the window.
        $startC = Carbon::parse($start);
        $endC   = Carbon::parse($end);
        $parentQuery = CalendarEvent::query()
            ->where('is_recurring', true)
            ->where('event_date', '<=', $endC)
            ->visibleTo($user, $scope);
        if (!empty($filters['event_type'])) {
            $parentQuery->ofType($filters['event_type']);
        }
        if (!empty($filters['property_id'])) {
            $parentQuery->where('property_id', $filters['property_id']);
        }
        // Mirror the base status handling: a dismissed parent = "delete all".
        if (!empty($filters['status'])) {
            if ($filters['status'] !== '*') {
                $parentQuery->where('status', $filters['status']);
            }
        } else {
            $parentQuery->where('status', '!=', 'dismissed');
        }

        $expander = app(RecurrenceExpander::class);
        $occurrences = collect();
        foreach ($parentQuery->get() as $parent) {
            $occurrences = $occurrences->merge($expander->expand($parent, $startC, $endC));
        }

        return $base->merge($occurrences)->sortBy('event_date')->values();
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
     * AT-164 (single week-stream) — the grid shape ({byDate, spanningBars}) for an
     * ARBITRARY date range, not tied to a calendar month. The continuous month view is
     * now ONE seamless stream of week rows (no month blocks, no duplicated boundary
     * weeks), so its windows are addressed by WEEK. Single-day events bucket by date;
     * multi-day events split into per-week spanning segments exactly as getMonthGrid
     * does (same buildSpanningSegments), so the visual is byte-identical to before —
     * only the windowing unit changed from month to week.
     */
    public function getRangeGrid(User $user, \Carbon\Carbon $start, \Carbon\Carbon $end, array $filters = [], string $scope = 'all'): array
    {
        $start = $start->copy()->startOfDay();
        $end   = $end->copy()->endOfDay();

        $events = $this->getEventsForRange($user, $start, $end, $filters, $scope);

        $grouped = [];
        $spanningBars = [];
        // Segments are addressed by their OWN week (via start_date), so gridStart only
        // needs to be week-aligned for the per-week column math to be correct.
        $gridStart = $start->copy()->startOfWeek(\Carbon\Carbon::MONDAY);

        foreach ($events as $event) {
            $eventStart = $event->event_date->copy()->startOfDay();
            $eventEnd = $event->end_date ? $event->end_date->copy()->startOfDay() : $eventStart;
            $isMultiDay = $event->end_date && $eventEnd->gt($eventStart);

            if (!$isMultiDay) {
                $grouped[$eventStart->toDateString()][] = $event;
            } else {
                $from = $eventStart->lt($start) ? $start->copy()->startOfDay() : $eventStart->copy();
                $to = $eventEnd->gt($end) ? $end->copy()->startOfDay() : $eventEnd->copy();
                foreach ($this->buildSpanningSegments($from, $to, $gridStart, $event) as $seg) {
                    $spanningBars[] = $seg;
                }
            }
        }

        return [
            'start'        => $start,
            'end'          => $end,
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

    // ─────────────────────────────────────────────────────────────────
    // Manual-event engine — shared by the web calendar controller AND the
    // mobile/command-center API so the full "add event" flow (multi-
    // property, attendees with roles, agent invitations, deal link) is
    // identical on every surface. Integration is the moat: one engine.
    // ─────────────────────────────────────────────────────────────────

    /**
     * Create a manual calendar event WITH its full link graph (properties,
     * attendees, deal) and fire agent invitations. This is the single
     * write path behind both the web create-event panel and the mobile
     * add-event sheet.
     *
     * Expected $data keys (all optional except title/category/event_date):
     *   title, category, event_date, end_date, description, priority,
     *   property_id (int), property_ids (int[]), contact_ids (int[]),
     *   attendees ([{id,type:contact|agent,role?}]), deal_id (int).
     */
    public function createManualWithLinks(array $data, User $user): CalendarEvent
    {
        $category    = $data['category'] ?? 'meeting';
        $propertyIds = $this->resolvePropertyIds($data, $user, $category);
        $data['_resolved_property_ids'] = $propertyIds;

        // For multi-property events append the count to the title if the
        // user didn't already describe it as such (parity with web store()).
        $title = $data['title'];
        if (count($propertyIds) > 1 && ! str_contains($title, 'properties')) {
            $title = $title . ' — ' . count($propertyIds) . ' properties';
        }

        return DB::transaction(function () use ($data, $user, $propertyIds, $category, $title) {
            $eventDate = $data['event_date'];

            $event = CalendarEvent::create([
                'event_type'    => 'manual',
                'category'      => $category,
                'title'         => $title,
                'description'   => ($data['description'] ?? '') ?: null,
                'event_date'    => $eventDate,
                'end_date'      => ($data['end_date'] ?? null) ?: null,
                'all_day'       => array_key_exists('all_day', $data)
                                    ? (bool) $data['all_day']
                                    : Carbon::parse($eventDate)->format('H:i:s') === '00:00:00',
                'status'        => 'pending',
                'priority'      => $data['priority'] ?? 'normal',
                'source_type'   => 'manual',
                'user_id'       => $user->id,
                'created_by_id' => $user->id,
                // AT-241 — acting-context agency, not the raw column. See the
                // canonical fix + rationale in CalendarEventCreator::create.
                // (This createManualWithLinks path is currently uncalled but
                //  carried the same `?: 1` landmine — killed here so the class
                //  is dead across the file.)
                'agency_id'     => $user->effectiveAgencyId(),
                'branch_id'     => $user->branch_id,
                'property_id'   => $propertyIds[0] ?? ($data['property_id'] ?? null),
                'contact_id'    => $this->firstContactId($data),
                'send_reminder' => $data['send_reminder'] ?? true,
            ]);

            $this->syncManualEventLinks($event, $data, $user);

            return $event;
        });
    }

    /**
     * Apply class-config property caps. Single-property classes keep only
     * the first id. Mirrors the web store() cap enforcement exactly.
     */
    public function resolvePropertyIds(array $data, User $user, ?string $category = null): array
    {
        $category    = $category ?? ($data['category'] ?? null);
        $propertyIds = $data['property_ids'] ?? (! empty($data['property_id']) ? [$data['property_id']] : []);
        $propertyIds = array_values(array_unique(array_map('intval', $propertyIds)));

        if (count($propertyIds) > 1 && $category) {
            $classConfig = CalendarEventClassSetting::withoutGlobalScopes()
                ->where('event_class', $category)
                ->where(fn ($q) => $q->where('agency_id', $user->effectiveAgencyId())->orWhereNull('agency_id'))
                ->orderByRaw('agency_id IS NULL')
                ->first();
            if ($classConfig && ! $classConfig->allow_multiple_properties) {
                $propertyIds = [$propertyIds[0]];
            }
        }

        return $propertyIds;
    }

    /** First contact id from attendees[] (type=contact) or contact_ids[]. */
    private function firstContactId(array $data): ?int
    {
        if (! empty($data['attendees'])) {
            $first = collect($data['attendees'])->firstWhere('type', 'contact');
            if ($first) {
                return (int) $first['id'];
            }
        }

        return ($data['contact_ids'] ?? [])[0] ?? null;
    }

    /**
     * Sync calendar_event_links for a manual event and fire agent
     * invitations. Deletes only the link roles being re-submitted
     * (prevents the edit-wipe bug) then re-inserts from $data.
     *
     * Moved verbatim from CalendarController::syncEventLinks so the web
     * and mobile surfaces share ONE implementation. See CAL-3 (agency_id
     * on every row) and CAL-7 (null-safe class config) notes inline.
     */
    public function syncManualEventLinks(CalendarEvent $event, array $data, User $user): void
    {
        $rolesToSync = [];
        if (array_key_exists('property_ids', $data) || array_key_exists('property_id', $data) || array_key_exists('_resolved_property_ids', $data)) {
            $rolesToSync[] = CalendarEventLink::ROLE_SUBJECT_PROPERTY;
        }
        if (array_key_exists('attendees', $data) || array_key_exists('contact_ids', $data)) {
            $rolesToSync[] = CalendarEventLink::ROLE_ATTENDEE;
            $rolesToSync[] = 'buyer_contact';
            $rolesToSync[] = 'seller_contact';
            $rolesToSync[] = 'agent_contact';
        }
        if (array_key_exists('deal_id', $data)) {
            $rolesToSync[] = CalendarEventLink::ROLE_RELATED_DEAL;
        }

        if (! empty($rolesToSync)) {
            DB::table('calendar_event_links')
                ->where('calendar_event_id', $event->id)
                ->whereNotNull('created_by_user_id')
                ->whereIn('role', $rolesToSync)
                ->delete();
        }

        $links = [];
        $now = now();
        // AT-241 — mirror the parent event's agency exactly (NULL allowed; the
        // child columns are nullable since 2026_07_14_090000). No sentinel.
        $agencyId = $event->agency_id !== null ? (int) $event->agency_id : null;

        $propertyIds = $data['_resolved_property_ids'] ?? ($data['property_ids'] ?? []);
        if (empty($propertyIds) && ! empty($data['property_id'])) {
            $propertyIds = [$data['property_id']];
        }
        foreach ($propertyIds as $pid) {
            $links[] = [
                'agency_id'          => $agencyId,
                'calendar_event_id'  => $event->id,
                'linkable_type'      => Property::class,
                'linkable_id'        => (int) $pid,
                'role'               => CalendarEventLink::ROLE_SUBJECT_PROPERTY,
                'created_by_user_id' => $user->id,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        $classConfig = CalendarEventClassSetting::withoutGlobalScopes()
            ->where('event_class', $data['category'] ?? '')
            ->where(fn ($q) => $q->where('agency_id', $user->effectiveAgencyId())->orWhereNull('agency_id'))
            ->orderByRaw('agency_id IS NULL')
            ->first();
        $defaultRole = match ($classConfig?->actor_role ?? 'neither') {
            'buyer_action'  => 'buyer_contact',
            'seller_action' => 'seller_contact',
            default         => CalendarEventLink::ROLE_ATTENDEE,
        };

        foreach (($data['attendees'] ?? $data['contact_ids'] ?? []) as $attendee) {
            if (is_array($attendee)) {
                $type = ($attendee['type'] ?? 'contact') === 'agent' ? User::class : Contact::class;
                $id   = $attendee['id'];
                $role = $attendee['role'] ?? ($type === User::class ? 'agent_contact' : $defaultRole);
            } else {
                $type = Contact::class;
                $id   = $attendee;
                $role = $defaultRole;
            }
            $links[] = [
                'agency_id'          => $agencyId,
                'calendar_event_id'  => $event->id,
                'linkable_type'      => $type,
                'linkable_id'        => $id,
                'role'               => $role,
                'created_by_user_id' => $user->id,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        if (! empty($data['deal_id'])) {
            $links[] = [
                'agency_id'          => $agencyId,
                'calendar_event_id'  => $event->id,
                'linkable_type'      => \App\Models\DealV2\DealV2::class,
                'linkable_id'        => $data['deal_id'],
                'role'               => CalendarEventLink::ROLE_RELATED_DEAL,
                'created_by_user_id' => $user->id,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        if (! empty($links)) {
            DB::table('calendar_event_links')->insert($links);
        }

        // Create invitations for user attendees (agents) + notify them.
        foreach ($links as $link) {
            if (($link['linkable_type'] ?? '') === User::class && (int) ($link['linkable_id'] ?? 0) !== (int) $user->id) {
                $conflicts = app(ConflictDetectionService::class)
                    ->checkUserConflicts(
                        (int) $link['linkable_id'],
                        $event->event_date->toDateTimeString(),
                        ($event->end_date ?? $event->event_date)->toDateTimeString(),
                        $event->id
                    );

                CalendarEventInvitation::updateOrCreate(
                    ['event_id' => $event->id, 'invitee_user_id' => $link['linkable_id']],
                    [
                        // AT-241 — mirror the parent event's agency (NULL allowed).
                        'agency_id'          => $event->agency_id,
                        'inviter_user_id'    => $user->id,
                        'status'             => 'pending',
                        'conflict_at_invite' => ! empty($conflicts) ? $conflicts : null,
                    ]
                );

                DB::table('notifications')->insert([
                    'id'              => \Illuminate\Support\Str::uuid(),
                    'type'            => 'invitation_received',
                    'notifiable_type' => 'App\\Models\\User',
                    'notifiable_id'   => $link['linkable_id'],
                    'data'            => json_encode([
                        'message'      => $user->name . ' invited you to: ' . $event->title,
                        'event_id'     => $event->id,
                        'has_conflict' => ! empty($conflicts),
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Search attendees for the add-event form — agency contacts (canonical
     * all-identifier search, AT-131) PLUS agency users (agents), excluding
     * the requesting user. Shared by web + mobile.
     */
    public function searchAttendees(User $user, string $q): Collection
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return collect();
        }

        $agencyId = $user->agency_id ?: 1;

        $contacts = Contact::query()
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

        $users = User::query()
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

        return $contacts->concat($users)->values();
    }

    /**
     * All linked contacts for a property, with attendee-role + label, for
     * the add-event panel's property-select auto-fill. Raw join (no global
     * scopes) — see CAL-6. Shared by web + mobile.
     */
    public function propertyOwners(int $propertyId): Collection
    {
        $property = Property::find($propertyId);
        if (! $property) {
            return collect();
        }

        $toAttendeeRole = static function (?string $pivotRole): string {
            $r = strtolower(trim((string) $pivotRole));
            return match (true) {
                in_array($r, ['seller', 'owner', 'landlord', 'lessor'], true) => 'seller_contact',
                in_array($r, ['buyer', 'tenant', 'lessee'], true)             => 'buyer_contact',
                default                                                       => 'attendee',
            };
        };
        $toRoleLabel = static function (?string $pivotRole): ?string {
            $r = trim((string) $pivotRole);
            return $r === '' ? null : ucfirst(strtolower($r));
        };

        $rows = DB::table('contact_property as cp')
            ->join('contacts as c', 'c.id', '=', 'cp.contact_id')
            ->where('cp.property_id', $property->id)
            ->whereNull('c.deleted_at')
            ->where('c.agency_id', $property->agency_id)
            ->orderBy('c.id')
            ->get(['c.id', 'c.first_name', 'c.last_name', 'c.phone', 'c.email', 'cp.role']);

        return $rows->map(fn ($r) => [
            'id'         => (int) $r->id,
            'first_name' => $r->first_name,
            'last_name'  => $r->last_name,
            'name'       => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: ('Contact #' . $r->id),
            'phone'      => $r->phone,
            'email'      => $r->email,
            'type'       => 'contact',
            'role'       => $toAttendeeRole($r->role ?? null),
            'role_label' => $toRoleLabel($r->role ?? null),
        ])->values();
    }

    /**
     * The manual-creatable event classes visible to this user, with the
     * config flags the add-event form needs (multi-property cap, actor
     * role, completion behaviour). Mirrors the web sharedViewData().
     */
    public function manualCreatableClasses(User $user): Collection
    {
        return CalendarEventClassSetting::withoutGlobalScopes()
            ->whereNull('agency_id')
            ->where('is_active', true)
            ->whereIn('event_class', self::MANUAL_CREATABLE_CLASSES)
            ->orderBy('label')
            ->get(['event_class', 'label', 'allow_multiple_properties', 'actor_role', 'completion_behaviour', 'autofill_buyers'])
            ->map(fn ($c) => [
                'event_class'               => $c->event_class,
                'label'                     => $c->label,
                'allow_multiple_properties' => (bool) $c->allow_multiple_properties,
                'actor_role'                => $c->actor_role,
                'completion_behaviour'      => $c->completion_behaviour,
                'autofill_buyers'           => (bool) $c->autofill_buyers, // AT-154
            ])
            ->values();
    }
}
