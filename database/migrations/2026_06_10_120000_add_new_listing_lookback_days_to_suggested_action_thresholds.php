<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agency configurable lookback window for the MIC Work-tab
 * action_preset=new_today filter. Without this column the
 * MarketIntelligenceController.applyActionPreset() switch had no
 * `new_today` case → the filter was silently ignored and the page
 * rendered the entire canvass pool (~7,000+ rows) instead of new
 * listings. Stored alongside the existing suggested-action
 * thresholds because it's the same shape (per-agency integer day
 * threshold) and the same admin settings surface will edit it.
 *
 * Default 1 day (≈ "today"): listings first seen in the last 24
 * hours. Agencies can widen the window via the admin settings
 * screen at their discretion.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('suggested_action_thresholds', function (Blueprint $table) {
            $table->unsignedSmallInteger('new_listing_lookback_days')
                  ->default(1)
                  ->after('investigate_mid_min');
        });
    }

    public function down(): void
    {
        Schema::table('suggested_action_thresholds', function (Blueprint $table) {
            $table->dropColumn('new_listing_lookback_days');
        });
    }
};
