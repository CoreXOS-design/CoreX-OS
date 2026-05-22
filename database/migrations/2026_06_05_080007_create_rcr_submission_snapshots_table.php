<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9d B7 — immutable RCR snapshot.
 *
 * Taken exactly once when status transitions to 'submitted'. snapshot_json
 * captures the FULL denormalised state of the submission: every question,
 * every answer, every piece of evidence, every auto-population source data
 * blob, all questionnaire metadata. This is the artefact that survives a
 * FIC audit five years later, even if the live data has churned since.
 *
 * questionnaire_version_hash is SHA-256 of the questionnaire structure at
 * submission time — proves which version of the questions was answered.
 *
 * No updates, no soft-deletes. The snapshot is sacred.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('rcr_submission_snapshots')) return;

        Schema::create('rcr_submission_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')
                ->constrained('rcr_submissions', 'id', 'rss_sub_fk')
                ->cascadeOnDelete();
            $table->longText('snapshot_json');
            $table->string('questionnaire_version_hash', 64);
            $table->timestamp('taken_at')->useCurrent();
            $table->foreignId('taken_by_user_id')
                ->constrained('users', 'id', 'rss_taker_fk');

            // Append-only — no updated_at, no soft-deletes.
            $table->timestamp('created_at')->useCurrent();

            $table->index('submission_id', 'rss_sub_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rcr_submission_snapshots');
    }
};
