<?php

namespace App\Services\Properties;

use App\Models\CommandCenter\AgencyFeedbackOption;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventFeedback;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE one source for "how many viewings has this property had" and "what did
 * the buyers say about THIS property" — read by the agent Intelligence tab,
 * the seller live link and the client mobile API, so they cannot disagree.
 * (Spec: .ai/specs/seller-live-link.md §5; incident 2026-10-08, property 1482.)
 *
 * Rules, stated once:
 *
 *  1. A VIEWING is a calendar event in a viewing category, not deleted and not
 *     dismissed, linked to the property as its subject (or carrying feedback
 *     recorded against it). Deleted / dismissed events (test bookings,
 *     cancelled appointments) never count anywhere.
 *  2. A viewing is HELD once it is marked completed OR feedback has been
 *     captured for this property against it. Booked-but-unconfirmed viewings
 *     are not claimed as held.
 *  3. FEEDBACK belongs to a property only if it was recorded against that
 *     property (calendar_event_feedback.property_id), or it carries no
 *     property and the event is linked to this property. A multi-property
 *     viewing's remark about property B is never attributed to property A.
 *  4. The COUNT is the same for every audience; only the feedback CONTENT
 *     differs. Sellers get seller-visible rows and seller_visible_notes only —
 *     internal_notes / next_action_notes are never selected into a
 *     seller-facing structure.
 *  5. Soft-deleted feedback rows and events are excluded everywhere.
 *
 * Queries are deliberately unscoped by tenant/branch: the caller has already
 * authorised access to the property, and a per-user scope here is exactly how
 * two screens ended up with different numbers.
 */
class PropertyViewings
{
    /** Event categories that are viewings (mirrors CalendarEventClassSetting::ACTIONABLE_CATEGORIES minus listing_presentation). */
    public const VIEWING_CATEGORIES = ['viewing', 'viewings', 'buyer_viewing'];

    /**
     * @return array{events: Collection, held: Collection, feedback: Collection}
     *   events   — every qualifying viewing event (CalendarEvent), newest first
     *   held     — the subset that counts as held
     *   feedback — captured feedback rows attributed to this property on those events
     */
    public function forProperty(int $propertyId): array
    {
        $linkedEventIds = DB::table('calendar_event_links')
            ->where('linkable_type', 'App\\Models\\Property')
            ->where('linkable_id', $propertyId)
            ->where('role', 'subject_property')
            ->pluck('calendar_event_id');

        $feedback = CalendarEventFeedback::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('captured_at')
            ->where(function ($q) use ($propertyId, $linkedEventIds) {
                $q->where('property_id', $propertyId)
                  ->orWhere(function ($q2) use ($linkedEventIds) {
                      $q2->whereNull('property_id')->whereIn('calendar_event_id', $linkedEventIds);
                  });
            })
            ->get();

        $candidateIds = $linkedEventIds->merge($feedback->pluck('calendar_event_id'))->unique()->values();
        if ($candidateIds->isEmpty()) {
            return ['events' => collect(), 'held' => collect(), 'feedback' => collect()];
        }

        $events = CalendarEvent::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', '!=', 'dismissed')
            ->whereIn('category', self::VIEWING_CATEGORIES)
            ->whereIn('id', $candidateIds)
            ->orderByDesc('event_date')
            ->get();

        $feedback = $feedback->whereIn('calendar_event_id', $events->pluck('id'))->values();
        $withFeedback = $feedback->pluck('calendar_event_id')->unique();
        $held = $events->filter(fn ($e) => $e->status === 'completed' || $withFeedback->contains($e->id))->values();

        return ['events' => $events, 'held' => $held, 'feedback' => $feedback];
    }

    /**
     * Feedback rows for one audience. Seller view drops internal_only rows.
     */
    private function feedbackFor(array $data, bool $sellerView): Collection
    {
        return $sellerView
            ? $data['feedback']->filter(fn ($f) => $f->visibility !== 'internal_only')->values()
            : $data['feedback'];
    }

    /**
     * Roll-up shared by every screen. total_viewings is audience-independent;
     * the concern / outcome tallies follow the audience's visible feedback.
     */
    public function rollup(int $propertyId, bool $sellerView = false): array
    {
        $data = $this->forProperty($propertyId);
        $rows = $this->feedbackFor($data, $sellerView);

        $concerns = $rows->pluck('concern_option_ids')->flatten()->filter()->countBy();
        $outcomes = $rows->pluck('outcome_option_id')->filter()->countBy();
        $top = $concerns->sortDesc()->take(5);
        $labels = $this->concernLabels($top->keys());
        $topLabels = [];
        foreach ($top as $id => $n) {
            if (isset($labels[$id])) {
                $topLabels[$labels[$id]] = $n;
            }
        }

        return [
            'total_viewings'         => $data['held']->count(),
            'viewings_with_feedback' => $rows->pluck('calendar_event_id')->unique()->count(),
            'total_feedback_rows'    => $rows->count(),
            'top_concerns'           => $top->toArray(),
            'top_concern_labels'     => $topLabels,
            'outcome_distribution'   => $outcomes->toArray(),
        ];
    }

