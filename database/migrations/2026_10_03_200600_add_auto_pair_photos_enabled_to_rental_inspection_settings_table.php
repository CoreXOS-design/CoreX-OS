<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §24.5/§24.7 — AT-433 Part B. Johan's
 * ruling, 2026-09-26: auto-pair defaults ON for a new agency — "pairing
 * forty photos by hand is exactly the work we are supposed to be doing for
 * them." Nullable, same read-time-default pattern as every other resolver
 * on this table (null = the built-in default,
 * RentalInspectionSetting::DEFAULT_AUTO_PAIR_PHOTOS_ENABLED) — an agency
 * that has never touched this setting gets the default with no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->boolean('auto_pair_photos_enabled')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('auto_pair_photos_enabled');
        });
    }
};
