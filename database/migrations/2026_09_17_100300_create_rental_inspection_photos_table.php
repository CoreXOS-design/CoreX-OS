<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §3.2/§3.3 — photos hang off the
 * OBSERVATION, never the item directly. Immutable, no deleted_at, same
 * evidence-integrity reasoning as the observation itself: a photo uploaded
 * to the wrong item is corrected by a NEW observation with a note, never
 * an edit or delete of the wrong one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inspection_observation_id')
                ->constrained(indexName: 'ri_photos_observation_fk')->cascadeOnDelete();
            // explicit short constraint name — auto-generated one exceeds MySQL's 64-char limit.

            $table->string('storage_path', 500);
            $table->foreignId('uploaded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->uuid('client_idempotency_key')->unique();
            $table->unsignedBigInteger('file_size_bytes')->nullable();

            $table->timestamp('created_at');
            // immutable, no deleted_at — same reasoning as observations.

            $table->index(['agency_id', 'rental_inspection_observation_id'], 'ri_photos_agency_observation_idx');
            // explicit short name — auto-generated one exceeds MySQL's 64-char limit.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_photos');
    }
};
