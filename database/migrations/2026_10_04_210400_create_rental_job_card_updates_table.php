<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-442 — the job card's own history log, same append-only evidence-
 * integrity shape as rental_work_order_updates (rental-work-orders.md
 * §3.1) — no updated_at, no deleted_at, never edited after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_job_card_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_job_card_id')->constrained(indexName: 'rjcu_job_card_fk')->cascadeOnDelete();

            $table->string('update_type', 30);
            // status_change | note | crew_assigned | scheduled | task_added |
            // task_ticked | task_archived | line_added | line_changed |
            // line_archived | quote_sent | sign_off | archived | restored
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['agency_id', 'rental_job_card_id'], 'rjcu_agency_job_card_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_job_card_updates');
    }
};
