<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ES-6.7 — AI extraction-fidelity verification + human review gate (PDF imports).
 *
 * After a PDF is extracted to the CDS shape, an AI vision pass compares the
 * ORIGINAL PDF against the EXTRACTED structure and records each divergence as a
 * flag a human must ratify before the resulting template can be used in the
 * e-sign wizard. AI gives confidence, NOT a guarantee — a human always clears
 * high-severity flags.
 *
 *   cds_extraction_flags         — one row per detected divergence (soft-delete,
 *                                  resolution-tracked, audit-trailed).
 *   cds_drafts.extraction_verification        — run-level state for the builder.
 *   docuperfect_templates.extraction_verification — run-level state the wizard
 *                                  gate reads (a 'blocked' template is excluded).
 *
 * Values: null (not applicable — Word import / pre-feature) | 'passed' |
 *         'warnings' | 'blocked' | 'cleared' | 'could_not_run'.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cds_extraction_flags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cds_draft_id')->nullable()->index();
            $table->unsignedBigInteger('template_id')->nullable()->index();
            $table->string('severity', 10)->default('high');          // high | low
            $table->string('divergence_type', 60)->default('unknown');
            $table->string('location', 255)->nullable();
            $table->text('description');
            $table->text('source_snippet')->nullable();
            $table->text('extracted_snippet')->nullable();
            $table->string('status', 20)->default('pending');         // pending | accepted | fixed | acknowledged
            $table->text('resolution_note')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['template_id', 'severity', 'status'], 'cds_flags_gate_idx');

            $table->foreign('cds_draft_id')->references('id')->on('cds_drafts')->nullOnDelete();
            $table->foreign('template_id')->references('id')->on('docuperfect_templates')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('cds_drafts', function (Blueprint $table) {
            $table->string('extraction_verification', 20)->nullable()->after('status');
        });

        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->string('extraction_verification', 20)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->dropColumn('extraction_verification');
        });
        Schema::table('cds_drafts', function (Blueprint $table) {
            $table->dropColumn('extraction_verification');
        });
        Schema::dropIfExists('cds_extraction_flags');
    }
};
