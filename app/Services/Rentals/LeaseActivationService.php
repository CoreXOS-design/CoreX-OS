<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/leases.md §3.5 — no property may have two ACTIVE leases at
 * once. MySQL has no partial/filtered unique index (unlike Postgres), so
 * this cannot be a bare DB constraint the way lease_tenants' unique
 * (lease_id, contact_id) can. Enforced here at the service layer instead,
 * via the same lockForUpdate()-guarded-transaction pattern already proven
 * in this codebase for an analogous "must not race" problem
 * (MobilePropertyController::uploadImages()'s gallery-append lock).
 *
 * Multiple DRAFT leases on the same property ARE allowed to coexist (e.g.
 * an agent preparing a renewal's paperwork while the current lease is
 * still active) — the guard is specifically "at most one ACTIVE lease per
 * property at any time," not "at most one lease record."
 */
class LeaseActivationService
{
    /**
     * Activates $lease. If $lease->previous_lease_id is set (a renewal),
     * the previous lease is atomically expired in the same transaction —
     * this is the one operation where two leases briefly changing status
     * together is correct, not a race.
     *
     * @throws ValidationException if another lease on this property is
     *   already active and is not the one being renewed.
     */
    public function activate(Lease $lease): Lease
    {
        return DB::transaction(function () use ($lease) {
            /** @var Property $lockedProperty */
            $lockedProperty = Property::withoutGlobalScopes()->whereKey($lease->property_id)->lockForUpdate()->firstOrFail();

            $currentlyActive = Lease::withoutGlobalScopes()
                ->where('property_id', $lockedProperty->id)
                ->where('status', Lease::STATUS_ACTIVE)
                ->where('id', '!=', $lease->id)
                ->first();

            if ($currentlyActive) {
                if ($lease->previous_lease_id === $currentlyActive->id) {
                    // This is the renewal case: the lease being activated names the
                    // currently-active one as its predecessor — expire it as part of
                    // the same atomic operation, chain the pointers both ways.
                    $currentlyActive->status = Lease::STATUS_EXPIRED;
                    $currentlyActive->renewed_lease_id = $lease->id;
                    $currentlyActive->save();
                } else {
                    throw ValidationException::withMessages([
                        'status' => "Property already has an active lease (#{$currentlyActive->id}). End or renew it before activating a new one.",
                    ]);
                }
            }

            $lease->status = Lease::STATUS_ACTIVE;
            $lease->save();

            return $lease->fresh();
        });
    }
}
