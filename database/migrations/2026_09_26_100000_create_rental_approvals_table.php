<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §3.4a/§0c, settled 2026-09-25 — a single,
 * shared, append-only evidence log for an owner's approval decision. Shared
 * between a fault report's own approval (§3a.1, the normal path, wired up
 * this stage) and a work order raised WITHOUT an upstream fault report
 * (Stage 4, not wired up yet — the column exists now so §3.4a's schema is
 * spec-complete from day one, same discipline Stage 1 applied to
 * rental_fault_reports.rental_work_order_id).
 *
 * No foreign-key constraint on rental_work_order_id — rental_work_orders
 * does not exist until Stage 4. Exactly one of rental_fault_report_id /
 * rental_work_order_id is set per row, enforced at the application layer,
 * matching this spec's own established "exactly one of" pattern (§3.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();

            $table->foreignId('rental_fault_report_id')->nullable()
                ->constrained(indexName: 'rental_approvals_fault_report_fk')->cascadeOnDelete();
            $table->unsignedBigInteger('rental_work_order_id')->nullable();
            // NO foreign-key constraint — rental_work_orders (Stage 4) does not
            // exist yet. Real FK added in Stage 4's own migration.

            $table->string('decision', 20);
            // approved | declined
            $table->string('approval_route', 20)->nullable();
            // agency_appoints | owner_handles — required when decision='approved'
            // AND rental_fault_report_id is set; always null at the (future)
            // work-order level, since a work order's mere existence already
            // means the agency-appoints route was chosen upstream (§3a.1).

            $table->string('evidence_type', 20);
            // whatsapp | email | verbal_note — Johan's ruling: approval is
            // always in writing. verbal_note is the honest, rare fallback.
            $table->text('evidence_text')->nullable();
            $table->string('evidence_file_path', 500)->nullable();
            // an uploaded screenshot or forwarded email, reusing the same
            // storage pattern as every other file in this feature family —
            // no second pipeline.

            $table->timestamp('decided_at');
            // when the owner actually decided — may predate when the agent
            // typed this row in.
            $table->foreignId('recorded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamp('created_at');
            // immutable, no updated_at, no deleted_at — the "comprehensive
            // log" evidence-integrity reasoning applied here the same way it
            // already applies to rental_work_order_updates and every photo
            // table in this feature family. An approval is never edited or
            // deleted after the fact; a wrong entry is corrected by a NEW
            // row, same as everywhere else evidence lives in this spec.

            $table->index(['agency_id', 'rental_fault_report_id'], 'rental_approvals_agency_report_idx');
            $table->index('rental_work_order_id', 'rental_approvals_work_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_approvals');
    }
};
