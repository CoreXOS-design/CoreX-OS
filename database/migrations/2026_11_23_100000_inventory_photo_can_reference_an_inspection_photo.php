<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-10-19 B32 - one photo set per room, shared by the inventory and the inspection.
 *
 * An inventory photo row may now be a REFERENCE to an inspection photo: it carries no file of its own (`storage_path` is empty) and
 * `rental_inspection_photo_id` says which inspection photo it shows. Everything that already works on an inventory photo - the room strip,
 * tagging to a line, the move-in and move-out comparison, the public page - keeps working on a reference with no change, because a reference
 * is still a row in this table. Archiving a reference (soft delete) never touches the inspection photo.
 *
 * A reference is unique per inventory + inspection photo (a restored one reuses its row), so an agent cannot add the same picture twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inventory_photos', function (Blueprint $table) {
            $table->string('storage_path')->nullable()->change();
            $table->foreignId('rental_inspection_photo_id')->nullable()->after('property_room_id')
                ->constrained('rental_inspection_photos')->nullOnDelete();
            $table->unique(['rental_inventory_id', 'rental_inspection_photo_id'], 'rental_inv_photos_inspection_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inventory_photos', function (Blueprint $table) {
            $table->dropUnique('rental_inv_photos_inspection_ref_unique');
            $table->dropConstrainedForeignId('rental_inspection_photo_id');
        });
    }
};
