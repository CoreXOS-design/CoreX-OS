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
 * FOUNDATION: both methods are inert (they refuse nothing).
 */
class RentalCloseGuards
{
    /**
     * §17.10.9 — refuse to close while a tenant has an OPEN dispute on this work order
     * (work order status = disputed).
     */
    public function assertNotDisputed(RentalWorkOrder $workOrder): void
    {
        // BUILD 3 — §17.10.9. Not blocked while a round is merely awaiting the tenant (the office may close and
        // invoice during the window; a dispute inside the window reopens it, §17.22 Decision 2) — only while
        // the work order itself is in the disputed stage.
        if ($workOrder->hasOpenDispute()) {
            throw new \LogicException('A tenant has reported this work as not complete — resolve the dispute first.');
        }
    }

    /**
     * §17.9.4 — an external job's final cost above the owner-approved amount is tested by the gate
     * (within tolerance → auto-approved and logged; otherwise the close is refused with a plain message).
     * Build 2 fills this in.
     */
    public function assertFinalCostWithinApproval(RentalWorkOrder $workOrder, ?float $finalAmount, ?User $by): void
    {
        // Inert in the foundation.
    }
}
