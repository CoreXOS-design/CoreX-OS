<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §15.6 — the agency-configurable short
 * reason list an agent picks from when recording a tenant/landlord refusal.
 * JSON so agencies can edit wording; a null column resolves to the neutral
 * default in RentalInspectionSetting (never hardcoded, never HFC-shaped),
 * matching this table's existing read-time-default pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->json('refusal_reason_presets')->nullable()->after('out_inspection_signing_window_days');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('refusal_reason_presets');
        });
    }
};
