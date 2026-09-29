<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-09-20: "we should have a setting somewhere on rentals that
 * defines room types and what gets added - ceiling, walls, floors, windows,
 * doors - that should be a std. if its a patio as example there are still
 * things to check." Agreed with cc4 (owns the inspections-rework seeder
 * that consumes this) before building: an agency-editable, ORDERED list of
 * default inspection items per room type, keyed on the SAME space-type
 * strings config('property-spaces.all_space_types') already uses
 * (rental_inspection_items.space_type's own existing convention — a free
 * string for consistency, never a constrained enum), so a property's real
 * room ("Bedroom 2") resolves its defaults via its space TYPE ("Bedroom").
 *
 * JSON so it stores {space_type: [ordered item labels]}; null resolves to
 * a neutral default (ceiling/walls/floors/windows/doors for every type,
 * Johan's own literal baseline, never a per-type guess) covering every
 * known space type plus a generic fallback for any type not explicitly
 * listed — same read-time-default pattern as this table's other columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->json('room_type_item_defaults')->nullable()->after('inspection_feature_labels');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('room_type_item_defaults');
        });
    }
};
