<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC funnel phase 2 (Johan 2026-08-13) — stale-claim tracking on prospecting_claims.
 *
 * `warned_at`      — set when the agent-on-it is warned the claim is going stale (dedup); CLEARED
 *                    the moment the claim is worked (recordActionOnClaim) so it re-arms next cycle.
 * `release_reason` — structured reason a claim was released back to MIC (e.g. 'no_address'), so other
 *                    agents / the BM see WHY on the pool ("no address could be established, released").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_claims', function (Blueprint $table) {
            $table->timestamp('warned_at')->nullable()->after('flagged_at');
            $table->string('release_reason', 40)->nullable()->after('released_at');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_claims', function (Blueprint $table) {
            $table->dropColumn(['warned_at', 'release_reason']);
        });
    }
};
