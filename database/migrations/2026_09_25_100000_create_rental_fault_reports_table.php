<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §3a, amended 2026-09-25 (§0c) — a fault
 * report is its own record with its own lifecycle: reported -> owner
 * approval where required -> work order raised (optional) -> outcome.
 * `rental_work_order_id` (the reverse FK) and the approval/outcome columns
 * are part of the settled schema from day one (§3a's own schema block is
 * spec-complete) even though this migration's own Stage 1 build only wires
 * up reporting + cancel/restore — the lifecycle actions land in Stage 2
 * against these same columns, not a later schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_fault_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->nullable()->constrained()->nullOnDelete();
            // nullable only for the rare vacancy-period case (§3.1a's sibling
            // reasoning on rental_work_orders, mirrored here).

            $table->foreignId('rental_inspection_item_id')->nullable()
                ->constrained(indexName: 'rfr_item_fk')->nullOnDelete();
            $table->foreignId('reported_inspection_observation_id')->nullable()
                ->constrained('rental_inspection_observations', indexName: 'rfr_observation_fk')->nullOnDelete();
            $table->unsignedBigInteger('rental_work_order_id')->nullable();
            // NO foreign-key constraint here — rental_work_orders (Stage 4) does
            // not exist yet at this migration. The real FK is added in Stage 4's
            // own migration once that table exists, per §11's own note that this
            // reverse FK lands alongside rental_work_orders' own creation. Column
            // + index exist now so §3a's schema is spec-complete from day one.
            $table->index('rental_work_order_id', 'rfr_work_order_idx');
            // reverse side of rental_work_orders.reported_fault_report_id — set
            // once a work order is raised FROM this report. Stays null forever
            // for the owner_handles route (§3a.1) — that is a complete, correct
            // case, not a pending one.

            $table->string('reported_by_type', 20);
            // tenant | agent_noticed | owner_instructed
            $table->foreignId('reported_by_contact_id')->nullable()
                ->constrained('contacts')->nullOnDelete();
            $table->foreignId('reported_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('reported_channel', 20);
            // phone | whatsapp | email | in_person | app | other — §3.2a/§0c,
            // settled 2026-09-25: HOW the report reached the agency. 'app' is a
            // reserved placeholder for Andre's future tenant-app channel.
            $table->foreignId('captured_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            // WHO typed this in on the reporter's behalf. Set on every row
            // today; nullable so a future self-service channel can leave it
            // null without a schema change.

            $table->string('title', 191);
            $table->text('description');

            $table->string('status', 20)->default('reported');
            // reported | awaiting_approval | approved | declined |
            // work_order_raised | owner_handling | resolved | cancelled

            $table->string('owner_approval_status', 20)->default('not_required');
            // not_required | pending | approved | declined — denormalized from
            // the latest rental_approvals row for this report (§3.4a).
            $table->string('approval_route', 20)->nullable();
            // agency_appoints | owner_handles — set only once approved (§0c/§3.4a).

            $table->string('outcome', 20)->nullable();
            // repaired | repaired_partially | not_repaired | owner_declined |
            // tenant_liable — set only once status='resolved' (§3a.2).
            $table->text('outcome_note')->nullable();
            $table->date('repaired_at')->nullable();
            // WHEN the repair actually happened — the spine field (§0c/§3a.1),
            // independent of who did it or who paid. Required whenever outcome
            // is repaired/repaired_partially, enforced at the application layer.

            $table->timestamp('reported_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
            // Gated at the application layer (§3a schema block): deletable only
            // while nothing has been logged against it (no photo, no linked
            // work order) — once anything exists, only 'cancelled'.

            $table->index(['agency_id', 'lease_id'], 'rfr_agency_lease_idx');
            $table->index(['agency_id', 'property_id'], 'rfr_agency_property_idx');
            $table->index(['agency_id', 'status'], 'rfr_agency_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_fault_reports');
    }
};
