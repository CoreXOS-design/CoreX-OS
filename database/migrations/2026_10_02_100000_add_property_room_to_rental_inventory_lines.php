<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §0b — Johan, 2026-09-22: "agent needs to see
 * the spaces again, like with inspections... why ask the agent to retype
 * something we know." Lines now attach to the property's OWN PropertyRoom
 * record — the SAME rooms/order the inspection recording surface already
 * uses (PropertyRoom.sort_order) — instead of a free-typed room_label.
 * `room_label` is NOT dropped (no renames/removals per instruction); it
 * stays as the historical free-text value for any line captured before this
 * change, and as a fallback for the rare line with no resolvable room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inventory_lines', function (Blueprint $table) {
            $table->foreignId('property_room_id')->nullable()->after('rental_inventory_id')
                ->constrained('property_rooms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inventory_lines', function (Blueprint $table) {
            $table->dropForeign(['property_room_id']);
            $table->dropColumn('property_room_id');
        });
    }
};
