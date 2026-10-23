<?php

namespace App\Observers;

use App\Events\Evaluation\EvaluationCompleted;
use App\Events\Viewing\ViewingHeld;
use App\Models\BuyerPropertyView;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\Contact;
use App\Models\DailyActivityEntry;
use App\Services\Activity\PointStateService;
use App\Services\BuyerStateService;
use App\Services\Properties\PropertyViewings;
use App\Support\Events\SafeRaise;
use Illuminate\Support\Facades\Log;

class CalendarEventFeedbackObserver
{
    public function saved(CalendarEventFeedback $feedback): void
    {
        // M6.4 — confirm provisional auto_calendar points rows for this
        // calendar event. Runs BEFORE the existing buyer_facing early-return
        // because the M6.2 mapping table is the source of truth for "this
        // event earns points" — the buyer_facing flag below gates only the
        // unrelated buyer-state logic. SAFETY: any exception is caught,
        // logged, and SWALLOWED so the feedback save always completes.
        if ($feedback->calendar_event_id && $feedback->captured_at !== null) {
            try {
                $entries = DailyActivityEntry::where('calendar_event_id', $feedback->calendar_event_id)
                    ->where('source', DailyActivityEntry::SOURCE_AUTO_CALENDAR)
                    ->where('point_state', DailyActivityEntry::STATE_PROVISIONAL)
                    ->get();
                foreach ($entries as $entry) {
                    app(PointStateService::class)->confirm($entry, $feedback->captured_at);
                }
            } catch (\Throwable $e) {
                Log::warning('M6.4 confirm hook caught exception, feedback save proceeds', [
                    'feedback_id' => $feedback->id ?? null,
                    'event_id'    => $feedback->calendar_event_id,
                    'message'     => $e->getMessage(),
                ]);
            }
        }

        // Lead response time (Johan, 2026-10-07): feedback provided on an appointment the contact is part of
        // is a genuine first contact. Never breaks the feedback save.
        if ($feedback->calendar_event_id && $feedback->captured_at !== null) {
            try {
                $contactIds = $feedback->contact_id
                    ? [(int) $feedback->contact_id]
                    : \Illuminate\Support\Facades\DB::table('calendar_event_links')
                        ->where('calendar_event_id', $feedback->calendar_event_id)
                        ->where('linkable_type', Contact::class)
                        ->pluck('linkable_id')->map(fn ($i) => (int) $i)->all();
                foreach (Contact::withoutGlobalScopes()->whereIn('id', $contactIds)->get() as $c) {
                    $c->recordAgentContact('appointment_feedback', $feedback->captured_at, $feedback->captured_by_user_id);
                }
            } catch (\Throwable $e) {
                Log::warning('Lead response feedback hook caught exception, feedback save proceeds', [
                    'feedback_id' => $feedback->id ?? null,
                    'message'     => $e->getMessage(),
                ]);
            }
        }

        if (!$feedback->contact_id || !$feedback->calendar_event_id) {
            return;
        }

        $event = CalendarEvent::withoutGlobalScopes()->find($feedback->calendar_event_id);
        if (!$event) {
            return;
        }

        // Check if this event's class is buyer-facing
        $config = CalendarEventClassSetting::withoutGlobalScopes()
            ->where('event_class', $event->category)
            ->where(fn($q) => $q->where('agency_id', $event->agency_id)->orWhereNull('agency_id'))
            ->orderByRaw('agency_id IS NULL')
            ->first();

        if (!$config || !$config->buyer_facing) {
            return;
        }

        $contact = Contact::withoutGlobalScopes()->find($feedback->contact_id);
        if (!$contact) {
            return;
        }

        // Mark activity via BuyerStateService (handles is_buyer flag + state recompute)
        $activityType = $event->category === 'viewing' ? 'viewing_completed' : 'presentation';
        app(BuyerStateService::class)->markActivity(
            $contact,
            $activityType,
            $event->id,
            $feedback->property_id,
            $feedback->id,
            $feedback->captured_by_user_id
        );

        // Refresh buyer_property_views cache for this (contact × property)
        if ($feedback->property_id) {
            $this->refreshPropertyView($feedback->contact_id, $feedback->property_id, $feedback->id, $feedback->agency_id);
        }
    }

    /**
     * Road to Sold step 4: the evaluation was DONE - the first feedback captured for a property_evaluation
     * event. Once per evaluation: a second attendee's feedback, or an edit of the first, raises nothing.
     * created() and updated() are separate hooks on purpose: after a create, wasRecentlyCreated stays true for
     * every later save of the same in-memory object, so it cannot be used to tell "the save that captured it".
     */
    public function created(CalendarEventFeedback $feedback): void
    {
        $this->raiseEvaluationCompleted($feedback);
        $this->raiseViewingHeld($feedback, null);
    }

    public function updated(CalendarEventFeedback $feedback): void
    {
        if ($feedback->wasChanged('captured_at') && $feedback->getOriginal('captured_at') === null) {
            $this->raiseEvaluationCompleted($feedback);
        }
        // The row as it was before this save: a viewing counts only on the save that first makes it count.
        $this->raiseViewingHeld($feedback, (new CalendarEventFeedback)->setRawAttributes($feedback->getOriginal(), true));
    }

