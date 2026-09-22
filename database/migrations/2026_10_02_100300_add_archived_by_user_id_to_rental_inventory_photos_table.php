<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §4a — adopting the SAME photo machinery
 * rental-inspections already built (rental-inspections.md §20.13.1): who
 * archived a photo, alongside the deleted_at the table already carries.
 * Mirrors `rental_inspection_photos.archived_by_user_id` exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inventory_photos', function (Blueprint $table) {
            $table->foreignId('archived_by_user_id')->nullable()->after('uploaded_by_user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inventory_photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archived_by_user_id');
        });
    }
};
