<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-200 — P24 stats sweep rotation cursor.
 *
 * The nightly stats sweep polls the STALEST listings first so the whole active
 * book rotates over successive nights instead of re-doing the same low-id head
 * and starving the rest (the bug that left 3505 and ~98% of listings with no
 * portal view data). Staleness needs a per-listing "last ATTEMPTED" timestamp —
 * NOT the metric row's synced_at, because a listing that returns no view data
 * writes no metric row and would otherwise sort first forever, wedging rotation.
 *
 * Stamped on every poll attempt (success OR empty). Additive, nullable, indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('properties', 'p24_stats_synced_at')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->timestamp('p24_stats_synced_at')->nullable()->after('p24_syndication_status');
            $table->index('p24_stats_synced_at', 'properties_p24_stats_synced_at_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('properties', 'p24_stats_synced_at')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_p24_stats_synced_at_idx');
            $table->dropColumn('p24_stats_synced_at');
        });
    }
};
