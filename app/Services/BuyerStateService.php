<?php

namespace App\Services;

use App\Models\AgencyContactSettings;
use App\Models\BuyerActivityLog;
use App\Models\BuyerStateTransition;
use App\Models\Contact;

class BuyerStateService
{
    /**
     * Resolve the current buyer state based on last_activity_at and agency thresholds.
     */
    public function resolveState(Contact $contact): ?string
    {
        if (!$contact->is_buyer) {
            return null;
        }

        if (!$contact->last_activity_at) {
            return 'new';
        }

        $agencyId = $contact->agency_id ?? 1;
        $settings = AgencyContactSettings::forAgency($agencyId);

        $daysSinceActivity = (int) $contact->last_activity_at->diffInDays(now());

        if ($daysSinceActivity <= $settings->buyer_warm_days) {
            return 'warm';
        }
        if ($daysSinceActivity <= $settings->buyer_cold_days) {
            return 'cold';
        }
        return 'lost';
    }

    /**
     * Transition a buyer to a new state with audit logging.
     */
    public function transitionTo(Contact $contact, string $newState, string $reason = 'auto_recompute', ?int $userId = null): void
    {
        $oldState = $contact->buyer_state;

        if ($oldState === $newState) {
            return; // No change
        }

        $contact->updateQuietly(['buyer_state' => $newState]);

        BuyerStateTransition::create([
            'contact_id' => $contact->id,
            'from_state' => $oldState,
            'to_state' => $newState,
            'reason' => $reason,
            'triggered_by_user_id' => $userId,
            'occurred_at' => now(),
        ]);

        // Auto-record lost reason when transitioning to lost via cron
        if ($newState === 'lost' && $reason === 'auto_recompute' && $oldState !== 'lost') {
            \Illuminate\Support\Facades\DB::table('buyer_lost_records')->insert([
                'contact_id' => $contact->id,
                'agency_id' => $contact->agency_id ?? 1,
                'reason_code' => 'no_activity',
                'reason_label' => 'No activity (auto-transitioned)',
                'notes' => 'Auto-transitioned after inactivity exceeding lost threshold.',
                'recorded_by_user_id' => null,
                'recorded_at' => now(),
                'source' => 'auto_no_activity',
                'buyer_state_at_loss' => $oldState,
                'days_in_pipeline_at_loss' => $contact->buyer_pipeline_entered_at ? (int) $contact->buyer_pipeline_entered_at->diffInDays(now()) : null,
                'days_since_last_activity_at_loss' => $contact->last_activity_at ? (int) $contact->last_activity_at->diffInDays(now()) : null,
                'agent_owner_user_id_at_loss' => $contact->created_by_user_id,
                'branch_id_at_loss' => $contact->branch_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Record an activity for a buyer and recompute state.
     */
    public function markActivity(
        Contact $contact,
        string $activityType,
        ?int $eventId = null,
        ?int $propertyId = null,
        ?int $feedbackId = null,
        ?int $userId = null,
        ?array $metadata = null
    ): void {
        // Ensure contact is marked as buyer
        if (!$contact->is_buyer) {
            $contact->updateQuietly([
                'is_buyer' => true,
                'buyer_pipeline_entered_at' => $contact->buyer_pipeline_entered_at ?? now(),
            ]);
            $contact->refresh();
        }

        // Log the activity
        BuyerActivityLog::create([
            'contact_id' => $contact->id,
            'agency_id' => $contact->agency_id ?? 1,
            'activity_type' => $activityType,
            'activity_date' => now(),
            'related_event_id' => $eventId,
            'related_property_id' => $propertyId,
            'related_feedback_id' => $feedbackId,
            'metadata' => $metadata,
            'logged_by_user_id' => $userId,
        ]);

        // Update last_activity_at
        $contact->updateQuietly(['last_activity_at' => now()]);
        $contact->refresh();

        // Recompute state
        $newState = $this->resolveState($contact);
        if ($newState) {
            $reason = $contact->buyer_state === null ? 'first_activity' : 'auto_recompute';
            $this->transitionTo($contact, $newState, $reason, $userId);
        }
    }

    /**
     * Recompute state for a contact without adding an activity.
     * Used by the daily cron to detect cold/lost transitions.
     */
    public function recomputeState(Contact $contact): void
    {
        $newState = $this->resolveState($contact);
        if ($newState && $newState !== $contact->buyer_state) {
            $this->transitionTo($contact, $newState, 'auto_recompute');
        }
    }
}
