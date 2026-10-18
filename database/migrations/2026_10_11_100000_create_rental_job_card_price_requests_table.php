<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §17.5.4 (foundation F3) — "Ask the crew to price this job".
 * One OPEN request per card; the office closes it when every submitted line has
 * been accepted or rejected. Rows are never deleted (a withdrawn request is
 * `cancelled`). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_job_card_price_requests')) {
            return;
        }

        Schema::create('rental_job_card_price_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_job_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->text('note')->nullable();
            // open | submitted | closed | cancelled
            $table->string('status', 20)->default('open');
            $table->timestamp('submitted_at')->nullable();
            $table->string('submitted_label', 191)->nullable();
            $table->string('submitted_ip', 45)->nullable();
            $table->string('submitted_device', 255)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['rental_job_card_id', 'status'], 'rjcpr_card_status_idx');
            $table->index(['agency_id', 'status'], 'rjcpr_agency_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_job_card_price_requests');
    }
};