    /**
     * Road to Sold step 10: a buyer's viewing of one property was held. Counts by the one rule PropertyViewings::capture() applies
     * everywhere (status "viewed", not blank), plus captured and not archived, on a viewing-category appointment. Raised only on
     * the save that moves the row from "does not count" to "counts" ($before = null on a create), so an edit of a row that already
     * counted - or a second save of the same row - raises nothing. Never breaks the feedback save.
     */
    private function raiseViewingHeld(CalendarEventFeedback $feedback, ?CalendarEventFeedback $before): void
    {
        try {
            if (! $this->countsAsViewingHeld($feedback) || ($before && $this->countsAsViewingHeld($before))) {
                return;
            }
            $event = CalendarEvent::withoutGlobalScopes()->find($feedback->calendar_event_id);
            if (! $event || $event->deleted_at !== null || ! in_array($event->category, PropertyViewings::VIEWING_CATEGORIES, true)) {
                return;
            }
            if ($feedback->agency_id !== null && $event->agency_id !== null && (int) $feedback->agency_id !== (int) $event->agency_id) {
                return;
            }
            SafeRaise::dispatch('ViewingHeld', fn () => new ViewingHeld(
                feedbackId: (int) $feedback->id,
                calendarEventId: (int) $event->id,
                propertyId: $feedback->property_id !== null ? (int) $feedback->property_id : null,
                contactId: $feedback->contact_id !== null ? (int) $feedback->contact_id : ($event->contact_id !== null ? (int) $event->contact_id : null),
                agencyId: $event->agency_id !== null ? (int) $event->agency_id : null,
                branchId: $event->branch_id !== null ? (int) $event->branch_id : null,
                userId: $event->user_id !== null ? (int) $event->user_id : null,
                heldAt: (string) $feedback->captured_at,
                actorUserId: $feedback->captured_by_user_id !== null ? (int) $feedback->captured_by_user_id : null,
            ));
        } catch (\Throwable $e) {
            Log::warning('ViewingHeld hook caught exception, feedback save proceeds', [
                'feedback_id' => $feedback->id ?? null,
                'message'     => $e->getMessage(),
            ]);
        }
    }

    private function countsAsViewingHeld(CalendarEventFeedback $feedback): bool
    {
        if (! $feedback->calendar_event_id || $feedback->captured_at === null || $feedback->deleted_at !== null) {
            return false;
        }
        $capture = PropertyViewings::capture($feedback);

        return $capture['status'] === PropertyViewings::STATUS_VIEWED && ! $capture['blank'];
    }

    /** Never breaks the feedback save. */
    private function raiseEvaluationCompleted(CalendarEventFeedback $feedback): void
    {
        try {
            if (! $feedback->calendar_event_id || $feedback->captured_at === null) {
                return;
            }
            $event = CalendarEvent::withoutGlobalScopes()->find($feedback->calendar_event_id);
            if (! $event || $event->category !== 'property_evaluation') {
                return;
            }
            $earlier = CalendarEventFeedback::withoutGlobalScopes()
                ->where('calendar_event_id', $feedback->calendar_event_id)
                ->where('id', '!=', $feedback->id)
                ->whereNotNull('captured_at')
                ->exists();
            if ($earlier) {
                return;
            }
            SafeRaise::dispatch('EvaluationCompleted', fn () => new EvaluationCompleted(
                calendarEventId: (int) $event->id,
                feedbackId: (int) $feedback->id,
                agencyId: $event->agency_id !== null ? (int) $event->agency_id : null,
                userId: $event->user_id !== null ? (int) $event->user_id : null,
                propertyId: $event->property_id !== null ? (int) $event->property_id : null,
                contactId: $feedback->contact_id !== null ? (int) $feedback->contact_id : ($event->contact_id !== null ? (int) $event->contact_id : null),
                completedAt: (string) $feedback->captured_at,
                actorUserId: $feedback->captured_by_user_id !== null ? (int) $feedback->captured_by_user_id : null,
            ));
        } catch (\Throwable $e) {
            Log::warning('EvaluationCompleted hook caught exception, feedback save proceeds', [
                'feedback_id' => $feedback->id ?? null,
                'message'     => $e->getMessage(),
            ]);
        }
    }

    private function refreshPropertyView(int $contactId, int $propertyId, int $feedbackId, ?int $agencyId): void
    {
        $viewCount = CalendarEventFeedback::where('contact_id', $contactId)
            ->where('property_id', $propertyId)
            ->whereNotNull('captured_at')
            ->distinct('calendar_event_id')
            ->count('calendar_event_id');

        $lastFeedback = CalendarEventFeedback::where('contact_id', $contactId)
            ->where('property_id', $propertyId)
            ->whereNotNull('captured_at')
            ->orderByDesc('captured_at')
            ->first();

        BuyerPropertyView::updateOrCreate(
            ['contact_id' => $contactId, 'property_id' => $propertyId],
            [
                // Explicit so the cache row is correctly tenant-stamped even when
                // no Auth context exists (console resave, queue) — BelongsToAgency
                // only auto-stamps for authed/single-agency creates.
                'agency_id' => $agencyId,
                'last_viewed_at' => $lastFeedback?->captured_at ?? now(),
                'view_count' => $viewCount,
                'most_recent_feedback_id' => $lastFeedback?->id ?? $feedbackId,
            ]
        );
    }
}
