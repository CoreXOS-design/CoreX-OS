<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 5 of the Inspections-tab rebuild (2026-09-22): "All Good" bulk-fill
 * must use the agency's configured baseline condition, never a hardcoded
 * 'good' string. RentalInspectionSetting::baselineConditionKeyFor() resolves
 * this column, defaulting to 'good' when unset — same read-time-default
 * pattern as every other column on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->string('baseline_condition_key', 60)->nullable()->after('condition_states');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('baseline_condition_key');
        });
    }
};
