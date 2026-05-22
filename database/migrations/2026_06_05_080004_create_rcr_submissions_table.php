<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9d B4 — one RCR cycle per agency per questionnaire-period.
 *
 * Workflow states:
 *   draft                  — CO drafting; auto-populate runs; edits free
 *   in_review              — CO sent for principal/MLRO review
 *   approved_for_submission — reviewer signed off; ready for goAML transposition
 *   submitted              — CO confirmed they've entered goAML; snapshot taken
 *   locked                 — 30 days post-submission, auto-locked; no edits
 *
 * UNIQUE(agency, questionnaire, reporting_period_from) ensures one row per
 * cycle. If the FIC issues a 2026 revision mid-year, the new questionnaire_id
 * gets a fresh row — no conflict.
 *
 * submitted_to_platform_reference holds the FIC's goAML confirmation number
 * once Elize transposes the answers into goAML and gets a receipt.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('rcr_submissions')) return;

        Schema::create('rcr_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')
                ->constrained('agencies', 'id', 'rcs_agency_fk')
                ->cascadeOnDelete();
            $table->foreignId('questionnaire_id')
                ->constrained('rcr_questionnaires', 'id', 'rcs_quest_fk');
            $table->enum('status', [
                'draft', 'in_review', 'approved_for_submission', 'submitted', 'locked',
            ])->default('draft');

            // Denorm from questionnaire for convenient querying.
            $table->date('reporting_period_from');
            $table->date('reporting_period_to');
            $table->date('submission_deadline');

            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()
                ->constrained('users', 'id', 'rcs_submitter_fk')->nullOnDelete();
            $table->string('submitted_to_platform_reference', 200)->nullable();

            $table->timestamp('locked_at')->nullable();

            $table->string('export_document_path', 500)->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('assigned_co_user_id')->nullable()
                ->constrained('users', 'id', 'rcs_assigned_fk')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['agency_id', 'questionnaire_id', 'reporting_period_from'],
                'rcs_agency_quest_period_uq',
            );
            $table->index(['agency_id', 'status'], 'rcs_agency_status_idx');
            $table->index(['submission_deadline', 'status'], 'rcs_deadline_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rcr_submissions');
    }
};
