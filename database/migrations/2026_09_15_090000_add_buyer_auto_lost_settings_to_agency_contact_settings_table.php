<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-Core-Matches — Johan: "the buyer going lost after nothing for 60 days
 * should be something an agency turns on and sets the days stale before
 * moving. I dont like it happening silently." Two new knobs:
 *
 * - buyer_auto_lost_enabled: whether the nightly recompute may EVER move a
 *   stale buyer to Lost at all. OFF by default — today it happens
 *   unconditionally, which is exactly the silent behaviour being fixed.
 *   A manual Lost via the pipeline button is unaffected either way.
 * - buyer_lost_warning_days: the at-risk badge's warning window, separate
 *   from buyer_lost_days itself (an agency running a 90-day stale rule may
 *   want 14 days of warning, not 7).
 *
 * buyer_lost_days ITSELF already exists (2026-era column, default 60) —
 * this migration does not touch it. What changes elsewhere in this build is
 * that BuyerStateService::resolveState() finally reads it; before, anything
 * past buyer_cold_days became Lost immediately regardless of this column's
 * value, a pre-existing bug this build fixes in the same line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->boolean('buyer_auto_lost_enabled')->default(false)->after('buyer_lost_days');
            $table->unsignedSmallInteger('buyer_lost_warning_days')->default(7)->after('buyer_auto_lost_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn(['buyer_auto_lost_enabled', 'buyer_lost_warning_days']);
        });
    }
};
