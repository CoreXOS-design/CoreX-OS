<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspection-form.md §13 (OMR scan reader) — the fraction
 * of a tick-box's interior that must be dark for it to count as marked.
 * Agency-configurable, sensible default, same read-time-default pattern as
 * every other column on this table (RentalInspectionSetting::
 * omrMarkThresholdFor() resolves it, defaulting when unset).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->decimal('omr_mark_threshold', 3, 2)->nullable()->after('baseline_condition_key');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('omr_mark_threshold');
        });
    }
};
