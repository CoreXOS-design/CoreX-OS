<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §0b — Johan's own example: "the serial
 * number of the TV in the lounge photo." A many-to-many tag between a line
 * and a photo in the SAME room — optional, never required, a photo may
 * carry several line tags and a line may point at several photos. A pure
 * reference row, not itself an evidence record (the line and the photo it
 * points at are each already preserved independently) — removing a tag
 * genuinely deletes the pivot row rather than soft-deleting it, the same
 * way un-checking a feature tag elsewhere in CoreX doesn't leave a
 * soft-deleted tag row behind. The photo and the line themselves are never
 * touched by this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inventory_line_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inventory_line_id')->constrained('rental_inventory_lines')->cascadeOnDelete();
            $table->foreignId('rental_inventory_photo_id')->constrained('rental_inventory_photos')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['rental_inventory_line_id', 'rental_inventory_photo_id'], 'rental_inv_line_photos_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inventory_line_photos');
    }
};
