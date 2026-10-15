<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-463 — the paper the printed site form is laid out for. A per-agency setting
 * (rental-inspections.md §60): nullable so "never chosen" reads as the default
 * (A4) through RentalInspectionSetting::formPaperSizeFor(), the same read-time
 * default pattern as every other column on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->string('form_paper_size', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('form_paper_size');
        });
    }
};
