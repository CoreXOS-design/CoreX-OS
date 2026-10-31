<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-525 - auto-generated how-to videos (spec .ai/specs/training-videos.md section 9). A generated video is a TrainingVideo with source = 'generated' (the
 * AT-509 module's table, admin and queue are reused), plus what the generator needs: which screen it explains, what it was built from (so it can be flagged
 * out of date), and its chapters. agencies.is_training marks the ONE demo-data agency the recorder is allowed to log in as (POPIA: a recording never shows
 * a real client).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_videos', function (Blueprint $table) {
            $table->string('screen_key', 80)->nullable()->after('source')->index();
            $table->char('source_hash', 40)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->string('tts_voice', 80)->nullable();
            $table->string('captions_file', 64)->nullable();
            $table->json('chapters')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
        });
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('is_training')->default(false)->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('training_videos', function (Blueprint $table) {
            $table->dropColumn(['screen_key', 'source_hash', 'generated_at', 'tts_voice', 'captions_file', 'chapters', 'generated_by']);
        });
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('is_training');
        });
    }
};
