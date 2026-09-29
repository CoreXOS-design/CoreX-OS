<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §3.1/§3.2/§3.4/§3.4a — Stage 4. A work
 * order is evidence, permanently, not an operational ticket that closes
 * and is forgotten (§1). Contractors come from the existing supplier list
 * (agency_service_providers) — no second directory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->nullable()->constrained()->nullOnDelete();
            // nullable — a vacancy-period repair has no tenant, §3.1a.

            $table->foreignId('rental_inspection_item_id')->nullable()
                ->constrained(indexName: 'rwo_item_fk')->nullOnDelete();
            $table->foreignId('agency_service_provider_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('owner_approval_status', 20)->default('not_required');
            // not_required | pending | approved | declined — §3.4a. A work
            // order raised from an already-approved fault report inherits
            // 'approved' directly (§3a.1); one raised directly goes through
            // its own rental_approvals row.
            $table->string('trade_type', 60)->nullable();
            // references agency_service_types.code (§2) — no FK, matching
            // that table's own free-text code convention.

            $table->string('title', 191);
            $table->text('description');
            $table->string('status', 20)->default('reported');
            // reported | ordered | in_progress | completed | cancelled
            $table->string('priority', 20)->nullable();
            // low | normal | urgent — [cc4 design call, §3.1's own note],
            // not load-bearing, kept for the list screen's own sort/filter.

            $table->string('reported_by_type', 20);
            // tenant | agent_noticed | owner_instructed | inspection | fault_report
            $table->foreignId('reported_by_contact_id')->nullable()
                ->constrained('contacts')->nullOnDelete();
            $table->foreignId('reported_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('reported_inspection_observation_id')->nullable()
                ->constrained('rental_inspection_observations', indexName: 'rwo_observation_fk')->nullOnDelete();
            $table->foreignId('reported_fault_report_id')->nullable()
                ->constrained('rental_fault_reports', indexName: 'rwo_fault_report_fk')->nullOnDelete();
            // real FK now that rental_fault_reports exists (Stage 1) — the
            // reverse side of rental_fault_reports.rental_work_order_id.

            $table->timestamp('reported_at');
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();

            $table->string('paid_by', 20)->nullable();
            // owner | tenant | deposit_deduction | not_yet_paid
            $table->decimal('cost_amount', 10, 2)->nullable();
            // evidence trail only — §5.1, not a financial feature.

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
            // deletable only while nothing has been logged against it (no
            // update row, no photo) — application-layer gate, §3.1.

            $table->index(['agency_id', 'lease_id'], 'rwo_agency_lease_idx');
            $table->index(['agency_id', 'property_id'], 'rwo_agency_property_idx');
            $table->index(['agency_id', 'status'], 'rwo_agency_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_work_orders');
    }
};
