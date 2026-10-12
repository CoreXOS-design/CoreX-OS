<?php

namespace App\Services\Properties;

use App\Models\CommandCenter\AgencyFeedbackOption;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventFeedback;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE one READ source for "how many viewings has this property had" and "what did buyers say about THIS
 * property" - read by the agent Intelligence tab, the appointment panel, the seller live link, the contact
 * page and the client mobile API, so they cannot disagree. The WRITE side is ViewingFeedbackService.
 * (Spec: .ai/specs/calendar-viewing-feedback.md; incident 2026-10-08, property 1482.)
 *
 * ONE STORE (R1): viewing feedback lives in the calendar_event_feedback COLUMNS only -
 * viewing_status, outcome_option_id, concern_option_ids, seller_visible_notes (SELLER comment),
 * internal_notes (INTERNAL comment), next_action_notes. The old per-property JSON bundle
 * (kind_specific_data) is migrated into the columns by `viewing-feedback:migrate-single-store`
 * and is never read here.
 *
 * Rules, stated once:
 *
 *  1. A VIEWING is a calendar event in a viewing category, not deleted and not dismissed, that is
 *     STILL LINKED to the property as a subject property (R9: removing a property from the
 *     appointment stops it counting). Test bookings and cancelled appointments never count.
 *  2. HELD (R6): per property, a viewing counts as held when the appointment is marked completed -
 *     even with no feedback ("no feedback given" is not "no viewing") - or feedback has been captured
 *     for that property. Booked-only appointments are listed to the agent but never claimed as held.
 *  3. PER-PROPERTY STATUS (R6): the latest capture for the property says
 *     viewed (default) | did_not_happen (does not count; nothing shown to the seller) |
 *     declined_on_arrival (does NOT add to the viewings-held count; shown to the seller as a separate line).
 *  4. FEEDBACK belongs to a property only if it was recorded against that property, or it carries no
 *     property and the event is linked to this property. A multi-property viewing's remark about
 *     property B is never attributed to property A.
 *  5. BLANK rows (viewed, nothing ticked, nothing written) are not feedback (R7): they never count as a
 *     capture, never make a viewing "held" and never feed a roll-up.
 *  6. The COUNT is the same for every audience; only the CONTENT differs. Sellers get seller-visible
 *     rows, every concern tick and every SELLER comment - never internal_notes / next_action_notes
 *     (those are never selected into a seller-facing structure).
 *  7. Archived (soft-deleted) rows and events are excluded everywhere.
 *
 * Queries are deliberately unscoped by tenant/branch: the caller has already authorised the property.
 */
class PropertyViewings
{
    /** Event categories that are viewings (mirrors CalendarEventClassSetting::ACTIONABLE_CATEGORIES minus listing_presentation). */
    public const VIEWING_CATEGORIES = ['viewing', 'viewings', 'buyer_viewing'];

    public const STATUS_VIEWED = 'viewed';
    public const STATUS_DID_NOT_HAPPEN = 'did_not_happen';
    public const STATUS_DECLINED = 'declined_on_arrival';
    public const STATUSES = [self::STATUS_VIEWED, self::STATUS_DID_NOT_HAPPEN, self::STATUS_DECLINED];
    public const STATUS_LABELS = [
        self::STATUS_VIEWED         => 'Viewed',
        self::STATUS_DID_NOT_HAPPEN => 'Viewing did not happen',
        self::STATUS_DECLINED       => 'Buyer declined to view on arrival',
    ];

