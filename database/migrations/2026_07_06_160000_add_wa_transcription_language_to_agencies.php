<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-194 — per-agency voice-note TRANSCRIPTION LANGUAGE hint.
 *
 * `wa_transcription_language` feeds whisper's `-l <lang>` (replacing the hardcoded
 * `-l auto` in the worker). Nullable with a CODE default of 'auto' (see
 * Agency::transcriptionLanguage()) so an agency with no value keeps the current
 * behaviour EXACTLY — auto-detect. No data backfill here on purpose: the HFC 'af'
 * value is applied as a deliberate operational step (staging now, live in the gated
 * night flip), never silently by the schema migration, so deploying this code is inert
 * on live until that step runs.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('wa_transcription_language', 10)->nullable()->after('wa_transcription_time');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('wa_transcription_language');
        });
    }
};
