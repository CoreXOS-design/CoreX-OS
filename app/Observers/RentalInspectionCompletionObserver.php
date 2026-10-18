<?php

namespace App\Observers;

use App\Models\Lease;
use App\Models\RentalInspection;
use App\Services\Rentals\LeaseTermAdvanceService;

/**
 * .ai/specs/rental-renewals.md §15 — GATE 2 row 6: "lease ended AND
 * out-inspection confirms vacant" restores the property's pre-let status.
 *
 * Deliberately a MODEL OBSERVER, not a hook inside RentalInspectionController
 * or RentalInspectionRecordingController — RentalInspectionController is
 * cc1's file (do not touch, per task brief); an observer reacts to the
 * STATE CHANGE itself regardless of which controller/service performs the
 * write, the same pattern PropertyObserver already uses for Property. No
 * existing file in the inspection module is edited to wire this in — only
 * the one new `RentalInspection::observe()` registration in
 * AppServiceProvider.
 */
class RentalInspectionCompletionObserver
{
    public function updated(RentalInspection $inspection): void
    {
        if ($inspection->type !== RentalInspection::TYPE_OUT) {
            return;
        }
        if (!$inspection->wasChanged('status') || $inspection->status !== RentalInspection::STATUS_COMPLETED) {
            return;
        }
        if (!$inspection->lease_id) {
            return;
        }

        // The tenancy ends on its move-out date, not when the inspection is completed: completing it early only records that the
        // property is vacant. LeaseTermAdvanceService ends the lease now when the date has come, and the daily leases:advance-terms
        // does it on the date otherwise (rental-renewals.md §22).
        $lease = Lease::withoutGlobalScopes()->find($inspection->lease_id);
        if (!$lease) {
            return;
        }

        app(LeaseTermAdvanceService::class)->endIfVacated($lease);
    }
}
