<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-463 / rental-inspections.md §63 - how far in from every paper edge the printed site form keeps ALL its content (the corner
 * marks, the QR, headers, rows, tick boxes). A copier or scanner clips the outer few millimetres of a page; the marks must
 * survive that. A per-agency setting: nullable so "never chosen" reads as the default (15 mm) through
 * RentalInspectionSetting::formSafeMarginMmFor(), the same read-time default pattern as every other column on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('form_safe_margin_mm')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('form_safe_margin_mm');
        });
    }
};
