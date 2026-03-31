<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_pipeline_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_template_id')->constrained('deal_pipeline_templates')->cascadeOnDelete();
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
            $table->enum('trigger_type', ['on_creation', 'after_step', 'manual', 'on_date'])->default('on_creation');
            $table->unsignedBigInteger('trigger_step_id')->nullable();
            $table->integer('days_offset')->default(0);
            $table->integer('rag_green_days')->default(14);
            $table->integer('rag_amber_days')->default(7);
            $table->integer('rag_red_days')->default(3);
            $table->boolean('notify_agent')->default(true);
            $table->boolean('notify_bm')->default(true);
            $table->boolean('notify_admin')->default(false);
            $table->json('escalation_config')->nullable();
            $table->json('required_before')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('trigger_step_id')->references('id')->on('deal_pipeline_steps')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_pipeline_steps');
    }
};
