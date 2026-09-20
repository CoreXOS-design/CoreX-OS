<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §3a/§3a.3 — evidence at the time of
 * report, same photo pipeline (PropertyImageStorer) as every other photo
 * table in this feature family. Deliberately NO photo_type column — a fault
 * report's photos are always "as reported"; repair evidence lives on the
 * linked work order's own photos once one exists. Immutable, no
 * deleted_at, same evidence-integrity reasoning as rental_inspection_photos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_fault_report_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_fault_report_id')
                ->constrained(indexName: 'rfr_photos_report_fk')->cascadeOnDelete();

            $table->string('storage_path', 500);
            $table->foreignId('uploaded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->uuid('client_idempotency_key')->unique();
            $table->unsignedBigInteger('file_size_bytes')->nullable();

            $table->timestamp('created_at');

            $table->index(['agency_id', 'rental_fault_report_id'], 'rfr_photos_agency_report_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_fault_report_photos');
    }
};
