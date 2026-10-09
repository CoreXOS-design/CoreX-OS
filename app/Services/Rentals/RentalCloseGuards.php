<?php

namespace App\Services\Rentals;

use App\Models\RentalWorkOrder;
use App\Models\User;

/**
 * .ai/specs/rental-work-orders.md §17.21.1 — the ONE place the two close rules
 * live, called from the single close path in RentalWorkOrder::complete() and
 * RentalJobCard::complete(). It exists so Build 2 and Build 3 never edit the
 * same lines of those two methods: each fills exactly one method here.
 *
 * assertNotDisputed is Build 3's (inert until it lands); assertFinalCostWithinApproval is Build 2's.
 */
class RentalCloseGuards
{
    /**
     * §17.10.9 — refuse to close while a tenant has an OPEN dispute on this work order
     * (work order status = disputed).
     */
    public function assertNotDisputed(RentalWorkOrder $workOrder): void
    {
        // Johan, 9 Oct 2026 (T1): "agent can close on word and evidence from the crew or contractor". The tenant's answer - a confirmation or a
        // "not fixed" - is an optional RECORD and never blocks closing, before or after. (Before this, a tenant dispute put the work order in the
        // disputed stage and this refused to close it.) A "not fixed" raises a needs-action row; it does not reopen anything by itself.
    }

    /**
     * §17.9.4 — a final cost above the owner-approved amount is tested by the gate: within the owner's terms →
     * auto-approved and logged; otherwise the close is refused in plain words. Emergency work (no baseline, settled
     * afterwards), work with no approved amount (older rows — RentalWorkOrder's own threshold check still applies) and
     * a final cost at or under the approved amount are never refused. A variation still waiting on the owner blocks
     * the close too: the extra has not been approved.
     */
    public function assertFinalCostWithinApproval(RentalWorkOrder $workOrder, ?float $finalAmount, ?User $by): void
    {
        $gate = app(RentalApprovalGateService::class);
        if ($gate->isEmergency($workOrder) || ! $workOrder->hasApprovedBaseline() || $finalAmount === null) {
            return;
        }

        $open = $workOrder->openVariation();
        if ($open) {
            throw new \LogicException('A request for extra work (R' . number_format((float) $open->extra_amount, 2) . ') is still waiting for the owner — close this job once the owner has decided.');
        }

        $baseline = (float) $workOrder->approved_amount;
        if (round($finalAmount, 2) <= round($baseline, 2)) {
            return;
        }

        $decision = $gate->evaluateVariation($workOrder, $finalAmount, $by);
        if (! $decision->authorised) {
            throw new \LogicException('The final cost of R' . number_format($finalAmount, 2) . ' is above what the owner approved (R' . number_format($baseline, 2)
                . ') — capture the contractor\'s revised quote and select it so the owner can approve the extra, then complete the work order.');
        }
    }
}
