<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-201 — Private Property nightly stats snapshot toggle.
 *
 * PP's Agency Feed Service DOES expose per-listing engagement (ListingPerformanceStats:
 * Views, Messages, TelLeads, Alerts) — the UI's old "PP does not expose stats" claim
 * was wrong. This gates the nightly PP snapshot that accumulates that series into
 * property_portal_metrics (portal='pp'). PP gives NO historical backfill, so the curve
 * starts the day it's switched on.
 *
 * Default OFF (doctrine, kill-switch), but SEEDED ON for HFC (agency 1) — Johan asked
 * for it working. Additive; portal_portal_metrics.portal already carries 'pp'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agencies', 'pp_stats_pull_enabled')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->boolean('pp_stats_pull_enabled')->default(false)->after('pp_lead_pull_enabled');
            });
        }

        // Seed ON for HFC (agency 1) — the intent is for it to start collecting now.
        DB::table('agencies')->where('id', 1)->update(['pp_stats_pull_enabled' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('agencies', 'pp_stats_pull_enabled')) {
            return;
        }
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('pp_stats_pull_enabled');
        });
    }
};
