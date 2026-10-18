<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §45.14 — the agency's own words for the three move-out classifications
 * (pre-existing / landlord's responsibility / charge to tenant). key → label; the keys are fixed
 * (RentalInspectionItemFinding), only the labels are the agency's. Nullable JSON with a read-time default
 * (RentalInspectionSetting::moveOutClassificationLabelsFor()) like every other list on this table — an agency
 * that never touches it keeps today's wording, and no existing row needs a backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->json('move_out_classification_labels')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('move_out_classification_labels');
        });
    }
};
