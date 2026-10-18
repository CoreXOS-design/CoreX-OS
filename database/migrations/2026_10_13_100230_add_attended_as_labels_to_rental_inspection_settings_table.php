<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §45.5 (Build I-3) — the agency's own words for HOW someone attended
 * (key → label; the four keys are fixed, the labels are the agency's). Nullable JSON, read-time default
 * (RentalInspectionSetting::attendedAsLabelsFor()) like every other list on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->json('attended_as_labels')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('attended_as_labels');
        });
    }
};
