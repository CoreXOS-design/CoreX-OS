<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MIC pitch lock — a claim that originates from an actual PITCH (the agent
 * captured + linked a contact via "Pitch now") is permanent: it never falls
 * back into the canvassing pool on the 48h no-feedback timer, and cannot be
 * silently re-claimed. `pitched_at` is that marker.
 *
 * A claim WITHOUT a pitch (the plain "Claim" button) leaves pitched_at NULL and
 * keeps the existing 48h feedback window / auto-release / re-claim behaviour.
 *
 * Backfill: existing active pitch-originated claims are the ones whose temp
 * pitch-lock was consumed by a send (prospecting_pitch_locks.release_reason =
 * 'consumed_by_send') for the same listing+user. Those are the historical
 * pitched claims currently stuck visible in the pool — stamp them so the new
 * default hide catches them too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_claims', function (Blueprint $table) {
            $table->timestamp('pitched_at')->nullable()->after('claimed_at')->index();
        });

        // Backfill historical pitches by the precise "lock consumed by a send"
        // signal. Guarded so it is a no-op if the locks table/column is absent.
        if (Schema::hasTable('prospecting_pitch_locks')
            && Schema::hasColumn('prospecting_pitch_locks', 'release_reason')) {
            DB::statement("
                UPDATE prospecting_claims pc
                JOIN prospecting_pitch_locks pl
                  ON pl.prospecting_listing_id = pc.prospecting_listing_id
                 AND pl.user_id = pc.user_id
                 AND pl.release_reason = 'consumed_by_send'
                SET pc.pitched_at = pc.claimed_at
                WHERE pc.pitched_at IS NULL
            ");
        }
    }

    public function down(): void
    {
        Schema::table('prospecting_claims', function (Blueprint $table) {
            $table->dropColumn('pitched_at');
        });
    }
};
