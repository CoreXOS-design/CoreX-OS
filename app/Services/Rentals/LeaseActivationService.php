<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\Property;
use App\Services\Audit\PropertyAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    /**
     * The OTHER lease that would stop this one going active right now - active on the same property and not the term this one
     * renews - or null when nothing does. The same test activate() applies (kept in one place for the signed-copy mail, which
     * goes out before the lease is activated).
     */
    public function blockingLease(Lease $lease): ?Lease
    {
        $other = Lease::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('property_id', $lease->property_id)
            ->where('status', Lease::STATUS_ACTIVE)
            ->where('id', '!=', $lease->id)
            ->first();

        return $other && (int) $lease->previous_lease_id !== (int) $other->id ? $other : null;
    }

    public function activate(Lease $lease): Lease
    {
        return DB::transaction(function () use ($lease) {
            /** @var Property $lockedProperty */
            $lockedProperty = Property::withoutGlobalScopes()->whereKey($lease->property_id)->lockForUpdate()->firstOrFail();

            // A cancelled or expired lease is closed history — re-activating it
            // would resurrect a dead record with its cancel data still set
            // (audit M6). Status is re-read under the property lock.
            $currentStatus = Lease::withoutGlobalScopes()->whereKey($lease->id)->value('status');
            if (in_array($currentStatus, [Lease::STATUS_CANCELLED, Lease::STATUS_EXPIRED], true)) {
                throw ValidationException::withMessages([
                    'status' => "A {$currentStatus} lease cannot be activated again. Create a new lease instead.",
                ]);
            }

            // An archived lease (deleted_at set) never blocks a new one, whatever status it carries (leases.md §3.8).
            $currentlyActive = Lease::withoutGlobalScopes()
                ->whereNull('deleted_at')
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
     * Audit A26 (2026-10-19): the status written is whatever
     * PropertyStatusFollowsLeaseService::leasedOutStatusFor() resolves for
     * THIS agency's status vocabulary - no literal here. When the vocabulary
     * accepts none, the property is left alone (BUILD_STANDARD §3
     * prevent-or-absorb) but NOT silently: a lease event and a log line
     * record it, and propertyStatusWarning() puts the plain sentence on the
     * lease screen and in the activation message.
     *
     * Goes through the property's normal save() — never a direct
     * DB::table('properties')->update() — so PropertyObserver's status
     * vocabulary guard and PropertyAuditService::logStatusChange() both
     * fire exactly as they would for a manual agent status change. This
     * is also what makes the portal consequence automatic with no new
     * portal-sync code: Property::isOnMarket() already reads
     * OFF_MARKET_STATUSES, which already contains the let statuses.
     */
    public function flipPropertyToLeasedOut(Property $property, Lease $lease): void
    {
        $statusService = app(PropertyStatusFollowsLeaseService::class);
        $leasedOutStatus = $statusService->leasedOutStatusFor($property->agency_id);

        if ($leasedOutStatus === null) {
            $message = $this->noLeasedOutStatusMessage();
            Log::warning('Lease activated but the property status could not be set to a let status', ['lease_id' => $lease->id, 'property_id' => $property->id, 'agency_id' => $property->agency_id]);
            LeaseEvent::create([
                'lease_id' => $lease->id, 'event_type' => LeaseEvent::TYPE_PROPERTY_STATUS_NOT_UPDATED,
                'description' => $message, 'occurred_at' => now(), 'created_at' => now(),
            ]);

            return;
        }

        if ((string) $property->status === $leasedOutStatus) {
            return;
        }

        // .ai/specs/rental-renewals.md §15 (GATE 2) — capture what the
        // property was BEFORE being let, so rows 6/7 can restore it once
        // the tenancy truly ends. Must happen before $oldStatus below
        // overwrites $property->status in memory.
        $statusService->captureStatusBeforeLetting($property);

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
            humanSummary: 'Status changed from ' . ucfirst($oldStatus ?: 'none') . ' to ' . $statusService->statusLabel($leasedOutStatus) . ' — Lease #' . $lease->id . ' activated',
        );
    }

    /**
     * Audit A26 - the plain sentence shown on the lease screen (and appended to "Lease activated.") while this lease's property cannot be
     * given a let status in this agency's vocabulary. Null when all is well or the lease is not active.
     */
    public function propertyStatusWarning(Lease $lease): ?string
    {
        if ($lease->status !== Lease::STATUS_ACTIVE) {
            return null;
        }
        $agencyId = Property::withoutGlobalScopes()->whereKey($lease->property_id)->value('agency_id') ?? $lease->agency_id;

        return app(PropertyStatusFollowsLeaseService::class)->leasedOutStatusFor($agencyId ? (int) $agencyId : null) === null
            ? $this->noLeasedOutStatusMessage()
            : null;
    }

    private function noLeasedOutStatusMessage(): string
    {
        return 'The lease is active, but this agency has no property status for a let property, so the property\'s status was not changed. '
            . 'Set the property\'s status by hand under Settings → Property Statuses.';
    }
}
