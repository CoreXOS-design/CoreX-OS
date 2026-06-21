<?php

namespace App\Services;

use App\Models\AgencyContactSettings;
use App\Models\BuyerActivityLog;
use App\Models\BuyerStateTransition;
use App\Models\Contact;
use Illuminate\Support\Carbon;

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
            // agency_id is NOT-NULL (2026_05_23_030800) with no DB default.
            // Set it explicitly from the contact — relying on the BelongsToAgency
            // auto-stamp only works in single-agency DBs; in a multi-agency DB
            // the unstamped insert 1364-fails. Fixes the whole transition class
            // (this path + markActivity + the daily recompute cron).
            'agency_id' => $contact->agency_id ?? 1,
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
     * AT-72 — Auto-land a contact on the Buyer Pipeline as "New".
     *
     * The pipeline board (BuyerPipelineController) lists Contacts by
     * `is_buyer` + `buyer_state`. Until AT-72 those flags were only ever set
     * by first ACTIVITY (markActivity), so a buyer created purely by adding a
     * countable wishlist (ContactMatch) had is_buyer=false / buyer_state=NULL
     * and was invisible on the pipeline and every surface that reads it.
     *
     * This method makes a buyer LAND on the pipeline:
     *   1. Ensure is_buyer=true and stamp buyer_pipeline_entered_at (once).
     *   2. If — and only if — the buyer has no state yet, transition to 'new'
     *      with an audited row in buyer_state_transitions.
     *
     * It NEVER overwrites an existing state: a buyer already Warm/Cold/Lost
     * stays exactly where they are when another wishlist is added. It is
     * idempotent — calling it repeatedly on an already-landed buyer is a
     * no-op after the first landing.
     *
     * @param  string  $reason  Audit reason for the 'new' transition (e.g.
     *                          'wishlist_created', 'auto_landed').
     * @return bool  true iff a transition to 'new' was recorded this call.
     */
    public function landOnPipeline(Contact $contact, string $reason = 'wishlist_created', ?int $userId = null): bool
    {
        // 1. Ensure the buyer flag and pipeline-entry stamp. updateQuietly so
        //    we don't re-enter Contact observers / state machinery here.
        $updates = [];
        if (!$contact->is_buyer) {
            $updates['is_buyer'] = true;
        }
        if (!$contact->buyer_pipeline_entered_at) {
            $updates['buyer_pipeline_entered_at'] = now();
        }
        if ($updates) {
            $contact->updateQuietly($updates);
            $contact->refresh();
        }

        // 2. Land on 'new' ONLY if the buyer has no state yet. An existing
        //    Warm/Cold/Lost buyer adding another wishlist must NOT be reset.
        if ($contact->buyer_state !== null) {
            return false;
        }

        $this->transitionTo($contact, 'new', $reason, $userId);
        return true;
    }

    /**
     * Recompute state for a contact without adding an activity.
     * Used by the daily cron to detect cold/lost transitions.
     *
     * AT-74 — respects a recent MANUAL placement: a buyer an agent set/moved
     * within the protection window is left exactly where the agent put them
     * (the nightly recompute no longer clobbers agent decisions). Genuinely
     * stale buyers (no recent agent action) still decay normally.
     */
    public function recomputeState(Contact $contact): void
    {
        if ($this->isManualPlacementProtected($contact)) {
            return;
        }

        $newState = $this->resolveState($contact);
        if ($newState && $newState !== $contact->buyer_state) {
            $this->transitionTo($contact, $newState, 'auto_recompute');
        }
    }

    /**
     * AT-74 — is this buyer protected from auto-recompute because an agent
     * manually placed/moved them recently?
     *
     * A manual buyer_state transition (pipeline drag / mark-lost / re-engage —
     * the `manual_override` reason) is an agent ACTION. Per Johan's doctrine an
     * agent action counts as activity, so a buyer the agent touched within the
     * agency's stale window must NOT be auto-moved to a stale state that night.
     * The protection window is the agency's `buyer_cold_days` (the "still in
     * play" horizon) — after it lapses with no further agent action and no buyer
     * activity, normal last_activity_at-based decay resumes.
     *
     * Public so the recompute command can report protected buyers in --dry-run.
     */
    public function isManualPlacementProtected(Contact $contact): bool
    {
        $manualAt = BuyerStateTransition::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('reason', 'manual_override')
            ->latest('occurred_at')
            ->value('occurred_at');

        if (!$manualAt) {
            return false;
        }

        $settings   = AgencyContactSettings::forAgency($contact->agency_id ?? 1);
        $windowDays = (int) ($settings->buyer_cold_days ?? 30);

        return (int) Carbon::parse($manualAt)->diffInDays(now()) <= $windowDays;
    }
}
