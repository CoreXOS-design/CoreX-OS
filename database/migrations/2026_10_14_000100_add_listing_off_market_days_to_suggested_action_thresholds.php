<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-10-07 — "a mandate normally runs 90 days": the nightly
 * `prospecting:flag-stale-listings` job used a hard-coded 30-day window to presume a
 * portal listing off-market, which silently emptied the presentation's Active
 * Competition section (Uvongo: 1,321 listings switched off on 26 Sep). The window is
 * now an agency setting, default 90. Existing agency rows pick up 90 from the column
 * default. Spec: .ai/specs/market-intelligence-discovery.md "Stale listing window".
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('suggested_action_thresholds', function (Blueprint $table) {
            $table->unsignedSmallInteger('listing_off_market_days')->default(90)->after('mic_counts_cache_stale_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('suggested_action_thresholds', function (Blueprint $table) {
            $table->dropColumn('listing_off_market_days');
        });
    }
};
