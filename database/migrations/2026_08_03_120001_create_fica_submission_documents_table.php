<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-361 — link (reference) a contact's EXISTING documents into a wet-ink FICA
 * process, so the Reviewing Officer + Compliance Officer can view them when
 * approving FICA — WITHOUT re-uploading or copying the file.
 *
 * This is a pure REFERENCE pivot between fica_submissions and the unified
 * `documents` table (App\Models\Document). No bytes are copied: the FICA process
 * points at the contact's already-stored Document, which continues to be served
 * through the Document's own disk/path. Distinct from `fica_documents` (the
 * uploaded/encrypted FICA-owned copies) — those are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fica_submission_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fica_submission_id')->constrained('fica_submissions')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            // The FICA slot this contact document stands in for: fica_form | id_copy | proof_of_address | supporting.
            $table->string('document_type', 50)->default('supporting');
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A given contact document links to a given submission at most once.
            $table->unique(['fica_submission_id', 'document_id'], 'fica_sub_doc_unique');
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fica_submission_documents');
    }
};
