<?php

namespace App\Services;

use App\Events\Contact\ContactMarkedLostInBuyerPipeline;
use App\Events\Contact\ContactRestoredFromLostInBuyerPipeline;
use App\Models\AgencyContactSettings;
use App\Models\BuyerActivityLog;
use App\Models\BuyerStateTransition;
use App\Models\Contact;
use App\Models\Deal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BuyerStateService
{
    /** Terminal "converted" state — set when a buyer is linked to a property (Johan 2026-08-13). */
    public const WON = 'won';

    /** Audit reason written when a Won buyer goes back to the active pipeline because their deal ended (AT-500). */
    public const DEAL_FELL_THROUGH_REASON = 'deal_fell_through';

    /** Audit reason written when the back-fill returns a Won buyer who is on no live deal (and was not moved by hand) to the board (AT-500). */
    public const WON_WITHOUT_LIVE_DEAL_REASON = 'won_without_live_deal';

    /** Every Buyer / Rental Pipeline status, in pipeline order. */
    public const PIPELINE_STATES = ['new', 'warm', 'cold', 'lost', self::WON];

    /**
     * Resolve the current buyer state based on last_activity_at and agency thresholds.
     *
     * RULING (Johan, via conductor, 2026-09-14) — resolveState() does NOT read
     * Contact::last_contacted_at, and this is deliberate, not a gap. Sending a
     * WhatsApp/email or marking a contact "contacted" (AT-372's
     * contacted_marked_at / last_contacted_at — see .ai/specs/at372-contacted-signal.md)
     * never advances buyer_state. Investigated on QA1 as a possible defect
     * after a live report of "150 in New looks like everything" — traced to a
     * real, separate classification bug (fixed, see Contact::primaryMatchIsRental())
     * plus this: 97 of 150 New-state buyers HAD been contacted, some within
     * the prior 8 days, and the board still showed them untouched.
     *
     * Johan's own words on why that stays exactly as it is:
     * "the pipeline is a manual agent action to move the buyers / tenants
     * around. so nothing to do there. we not automating it. the problem is
     * an agent has the responsibility to work with their buyers and the
     * pipeline is where the buyers sit. not automatically be moved around as
     * agents will not work with their buyers then."
     *
     * In other words: if sending a message silently advanced buyer_state, the
     * board would look worked without anyone having triaged anything — the
     * opposite of what it's for. A buyer contacted-but-left-in-New is exactly
     * the management signal this board exists to surface, not a bug in it.
     * `last_contacted_at` answers "has anyone been in touch" (automatic).
     * `buyer_state` answers "has an agent actually triaged this buyer"
     * (manual-only, moved by drag/updateState or the activity types below).
     * Do not wire the two together.
     */
    public function resolveState(Contact $contact): ?string
    {
        if (!$contact->is_buyer) {
            return null;
        }

        if (!$contact->last_activity_at) {
            return 'new';
        }

        $agencyId = (int) ($contact->agency_id ?: 0);   // AT-253 Rule 17: the CONTACT's agency, or none
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

        // AT-Core-Matches, Task 6 — updateQuietly() above suppresses
        // Eloquent model events, so this is dispatched explicitly rather
        // than relying on an observer that would never fire. Real column
        // is buyer_state, not buyer_status (corex-domain-events-spec.md's
        // ContactBuyerStatusChanged entry was stale/never built — see the
        // corrected entry in that file).
        if ($newState === 'lost') {
            event(new ContactMarkedLostInBuyerPipeline($contact, $userId));
        } elseif ($oldState === 'lost') {
            event(new ContactRestoredFromLostInBuyerPipeline($contact, $userId));
        }

        // AT-253 Rule 17 — a contact with no tenant has no history to file. Skip the row
        // (loudly, in the log) rather than file it under someone else's agency. The state
        // change above still stands: an audit gap must never break the actual work.
        if (! $contact->agency_id) {
            \Log::warning('AT-253 buyer-state history skipped: contact has no agency', [
                'contact_id' => $contact->id, 'from' => $oldState, 'to' => $newState,
            ]);

            return;
        }

        BuyerStateTransition::create([
            // agency_id is NOT-NULL (2026_05_23_030800) with no DB default.
            // Set it explicitly from the contact — relying on the BelongsToAgency
            // auto-stamp only works in single-agency DBs; in a multi-agency DB
            // the unstamped insert 1364-fails. Fixes the whole transition class
            // (this path + markActivity + the daily recompute cron).
            // AT-253 Rule 17 — the CONTACT's tenant, never a hardcoded 1.
            'agency_id' => $contact->agency_id,
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
                'agency_id' => $contact->agency_id,   // AT-253 Rule 17
                'reason_code' => 'no_activity',
                'reason_label' => 'No activity (auto-transitioned)',
                'notes' => 'Auto-transitioned after inactivity exceeding lost threshold.',
                'recorded_by_user_id' => null,
                'recorded_at' => now(),
                'source' => 'auto_no_activity',
                'buyer_state_at_loss' => $oldState,
                'days_in_pipeline_at_loss' => $contact->buyer_pipeline_entered_at ? (int) $contact->buyer_pipeline_entered_at->diffInDays(now()) : null,
                'days_since_last_activity_at_loss' => $contact->last_activity_at ? (int) $contact->last_activity_at->diffInDays(now()) : null,
                'agent_owner_user_id_at_loss' => $contact->agent_id, // AT-159: owner = assigned agent, not capturer
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

        // Log the activity. AT-253 Rule 17 — no tenant, no row (never agency 1).
        if (! $contact->agency_id) {
            \Log::warning('AT-253 buyer-activity log skipped: contact has no agency', [
                'contact_id' => $contact->id, 'activity' => $activityType,
            ]);

            return;
        }

        BuyerActivityLog::create([
            'contact_id' => $contact->id,
            'agency_id' => $contact->agency_id,   // AT-253 Rule 17
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

        // AT-500 — Won is terminal: activity on a converted buyer is logged (above) but never
        // moves them back onto the active board.
        if ($contact->buyer_state === self::WON) {
            return;
        }

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
        $newState = $this->nextAutoState($contact);
        if ($newState !== null) {
            $this->transitionTo($contact, $newState, 'auto_recompute');
        }
    }

    /**
     * AT-500 — the ONE answer to "what may the automatic recompute do to this buyer?".
     * Returns the state to move them to, or null for "leave them alone". Everything that
     * recomputes buyer state — recomputeState(), the nightly `buyers:recompute-states`
     * command and its --dry-run report — asks this, so a rule added here applies everywhere.
     *
     * 'won' is a TERMINAL state (Johan 2026-08-13) — a converted buyer must never be decayed
     * back to warm/cold/lost. resolveState() only ever yields new/warm/cold/lost, so without
     * this short-circuit the cron clobbers the win the next morning (it did, for every Won
     * buyer, from 13 Aug until AT-500). A recent manual placement is also left alone (AT-74).
     */
    public function nextAutoState(Contact $contact): ?string
    {
        if ($contact->buyer_state === self::WON) {
            return null;
        }

        if ($this->isManualPlacementProtected($contact)) {
            return null;
        }

        $newState = $this->resolveState($contact);

        return ($newState && $newState !== $contact->buyer_state) ? $newState : null;
    }

    /**
     * Buyer WON (Johan 2026-08-13) — the buyer converted (linked to / bought a property). Mark the
     * buyer_state 'won' (terminal) and ensure they are ON the pipeline so they surface in the
     * success section. Idempotent: a buyer already 'won' is left alone. Mirrors landOnPipeline()'s
     * is_buyer / pipeline-entry stamping, then transitions unconditionally (a win beats any prior
     * state — new/warm/cold/lost). Reason 'property_linked' is audited in buyer_state_transitions.
     */
    public function markWon(Contact $contact, ?int $userId = null, string $reason = 'property_linked'): bool
    {
        if ($contact->buyer_state === self::WON) {
            return false;
        }

        $updates = [];
        if (! $contact->is_buyer) {
            $updates['is_buyer'] = true;
        }
        if (! $contact->buyer_pipeline_entered_at) {
            $updates['buyer_pipeline_entered_at'] = now();
        }
        if ($updates) {
            $contact->updateQuietly($updates);
            $contact->refresh();
        }

        $this->transitionTo($contact, self::WON, $reason, $userId);
        return true;
    }

    /**
     * AT-500 — is this deal "live" for the purposes of the buyer's Won state? (Johan, 22 Oct 2026:
     * Won = linked to a live or registered deal only.) Pending, granted and registered deals are
     * live; a declined deal (accepted_status 'D') or an archived (soft-deleted) one is not.
     */
    public function dealIsLive(Deal $deal): bool
    {
        return $deal->deleted_at === null && (string) $deal->accepted_status !== 'D';
    }

    /**
     * AT-500 — is this contact a buyer party on at least one LIVE deal? The same definition as
     * dealIsLive(), as one query, so the fall-through reversal and the back-fill share it.
     */
    public function isOnLiveDeal(int $contactId): bool
    {
        return DB::table('deal_contacts')
            ->join('deals', 'deals.id', '=', 'deal_contacts.deal_id')
            ->where('deal_contacts.role', 'buyer')
            ->where('deal_contacts.contact_id', $contactId)
            ->whereNull('deals.deleted_at')
            ->whereRaw("COALESCE(deals.accepted_status, '') <> 'D'")
            ->exists();
    }

    /**
     * AT-500 — a DR2 deal names these contacts as its buyers: if the deal is live, each becomes Won.
     * This is the DR2 save's own call, independent of the property link, so it holds when the buyer
     * was already linked to the property in another role (no new link event fires) and when the deal
     * has no property yet. Idempotent. Contacts are limited to the deal's own agency.
     *
     * @param  int[]  $contactIds
     * @return int  how many buyers were moved to Won by this call
     */
    public function markBuyersWonForLiveDeal(Deal $deal, array $contactIds, ?int $userId = null): int
    {
        if ($contactIds === [] || ! $this->dealIsLive($deal)) {
            return 0;
        }

        $moved = 0;
        $buyers = Contact::withoutGlobalScopes()
            ->whereIn('id', $contactIds)
            ->where('agency_id', $deal->agency_id)
            ->whereNull('deleted_at')
            ->get();

        foreach ($buyers as $buyer) {
            if ($this->markWon($buyer, $userId)) {
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * AT-500 (Johan, 22 Oct 2026) — a buyer whose deal falls through (declined), is cancelled (archived) or
     * is taken off the deal goes BACK to the active pipeline. Won ends only if the buyer is no longer a buyer
     * on ANY live deal; they are then recomputed into New / Warm / Cold from their activity (never straight
     * into Lost — they have just been working with the agency) and the move is audited as 'deal_fell_through'.
     *
     * A Won buyer with no deal at all (Won through the property page link) is left alone unless the caller
     * says they were just taken off a deal ($wasDealBuyer).
     *
     * @return bool  true iff the buyer was moved off Won by this call
     */
    public function revertWonAfterDealEnded(Contact $contact, ?int $userId = null, bool $wasDealBuyer = false): bool
    {
        if ($contact->buyer_state !== self::WON || $this->isOnLiveDeal((int) $contact->id)) {
            return false;
        }

        if (! $wasDealBuyer && ! DB::table('deal_contacts')->where('contact_id', $contact->id)->where('role', 'buyer')->exists()) {
            return false;
        }

        $this->returnToBoard($contact, self::DEAL_FELL_THROUGH_REASON, $userId);

        return true;
    }

    /**
     * AT-500 — take a Won buyer off Won and recompute them into New / Warm / Cold from their activity
     * (never straight into Lost: they are for the agent to work, not to write off). Audited with $reason.
     */
    private function returnToBoard(Contact $contact, string $reason, ?int $userId): void
    {
        $target = $this->resolveState($contact) ?? 'new';
        if ($target === 'lost') {
            $target = 'cold';
        }

        $this->transitionTo($contact, $target, $reason, $userId);
    }

    /**
     * AT-500 (Johan, 22 Oct 2026, hard rule) — Won means exactly two things: the buyer is on a live DR2 deal,
     * or an agent moved them there by hand. A Won buyer who is on NO live deal and whose most recent move into
     * Won was not manual_override got there some other way (the old property-link rule, a deal that has since
     * ended) and belongs back on the board. Used by the back-fill's second part.
     */
    public function isWonWithoutBasis(Contact $contact): bool
    {
        if ($contact->buyer_state !== self::WON || $this->isOnLiveDeal((int) $contact->id)) {
            return false;
        }

        $lastWonReason = BuyerStateTransition::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('to_state', self::WON)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->value('reason');

        return $lastWonReason !== 'manual_override';
    }

    /** @return bool true iff the buyer was returned to the board by this call */
    public function returnWonWithoutBasisToBoard(Contact $contact, ?int $userId = null): bool
    {
        if (! $this->isWonWithoutBasis($contact)) {
            return false;
        }

        $this->returnToBoard($contact, self::WON_WITHOUT_LIVE_DEAL_REASON, $userId);

        return true;
    }

    /**
     * AT-500 — a deal changed (declined / re-opened / archived / restored): bring every buyer on it in line.
     * Live deal → its buyers are Won; otherwise each buyer who is on no other live deal leaves Won.
     */
    public function reconcileDealBuyers(Deal $deal, ?int $userId = null): void
    {
        $ids = DB::table('deal_contacts')->where('deal_id', $deal->id)->where('role', 'buyer')
            ->pluck('contact_id')->map(fn ($id) => (int) $id)->all();

        if ($this->dealIsLive($deal)) {
            $this->markBuyersWonForLiveDeal($deal, $ids, $userId);

            return;
        }

        $this->revertWonForBuyers($deal, $ids, $userId, false);
    }

    /**
     * AT-500 — these contacts were just taken off the deal as buyers (a correction on the DR2 edit):
     * any of them left on no live deal leave Won.
     *
     * @param  int[]  $contactIds
     */
    public function revertWonForBuyers(Deal $deal, array $contactIds, ?int $userId = null, bool $wasDealBuyer = true): void
    {
        if ($contactIds === []) {
            return;
        }

        $buyers = Contact::withoutGlobalScopes()
            ->whereIn('id', $contactIds)
            ->where('agency_id', $deal->agency_id)
            ->whereNull('deleted_at')
            ->get();

        foreach ($buyers as $buyer) {
            $this->revertWonAfterDealEnded($buyer, $userId, $wasDealBuyer);
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
            ->whereIn('reason', ['manual_override', self::DEAL_FELL_THROUGH_REASON, self::WON_WITHOUT_LIVE_DEAL_REASON])   // AT-500: a buyer sent back to the board by a fallen-through deal / the back-fill gets the same window to be worked
            ->latest('occurred_at')
            ->value('occurred_at');

        if (!$manualAt) {
            return false;
        }

        $settings   = AgencyContactSettings::forAgency((int) ($contact->agency_id ?: 0));   // AT-253 Rule 17
        $windowDays = (int) ($settings->buyer_cold_days ?? 30);

        return (int) Carbon::parse($manualAt)->diffInDays(now()) <= $windowDays;
    }
}
