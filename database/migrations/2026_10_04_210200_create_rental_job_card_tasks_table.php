<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AT-442 — the job card's own task checklist: add/tick/reorder/archive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_job_card_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_job_card_id')->constrained(indexName: 'rjct_job_card_fk')->cascadeOnDelete();

            $table->string('description', 500);
            $table->boolean('is_done')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('done_by_user_id')->nullable()
                ->constrained('users', indexName: 'rjct_done_by_fk')->nullOnDelete();
            $table->timestamp('done_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
            // "archive" per the brief's own task-checklist wording — soft
            // delete only, never a hard removal of a task someone ticked.

            $table->index(['agency_id', 'rental_job_card_id'], 'rjct_agency_job_card_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_job_card_tasks');
    }
};
