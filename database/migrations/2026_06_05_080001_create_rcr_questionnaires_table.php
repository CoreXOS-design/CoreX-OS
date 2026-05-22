<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9d B1 — RCR questionnaire template (versioned).
 *
 * One row per FIC questionnaire issue (e.g. "FIC 2026 RCR Composite",
 * "FIC 2026 RCR Estate Agents Sector-Specific"). Future FIC revisions
 * land as new rows; existing submissions remain bound to their original
 * questionnaire row for audit fidelity.
 *
 * Questionnaire content is admin-imported via CSV (see CSV import in the
 * controller), so this table holds the SHELL only — questions live in
 * rcr_questions, sections in rcr_questionnaire_sections.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('rcr_questionnaires')) return;

        Schema::create('rcr_questionnaires', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('issued_by', 100)->default('FIC');
            $table->string('directive_reference', 100)->nullable();
            $table->date('reporting_period_from');
            $table->date('reporting_period_to');
            $table->date('submission_deadline');
            $table->string('submission_platform', 100)->default('FIC goAML');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'submission_deadline'], 'rcr_q_active_deadline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rcr_questionnaires');
    }
};
