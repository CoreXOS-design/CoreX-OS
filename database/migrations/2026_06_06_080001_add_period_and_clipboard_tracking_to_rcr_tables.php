<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9d.1 B — additive columns for FIC RCR remediation.
 *
 * Adds period-bound answers (p1/p2/p3/static), clipboard tracking for the
 * copy-to-goAML workflow, transposed-to-goAML status, and richer question
 * structure (parent_code for sub-questions, footnote, evidence_source_codes_json
 * array, auto_populate_hint).
 *
 * Drops + recreates the rcr_answers unique constraint to include period_code.
 *
 * Notes on rcr_sections vs rcr_questionnaire_sections: Phase 9d named the
 * sections table `rcr_questionnaire_sections`. The Phase 9d.1 brief calls it
 * `rcr_sections` — we adapt to the real name. See investigation audit A.0.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── rcr_questionnaire_sections ────────────────────────────────────
        Schema::table('rcr_questionnaire_sections', function (Blueprint $table) {
            if (!Schema::hasColumn('rcr_questionnaire_sections', 'has_period_columns')) {
                $table->boolean('has_period_columns')->default(true)->after('sort_order');
            }
            if (!Schema::hasColumn('rcr_questionnaire_sections', 'applies_when_json')) {
                $table->json('applies_when_json')->nullable()->after('has_period_columns');
            }
        });

        // ── rcr_questions ─────────────────────────────────────────────────
        Schema::table('rcr_questions', function (Blueprint $table) {
            if (!Schema::hasColumn('rcr_questions', 'parent_code')) {
                $table->string('parent_code', 30)->nullable()->after('question_code');
            }
            if (!Schema::hasColumn('rcr_questions', 'footnote')) {
                $table->text('footnote')->nullable()->after('question_text');
            }
            if (!Schema::hasColumn('rcr_questions', 'evidence_source_codes_json')) {
                $table->json('evidence_source_codes_json')->nullable()->after('auto_population_source');
            }
            if (!Schema::hasColumn('rcr_questions', 'auto_populate_hint')) {
                $table->text('auto_populate_hint')->nullable()->after('evidence_source_codes_json');
            }
        });
        Schema::table('rcr_questions', function (Blueprint $table) {
            // Index for parent-child lookup on sub-questions (1.29.1 → 1.29).
            $existing = collect(Schema::getIndexes('rcr_questions'))->pluck('name')->all();
            if (!in_array('rcq_parent_code_idx', $existing, true)) {
                $table->index('parent_code', 'rcq_parent_code_idx');
            }
        });

        // ── rcr_answers ───────────────────────────────────────────────────
        Schema::table('rcr_answers', function (Blueprint $table) {
            if (!Schema::hasColumn('rcr_answers', 'period_code')) {
                $table->string('period_code', 16)->default('static')->after('question_id');
            }
            if (!Schema::hasColumn('rcr_answers', 'copied_to_clipboard_at')) {
                $table->timestamp('copied_to_clipboard_at')->nullable();
            }
            if (!Schema::hasColumn('rcr_answers', 'copied_to_clipboard_count')) {
                $table->unsignedInteger('copied_to_clipboard_count')->default(0);
            }
            if (!Schema::hasColumn('rcr_answers', 'transposed_to_goaml_at')) {
                $table->timestamp('transposed_to_goaml_at')->nullable();
            }
            if (!Schema::hasColumn('rcr_answers', 'final_answer_format')) {
                $table->string('final_answer_format', 32)->nullable();
            }
        });

        // Replace unique key with period-aware version (only if old key exists).
        $existingIndexes = collect(Schema::getIndexes('rcr_answers'))->pluck('name')->all();
        if (in_array('rca_sub_quest_uq', $existingIndexes, true)) {
            Schema::table('rcr_answers', function (Blueprint $table) {
                $table->dropUnique('rca_sub_quest_uq');
            });
        }
        if (!in_array('rca_sub_quest_period_uq', $existingIndexes, true)) {
            Schema::table('rcr_answers', function (Blueprint $table) {
                $table->unique(
                    ['submission_id', 'question_id', 'period_code'],
                    'rca_sub_quest_period_uq',
                );
                $table->index('period_code', 'rca_period_idx');
            });
        }

        // ── rcr_submissions ───────────────────────────────────────────────
        Schema::table('rcr_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('rcr_submissions', 'transposed_to_goaml_at')) {
                $table->timestamp('transposed_to_goaml_at')->nullable()->after('locked_at');
            }
        });
    }

    public function down(): void
    {
        // Reverse the answer unique key first.
        $existingIndexes = collect(Schema::getIndexes('rcr_answers'))->pluck('name')->all();
        Schema::table('rcr_answers', function (Blueprint $table) use ($existingIndexes) {
            if (in_array('rca_period_idx', $existingIndexes, true)) $table->dropIndex('rca_period_idx');
            if (in_array('rca_sub_quest_period_uq', $existingIndexes, true)) $table->dropUnique('rca_sub_quest_period_uq');
        });
        Schema::table('rcr_answers', function (Blueprint $table) {
            $table->unique(['submission_id', 'question_id'], 'rca_sub_quest_uq');
        });

        Schema::table('rcr_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('rcr_submissions', 'transposed_to_goaml_at')) $table->dropColumn('transposed_to_goaml_at');
        });
        Schema::table('rcr_answers', function (Blueprint $table) {
            foreach (['final_answer_format','transposed_to_goaml_at','copied_to_clipboard_count','copied_to_clipboard_at','period_code'] as $c) {
                if (Schema::hasColumn('rcr_answers', $c)) $table->dropColumn($c);
            }
        });
        Schema::table('rcr_questions', function (Blueprint $table) {
            $idx = collect(Schema::getIndexes('rcr_questions'))->pluck('name')->all();
            if (in_array('rcq_parent_code_idx', $idx, true)) $table->dropIndex('rcq_parent_code_idx');
            foreach (['auto_populate_hint','evidence_source_codes_json','footnote','parent_code'] as $c) {
                if (Schema::hasColumn('rcr_questions', $c)) $table->dropColumn($c);
            }
        });
        Schema::table('rcr_questionnaire_sections', function (Blueprint $table) {
            foreach (['applies_when_json','has_period_columns'] as $c) {
                if (Schema::hasColumn('rcr_questionnaire_sections', $c)) $table->dropColumn($c);
            }
        });
    }
};
