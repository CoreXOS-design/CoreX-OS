<?php

namespace App\Services\Rentals;

use App\Models\Property;
use App\Models\RentalEmergencyApproval;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Models\RentalWorkOrderVariation;
use App\Models\User;

/**
 * .ai/specs/rental-work-orders.md §17.6.3 — the ONLY place the system decides
 * "does this need the owner?". No other code may. Every call that reaches a
 * decision writes a `rental_approval_decisions` row citing the term it relied on.
 *
 * FOUNDATION SHELL (§17.21.1): the signatures are final; BUILD 2 fills the
 * bodies. termsFor() is real (it is a pure read both builds need). The two
 * "inert defaults" the spec names are in force until Build 2 lands:
 * authoriseToProceed() authorises, assessAfterLineChange() does nothing — so
 * the foundation changes no behaviour. The decision and write methods refuse
 * loudly rather than silently decide nothing.
 */
class RentalApprovalGateService
{
    /** The two owner work terms in force for a property, with where each came from (§17.6.1). */
    public function termsFor(Property $property): WorkTerms
    {
        if ($property->rental_no_approval_spend_threshold !== null) {
            $limit = (float) $property->rental_no_approval_spend_threshold;
            $limitSource = WorkTerms::SOURCE_PROPERTY;
        } else {
            $limit = RentalWorkOrderSetting::spendThresholdFor($property->agency_id);
            $limitSource = RentalWorkOrderSetting::hasAgencySpendThreshold($property->agency_id)
                ? WorkTerms::SOURCE_AGENCY_DEFAULT
                : WorkTerms::SOURCE_CONSTANT;
        }

        if ($property->rental_variation_tolerance_percent !== null) {
            $pct = (float) $property->rental_variation_tolerance_percent;
            $pctSource = WorkTerms::SOURCE_PROPERTY;
        } else {
            $pct = RentalWorkOrderSetting::variationTolerancePercentFor($property->agency_id);
            $pctSource = RentalWorkOrderSetting::hasAgencyVariationTolerance($property->agency_id)
                ? WorkTerms::SOURCE_AGENCY_DEFAULT
                : WorkTerms::SOURCE_CONSTANT;
        }

        return new WorkTerms($limit, $limitSource, $pct, $pctSource);
    }

    /** §17.6.3 — the existing selectQuote() rule moved here unchanged. Build 2. */
    public function evaluateQuote(RentalWorkOrder $workOrder, float $ownerFacingAmount, ?User $by): GateDecision
    {
        throw new \LogicException('RentalApprovalGateService::evaluateQuote() lands in Build 2 (§17.21.3).');
    }

    /** §17.6.3 — the five-step variation rule. Build 2. */
    public function evaluateVariation(RentalWorkOrder $workOrder, float $newTotal, ?User $by): GateDecision
    {
        throw new \LogicException('RentalApprovalGateService::evaluateVariation() lands in Build 2 (§17.21.3).');
    }

    /**
     * Called after any change that can raise a card's accepted total (accepting crew lines, adding / editing /
     * restoring a line, repricing). Does nothing while the work order has no approved amount yet. INERT until Build 2.
     */
    public function assessAfterLineChange(RentalJobCard $card, ?User $by): ?RentalWorkOrderVariation
    {
        return null;
    }

    /**
     * §17.6.5 — may work start / be scheduled / be completed by the crew? INERT until Build 2: authorised, so the
     * guard sites (added by Build 2) never block anything in the foundation.
     */
    public function authoriseToProceed(RentalWorkOrder $workOrder): GateDecision
    {
        return new GateDecision(authorised: true, decision: 'no_change', note: 'Approval gate not active yet.');
    }

    /**
     * Record the owner's (or the agent-captured) decision on a variation, at the revision the owner saw.
     * $evidence carries the §17.7.4 evidence fields; $actor is `['user' => User]` or `['contact' => Contact]`. Build 2.
     *
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $actor
     */
    public function recordVariationDecision(RentalWorkOrderVariation $variation, string $decision, array $evidence, array $actor): void
    {
        throw new \LogicException('RentalApprovalGateService::recordVariationDecision() lands in Build 2 (§17.21.3).');
    }

    /**
     * §17.8 — capture the owner's emergency agreement (no amount). $data: approved_by_name, owner_contact_id?,
     * approved_via, approved_at, reason, reported_by_crew_name?, notes?, attachment?. Build 2.
     *
     * @param array<string, mixed> $data
     */
    public function recordEmergency(RentalWorkOrder $workOrder, array $data, User $by): RentalEmergencyApproval
    {
        throw new \LogicException('RentalApprovalGateService::recordEmergency() lands in Build 2 (§17.21.3).');
    }

    /** §17.8.3 — void a mistaken emergency approval (reason required) and re-run the gate. Build 2. */
    public function voidEmergency(RentalEmergencyApproval $approval, string $reason, User $by): void
    {
        throw new \LogicException('RentalApprovalGateService::voidEmergency() lands in Build 2 (§17.21.3).');
    }
}
