<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-158 DR2 · WS4 (§4.5, §8.1) — the distribution matrix.
 *
 * A rule = STAGE (pipeline_step) × DOCUMENT TYPE × PARTY ROLE → how that document
 * is delivered to that party at that stage ({delivery_mode, auto_on_stage_tick}).
 * pipeline_step_id NULL = "any stage / manual only". Every rule is agency-scoped
 * and configurable on the existing document-types settings surface. Sensible
 * defaults are seeded per agency; nothing hardcoded in the engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_stage_document_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            // The STAGE this rule fires at (NULL = any stage / manual only).
            $table->unsignedBigInteger('pipeline_step_id')->nullable();
            $table->unsignedBigInteger('document_type_id');
            $table->string('party_role', 40); // reuses the deal_v2_contacts.role vocabulary
            $table->enum('delivery_mode', ['secure_link', 'direct_attachment'])->default('secure_link');
            $table->boolean('auto_on_stage_tick')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('pipeline_step_id')->references('id')->on('deal_pipeline_steps')->nullOnDelete();
            $table->foreign('document_type_id')->references('id')->on('document_types')->cascadeOnDelete();
            // One rule per (agency, stage, doc-type, party-role). NULL stage rows
            // are distinct per MySQL NULL semantics — the "any stage" catch-all.
            $table->unique(['agency_id', 'pipeline_step_id', 'document_type_id', 'party_role'], 'dsdr_unique');
            $table->index(['agency_id', 'pipeline_step_id', 'is_active'], 'dsdr_stage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stage_document_rules');
    }
};
