<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §14.27.5 / §14.28 — the wet-ink route: the
 * crew brings the signed job card back to the office and the signed copy is
 * uploaded against the card. Stored on the PRIVATE disk (a signed document).
 * An earlier upload is never removed — a newer one stamps it Superseded
 * (`superseded_at`, `superseded_by_id`) and it stays as history; no hard
 * delete (non-negotiable #1, hence `deleted_at`, though nothing archives these
 * today).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_job_card_signed_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_job_card_id')->constrained('rental_job_cards')->cascadeOnDelete();
            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_kb');
            $table->string('signed_by_name', 191)->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['rental_job_card_id', 'superseded_at'], 'rjcsc_card_superseded_idx');
            $table->index('agency_id', 'rjcsc_agency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_job_card_signed_copies');
    }
};
