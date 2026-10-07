<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §45.3 (Build I-1) — whether an in/out inspection can be completed
 * while a checklist item is still ungraded (N/A counts as graded). Nullable, read-time-default
 * pattern like every other column on this table: null means
 * RentalInspectionSetting::DEFAULT_ALL_ITEMS_REQUIRED_TO_COMPLETE (true).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->boolean('all_items_required_to_complete')->nullable()->after('require_notes_blocks_progression');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('all_items_required_to_complete');
        });
    }
};
