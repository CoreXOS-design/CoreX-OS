<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\Property;
use App\Models\User;
use App\Notifications\PropertyReadyToRelistNotice;
use App\Services\Audit\PropertyAuditService;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Support\Facades\Log;

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
     * tick. Status -> "the agency's EXISTING on-market rental status"
     * (conductor's own wording) — ALWAYS this setting's value, never
     * `status_before_letting`: that column can itself hold an off-market
     * value (e.g. a property let directly from `draft`), and row 2's own
     * instruction is unconditional — restoring the literal prior status
     * is rows 6/7's job, not this one's. lease_start_date is the existing
     * field P24's own mapper already reads as "availabilityDate" for
     * commercial listings (Property24ListingMapper.php:133) — reused here
     * rather than inventing a new column; no new portal-sync code.
     *
     * $showAvailableFromOnPortals — the notice dialog's own "Show
     * available-from date on portals" tick (§19, ruling #3), persisted onto
     * the property's own `show_available_from_on_portals` setting in this
     * SAME save() so the dialog acts as a shortcut to that setting rather
     * than a separate one-off flag. Null (the controller only passes a
     * value when the dialog actually rendered this field) leaves whatever
     * the property already had untouched.
     */
    public function readvertiseOnNotice(Lease $lease, string $moveOutDate, ?User $user = null, ?bool $showAvailableFromOnPortals = null): void
    {
        $property = $lease->property;
        if (!$property) {
            return;
        }

        $newStatus = LeaseSetting::defaultPreLetStatusFor($property->agency_id);
        if (!Property::isAllowedStatus($newStatus, $property->agency_id)) {
            return;
        }

        $oldStatus = $property->status;
        $availableFrom = \Illuminate\Support\Carbon::parse($moveOutDate)->addDay()->toDateString();

        $property->status = $newStatus;
        $property->lease_start_date = $availableFrom;
        if ($showAvailableFromOnPortals !== null) {
            $property->show_available_from_on_portals = $showAvailableFromOnPortals;
        }
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
     * .ai/specs/rental-renewals.md §19 — Johan's ruling 2026-10-05: the
     * three-way notice-outcome choice's "Withdraw" arm. Uses the EXISTING
     * `withdrawn` status (already in Property::OFF_MARKET_STATUSES, already
     * a system status valid for every agency — no new status introduced)
     * through the same save()-driven mechanism as readvertiseOnNotice()
     * above, applied IMMEDIATELY (same timing as readvertise — Johan's
     * ruling #2: nothing invents a new timing rule, this matches the
     * existing design). No availability date is meaningful for a withdrawn
     * listing, so lease_start_date is left untouched.
     */
    public function withdrawOnNotice(Lease $lease, ?User $user = null): void
    {
        $property = $lease->property;
        if (!$property) {
            return;
        }

        $oldStatus = $property->status;
        $property->status = 'withdrawn';
        $property->save();

        app(PropertyAuditService::class)->log(
            $property,
            'property',
            'status_changed',
            $user,
            ['status' => $oldStatus],
            ['status' => 'withdrawn'],
            metadata: ['cause' => "Notice recorded on Lease #{$lease->id} — property withdrawn"],
            humanSummary: "Status changed from " . ucfirst($oldStatus ?: 'none') . " to Withdrawn — notice recorded on Lease #{$lease->id}",
        );
    }

    /**
     * Reversing a notice that was withdrawn — same "let_out" restore as
     * reverseReadvertise() above, since a property with an active,
     * un-notified lease can only ever be "let_out".
     */
    public function reverseWithdraw(Lease $lease, ?User $user = null): void
    {
        $property = $lease->property;
        if (!$property) {
            return;
        }

        $oldStatus = $property->status;
        $property->status = 'let_out';
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
     *
     * $withoutPortalPush (Johan, release audit C-F3) - true when the tenancy ended BY ITSELF (the move-out date came, the out-inspection
     * confirms vacant: LeaseTermAdvanceService::endIfVacated). The property goes back on the market in CoreX, but NOTHING is pushed to
     * Property24 / Private Property (the save runs with skipSyndicationAutomation) - putting it back on the portals is the agent's
     * decision, so the agent who owns the listing gets an in-app note instead. An agent changing a status by hand, and the other
     * callers (cancel, archive), keep today's behaviour.
     */
    public function restorePreLetStatus(Lease $lease, string $cause, string $availabilityDate, ?User $user = null, bool $withoutPortalPush = false): void
    {
        $property = $lease->property;
        if (!$property) {
            return;
        }

        // leases.md §3.8 — the property's let status follows its ACTIVE lease. If another live (not archived) lease
        // is still active on this property, it is still let: nothing to release.
        $stillLet = Lease::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('property_id', $property->id)
            ->where('status', Lease::STATUS_ACTIVE)
            ->where('id', '!=', $lease->id)
            ->exists();
        if ($stillLet) {
            return;
        }

        // First allowed of: what it was before it was let, the agency's default, then plain "active". The last step
        // matters: a vocabulary that lacks the first two used to leave the property stuck on "Let out" forever.
        $newStatus = null;
        foreach ([$property->status_before_letting, LeaseSetting::defaultPreLetStatusFor($property->agency_id), 'active'] as $candidate) {
            if ($candidate && Property::isAllowedStatus($candidate, $property->agency_id)) {
                $newStatus = $candidate;
                break;
            }
        }
        // cc5's second walk (property 21209, 14 Oct 2026): the property went back on the market asking the ORIGINAL rent (R14,500) although the tenancy's last rent was
        // R15,660 - the agent had to re-price by hand. The last rent carries over to the asking rent (the property's own rent field stays editable by the agent).
        $oldRent = $property->rental_amount;
        $carriedRent = $this->lastRentToCarry($lease, $property);

        if ($newStatus === null) {
            $property->status_before_letting = null;
            if ($carriedRent !== null) {
                $property->rental_amount = $carriedRent;
            }
            $property->save();

            return;
        }

        $oldStatus = $property->status;

        $property->status = $newStatus;
        $property->lease_start_date = $availabilityDate;
        $property->status_before_letting = null;
        if ($carriedRent !== null) {
            $property->rental_amount = $carriedRent;
        }
        $previousSkip = $property->skipSyndicationAutomation;
        $property->skipSyndicationAutomation = $previousSkip || $withoutPortalPush;
        try {
            $property->save();
        } finally {
            $property->skipSyndicationAutomation = $previousSkip;
        }

        app(PropertyAuditService::class)->log(
            $property,
            'property',
            'status_changed',
            $user,
            ['status' => $oldStatus] + ($carriedRent !== null ? ['rental_amount' => $oldRent] : []),
            ['status' => $newStatus, 'available_from' => $availabilityDate] + ($carriedRent !== null ? ['rental_amount' => $carriedRent] : []),
            metadata: ['cause' => $cause],
            humanSummary: "Status changed from " . ucfirst($oldStatus ?: 'none') . " to " . ucfirst($newStatus) . " — {$cause}"
                . ($carriedRent !== null ? '; asking rent set to the last lease rent R ' . number_format($carriedRent, 0, '.', ' ') : ''),
        );

        if ($withoutPortalPush && $oldStatus !== $newStatus) {
            $this->tellAgentReadyToRelist($property, $lease);
        }
    }

    /** In-app note to the agent who owns the listing (gateway event property.ready_to_relist). Best effort: never undoes the status change. */
    private function tellAgentReadyToRelist(Property $property, Lease $lease): void
    {
        try {
            $agent = $property->agent_id ? User::withoutGlobalScopes()->find($property->agent_id) : null;
            $agent ??= $lease->created_by_user_id ? User::withoutGlobalScopes()->find($lease->created_by_user_id) : null;
            if ($agent) {
                app(NotificationDispatcher::class)->send(
                    $agent, 'property.ready_to_relist', $property,
                    new PropertyReadyToRelistNotice($property),
                    ['threshold_hit_at' => now()],
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Ready-to-relist note could not be sent to the agent', ['property_id' => $property->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The rent the property should be offered at again: the ended lease's last monthly rent, when this is a rental property, the lease actually carried a rent,
     * and it differs from what the property already asks. Null = leave the asking rent alone.
     */
    private function lastRentToCarry(Lease $lease, Property $property): ?float
    {
        $rent = (float) $lease->rental_amount;
        if ($rent <= 0 || ! $property->isRental() || abs($rent - (float) $property->rental_amount) < 0.005) {
            return null;
        }

        return $rent;
    }
}
