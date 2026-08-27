<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC action-preset tile counts (MarketIntelligenceController::computeActionPresetCounts)
 * cache their [fresh, stale] Cache::flexible() window. Was hardcoded [60, 300] seconds;
 * Johan's standing rule is that a window/threshold left behind must be an
 * agency-configurable setting, never hardcoded — so it moves onto this table
 * alongside the other MIC thresholds. Defaults match the prior hardcoded values.
 *
 * Paired with per-write cache-version invalidation (ProspectingClaim::
 * bumpCountsCacheVersion) so a claim/release/expiry change is reflected
 * immediately regardless of this window — the window only bounds staleness
 * for counts that have NOT had a claim change (e.g. pitch-match recompute lag).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suggested_action_thresholds', function (Blueprint $table) {
            $table->unsignedSmallInteger('mic_counts_cache_fresh_seconds')->default(60)->after('deeds_duplicate_auto_take_days');
            $table->unsignedSmallInteger('mic_counts_cache_stale_seconds')->default(300)->after('mic_counts_cache_fresh_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('suggested_action_thresholds', function (Blueprint $table) {
            $table->dropColumn(['mic_counts_cache_fresh_seconds', 'mic_counts_cache_stale_seconds']);
        });
    }
};
