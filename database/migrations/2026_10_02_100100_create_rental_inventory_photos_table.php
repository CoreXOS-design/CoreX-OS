<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §0b — a photo uploaded against one room of
 * the property, e.g. the lounge. Soft-deletable (never a hard delete) —
 * removing a photo archives it, matching every other evidence record in
 * this module. Reuses PropertyImageStorer (the same store/downscale/EXIF-
 * normalize pipeline already used for property galleries and inspection
 * photos) — no second image pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inventory_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inventory_id')->constrained('rental_inventories')->cascadeOnDelete();
            $table->foreignId('property_room_id')->nullable()->constrained('property_rooms')->nullOnDelete();

            $table->string('storage_path');
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Same retry-safety pattern as RentalInspectionPhoto — a client
            // that times out mid-upload and retries never creates a duplicate.
            $table->uuid('client_idempotency_key')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'rental_inventory_id', 'property_room_id'], 'rental_inv_photos_agency_inv_room_idx');
            $table->unique('client_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inventory_photos');
    }
};
