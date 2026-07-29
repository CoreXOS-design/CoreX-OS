<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MIC pitch lock — backfill (Johan-approved, 2026-07-29).
 *
 * The initial pitch-lock migration (2026_08_19_000001) only stamped pitched_at
 * on claims whose temp lock was demonstrably consumed_by_send. That left the
 * pre-existing "worked" claims — active claims that already carry agent
 * feedback (feedback_at set) but have no surviving pitch-lock record — still
 * visible in the default canvassing pool, even though the agent has clearly
 * worked the owner.
 *
 * Johan's ruling: treat those worked claims exactly like a pitch — permanent
 * and hidden from the default pool (revealed by ?show_pitched=1). Marking
 * pitched_at is the mechanism (MarketIntelligenceController::work hides active
 * pitched claims; ProspectingClaim::isExpired / ProspectingClaimMaintenance
 * treat them as permanent).
 *
 * Predicate = the exact "worked" set: active, not already pitch-locked, and
 * carrying feedback. Closing outcomes (not_interested / lost) already set
 * is_active=false, so they are excluded automatically. Idempotent: the
 * pitched_at IS NULL guard makes a re-run a no-op, and it never touches a
 * genuine pitch (already stamped). pitched_at is set to feedback_at — the
 * moment the listing was actually worked.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('prospecting_claims')
            ->where('is_active', 1)
            ->whereNull('pitched_at')
            ->whereNotNull('feedback_at')
            ->update(['pitched_at' => DB::raw('feedback_at')]);
    }

    public function down(): void
    {
        // Data backfill — not structurally reversible. A backfilled claim is
        // indistinguishable from a genuine pitch once stamped, so we do not
        // blindly clear pitched_at on rollback (that would un-lock real pitches).
    }
};
