<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-433 Part C — the photo note's classification vocabulary (Defect /
 * Wear and tear / Reference by default), agency-configurable per the
 * standing "every list is a setting" rule. Same read-time-default JSON
 * column pattern as `condition_states` (2026_09_21_160000) — null resolves
 * to RentalInspectionSetting::DEFAULT_PHOTO_NOTE_CLASSIFICATIONS, nothing
 * is written on read.
 *
 * The classification is what lets the printed inspection report list
 * defects on their own at the end (.ai/specs/rental-inspections.md §23) —
 * this vocabulary is the whole reason that report section can exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->json('photo_note_classifications')->nullable()->after('condition_states');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('photo_note_classifications');
        });
    }
};
