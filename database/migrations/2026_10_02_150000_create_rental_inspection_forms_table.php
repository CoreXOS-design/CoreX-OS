<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md — the printable tick-box form. One row
 * per GENERATED PDF version for an inspection: the PDF file (private disk,
 * gated download) and its coordinate manifest (the OMR reader lane's whole
 * contract, §"THE CONTRACT" of the build brief) travel together, always
 * regenerable, never guessed at from the layout code after the fact.
 * `content_hash` lets generate() be idempotent — re-downloading with no
 * item-list change returns the existing current version rather than
 * spawning a new one on every click; the item list changing produces a
 * genuinely new hash, and therefore a new `version` row. Old versions are
 * never deleted (non-negotiable #1) and are never mutated once printed —
 * a returned scan must always be matchable to the EXACT version that was
 * physically handed out, so `version`/`content_hash` are set once, at
 * creation, and never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rental_inspection_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('version');
            $table->char('content_hash', 64);
            // sha256 of the ordered (room, item, condition-state) shape this
            // version was generated from — the "has the item list changed"
            // check, never a guess from row counts alone.

            $table->string('pdf_storage_path', 500);
            $table->json('manifest_json');
            // THE CONTRACT — see RentalInspectionFormPdfService::buildManifest()
            // for the full schema. Stored as a column, not a sibling file, so
            // the PDF path and its manifest can never drift apart independently
            // of each other — one row, one atomic write.

            $table->unsignedSmallInteger('page_count');
            $table->unsignedInteger('box_count');
            // Denormalized — cheap to display, and the exact number this
            // build's own verification step checks against manifest_json's
            // real box array length.

            $table->foreignId('generated_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['rental_inspection_id', 'version']);
            $table->index(['agency_id', 'rental_inspection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_forms');
    }
};
