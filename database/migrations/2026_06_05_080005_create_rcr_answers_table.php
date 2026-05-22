<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9d B5 — one answer per question per submission.
 *
 * answer_value carries scalar answers (yes/no, free text, number). Multi-
 * select + composite answers live in answer_data_json.
 *
 * is_auto_populated + manually_edited together gate re-population:
 *   auto-populate skips any row where manually_edited=true.
 *
 * auto_population_source_data captures WHAT the evidence service pulled at
 * the moment of population — invaluable when the auditor asks "where did
 * this number come from on 12 May".
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('rcr_answers')) return;

        Schema::create('rcr_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')
                ->constrained('rcr_submissions', 'id', 'rca_sub_fk')
                ->cascadeOnDelete();
            $table->foreignId('question_id')
                ->constrained('rcr_questions', 'id', 'rca_quest_fk');

            $table->text('answer_value')->nullable();
            $table->json('answer_data_json')->nullable();

            $table->boolean('is_auto_populated')->default(false);
            $table->json('auto_population_source_data')->nullable();
            $table->boolean('manually_edited')->default(false);

            $table->timestamp('last_edited_at')->nullable();
            $table->foreignId('last_edited_by_user_id')->nullable()
                ->constrained('users', 'id', 'rca_editor_fk')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->enum('status', [
                'unanswered', 'auto_filled', 'in_progress', 'answered', 'reviewed', 'approved',
            ])->default('unanswered');

            $table->foreignId('reviewer_user_id')->nullable()
                ->constrained('users', 'id', 'rca_reviewer_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->unique(['submission_id', 'question_id'], 'rca_sub_quest_uq');
            $table->index(['submission_id', 'status'], 'rca_sub_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rcr_answers');
    }
};
