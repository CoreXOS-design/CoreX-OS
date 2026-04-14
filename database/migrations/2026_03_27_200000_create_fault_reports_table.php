<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fault_reports', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['backend', 'frontend', 'manual']);
            $table->enum('severity', ['error', 'warning', 'info'])->default('error');
            $table->string('title', 500);
            $table->text('message')->nullable();
            $table->string('exception_class', 255)->nullable();
            $table->string('file', 500)->nullable();
            $table->integer('line')->nullable();
            $table->text('trace')->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('method', 10)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('request_data')->nullable();
            $table->string('screenshot_path', 500)->nullable();
            $table->enum('status', ['new', 'investigating', 'fixed', 'ignored'])->default('new');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->integer('occurrence_count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('type');
            $table->index('last_seen_at');
            $table->index(['exception_class', 'file', 'line'], 'fault_reports_dedup_index');

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fault_reports');
    }
};
