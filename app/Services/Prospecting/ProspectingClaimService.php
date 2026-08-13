<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\ProspectingClaim;
use App\Models\Prospecting\ProspectingPitchLock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Central hub for prospecting claim lifecycle operations.
 *
 * Why this service exists: the prospecting tab has THREE places where a claim's state
 * can change — the explicit "Claim" button (existing ProspectingController::claim),
 * the "Release" button (existing ProspectingController::release for owner self-release,
 * + new releaseAsManager for BM/admin override), and the pitch-submit path. Without
 * a central service, the claim state mutates from three call sites with subtly different
 * rules — exactly the kind of incoherence the spec set out to fix.
 *
 * Responsibilities:
 *   - createTempLock              — soft 30-min lock when an agent clicks Pitch
 *   - consumeLockAsPermanentClaim — upgrade the temp lock to a permanent claim on submit
 *   - recordActionOnClaim         — append timestamped notes + bump status (future action hooks)
 *   - releaseClaim                — BM/admin or owner releases a claim back to the pool
 *   - loadTempLocksForListings    — bulk state lookup for the prospecting tab row enricher
 */
final class ProspectingClaimService
{
    /**
     * Create a temp lock when an agent clicks "Pitch Seller". Returns the active lock
     * (existing or newly created). Throws PitchLockConflictException when another
     * agent already holds an active lock.
     *
     * Transaction + lockForUpdate enforces the "one active lock per listing" invariant
     * across concurrent requests (since MySQL can't enforce a partial unique index).
     */
    public function createTempLock(int $listingId, int $userId, int $agencyId): ProspectingPitchLock
    {
        return DB::transaction(function () use ($listingId, $userId, $agencyId) {
            // Reap expired locks for this listing first so they don't block the new one.
            DB::table('prospecting_pitch_locks')
                ->where('prospecting_listing_id', $listingId)
                ->whereNull('released_at')
                ->where('expires_at', '<', now())
                ->update([
                    'released_at'    => now(),
                    'release_reason' => 'auto_expired',
                    'updated_at'     => now(),
                ]);

            // Acquire a row-level lock on any active row for this listing.
            $existing = ProspectingPitchLock::where('prospecting_listing_id', $listingId)
                ->whereNull('released_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($existing && (int) $existing->user_id !== $userId) {
                throw new PitchLockConflictException(
                    lockedByUserId: (int) $existing->user_id,
                    expiresAt: $existing->expires_at,
                );
            }

            // Same agent clicking Pitch again → extend the lock.
            if ($existing && (int) $existing->user_id === $userId) {
                $existing->update(['expires_at' => $this->computeExpiry($agencyId)]);
                return $existing->refresh();
            }

            return ProspectingPitchLock::create([
                'agency_id'              => $agencyId,
                'prospecting_listing_id' => $listingId,
                'user_id'                => $userId,
                'locked_at'              => now(),
                'expires_at'             => $this->computeExpiry($agencyId),
            ]);
        });
    }

    /**
     * Map Workspace Phase B (Fix 2+3) — temp lock for a Tracked Property.
     *
     * Sibling of createTempLock(): same 30-min lock semantics, same XOR
     * invariant (one active lock per TP, enforced via SELECT ... FOR UPDATE
     * in this transaction), but keyed to tracked_property_id instead of
     * prospecting_listing_id. Used by the T-pin "WhatsApp / Pitch" flow,
     * where the source isn't a portal listing.
     *
     * The prospecting_pitch_locks table holds EITHER a listing_id OR a
     * tracked_property_id, never both — the column the row uses depends on
     * which entry-point created it.
     */
    public function createTempLockForTrackedProperty(
        int $trackedPropertyId,
        int $userId,
        int $agencyId,
    ): ProspectingPitchLock {
        return DB::transaction(function () use ($trackedPropertyId, $userId, $agencyId) {
            DB::table('prospecting_pitch_locks')
                ->where('tracked_property_id', $trackedPropertyId)
                ->whereNull('released_at')
                ->where('expires_at', '<', now())
                ->update([
                    'released_at'    => now(),
                    'release_reason' => 'auto_expired',
                    'updated_at'     => now(),
                ]);

            $existing = ProspectingPitchLock::where('tracked_property_id', $trackedPropertyId)
                ->whereNull('released_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($existing && (int) $existing->user_id !== $userId) {
                throw new PitchLockConflictException(
                    lockedByUserId: (int) $existing->user_id,
                    expiresAt: $existing->expires_at,
                );
            }

            if ($existing && (int) $existing->user_id === $userId) {
                $existing->update(['expires_at' => $this->computeExpiry($agencyId)]);
                return $existing->refresh();
            }

            return ProspectingPitchLock::create([
                'agency_id'           => $agencyId,
                'tracked_property_id' => $trackedPropertyId,
                'user_id'             => $userId,
                'locked_at'           => now(),
                'expires_at'          => $this->computeExpiry($agencyId),
            ]);
        });
    }

