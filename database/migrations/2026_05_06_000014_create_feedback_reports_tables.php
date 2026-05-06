<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('feedback_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['bug', 'enhancement', 'question', 'compliment', 'other']);
            $table->enum('severity', ['critical', 'major', 'minor'])->nullable();
            $table->string('title', 200);
            $table->text('description');
            $table->text('steps_to_reproduce')->nullable();
            $table->text('expected_behaviour')->nullable();
            $table->text('actual_behaviour')->nullable();
            $table->string('page_url', 500)->nullable();
            $table->string('page_title', 200)->nullable();
            $table->string('module_tag', 50)->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('os', 50)->nullable();
            $table->unsignedSmallInteger('viewport_width')->nullable();
            $table->unsignedSmallInteger('viewport_height')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('server_log_window_start')->nullable();
            $table->timestamp('server_log_window_end')->nullable();
            $table->enum('status', ['new', 'reviewing', 'in_progress', 'fixed', 'wont_fix', 'duplicate', 'deferred'])->default('new');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('related_commit', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'submitted_at']);
            $table->index('status');
            $table->index('module_tag');
        });

        Schema::create('feedback_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_report_id')->constrained('feedback_reports')->cascadeOnDelete();
            $table->string('filename', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('storage_path', 500);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_attachments');
        Schema::dropIfExists('feedback_reports');
    }
};
