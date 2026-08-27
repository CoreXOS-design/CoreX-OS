<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\Prospecting\ClaimCreated;
use App\Events\Prospecting\ClaimReleased;
use App\Models\ProspectingClaim;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * SPINE-3 — dispatches ProspectingClaim domain events. Activity-points
 * crediting subscribes to those events from CreditInstantActionListener.
 *
 * Two firing paths:
 *
 *   1) ClaimCreated — on EVERY ProspectingClaim row insert. Covers BOTH
 *      the manual bookmark/claim button (MarketIntelligenceController::
 *      claim) AND the pitch-now upgrade path
 *      (ProspectingClaimService::consumeLockAsPermanentClaim — where the
 *      pitch lock is consumed and a fresh claim is created with
 *      status='contacted'). Both flows score mic.claim_taken cleanly
 *      from a single hook.
 *
 *   2) ClaimReleased — on the FIRST update where released_at transitions
 *      from NULL to a value. This is the DELIBERATE manual release path
 *      (ProspectingClaimService::releaseClaim). It is NOT triggered by
 *      the pitch-now flow because consumeLockAsPermanentClaim() releases
 *      the pitch LOCK (a different row in prospecting_pitch_locks), NOT
 *      the claim row. Therefore the prompt's hard rule — "pitch-now
 *      claim must NEVER auto-release and NEVER be revoked" — is
 *      structurally enforced: there is no code path that sets
 *      ProspectingClaim.released_at without an explicit human action.
 *
 * Failure-isolated: event dispatch wrapped in try/catch so an event-bus
 * blip never breaks the underlying claim save.
 *
 * MIC counts cache invalidation (2026-08-27) — piggybacks on this SAME
 * observer rather than a new mechanism. Every single-model claim write
 * (create, or update to is_active/released_at/last_updated_at/feedback_at
 * — the fields computeActionPresetCounts() reads) bumps
 * ProspectingClaim::bumpCountsCacheVersion() so the MIC tile counts can
 * never serve a value from before this write. Bulk `->update()` query
 * builder calls (PropertyController::markNotSelling(),
 * ProspectingClaimMaintenance's auto-release sweep) do NOT hydrate
 * individual models and so never reach this observer — those two sites
 * bump the version explicitly at their own call site.
 */
final class ProspectingClaimObserver
{
    public function created(ProspectingClaim $claim): void
    {
        $this->bumpCountsCacheVersion($claim);

        try {
            event(new ClaimCreated($claim));
        } catch (\Throwable $e) {
            Log::warning('SPINE-3 ClaimCreated dispatch failed (swallowed)', [
                'claim_id' => $claim->id ?? null,
                'message'  => $e->getMessage(),
            ]);
        }
    }

    public function updated(ProspectingClaim $claim): void
    {
        if ($claim->wasChanged(['is_active', 'released_at', 'last_updated_at', 'feedback_at'])) {
            $this->bumpCountsCacheVersion($claim);
        }

        try {
            // Fire ClaimReleased only on the FIRST transition NULL → set on
            // released_at — releasing an already-released claim, or any
            // unrelated edit, is a no-op.
            if (! $claim->wasChanged('released_at')) {
                return;
            }
            if ($claim->getOriginal('released_at') !== null || $claim->released_at === null) {
                return;
            }
            event(new ClaimReleased(
                claim: $claim,
                releasedByUserId: (int) (Auth::id() ?? $claim->user_id),
                reason: null,
            ));
        } catch (\Throwable $e) {
            Log::warning('SPINE-3 ClaimReleased dispatch failed (swallowed)', [
                'claim_id' => $claim->id ?? null,
                'message'  => $e->getMessage(),
            ]);
        }
    }

    private function bumpCountsCacheVersion(ProspectingClaim $claim): void
    {
        try {
            ProspectingClaim::bumpCountsCacheVersion((int) $claim->agency_id);
        } catch (\Throwable $e) {
            Log::warning('MIC counts cache version bump failed (swallowed)', [
                'claim_id' => $claim->id ?? null,
                'message'  => $e->getMessage(),
            ]);
        }
    }
}
