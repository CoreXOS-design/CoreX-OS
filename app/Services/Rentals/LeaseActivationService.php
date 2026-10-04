<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\Property;
use App\Services\Audit\PropertyAuditService;
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

            $this->flipPropertyToLeasedOut($lockedProperty, $lease);

            return $lease->fresh();
        });
    }

    /**
     * .ai/specs/leases.md §12.5 — "lease becomes active → property status
     * set to the agency's configured 'leased out' status" (Johan's ruling,
     * 4 Oct 2026, scoped down for this build — see this feature's own
     * report for what was deliberately NOT built here: notice-given/
     * lease-ended reversion/month-to-month rows are Stage 6/7, out of this
     * ticket's scope).
     *
     * `let_out` is used directly, not a new per-agency setting — it is
     * ALREADY a system-wide status slug hardcoded into Property's own
     * OFF_MARKET_STATUSES/CONCLUDED_STATUSES constants (Property.php:68,
     * 1726), not an HFC-specific concept, so this does not introduce a
     * new multi-agency assumption. Prevent-or-absorb (BUILD_STANDARD §3):
     * if an agency's own property_status vocabulary doesn't include
     * `let_out` (Property::isAllowedStatus() check), the automatic status
     * write is skipped entirely rather than writing a status the agency
     * never configured — the Lease Hub's own next-step card is unaffected
     * either way, since it reasons about the LEASE's state, not the
     * property's.
     *
     * Goes through the property's normal save() — never a direct
     * DB::table('properties')->update() — so PropertyObserver's status
     * vocabulary guard and PropertyAuditService::logStatusChange() both
     * fire exactly as they would for a manual agent status change. This
     * is also what makes the portal consequence automatic with no new
     * portal-sync code: Property::isOnMarket() already reads
     * OFF_MARKET_STATUSES, which already contains `let_out`.
     */
    private function flipPropertyToLeasedOut(Property $property, Lease $lease): void
    {
        $leasedOutStatus = 'let_out';

        if (!Property::isAllowedStatus($leasedOutStatus, $property->agency_id)) {
            return;
        }

        if ((string) $property->status === $leasedOutStatus) {
            return;
        }

        // .ai/specs/rental-renewals.md §15 (GATE 2) — capture what the
        // property was BEFORE being let, so rows 6/7 can restore it once
        // the tenancy truly ends. Must happen before $oldStatus below
        // overwrites $property->status in memory.
        app(PropertyStatusFollowsLeaseService::class)->captureStatusBeforeLetting($property);

        $oldStatus = $property->status;
        $property->status = $leasedOutStatus;
        $property->save();

        app(PropertyAuditService::class)->log(
            $property,
            'property',
            'status_changed',
            null,
            ['status' => $oldStatus],
            ['status' => $leasedOutStatus],
            metadata: ['cause' => "Lease #{$lease->id} activated"],
            humanSummary: 'Status changed from ' . ucfirst($oldStatus ?: 'none') . ' to Let Out — Lease #' . $lease->id . ' activated',
        );
    }
}
