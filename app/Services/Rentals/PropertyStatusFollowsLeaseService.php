<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\Property;
use App\Models\User;
use App\Services\Audit\PropertyAuditService;

/**
 * .ai/specs/rental-renewals.md §15 — GATE 2, approved 2026-10-04 WITH Johan's
 * change: NO new property status is introduced or removed. Notice is a fact
 * about the LEASE (leases.notice_date/move_out_date), never a property
 * status. This service only drives the EXISTING status mechanism (Property's
 * own save() → PropertyObserver → PropertyAuditService → PropertyStatusChanged)
 * for the transitions the gate approved — mirroring exactly how
 * LeaseActivationService::flipPropertyToLeasedOut() already does it for row 1.
 *
 * Each transition here is independently agency-toggleable
 * (LeaseSetting::auto*For()), default ON. Turning a toggle off skips the
 * automatic Property write entirely — nothing else about the Lease Hub
 * changes.
 */
class PropertyStatusFollowsLeaseService
{
    /**
     * Called from LeaseActivationService::flipPropertyToLeasedOut() the
     * moment a property is first flipped to "leased out" — captures
     * whatever it was before, ONCE, so rows 6/7 can restore it later. A
     * no-op if already captured (a renewal re-activation never overwrites
     * the true original).
     */
    public function captureStatusBeforeLetting(Property $property): void
    {
        if ($property->status_before_letting !== null) {
            return;
        }

        $property->status_before_letting = $property->status;
        $property->save();
    }

    /**
     * Row 2 — the notice dialog's "put this property back on the market"
     * tick. Status -> the SAME value rows 6/7 would restore (whatever the
     * property was before being let, falling back to the agency's default
     * pre-let status) — re-advertising ahead of vacancy uses the property's
     * own normal pre-let status, not a new concept. lease_start_date is the
     * existing field P24's own mapper already reads as "availabilityDate"
     * for commercial listings (Property24ListingMapper.php:133) — reused
     * here rather than inventing a new column; no new portal-sync code.
     */
    public function readvertiseOnNotice(Lease $lease, string $moveOutDate, ?User $user = null): void
    {
        $property = $lease->property;
        if (!$property) {
            return;
        }

        $newStatus = $property->status_before_letting ?: LeaseSetting::defaultPreLetStatusFor($property->agency_id);
        if (!Property::isAllowedStatus($newStatus, $property->agency_id)) {
            return;
        }

        $oldStatus = $property->status;
        $availableFrom = \Illuminate\Support\Carbon::parse($moveOutDate)->addDay()->toDateString();

        $property->status = $newStatus;
        $property->lease_start_date = $availableFrom;
        $property->save();

        app(PropertyAuditService::class)->log(
            $property,
            'property',
            'status_changed',
            $user,
            ['status' => $oldStatus],
            ['status' => $newStatus, 'available_from' => $availableFrom],
            metadata: ['cause' => "Notice recorded on Lease #{$lease->id} — back on market"],
            humanSummary: "Status changed from " . ucfirst($oldStatus ?: 'none') . " to " . ucfirst($newStatus) . " — notice recorded on Lease #{$lease->id}, available from {$availableFrom}",
        );
    }

    /**
     * Reversing a notice that was readvertised — restores "leased out"
     * (the only status a property with an active, un-notified lease can
     * be, per LeaseActivationService's own invariant) and clears the
     * availability date, since it no longer means anything.
     */
    public function reverseReadvertise(Lease $lease, ?User $user = null): void
    {
        $property = $lease->property;
        if (!$property) {
            return;
        }

        $oldStatus = $property->status;
        $property->status = 'let_out';
        $property->lease_start_date = null;
        $property->save();

        app(PropertyAuditService::class)->log(
            $property,
            'property',
            'status_changed',
            $user,
            ['status' => $oldStatus],
            ['status' => 'let_out'],
            metadata: ['cause' => "Notice reversed on Lease #{$lease->id}"],
            humanSummary: "Status changed from " . ucfirst($oldStatus ?: 'none') . " to Let Out — notice reversed on Lease #{$lease->id}",
        );
    }

    /**
     * Rows 6/7 — lease ended (out-inspection confirms vacant) or cancelled.
     * Restores the property's own pre-let status (captured at row 1,
     * falling back to the agency's configured default if none was ever
     * stored), THEN clears status_before_letting so the next lease cycle
     * captures fresh rather than reusing a stale value.
     */
    public function restorePreLetStatus(Lease $lease, string $cause, string $availabilityDate, ?User $user = null): void
    {
        $property = $lease->property;
        if (!$property) {
            return;
        }

        $newStatus = $property->status_before_letting ?: LeaseSetting::defaultPreLetStatusFor($property->agency_id);
        if (!Property::isAllowedStatus($newStatus, $property->agency_id)) {
            $property->status_before_letting = null;
            $property->save();

            return;
        }

        $oldStatus = $property->status;

        $property->status = $newStatus;
        $property->lease_start_date = $availabilityDate;
        $property->status_before_letting = null;
        $property->save();

        app(PropertyAuditService::class)->log(
            $property,
            'property',
            'status_changed',
            $user,
            ['status' => $oldStatus],
            ['status' => $newStatus, 'available_from' => $availabilityDate],
            metadata: ['cause' => $cause],
            humanSummary: "Status changed from " . ucfirst($oldStatus ?: 'none') . " to " . ucfirst($newStatus) . " — {$cause}",
        );
    }
}
