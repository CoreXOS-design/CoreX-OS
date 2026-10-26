<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Re-import from Word" — one row per Word file a CDS template has been built from.
 * Spec: .ai/specs/cds-reimport-from-word.md.
 *
 * status: pending (uploaded, builder draft open, not saved yet) -> current (saved onto the template)
 * -> superseded (a later re-import replaced it). A pending row a newer upload overtakes is 'abandoned'. Superseded rows keep the template's previous
 * cds_json / editor_state / field_mappings so nothing a re-import replaces is ever lost. Soft
 * deletes only; no code path hard-deletes a generation. The .docx itself lives in `documents`
 * (private disk, source_type = template_source) and is served by corex.documents.file.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('docuperfect_template_generations')) {
            return;
        }

        Schema::create('docuperfect_template_generations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->unsignedBigInteger('template_id')->index();
            $table->unsignedInteger('generation')->default(0);            // 0 while pending; numbered when it is applied
            $table->string('status', 12)->default('pending');           // pending | current | superseded | abandoned
            $table->unsignedBigInteger('draft_id')->nullable()->index();  // the builder draft a pending generation belongs to
            $table->unsignedBigInteger('document_id')->nullable();        // the stored .docx (null: no file kept, e.g. pre-feature content)
            $table->string('original_filename', 255)->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->json('report')->nullable();                           // the match report shown after upload
            $table->json('carried_bindings')->nullable();                 // new field index => binding carried over by name
            $table->json('cds_json')->nullable();                         // superseded snapshot
            $table->json('editor_state')->nullable();                     // superseded snapshot (tags, mappings, tagged_html, look)
            $table->json('field_mappings')->nullable();                   // superseded snapshot
            $table->timestamp('applied_at')->nullable();                  // became current
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['template_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docuperfect_template_generations');
    }
};
