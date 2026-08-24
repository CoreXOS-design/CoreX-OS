<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Exceptions\SeatReinstatementLockedException;
use App\Models\Billing\AgentSeatRelease;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The 30-day seat reinstatement lock — AT-278.
 * Spec: .ai/specs/agent-seat-release-lock.md §5
 *
 * WHY THIS EXISTS
 * CoreX bills an agency on its LIVE billable seat count (agency-billing.md §3 D1),
 * recomputed on every read. That is honest — an agency that genuinely sheds an
 * agent stops paying immediately — but it also means a seat can be freed and
 * retaken at will, at no cost. Nothing stopped an agency archiving eight agents
 * on the 28th and restoring them on the 1st.
 *
 * So: the bill still drops the moment someone leaves (we never charge for people
 * who are gone), but that PERSON cannot occupy a seat again for 30 days. An
 * agency dodging R450 loses that agent's production for a month instead. The
 * incentive dies without punishing an honest departure.
 *
 * A CoreX System Owner can override immediately, with a mandatory reason, on the
 * record. That asymmetry is the design: the agency cannot self-serve the bypass,
 * so the deterrent holds; we can fix an honest mistake in seconds, so the rule
 * never traps a real customer.
 *
 * THIS SERVICE IS THE ONLY GATE. Every path that frees or re-occupies a seat
 * goes through it (spec §5.1), including a class-level backstop in UserObserver
 * so a future controller, command or job cannot quietly route around it.
 */
class AgentSeatLockService
{
    /** @see bypass() */
    private static bool $bypassing = false;

    /**
     * Run $fn with the UserObserver backstop suspended.
     *
     * Two legitimate callers, and only two:
     *
     * 1. A controller that has ALREADY passed assertCanReinstate() — the gate
     *    was cleared, so the observer must not re-refuse the very write the
     *    gate just authorised (an owner override would otherwise block itself).
     *    Read it as "the caller has satisfied the gate", not "skip the rule".
     * 2. Seeders, factories and console fixtures that create-delete-restore
     *    users wholesale and were never the thing this rule polices.
     *
     * Restores the previous state even if $fn throws, so a failing test can
     * never leave the guard off for the rest of the suite.
     *
     * @template T
     * @param  callable():T  $fn
     * @return T
     */
    public static function bypass(callable $fn): mixed
    {
        $previous = self::$bypassing;
        self::$bypassing = true;

        try {
            return $fn();
        } finally {
            self::$bypassing = $previous;
        }
    }

    public static function isBypassing(): bool
    {
        return self::$bypassing;
    }

    /** The configured hold, in days. Platform-wide, never an agency setting (spec D3). */
    public function lockDays(): int
    {
        return max(0, (int) config('corex-billing.seat_release.lock_days', 30));
    }

    // ── Releasing a seat ─────────────────────────────────────────────────────

    /**
     * Record that this user's billable seat has been freed, and start the hold.
     *
     * IDEMPOTENT by design: if a hold is already open for this user, that row is
     * returned untouched rather than a second one being stacked. Deleting an
     * already-deactivated agent is one release, not two, and the clock runs from
     * the FIRST release — the moment the seat actually became free.
     *
     * Returns null (and writes nothing) for anyone who never occupied a billable
     * seat: assistants (spec D7) and agency-less users (D8). Mirrors the same
     * exemptions UserObserver::announceHeadcountChange() applies.
     *
     * @param  string  $reason  AgentSeatRelease::REASON_*
     */
    public function release(User $user, string $reason, ?int $actorId = null): ?AgentSeatRelease
    {
        if (! $this->occupiesBillableSeat($user)) {
            return null;
        }

        if ($existing = $this->openReleaseFor($user)) {
            return $existing;
        }

        $now = now();

        $release = AgentSeatRelease::create([
            'agency_id'           => (int) $user->agency_id,
            'user_id'             => (int) $user->id,
            'released_at'         => $now,
            'released_by_user_id' => $actorId,
            'release_reason'      => $reason,
            // Stamped, not derived (spec D4).
            'reinstatable_at'     => $now->copy()->addDays($this->lockDays()),
        ]);

        Log::info('agent.seat_released', [
            'user_id'         => $user->id,
            'agency_id'       => $user->agency_id,
            'reason'          => $reason,
            'actor_user_id'   => $actorId,
            'reinstatable_at' => $release->reinstatable_at->toDateTimeString(),
        ]);

        return $release;
    }

