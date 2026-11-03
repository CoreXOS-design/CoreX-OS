<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold belt TARGETS (.ai/specs/road-to-sold.md 23.29, Johan 3 Nov 2026 09:29): the agency's settings for the derived "should be here" figures.
 *
 *   target_listings_per_month   Listings (Active) per agent per month, used when neither the agent's own monthly target (Budgets & Targets) nor the agency
 *                               default monthly target is set (default 2)
 *   target_window_days          the rolling window the step-to-step conversion rates are read over (default 90)
 *   target_min_sample           records that must have gone through a step before its real rate is trusted; below it the default rate applies and the figure is
 *                               labelled "estimate" (default 30)
 *   target_rates                step => default conversion % to the next station (NULL = the code defaults)
 *   target_overrides            station => monthly target per agent that replaces the derived figure (NULL / a missing station = derived)
 *
 * Additive only. Rolls back by dropping the five columns.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_settings', 'target_listings_per_month')) {
                $table->unsignedSmallInteger('target_listings_per_month')->default(2);
            }
            if (! Schema::hasColumn('rts_settings', 'target_window_days')) {
                $table->unsignedSmallInteger('target_window_days')->default(90);
            }
            if (! Schema::hasColumn('rts_settings', 'target_min_sample')) {
                $table->unsignedSmallInteger('target_min_sample')->default(30);
            }
            if (! Schema::hasColumn('rts_settings', 'target_rates')) {
                $table->json('target_rates')->nullable();
            }
            if (! Schema::hasColumn('rts_settings', 'target_overrides')) {
                $table->json('target_overrides')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            foreach (['target_listings_per_month', 'target_window_days', 'target_min_sample', 'target_rates', 'target_overrides'] as $c) {
                if (Schema::hasColumn('rts_settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
