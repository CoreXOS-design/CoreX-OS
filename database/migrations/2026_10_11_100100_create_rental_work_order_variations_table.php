<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §17.7.3 (foundation F5) — a variation: extra
 * work (or a higher external quote) raised after the owner approved an amount.
 * Never deleted — a variation that no longer applies is `withdrawn`.
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_work_order_variations')) {
            return;
        }

        Schema::create('rental_work_order_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_job_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rental_work_order_quote_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('revision')->default(1);
            // awaiting_owner | auto_approved | approved | declined | withdrawn
            $table->string('status', 20)->default('awaiting_owner');
            // crew_lines | office_edit | external_quote
            $table->string('origin', 20);

            $table->decimal('baseline_amount', 10, 2);
            $table->decimal('extra_amount', 10, 2);
            $table->decimal('new_total', 10, 2);
            $table->decimal('price_change_amount', 10, 2)->default(0);

            // the term the gate relied on (variation_tolerance | no_approval_limit) — null while awaiting_owner
            $table->string('term_basis', 30)->nullable();
            $table->decimal('term_value', 10, 2)->nullable();
            $table->string('term_source', 20)->nullable();

            $table->text('note')->nullable();
            $table->text('term_text')->nullable();

            $table->foreignId('raised_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('raised_at');
            $table->timestamp('mail_sent_at')->nullable();

            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('decided_by_contact_id')->nullable();
            // portal | agent_capture
            $table->string('decided_via', 20)->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['rental_work_order_id', 'status'], 'rwov_wo_status_idx');
            $table->index(['agency_id', 'status'], 'rwov_agency_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_work_order_variations');
    }
};