    // ── Reading the lock ─────────────────────────────────────────────────────

    /** The open hold for this user, or null. */
    public function openReleaseFor(User $user): ?AgentSeatRelease
    {
        return AgentSeatRelease::query()
            ->where('user_id', $user->id)
            ->open()
            ->latest('released_at')
            ->first();
    }

    /** Open AND still inside the window — the only state that refuses a reinstatement. */
    public function isLocked(User $user): bool
    {
        return (bool) $this->openReleaseFor($user)?->isBlocking();
    }

    /** When the hold lifts, for the UI. Null when there is no blocking hold. */
    public function lockedUntil(User $user): ?CarbonInterface
    {
        $release = $this->openReleaseFor($user);

        return $release?->isBlocking() ? $release->reinstatable_at : null;
    }

    // ── The gate ─────────────────────────────────────────────────────────────

    /**
     * Throw unless this user may be put back into a billable seat right now.
     *
     * Passes when: there is no hold, the hold has elapsed, or the actor is a
     * CoreX System Owner supplying a reason.
     *
     * The owner check reads the actor's REAL role (isOwnerRole()), never the
     * View-As lens (isEffectiveOwner()) — an owner viewing as an agency admin is
     * still an owner, and no lens can make an agency admin into one.
     *
     * @throws SeatReinstatementLockedException
     */
    public function assertCanReinstate(User $user, ?User $actor = null, ?string $overrideReason = null): void
    {
        $release = $this->openReleaseFor($user);

        if ($release === null || ! $release->isBlocking()) {
            return;
        }

        // Not an owner — the hold stands, whatever permissions they hold.
        // Authority here is deliberately not delegable (spec §8): a permission
        // key could be granted to an agency admin by another agency admin,
        // handing the agency the bypass and voiding the rule.
        if (! $actor?->isOwnerRole()) {
            // An explicit override attempt by a non-owner is a different error
            // from simply hitting the wall, and deserves a different message.
            throw $overrideReason !== null
                ? SeatReinstatementLockedException::notAuthorised()
                : SeatReinstatementLockedException::for($user->name ?? 'This agent', $release);
        }

        if (mb_strlen(trim((string) $overrideReason)) < 10) {
            throw SeatReinstatementLockedException::overrideNeedsReason();
        }
    }

    /**
     * Close the open hold — the person is back in a seat.
     *
     * Call ONLY after assertCanReinstate() has passed. The write is a
     * compare-and-set on `reinstated_at IS NULL`, so two admins restoring the
     * same agent at once produce one close and one no-op, never a double.
     */
    public function reinstate(User $user, ?User $actor = null, ?string $overrideReason = null): void
    {
        $release = $this->openReleaseFor($user);

        if ($release === null) {
            return;
        }

        // An owner acting inside the window is an override; anything else is the
        // clock having simply run out.
        $isOverride = ! $release->isElapsed() && (bool) $actor?->isOwnerRole();

        DB::transaction(function () use ($release, $actor, $overrideReason, $isOverride) {
            $affected = AgentSeatRelease::query()
                ->whereKey($release->getKey())
                ->whereNull('reinstated_at')   // compare-and-set
                ->update([
                    'reinstated_at'         => now(),
                    'reinstated_by_user_id' => $actor?->id,
                    'reinstated_via'        => $isOverride
                        ? AgentSeatRelease::VIA_OWNER_OVERRIDE
                        : AgentSeatRelease::VIA_ELAPSED,
                    'override_reason'       => $isOverride ? trim((string) $overrideReason) : null,
                    'updated_at'            => now(),
                ]);

            if ($affected !== 1) {
                return;   // someone else closed it first — not an error
            }

            Log::info('agent.seat_reinstated', [
                'user_id'        => $release->user_id,
                'agency_id'      => $release->agency_id,
                'actor_user_id'  => $actor?->id,
                'via'            => $isOverride
                    ? AgentSeatRelease::VIA_OWNER_OVERRIDE
                    : AgentSeatRelease::VIA_ELAPSED,
                'override_reason' => $isOverride ? trim((string) $overrideReason) : null,
            ]);
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Did this user ever occupy a billable seat? Same definition billing uses
     * (agency-billing.md §3 D1): an assistant is an extension of an agent and
     * is never charged, and a System Owner carries no agency at all. Releasing
     * either frees nothing, so there is nothing to hold.
     */
    private function occupiesBillableSeat(User $user): bool
    {
        return (bool) $user->agency_id && ! $user->is_assistant;
    }
}
