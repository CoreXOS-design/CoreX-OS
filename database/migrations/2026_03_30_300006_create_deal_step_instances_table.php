<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_step_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals_v2')->cascadeOnDelete();
            $table->foreignId('pipeline_step_id')->constrained('deal_pipeline_steps');
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_milestone')->default(false);
            $table->enum('completion_type', [
                'manual_tick', 'date_input', 'amount_input',
                'document_upload', 'document_signed', 'text_input',
                'multi_field', 'auto_from_linked_deal',
            ])->default('manual_tick');
            $table->json('completion_config')->nullable();
            $table->enum('status', ['not_started', 'active', 'completed', 'overdue', 'skipped'])->default('not_started');
            $table->enum('trigger_type', ['on_creation', 'after_step', 'manual', 'on_date']);
            $table->unsignedBigInteger('trigger_step_instance_id')->nullable();
            $table->integer('days_offset')->default(0);
            $table->date('due_date')->nullable();
            $table->datetime('activated_at')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->foreignId('completed_by_id')->nullable()->constrained('users');
            $table->json('completion_data')->nullable();
            $table->integer('rag_green_days')->default(14);
            $table->integer('rag_amber_days')->default(7);
            $table->integer('rag_red_days')->default(3);
            $table->enum('current_rag', ['grey', 'green', 'amber', 'red', 'overdue'])->default('grey');
            $table->boolean('notify_agent')->default(true);
            $table->boolean('notify_bm')->default(true);
            $table->boolean('notify_admin')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('trigger_step_instance_id')->references('id')->on('deal_step_instances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_step_instances');
    }
};
