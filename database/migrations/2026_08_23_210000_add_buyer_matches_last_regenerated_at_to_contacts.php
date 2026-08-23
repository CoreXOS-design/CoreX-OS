<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RegenerateBuyerMatchesJob chunking (2026-08-23) — the per-contact "last
 * ATTEMPTED this rotation" cursor for the agency-wide (contactId=null)
 * regenerate path. Same idiom as Property24SyndicationService's
 * p24_activation_last_checked_at, itself modelled on P24StatsService's
 * p24_stats_synced_at (AT-200): stamped for every contact processed in a
 * chunk, success or failure, so a chronically-failing contact still rotates
 * away instead of blocking the rest of the agency from ever completing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('buyer_matches_last_regenerated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('buyer_matches_last_regenerated_at');
        });
    }
};
