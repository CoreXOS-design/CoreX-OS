<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SyncProperty24Activations chunking (2026-08-23) — the per-listing "last
 * ATTEMPTED" cursor for the activation-status check, same idiom as
 * P24StatsService's p24_stats_synced_at (AT-200, stale-first rotation).
 * Stamped for every property checked in syncAllActivations(), success or
 * failure — an ATTEMPT cursor, not a success cursor, so a chronically-failing
 * listing still rotates away instead of being retried every single run while
 * the rest of the set starves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->timestamp('p24_activation_last_checked_at')->nullable()->after('p24_listing_last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('p24_activation_last_checked_at');
        });
    }
};
