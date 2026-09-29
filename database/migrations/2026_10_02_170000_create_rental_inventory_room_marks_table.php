<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §12 — the completion gate needs a way to
 * distinguish "nobody opened this room" from "this room genuinely has
 * nothing in it." One row per (inventory, room) an agent has explicitly
 * confirmed empty — the same shape as rental-inspections' "Mark room N/A",
 * but recorded as its own row rather than a per-item observation, because
 * an inventory room has no checklist items to write an observation against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inventory_room_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inventory_id')->constrained('rental_inventories')->cascadeOnDelete();
            $table->foreignId('property_room_id')->constrained('property_rooms')->cascadeOnDelete();
            $table->foreignId('marked_empty_by_user_id')->constrained('users');
            $table->timestamp('marked_empty_at');
            $table->timestamps();

            // Explicit short name — Laravel's auto-generated name for this
            // column pair (rental_inventory_room_marks_rental_inventory_id_
            // property_room_id_unique) is 74 characters, over MySQL's 64-char
            // identifier limit (error 1059), caught running this migration
            // against the test schema.
            $table->unique(['rental_inventory_id', 'property_room_id'], 'rim_room_marks_inventory_room_unique');
            $table->index(['agency_id', 'rental_inventory_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inventory_room_marks');
    }
};
