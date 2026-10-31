<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shrink progress for an uploaded training video (spec .ai/specs/training-videos.md §8.11).
 * NULL = waiting in the queue / not started; 0-100 = being shrunk. Existing rows stay NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_videos', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress_percent')->nullable()->after('processing_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('training_videos', function (Blueprint $table) {
            $table->dropColumn('progress_percent');
        });
    }
};
