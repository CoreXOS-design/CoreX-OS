<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §14 — "unchanged lines collapse to one grey
 * line" needs the comparison view to know which of an agency's OWN
 * disposition_presets means "nothing wrong," the same way
 * RentalInspectionSetting::baseline_condition_key already tells the
 * Inspections tab's "All Good" bulk-fill which condition state is its
 * baseline — never hardcoded to the literal string 'present', since an
 * agency can rename or reorder its own preset list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inventory_settings', function (Blueprint $table) {
            $table->string('baseline_disposition_key', 60)->nullable()->after('disposition_presets');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inventory_settings', function (Blueprint $table) {
            $table->dropColumn('baseline_disposition_key');
        });
    }
};
