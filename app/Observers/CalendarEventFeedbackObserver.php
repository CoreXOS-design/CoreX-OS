<?php

namespace App\Observers;

use App\Models\BuyerPropertyView;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\Contact;
use App\Models\DailyActivityEntry;
use App\Services\Activity\PointStateService;
use App\Services\BuyerStateService;
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
