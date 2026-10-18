<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-442 — one job card per internal rental work order. A job card BUILDS
 * the work order (rental-work-orders.md §3) it belongs to — created
 * together, always exactly one rental_job_cards row per
 * rental_work_orders row with assignment_type='internal'. property_id/
 * lease_id are denormalized convenience columns, same reasoning as every
 * other rentals table in this family (rental-work-orders.md §3.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_job_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('rental_work_order_id')->unique()
                ->constrained(indexName: 'rjc_work_order_fk')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title', 191);
            $table->string('status', 20)->default('draft');
            // draft | quoted | approved | scheduled | in_progress | completed | cancelled

            $table->foreignId('assigned_user_id')->nullable()
                ->constrained('users', indexName: 'rjc_assigned_user_fk')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->text('access_notes')->nullable();

            $table->decimal('total_amount', 10, 2)->nullable();
            // cached sum of rental_job_card_lines — recalculated on every
            // line change, never hand-entered.

            $table->timestamp('worker_signed_off_at')->nullable();
            $table->foreignId('worker_signed_off_by_user_id')->nullable()
                ->constrained('users', indexName: 'rjc_worker_signoff_fk')->nullOnDelete();
            $table->timestamp('agent_signed_off_at')->nullable();
            $table->foreignId('agent_signed_off_by_user_id')->nullable()
                ->constrained('users', indexName: 'rjc_agent_signoff_fk')->nullOnDelete();
            $table->timestamp('tenant_confirmed_at')->nullable();
            // Johan, AT-442 brief — tenant confirmation is recorded BY THE
            // AGENT for now (tenant login is AT-445); no tenant_confirmed_by
            // Contact FK needed yet, mirrors rental_work_orders'
            // tenant_confirmed_completion_at being agent-recorded evidence.
            $table->foreignId('tenant_confirmed_by_user_id')->nullable()
                ->constrained('users', indexName: 'rjc_tenant_confirm_fk')->nullOnDelete();
            $table->text('tenant_confirmation_note')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()
                ->constrained('users', indexName: 'rjc_cancelled_by_fk')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
            // deletable only while nothing has been logged against it
            // (application-layer gate, same as rental_work_orders §3.1) —
            // once any task/line/update exists, only 'cancelled'.

            $table->index(['agency_id', 'property_id'], 'rjc_agency_property_idx');
            $table->index(['agency_id', 'lease_id'], 'rjc_agency_lease_idx');
            $table->index(['agency_id', 'status'], 'rjc_agency_status_idx');
            $table->index(['agency_id', 'due_at'], 'rjc_agency_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_job_cards');
    }
};
