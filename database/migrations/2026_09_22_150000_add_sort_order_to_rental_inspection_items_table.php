<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §3.2 — Johan: "add to a room, not a new
 * room." An item added to an existing room needs a position within that
 * room's checklist an agent can move up/down, mirroring
 * property_rooms.sort_order exactly (same default, same unsigned type).
 * Existing rows default to 0 — combined with the existing id tiebreak
 * pattern already used for rooms, this preserves every item's current
 * relative order (creation order) until an agent explicitly reorders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_items', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('space_type');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_items', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
