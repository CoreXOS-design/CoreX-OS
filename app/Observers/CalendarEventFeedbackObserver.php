<?php

namespace App\Observers;

use App\Models\BuyerPropertyView;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\Contact;
use App\Services\BuyerStateService;

class CalendarEventFeedbackObserver
{
    public function saved(CalendarEventFeedback $feedback): void
    {
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
            $this->refreshPropertyView($feedback->contact_id, $feedback->property_id, $feedback->id);
        }
    }

    private function refreshPropertyView(int $contactId, int $propertyId, int $feedbackId): void
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
                'last_viewed_at' => $lastFeedback?->captured_at ?? now(),
                'view_count' => $viewCount,
                'most_recent_feedback_id' => $lastFeedback?->id ?? $feedbackId,
            ]
        );
    }
}