    /**
     * "N of M viewers mentioned X" — a viewer is a viewing (event) that has
     * seller-visible feedback for this property; a concern raised twice in one
     * viewing counts once.
     */
    public function themes(int $propertyId, bool $sellerView = false): array
    {
        $rows = $this->feedbackFor($this->forProperty($propertyId), $sellerView);

        $totalViewers = $rows->pluck('calendar_event_id')->unique()->count();
        if ($totalViewers === 0) {
            return [];
        }

        $counts = $rows->groupBy('calendar_event_id')
            ->map(fn ($r) => $r->pluck('concern_option_ids')->flatten()->filter()->unique())
            ->flatten()
            ->countBy();
        if ($counts->isEmpty()) {
            return [];
        }

        $labels = $this->concernLabels($counts->keys());

        return $counts->sortDesc()->take(2)->map(fn ($count, $id) => [
            'label' => $labels[$id] ?? null,
            'count' => $count,
            'total' => $totalViewers,
        ])->filter(fn ($t) => $t['label'] !== null)->values()->all();
    }

    /**
     * Agent-side list: each qualifying viewing with the feedback recorded for
     * THIS property (internal notes included — never feed this to a seller view).
     */
    public function recentForAgent(int $propertyId, int $limit = 20): Collection
    {
        $data = $this->forProperty($propertyId);
        $events = $data['events']->take($limit);
        if ($events->isEmpty()) {
            return collect();
        }
        $eventIds = $events->pluck('id');
        $feedback = $data['feedback']->groupBy('calendar_event_id');

        $agents = \App\Models\User::withoutGlobalScopes()
            ->whereIn('id', $events->pluck('user_id')->unique()->filter())
            ->pluck('name', 'id');
        $options = AgencyFeedbackOption::withoutGlobalScopes()->pluck('label', 'id');

        // CAL-7 Class 3 — no role whitelist: legacy events save buyer links with other/NULL roles.
        $buyerLinks = DB::table('calendar_event_links')
            ->whereIn('calendar_event_id', $eventIds)
            ->where('linkable_type', 'App\\Models\\Contact')
            ->get()
            ->groupBy('calendar_event_id');
        $contacts = \App\Models\Contact::withoutGlobalScopes()
            ->whereIn('id', $buyerLinks->flatten()->pluck('linkable_id')->unique())
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        return $events->map(function ($ev) use ($feedback, $agents, $options, $buyerLinks, $contacts) {
            $buyers = ($buyerLinks->get($ev->id, collect()))->map(function ($bl) use ($contacts) {
                $c = $contacts->get($bl->linkable_id);
                return $c ? ['id' => $c->id, 'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''))] : null;
            })->filter()->values();

            return [
                'event_id'   => $ev->id,
                'event_date' => $ev->event_date,
                'title'      => $ev->title,
                'agent_name' => $agents->get($ev->user_id, 'Unknown'),
                'buyers'     => $buyers,
                'feedback'   => ($feedback->get($ev->id, collect()))->map(fn ($fb) => [
                    'outcome_label'  => $options->get($fb->outcome_option_id),
                    'concerns'       => collect($fb->concern_option_ids ?? [])->map(fn ($id) => $options->get($id))->filter()->values()->all(),
                    'seller_notes'   => $fb->seller_visible_notes,
                    'internal_notes' => $fb->internal_notes,
                    'captured_at'    => $fb->captured_at,
                ])->values(),
            ];
        });
    }

    /**
     * Seller-safe notes: outcome label, seller_visible_notes and a date — no
     * buyer, no internal note, no next-action note. Rows with neither an
     * outcome nor a note are omitted (their concern tags surface via themes()).
     */
    public function sellerNotes(int $propertyId, int $limit = 5): array
    {
        $rows = $this->feedbackFor($this->forProperty($propertyId), true);
        $outcomes = AgencyFeedbackOption::withoutGlobalScopes()
            ->whereIn('id', $rows->pluck('outcome_option_id')->filter()->unique())
            ->pluck('label', 'id');

        return $rows
            ->map(fn ($fb) => [
                'outcome_label' => $outcomes->get($fb->outcome_option_id),
                'notes'         => $fb->seller_visible_notes,
                'date'          => $fb->captured_at,
            ])
            ->filter(fn ($r) => !empty($r['outcome_label']) || !empty($r['notes']))
            ->sortByDesc('date')
            ->take($limit)
            ->values()
            ->all();
    }

    private function concernLabels(Collection $ids): Collection
    {
        return $ids->isEmpty()
            ? collect()
            : AgencyFeedbackOption::withoutGlobalScopes()->whereIn('id', $ids)->pluck('label', 'id');
    }
}