    /**
     * Normalise one feedback row into the shape every reader uses. The ONLY place a row's meaning is decided.
     *
     * @return array<string,mixed>
     */
    public static function capture(CalendarEventFeedback $fb): array
    {
        $status = in_array($fb->viewing_status, self::STATUSES, true) ? $fb->viewing_status : self::STATUS_VIEWED;
        $concerns = collect($fb->concern_option_ids ?? [])->filter()->map(fn ($i) => (int) $i)->unique()->values()->all();
        $seller = trim((string) $fb->seller_visible_notes);
        $internal = trim((string) $fb->internal_notes);
        $next = trim((string) $fb->next_action_notes);
        $outcome = $fb->outcome_option_id ? (int) $fb->outcome_option_id : null;

        return [
            'id'                  => (int) $fb->id,
            'event_id'            => (int) $fb->calendar_event_id,
            'property_id'         => $fb->property_id ? (int) $fb->property_id : null,
            'contact_id'          => $fb->contact_id ? (int) $fb->contact_id : null,
            'status'              => $status,
            'outcome_option_id'   => $outcome,
            'concern_ids'         => $concerns,
            'seller_comment'      => $seller !== '' ? $seller : null,
            'internal_comment'    => $internal !== '' ? $internal : null,
            'next_action'         => $next !== '' ? $next : null,
            'visibility'          => $fb->visibility,
            'captured_by_user_id' => $fb->captured_by_user_id ? (int) $fb->captured_by_user_id : null,
            'captured_at'         => $fb->captured_at,
            'last_edited_by_user_id' => $fb->last_edited_by_user_id ? (int) $fb->last_edited_by_user_id : null,
            'last_edited_at'      => $fb->last_edited_at,
            'archived'            => $fb->deleted_at !== null,
            // R7: nothing ticked, nothing written, status untouched.
            'blank'               => $status === self::STATUS_VIEWED && $outcome === null && $concerns === []
                                      && $seller === '' && $internal === '' && $next === '',
            'sort_key'            => ($fb->last_edited_at ?? $fb->captured_at)?->getTimestamp() ?? 0,
        ];
    }

    /**
     * @return array{events: Collection, held: Collection, declined: Collection, captures: Collection, status_by_event: array}
     *   events   - every qualifying, still-linked viewing event (CalendarEvent), newest first
     *   held     - events that count as a viewing held for THIS property
     *   declined - events where the buyer declined to view on arrival
     *   captures - normalised, non-blank, non-archived feedback attributed to this property on those events
     *   status_by_event - eventId => viewed|did_not_happen|declined_on_arrival
     */
    public function forProperty(int $propertyId): array
    {
        $empty = ['events' => collect(), 'held' => collect(), 'declined' => collect(), 'captures' => collect(), 'status_by_event' => []];

        $linkedEventIds = DB::table('calendar_event_links')
            ->where('linkable_type', 'App\\Models\\Property')
            ->where('linkable_id', $propertyId)
            ->where('role', 'subject_property')
            ->whereNull('deleted_at')
            ->pluck('calendar_event_id');
        if ($linkedEventIds->isEmpty()) {
            return $empty;
        }

        $events = CalendarEvent::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', '!=', 'dismissed')
            ->whereIn('category', self::VIEWING_CATEGORIES)
            ->whereIn('id', $linkedEventIds)
            ->orderByDesc('event_date')
            ->get();
        if ($events->isEmpty()) {
            return $empty;
        }

        $rows = CalendarEventFeedback::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('captured_at')
            ->whereIn('calendar_event_id', $events->pluck('id'))
            ->where(function ($q) use ($propertyId) {
                $q->where('property_id', $propertyId)->orWhereNull('property_id');
            })
            ->get()
            ->map(fn ($fb) => self::capture($fb));

        // Latest row decides the property's status on each event.
        $statusByEvent = [];
        foreach ($rows->groupBy('event_id') as $eventId => $group) {
            $statusByEvent[(int) $eventId] = $group->sortByDesc(fn ($c) => [$c['sort_key'], $c['id']])->first()['status'];
        }

        $captures = $rows->reject(fn ($c) => $c['blank'])->values();
        $capturedEvents = $captures->pluck('event_id')->unique();

        $held = collect();
        $declined = collect();
        foreach ($events as $e) {
            $status = $statusByEvent[$e->id] ?? self::STATUS_VIEWED;
            if ($status === self::STATUS_DECLINED) {
                $declined->push($e);
            } elseif ($status === self::STATUS_VIEWED && ($e->status === 'completed' || $capturedEvents->contains($e->id))) {
                $held->push($e);
            }
        }

        return [
            'events' => $events, 'held' => $held->values(), 'declined' => $declined->values(),
            'captures' => $captures, 'status_by_event' => $statusByEvent,
        ];
    }

    /** Captures for one audience. Seller view drops internal_only rows and the did-not-happen / declined rows. */
    private function capturesFor(array $data, bool $sellerView): Collection
    {
        $c = $data['captures']->filter(fn ($x) => $x['status'] === self::STATUS_VIEWED);

        return $sellerView ? $c->filter(fn ($x) => $x['visibility'] !== 'internal_only')->values() : $c->values();
    }

