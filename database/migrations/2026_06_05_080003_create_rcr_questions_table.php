<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9d B3 — individual questions within a questionnaire.
 *
 * auto_population_source is a dotted code (e.g. 'agency.fica_officer.primary')
 * resolved by EvidenceGatheringService. When set, the service tries to
 * pre-fill the answer; CO can always override.
 *
 * answer_type drives the UI widget. 'composite' is the escape hatch for
 * questions that combine a yes/no with a free-text justification — answer
 * lives in answer_data_json on the rcr_answers row.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('rcr_questions')) return;

        Schema::create('rcr_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_id')
                ->constrained('rcr_questionnaires', 'id', 'rcq_quest_fk')
                ->cascadeOnDelete();
            $table->foreignId('section_id')
                ->constrained('rcr_questionnaire_sections', 'id', 'rcq_section_fk')
                ->cascadeOnDelete();
            $table->string('question_code', 30);
            $table->text('question_text');
            $table->enum('answer_type', [
                'yes_no', 'yes_no_na', 'free_text', 'number', 'percentage',
                'multi_select', 'single_select', 'file_upload', 'composite',
            ])->default('free_text');
            $table->json('answer_options_json')->nullable();
            $table->boolean('is_required')->default(true);
            $table->string('auto_population_source', 100)->nullable();
            $table->text('help_text')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['questionnaire_id', 'question_code'], 'rcq_quest_code_uq');
            $table->index(['questionnaire_id', 'section_id', 'sort_order'], 'rcq_quest_sec_sort_idx');
            $table->index('auto_population_source', 'rcq_autopop_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rcr_questions');
    }
};
