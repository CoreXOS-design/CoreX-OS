<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §3.1/§4 — one line per real item, e.g.
 * "4x Remotes - 2x Fans - 2x Aircon" or "2x Big pots under dustbin room
 * window missing". Deliberately quantity + free text, nothing more
 * structured: location ("on top of cupboard"), brand/colour ("LG
 * Fridge/freezer silver"), and state annotations ("missing") all live in
 * `description` — a rigid schema would not survive a real agent in a real
 * flat (Johan, verbatim). `room_label` is free text, NOT a foreign key to
 * PropertyRoom or RentalInspectionItem — the inventory's own room list does
 * not match the inspection's (Johan's own finding, §5 of the conductor's
 * brief), and forcing a shared taxonomy neither document actually uses
 * would be inventing structure nobody asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inventory_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inventory_id')->constrained('rental_inventories')->cascadeOnDelete();

            $table->string('room_label', 100);
            $table->unsignedInteger('quantity')->default(1);
            $table->text('description');
            $table->unsignedInteger('sort_order')->default(0);

            // §3.3-style retirement, not a hard delete — a line added in
            // error stays in the record, never disappears from history.
            $table->boolean('is_retired')->default(false);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['agency_id', 'rental_inventory_id', 'is_retired']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inventory_lines');
    }
};
