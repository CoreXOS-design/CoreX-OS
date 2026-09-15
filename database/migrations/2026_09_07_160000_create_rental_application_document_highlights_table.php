<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 Phase 2 — persistent highlight marks for a rental-application
 * document. Mirrors viewing_pack_documents' proven shape (`redacted_file_path`
 * — a stable, re-generated-on-reapply artifact keyed to the document, plus a
 * DB pointer checked at read-time to serve the marked copy to the next
 * viewer) rather than inventing a new storage shape. `marks_json` is the one
 * addition beyond that pattern: redaction never needs to show existing boxes
 * back to the agent (nothing to un-redact), but a highlighter tool must let
 * the agent see and edit their own existing marks on reopen, so the
 * structured mark list is kept alongside the flattened playback artifact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_document_highlights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->json('marks_json')->nullable();
            $table->string('highlighted_file_path', 500)->nullable();
            // Explicit short constraint name — the auto-generated name
            // (rental_application_document_highlights_updated_by_user_id_foreign,
            // 67 chars) exceeds MySQL's 64-char identifier limit.
            $table->foreignId('updated_by_user_id')->nullable();
            $table->foreign('updated_by_user_id', 'ra_doc_highlights_updated_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_document_highlights');
    }
};
