<?php

namespace App\Observers;

use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\RentalInspection;
use App\Services\Rentals\PropertyStatusFollowsLeaseService;

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

        $lease = Lease::withoutGlobalScopes()->find($inspection->lease_id);
        if (!$lease || $lease->status !== Lease::STATUS_ACTIVE || $lease->renewed_lease_id) {
            // Already renewed, cancelled, or not a currently-active term —
            // rows 3/4/7 own those paths, this row is specifically "ended
            // with nothing else having happened to it."
            return;
        }

        if (!LeaseSetting::autoRestoreStatusOnLeaseEndedFor($lease->agency_id)) {
            return;
        }

        $lease->status = Lease::STATUS_EXPIRED;
        $lease->save();

        app(PropertyStatusFollowsLeaseService::class)->restorePreLetStatus(
            $lease,
            "Lease #{$lease->id} ended — out-inspection confirmed vacant",
            now()->toDateString(),
        );
    }
}
