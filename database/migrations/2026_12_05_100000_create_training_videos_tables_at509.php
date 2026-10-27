<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training Videos — CoreX's own public training catalogue (YouTube links in owner-made groups).
 *
 * Platform records, not tenant data: no agency_id on either table.
 * Spec: .ai/specs/training-videos.md §3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_video_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('training_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_video_group_id')->nullable()->constrained('training_video_groups')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('youtube_url', 500);
            $table->char('youtube_video_id', 11)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_videos');
        Schema::dropIfExists('training_video_groups');
    }
};