    /**
     * Roll-up shared by every screen. total_viewings and declined_on_arrival are audience-independent;
     * the concern / outcome tallies follow the audience's visible feedback.
     */
    public function rollup(int $propertyId, bool $sellerView = false): array
    {
        $data = $this->forProperty($propertyId);
        $rows = $this->capturesFor($data, $sellerView);

        $concerns = $rows->pluck('concern_ids')->flatten()->filter()->countBy()->sortDesc();
        $outcomes = $rows->pluck('outcome_option_id')->filter()->countBy();
        $labels = $this->optionLabels($concerns->keys());
        $labelled = [];
        foreach ($concerns as $id => $n) {
            if (isset($labels[$id])) {
                $labelled[$labels[$id]] = $n;
            }
        }

        return [
            'total_viewings'         => $data['held']->count(),
            'declined_on_arrival'    => $data['declined']->count(),
            'viewings_with_feedback' => $rows->pluck('event_id')->unique()->count(),
            'total_feedback_rows'    => $rows->count(),
            'top_concerns'           => $concerns->toArray(),
            'top_concern_labels'     => $labelled,
            'outcome_distribution'   => $outcomes->toArray(),
        ];
    }

    /**
     * "N of M viewers mentioned X" - EVERY concern (R5: no top-2 cut), most-mentioned first. A viewer is a
     * viewing (event) that has visible feedback for this property; a concern raised twice in one viewing counts once.
     */
    public function themes(int $propertyId, bool $sellerView = false): array
    {
        $rows = $this->capturesFor($this->forProperty($propertyId), $sellerView);

        $totalViewers = $rows->pluck('event_id')->unique()->count();
        if ($totalViewers === 0) {
            return [];
        }

        $counts = $rows->groupBy('event_id')
            ->map(fn ($r) => $r->pluck('concern_ids')->flatten()->filter()->unique())
            ->flatten()
            ->countBy();
        if ($counts->isEmpty()) {
            return [];
        }

        $labels = $this->optionLabels($counts->keys());

        return $counts->map(fn ($count, $id) => ['label' => $labels[$id] ?? null, 'count' => $count, 'total' => $totalViewers])
            ->filter(fn ($t) => $t['label'] !== null)
            ->sortBy([['count', 'desc'], ['label', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Agent-side list: each qualifying viewing with the feedback recorded for THIS property.
     * Internal comments are included - never feed this to a seller view.
     */
    public function recentForAgent(int $propertyId, int $limit = 20): Collection
    {
        $data = $this->forProperty($propertyId);
        $events = $data['events']->take($limit);
        if ($events->isEmpty()) {
            return collect();
        }
        $eventIds = $events->pluck('id');
        $captures = $data['captures']->groupBy('event_id');

        $users = \App\Models\User::withoutGlobalScopes()
            ->whereIn('id', $events->pluck('user_id')
                ->merge($data['captures']->pluck('captured_by_user_id'))
                ->merge($data['captures']->pluck('last_edited_by_user_id'))->unique()->filter())
            ->pluck('name', 'id');
        $options = AgencyFeedbackOption::withoutGlobalScopes()->pluck('label', 'id');

        // CAL-7 Class 3 - no role whitelist: legacy events save buyer links with other/NULL roles.
        $buyerLinks = DB::table('calendar_event_links')
            ->whereIn('calendar_event_id', $eventIds)
            ->where('linkable_type', 'App\\Models\\Contact')
            ->whereNull('deleted_at')
            ->get()
            ->groupBy('calendar_event_id');
        $contacts = \App\Models\Contact::withoutGlobalScopes()
            ->whereIn('id', $buyerLinks->flatten()->pluck('linkable_id')->unique())
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        return $events->map(function ($ev) use ($captures, $users, $options, $buyerLinks, $contacts, $data) {
            $buyers = ($buyerLinks->get($ev->id, collect()))->map(function ($bl) use ($contacts) {
                $c = $contacts->get($bl->linkable_id);
                return $c ? ['id' => $c->id, 'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''))] : null;
            })->filter()->values();
            $status = $data['status_by_event'][$ev->id] ?? self::STATUS_VIEWED;

            return [
                'event_id'       => $ev->id,
                'event_date'     => $ev->event_date,
                'title'          => $ev->title,
                'agent_name'     => $users->get($ev->user_id, 'Unknown'),
                'buyers'         => $buyers,
                'viewing_status' => $status,
                'status_label'   => self::STATUS_LABELS[$status],
                'feedback'       => ($captures->get($ev->id, collect()))->sortBy('id')->map(fn ($c) => self::present($c, $options, $users))->values(),
            ];
        });
    }

    /**
     * Seller-safe notes: outcome label, the SELLER comment and a date - no buyer, no internal comment, no
     * next-action note. EVERY seller comment (R5, no cut). Rows with neither an outcome nor a comment are
     * omitted (their concern ticks surface via themes()).
     */
    public function sellerNotes(int $propertyId, ?int $limit = null): array
    {
        $rows = $this->capturesFor($this->forProperty($propertyId), true);
        $outcomes = AgencyFeedbackOption::withoutGlobalScopes()
            ->whereIn('id', $rows->pluck('outcome_option_id')->filter()->unique())
            ->pluck('label', 'id');

        $out = $rows
            ->map(fn ($c) => [
                'outcome_label' => $c['outcome_option_id'] ? ($outcomes->get($c['outcome_option_id'])) : null,
                'notes'         => $c['seller_comment'],
                'date'          => $c['captured_at'],
            ])
            ->filter(fn ($r) => !empty($r['outcome_label']) || !empty($r['notes']))
            ->sortByDesc('date')
            ->values();

        return ($limit ? $out->take($limit) : $out)->all();
    }

    /**
     * Normalised, non-blank, non-archived captures for a set of appointments, grouped by event id. For the
     * contact page, which lists one row per (appointment, property) and used to take the event's FIRST feedback
     * row whatever property it was recorded against (mismatch 11).
     *
     * @return Collection<int,Collection<int,array>>
     */
    public static function capturesByEvent(iterable $eventIds): Collection
    {
        $ids = collect($eventIds)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return CalendarEventFeedback::withoutGlobalScopes()
            ->whereNull('deleted_at')->whereNotNull('captured_at')
            ->whereIn('calendar_event_id', $ids)
            ->get()
            ->map(fn ($fb) => self::capture($fb))
            ->reject(fn ($c) => $c['blank'])
            ->groupBy('event_id');
    }

    /**
     * The capture that belongs to ONE property on an appointment (never a sibling property's row).
     * $eventHasOneProperty lets an old property-less row belong to the appointment's only property.
     */
    public static function pick(?Collection $eventCaptures, int $propertyId, bool $eventHasOneProperty, ?int $contactId = null, bool $sellerVisibleOnly = false): ?array
    {
        if ($eventCaptures === null) {
            return null;
        }

        return $eventCaptures
            ->filter(fn ($c) => $c['property_id'] === $propertyId || ($eventHasOneProperty && $c['property_id'] === null))
            ->when($contactId !== null, fn ($c) => $c->filter(fn ($x) => $x['contact_id'] === $contactId))
            ->when($sellerVisibleOnly, fn ($c) => $c->filter(fn ($x) => $x['visibility'] !== 'internal_only'))
            ->sortByDesc(fn ($c) => [$c['sort_key'], $c['id']])
            ->first();
    }

    /**
     * Turn a normalised capture into the display shape used by the appointment panel and the Intelligence tab
     * (agent-side: includes the internal comment, who captured and who last edited).
     *
     * @param Collection<int,string> $options  option id => label
     * @param Collection<int,string> $users    user id => name
     */
    public static function present(array $c, Collection $options, Collection $users): array
    {
        return [
            'id'               => $c['id'],
            'viewing_status'   => $c['status'],
            'status_label'     => self::STATUS_LABELS[$c['status']],
            'outcome_label'    => $c['outcome_option_id'] ? $options->get($c['outcome_option_id']) : null,
            'concerns'         => collect($c['concern_ids'])->map(fn ($id) => $options->get($id))->filter()->values()->all(),
            'seller_notes'     => $c['seller_comment'],
            'internal_notes'   => $c['internal_comment'],
            'next_action'      => $c['next_action'],
            'captured_by'      => $c['captured_by_user_id'] ? $users->get($c['captured_by_user_id']) : null,
            'captured_at'      => $c['captured_at'],
            'last_edited_by'   => $c['last_edited_by_user_id'] ? $users->get($c['last_edited_by_user_id']) : null,
            'last_edited_at'   => $c['last_edited_at'],
            'archived'         => $c['archived'],
        ];
    }

    private function optionLabels(Collection $ids): Collection
    {
        return $ids->isEmpty()
            ? collect()
            : AgencyFeedbackOption::withoutGlobalScopes()->whereIn('id', $ids)->pluck('label', 'id');
    }
}
