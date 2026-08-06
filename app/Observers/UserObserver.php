<?php

namespace App\Observers;

use App\Events\Agent\AgencyHeadcountChanged;
use App\Events\Website\AgentVisibilityChanged;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Two independent concerns ride on the User lifecycle:
 *
 * 1. Agency Public API — emit agent.* webhooks when an agent's website presence
 *    changes. Guarded (only fires on a real transition or a public-profile
 *    change of a visible agent).
 *    Spec: .ai/specs/agency-public-api.md §6.1, §2 (layer 3).
 *
 * 2. Billing — emit AgencyHeadcountChanged when a BILLABLE SEAT appears or
 *    disappears, so the agency's plan can be reconciled and CoreX told if it
 *    switched. A seat is any active, non-archived user (spec §3 D1), so the
 *    triggers are: created, is_active flipped, soft-deleted, restored.
 *    Spec: .ai/specs/agency-billing.md §7.2 (AT-11).
 *
 * Both are FAILURE-ISOLATED. Nothing here may break a user save — an admin must
 * always be able to deactivate a resigning agent, even if our webhook endpoint
 * is down or our billing table is unhappy.
 */
class UserObserver
{
    /** Public-profile fields exposed by the website AgentResource. */
    private const PROFILE_FIELDS = ['name', 'email', 'phone', 'cell', 'agent_photo_path'];

    public function created(User $user): void
    {
        try {
            if ($user->show_on_website) {
                event(new AgentVisibilityChanged($user, 'published'));
            }
        } catch (\Throwable $e) {
            Log::warning("Agent website webhook (create) failed for user #{$user->id}: {$e->getMessage()}");
        }

        // A new active user is a new billable seat — this is what moves an
        // agency onto the Agency plan when they hire their 11th person.
        if ($user->is_active) {
            $this->announceHeadcountChange($user, 'created');
        }
    }

    /**
     * AT-278 backstop — a locked agent may not be un-archived.
     * Spec: .ai/specs/agent-seat-release-lock.md §5.1 (enforcement point 7)
     *
     * The controllers call AgentSeatLockService::assertCanReinstate() and then
     * perform the authorised write inside ::bypass(). This hook exists for
     * everything else: a future controller, an Artisan command, a queued job, a
     * one-off tinker fix. Without it the rule is a habit; with it, it is
     * structural. Deliberately NOT failure-isolated — a guard that swallows its
     * own exception is not a guard.
     */
    public function restoring(User $user): void
    {
        $this->assertReinstatable($user);
    }

    /**
     * Same backstop for the other way back into a billable seat: flipping
     * `is_active` 0 → 1. Deactivating frees a seat exactly as archiving does
     * (billing spec §3 D1), so reactivating must clear the same gate.
     */
    public function updating(User $user): void
    {
        $reactivating = $user->isDirty('is_active')
            && ! $user->getOriginal('is_active')
            && $user->is_active;

        if ($reactivating) {
            $this->assertReinstatable($user);
        }
    }

    /** @throws \App\Exceptions\SeatReinstatementLockedException */
    private function assertReinstatable(User $user): void
    {
        if (\App\Services\Admin\AgentSeatLockService::isBypassing()) {
            return;
        }

        app(\App\Services\Admin\AgentSeatLockService::class)->assertCanReinstate($user);
    }

    public function updated(User $user): void
    {
        try {
            // show_on_website flipped → published / removed.
            if ($user->wasChanged('show_on_website')) {
                event(new AgentVisibilityChanged($user, $user->show_on_website ? 'published' : 'removed'));
            } elseif ($user->show_on_website && $user->wasChanged(self::PROFILE_FIELDS)) {
                // A visible agent's public profile changed → updated.
                event(new AgentVisibilityChanged($user, 'updated'));
            }
        } catch (\Throwable $e) {
            Log::warning("Agent website webhook (update) failed for user #{$user->id}: {$e->getMessage()}");
        }

        // Deactivating a user frees a seat; reactivating takes one. Moving a
        // user BETWEEN agencies changes the headcount of both, so both are told.
        if ($user->wasChanged('is_active')) {
            $this->announceHeadcountChange($user, $user->is_active ? 'activated' : 'deactivated');
        }

        if ($user->wasChanged('agency_id')) {
            $previousAgencyId = $user->getOriginal('agency_id');

            if ($previousAgencyId) {
                $this->announceHeadcountChange($user, 'deactivated', (int) $previousAgencyId);
            }

            $this->announceHeadcountChange($user, 'activated');
        }
    }

    public function deleted(User $user): void
    {
        try {
            if ($user->show_on_website) {
                event(new AgentVisibilityChanged($user, 'removed'));
            }
        } catch (\Throwable $e) {
            Log::warning("Agent website webhook (delete) failed for user #{$user->id}: {$e->getMessage()}");
        }

        // Archiving a user frees their seat — the agency stops paying for them.
        $this->announceHeadcountChange($user, 'deleted');
    }

    public function restored(User $user): void
    {
        // ...and un-archiving takes it back.
        if ($user->is_active) {
            $this->announceHeadcountChange($user, 'restored');
        }
    }

    /**
     * Tell the Billing pillar that this agency's seat count may have moved.
     *
     * The event carries NO count — deliberately. Subscribers recount from the
     * users table, because a number captured here could be stale by the time a
     * queued listener reads it, and a stale seat count is a misbilled agency.
     * Non-negotiable #9: we announce a fact; billing subscribes. We never reach
     * across the pillar boundary ourselves.
     */
    private function announceHeadcountChange(User $user, string $reason, ?int $agencyId = null): void
    {
        $agencyId = $agencyId ?? $user->agency_id;

        // CoreX System Owners carry a NULL agency_id and never occupy a seat.
        if (! $agencyId) {
            return;
        }

        try {
            event(new AgencyHeadcountChanged(
                affectedAgencyId: (int) $agencyId,
                user: $user,
                reason: $reason,
            ));
        } catch (\Throwable $e) {
            Log::warning("Billing headcount event failed for user #{$user->id}: {$e->getMessage()}");
        }
    }
}
