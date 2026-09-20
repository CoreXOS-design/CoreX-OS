<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §3.1/§3.4 — evidence photos, reported-state
 * AND completed-state, distinguished by photo_type (unlike
 * rental_fault_report_photos, which is deliberately always "as reported").
 * A completed-state photo with photo_type='completed' is what
 * RentalWorkOrder::complete() requires when completion_requires_photo is on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_work_order_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_work_order_id')
                ->constrained(indexName: 'rwo_photos_work_order_fk')->cascadeOnDelete();

            $table->string('photo_type', 20);
            // reported | in_progress | completed
            $table->string('storage_path', 500);
            $table->foreignId('uploaded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->uuid('client_idempotency_key')->unique();
            $table->unsignedBigInteger('file_size_bytes')->nullable();

            $table->timestamp('created_at');

            $table->index(['agency_id', 'rental_work_order_id'], 'rwo_photos_agency_wo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_work_order_photos');
    }
};
