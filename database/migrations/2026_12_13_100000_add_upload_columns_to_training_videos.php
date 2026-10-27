<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training Videos — uploaded video files (spec .ai/specs/training-videos.md §8.4).
 *
 * A video is either a YouTube link (as before) or a file the owner uploaded and CoreX
 * shrank. Every existing row is a YouTube video: it keeps source = 'youtube' and
 * status = 'ready', so the public page and the admin list behave exactly as they did.
 *
 * (Spec U12 said to amend the original create migration; that migration has since run on
 * Staging, so this is a new, additive one instead.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_videos', function (Blueprint $table) {
            $table->string('source', 10)->default('youtube')->after('id');
            $table->string('status', 12)->default('ready')->after('source');
            $table->string('failure_reason', 255)->nullable()->after('status');

            // Required only when source = 'youtube' (enforced in the controller).
            $table->string('youtube_url', 500)->nullable()->change();
            $table->char('youtube_video_id', 11)->nullable()->change();

            // Uploaded files: {file_name}.mp4 and {file_name}.jpg in the training-videos folder.
            $table->string('file_name', 64)->nullable();
            $table->unsignedBigInteger('original_size_bytes')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamp('processing_started_at')->nullable();

            // The finished upload waiting to be (or being) shrunk. Kept after a failure so
            // "Try again" works; cleared once the compressed copy is safely written. A
            // replacement upload sets it while the old file keeps playing (U9).
            $table->string('pending_upload_id', 32)->nullable();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('training_videos', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn([
                'source', 'status', 'failure_reason', 'file_name', 'original_size_bytes', 'size_bytes',
                'duration_seconds', 'width', 'height', 'processing_started_at', 'pending_upload_id',
            ]);
        });
    }
};
