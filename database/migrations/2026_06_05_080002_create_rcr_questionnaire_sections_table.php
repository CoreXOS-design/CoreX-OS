<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9d B2 — top-level sections within a questionnaire (e.g. "A. Institution
 * Identification", "B. RMCP"). Hierarchy is intentionally flat: subsections
 * live in question_code itself (e.g. "3.2.1") to keep the import schema simple.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('rcr_questionnaire_sections')) return;

        Schema::create('rcr_questionnaire_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_id')
                ->constrained('rcr_questionnaires', 'id', 'rqs_quest_fk')
                ->cascadeOnDelete();
            $table->string('section_code', 20);
            $table->string('title', 300);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['questionnaire_id', 'section_code'], 'rqs_quest_code_uq');
            $table->index(['questionnaire_id', 'sort_order'], 'rqs_quest_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rcr_questionnaire_sections');
    }
};
