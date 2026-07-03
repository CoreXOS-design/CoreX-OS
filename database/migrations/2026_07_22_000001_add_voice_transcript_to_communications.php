<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-163 Stage 2 — voice-note transcription (§2.5).
 *
 * A transcript is 1:1 with the message (a voice note = one Communication), so the
 * transcript rides the communications row — no join, and it inherits the AT-118/132
 * visibility gate and the AT-136/AT-168 consent gate for free (a withheld body has
 * no transcript to leak, exactly like body_preview).
 *
 * States mirror the AT-148 media pattern: pending → processing → done / failed,
 * with a retry count + terminal failed. transcript_lang/model record what produced
 * it (the model default is the agency-tunable medium; large-v3 on escalation).
 *
 * Per-agency schedule + enable toggle live on agencies (nightly batch, §2.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->mediumText('transcript_text')->nullable()->after('body_status');
            $table->string('transcript_preview', 255)->nullable()->after('transcript_text');
            $table->string('transcript_status', 16)->nullable()->after('transcript_preview'); // pending|processing|done|failed
            $table->unsignedTinyInteger('transcript_retry_count')->default(0)->after('transcript_status');
            $table->string('transcript_lang', 8)->nullable()->after('transcript_retry_count');
            $table->string('transcript_model', 32)->nullable()->after('transcript_lang');
            $table->string('transcript_error', 500)->nullable()->after('transcript_model');
            $table->timestamp('transcript_at')->nullable()->after('transcript_error');
            $table->index('transcript_status', 'comm_transcript_status_idx');
        });

        Schema::table('agencies', function (Blueprint $table) {
            // Nightly voice-note transcription (§2.3) — on by default, agency-scheduled.
            $table->boolean('wa_transcription_enabled')->default(true)->after('wa_embargo_retention_days');
            $table->string('wa_transcription_time', 5)->default('22:00')->after('wa_transcription_enabled'); // HH:MM local
        });
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->dropIndex('comm_transcript_status_idx');
            $table->dropColumn([
                'transcript_text', 'transcript_preview', 'transcript_status', 'transcript_retry_count',
                'transcript_lang', 'transcript_model', 'transcript_error', 'transcript_at',
            ]);
        });
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['wa_transcription_enabled', 'wa_transcription_time']);
        });
    }
};
