<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9d B6 — supporting evidence attached to RCR answers.
 *
 * Four evidence types:
 *   document_upload         — agency uploads a PDF/image/etc to storage
 *   corex_record_reference  — link to an existing CoreX record (e.g. a
 *                             specific fica_submission row, a deal row,
 *                             a training completion)
 *   external_url            — link out to a portal (e.g. goAML thread,
 *                             FIC site reference)
 *   note                    — free text capturing context
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('rcr_answer_evidence')) return;

        Schema::create('rcr_answer_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('answer_id')
                ->constrained('rcr_answers', 'id', 'rae_answer_fk')
                ->cascadeOnDelete();
            $table->enum('evidence_type', [
                'document_upload', 'corex_record_reference', 'external_url', 'note',
            ]);
            $table->string('document_path', 500)->nullable();
            $table->string('corex_record_table', 100)->nullable();
            $table->unsignedBigInteger('corex_record_id')->nullable();
            $table->string('external_url', 500)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('added_by_user_id')
                ->constrained('users', 'id', 'rae_adder_fk');
            $table->timestamps();

            $table->index(['answer_id', 'evidence_type'], 'rae_answer_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rcr_answer_evidence');
    }
};