    /**
     * Upgrade the temp lock to a permanent claim. Called from ComposerController::submit
     * after the SellerOutreachSend row is written, when the send corresponds to a
     * prospecting-derived contact (resolved via property_id → matched_property_id).
     *
     * Idempotent: if the agent already has an active permanent claim, the existing
     * claim's notes are prepended and last_updated_at refreshed; no duplicate row.
     *
     * Throws ClaimOwnershipConflictException when an OTHER agent already holds the
     * permanent claim — defence in depth, since the temp lock should have prevented it.
     */
    public function consumeLockAsPermanentClaim(
        int $listingId,
        int $userId,
        int $agencyId,
        array $pitchContext = []
    ): ProspectingClaim {
        return DB::transaction(function () use ($listingId, $userId, $agencyId, $pitchContext) {
            // Release the temp lock (mark it consumed by this send).
            DB::table('prospecting_pitch_locks')
                ->where('prospecting_listing_id', $listingId)
                ->where('user_id', $userId)
                ->whereNull('released_at')
                ->update([
                    'released_at'    => now(),
                    'release_reason' => 'consumed_by_send',
                    'updated_at'     => now(),
                ]);

            // Pitch Now #4 — key the claim on the PROPERTY, not just the rotating
            // ref. The pitch has already linked/promoted the property onto the
            // listing (matched_property_id) by the time we get here (EntryPoint
            // sets it before claiming; the composer resolves the listing BY it), so
            // read it straight off the listing. Nullable when no property is linked.
            $propertyId = DB::table('prospecting_listings')
                ->where('id', $listingId)
                ->value('matched_property_id');
            $propertyId = $propertyId !== null ? (int) $propertyId : null;

            $note = $this->formatPitchNote($pitchContext);

            $existing = ProspectingClaim::where('prospecting_listing_id', $listingId)
                ->where('agency_id', $agencyId)
                ->where('is_active', true)
                ->whereNull('released_at')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((int) $existing->user_id !== $userId) {
                    throw new ClaimOwnershipConflictException(
                        currentOwnerUserId: (int) $existing->user_id,
                    );
                }
                // A pitch makes the claim PERMANENT — stamp pitched_at if this
                // claim pre-dated the pitch lock (e.g. a plain Claim upgraded by
                // an actual pitch). Never un-set it.
                if ($existing->pitched_at === null) {
                    $existing->pitched_at = now();
                }
                // Backfill the property key on a pre-existing claim that lacked it,
                // so it starts protecting the property under any rotating ref.
                // Never overwrite an already-set property_id.
                if ($existing->property_id === null && $propertyId !== null) {
                    $existing->property_id = $propertyId;
                }
                $this->recordActionOnClaim($existing, 'contacted', $note);
                return $existing->refresh();
            }

            return ProspectingClaim::create([
                'agency_id'              => $agencyId,
                'prospecting_listing_id' => $listingId,
                // Property key (Pitch Now #4) — protects the property under any ref.
                'property_id'            => $propertyId,
                'user_id'                => $userId,
                'status'                 => 'contacted',
                'notes'                  => $note,
                'claimed_at'             => now(),
                // Pitch lock: this claim was born from an actual pitch, so it is
                // permanent (no 48h expiry, no silent re-claim). See
                // ProspectingClaim::isExpired().
                'pitched_at'             => now(),
                'last_updated_at'        => now(),
                'is_active'              => true,
            ]);
        });
    }

    /**
     * Append a timestamped action note to a claim and (optionally) bump its status.
     * The single hook point for future action integrations (call logging, viewings,
     * mandates, etc.) — each integration is a one-liner against this method.
     *
     * Notes are PREpended so the most recent action is visible first.
     */
    public function recordActionOnClaim(
        ProspectingClaim $claim,
        ?string $newStatus,
        string $actionNote,
    ): void {
        $existing = (string) ($claim->notes ?? '');
        $timestamped = '[' . now()->format('Y-m-d H:i') . '] ' . $actionNote;
        $combined = trim($timestamped . ($existing === '' ? '' : "\n" . $existing));

        $claim->update([
            'status'          => $newStatus ?? $claim->status,
            'notes'           => $combined,
            'last_updated_at' => now(),
            // MIC funnel phase 2 — working the claim RESETS the staleness timer: clear the warn so it
            // re-arms next cycle (and drops out of the BM stale-review until it goes stale again).
            'warned_at'       => null,
        ]);
    }

    /**
     * Release a claim back to the prospecting pool. Preserves the audit trail —
     * sets released_at + is_active=false rather than hard-deleting.
     *
     * Callers (controllers) are responsible for authorisation. This service does
     * not gate access — it does the bookkeeping atomically.
     */
    public function releaseClaim(int $claimId, int $releasedByUserId, string $reason, ?string $reasonCode = null): ProspectingClaim
    {
        return DB::transaction(function () use ($claimId, $releasedByUserId, $reason, $reasonCode) {
            $claim = ProspectingClaim::lockForUpdate()->findOrFail($claimId);

            $existing = (string) ($claim->notes ?? '');
            $releaseNote = '[' . now()->format('Y-m-d H:i') . '] RELEASED by user #' . $releasedByUserId . ' — ' . $reason;
            $combined = trim($releaseNote . ($existing === '' ? '' : "\n" . $existing));

            $claim->update([
                'released_at'     => now(),
                'is_active'       => false,
                // Structured reason (MIC phase 2) so other agents / the BM see WHY on the pool
                // (e.g. 'no_address' → "no address could be established, released back"). Free-text
                // reason still captured in the notes trail above.
                'release_reason'  => $reasonCode,
                'notes'           => $combined,
                'last_updated_at' => now(),
                'warned_at'       => null,
            ]);

            return $claim->refresh();
        });
    }

    /**
     * MIC funnel phase 2 — BM/admin REASSIGNMENT (anti-poaching: agents never grab stale stock from
     * each other; the BM/admin moves it). Changes the owning agent, resets the staleness timer, and
     * leaves an audit note. Returns [claim, oldUserId] so the caller can warn the losing agent.
     */
    public function reassignClaim(int $claimId, int $newAgentId, int $byUserId): array
    {
        return DB::transaction(function () use ($claimId, $newAgentId, $byUserId) {
            $claim = ProspectingClaim::lockForUpdate()->findOrFail($claimId);
            $oldUserId = (int) $claim->user_id;

            $existing = (string) ($claim->notes ?? '');
            $note = '[' . now()->format('Y-m-d H:i') . '] REASSIGNED by user #' . $byUserId
                . ' from agent #' . $oldUserId . ' to agent #' . $newAgentId;
            $combined = trim($note . ($existing === '' ? '' : "\n" . $existing));

            $claim->update([
                'user_id'         => $newAgentId,
                'notes'           => $combined,
                'last_updated_at' => now(),   // fresh start for the new agent
                'warned_at'       => null,
                'is_active'       => true,
                'released_at'     => null,
            ]);

            return [$claim->refresh(), $oldUserId];
        });
    }

    /**
     * MIC funnel phase 2 — BM/admin KEEP decision: leave the claim with its current agent but reset
     * the staleness timer (a deliberate "still ours, carry on"), clearing the warn.
     */
    public function keepClaim(int $claimId, int $byUserId): ProspectingClaim
    {
        return DB::transaction(function () use ($claimId, $byUserId) {
            $claim = ProspectingClaim::lockForUpdate()->findOrFail($claimId);
            $existing = (string) ($claim->notes ?? '');
            $note = '[' . now()->format('Y-m-d H:i') . '] KEPT by user #' . $byUserId . ' — staleness timer reset';
            $combined = trim($note . ($existing === '' ? '' : "\n" . $existing));
            $claim->update([
                'notes'           => $combined,
                'last_updated_at' => now(),
                'warned_at'       => null,
            ]);
            return $claim->refresh();
        });
    }

    /**
     * Bulk lookup for the prospecting-tab row enricher.
     * Returns [prospecting_listing_id => ['lock_id','user_id','user_name','locked_at','expires_at','minutes_left']]
     * for every listing in $listingIds that has an active (unreleased, unexpired) lock.
     */
    public function loadTempLocksForListings(array $listingIds, int $agencyId): array
    {
        if (empty($listingIds)) return [];

        $rows = DB::table('prospecting_pitch_locks as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->whereIn('l.prospecting_listing_id', $listingIds)
            ->where('l.agency_id', $agencyId)
            ->whereNull('l.released_at')
            ->where('l.expires_at', '>', now())
            ->select('l.id as lock_id', 'l.prospecting_listing_id', 'l.user_id', 'l.locked_at', 'l.expires_at', 'u.name as user_name')
            ->get();

        $now = now();
        $result = [];
        foreach ($rows as $r) {
            $expires = $r->expires_at ? Carbon::parse($r->expires_at) : null;
            $minutesLeft = $expires ? max(0, $now->diffInMinutes($expires, false)) : null;
            $result[(int) $r->prospecting_listing_id] = [
                'lock_id'      => (int) $r->lock_id,
                'user_id'      => (int) $r->user_id,
                'user_name'    => $r->user_name,
                'locked_at'    => $r->locked_at,
                'expires_at'   => $r->expires_at,
                'minutes_left' => $minutesLeft,
            ];
        }
        return $result;
    }

    private function computeExpiry(int $agencyId): Carbon
    {
        $minutes = (int) (DB::table('agencies')
            ->where('id', $agencyId)
            ->value('prospecting_pitch_temp_lock_minutes') ?: 30);

        // Clamp to safe bounds even if a value lands outside the validated range.
        if ($minutes < 5)   $minutes = 5;
        if ($minutes > 240) $minutes = 240;

        return now()->addMinutes($minutes);
    }

    private function formatPitchNote(array $context): string
    {
        $when      = $context['sent_at']        ?? now();
        $channel   = $context['channel']        ?? 'pitch';
        $recipient = $context['recipient_name'] ?? 'seller';
        $template  = $context['template_name']  ?? null;

        $whenFormatted = $when instanceof \DateTimeInterface
            ? Carbon::instance($when)->format('j M Y \a\t H:i')
            : Carbon::parse((string) $when)->format('j M Y \a\t H:i');

        $note = "Sent {$channel} pitch on {$whenFormatted} to {$recipient}";
        if ($template) {
            $note .= " (template: {$template})";
        }
        return $note;
    }
}

/**
 * Thrown when an agent tries to create a temp lock but another agent already holds one.
 */
class PitchLockConflictException extends \DomainException
{
    public function __construct(
        public readonly int $lockedByUserId,
        public readonly Carbon|\DateTimeInterface $expiresAt,
    ) {
        parent::__construct('Another agent is already pitching this listing.');
    }
}

/**
 * Thrown when a pitch-submit tries to upgrade a temp lock to a permanent claim
 * but another agent already holds an active permanent claim on the listing.
 */
class ClaimOwnershipConflictException extends \DomainException
{
    public function __construct(public readonly int $currentOwnerUserId)
    {
        parent::__construct('This listing is already claimed by another agent.');
    }
}
