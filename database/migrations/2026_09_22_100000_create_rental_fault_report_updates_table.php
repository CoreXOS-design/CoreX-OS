<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §3a — "who did what" for a fault report.
 * Mirrors rental_work_order_updates exactly (same table shape, same author
 * intent — actor/action/from/to/note/timestamp) rather than inventing a
 * second audit convention: this is the closest existing sibling, built for
 * the same feature family, and already proven correct for the identical
 * job on the work-order side of this same spec. CoreX has no single
 * generic audit mechanism (checked: no spatie/activitylog, no polymorphic
 * AuditLog table) — only a repeated per-module pattern (ContactAuditLog,
 * PropertyAuditLog, RentalApplicationAuditLog, rental_work_order_updates).
 * This table follows that pattern's closest, same-domain instance.
 *
 * Append-only: no updated_at, no deleted_at. A correction is a NEW row,
 * never an edit — same evidence-integrity reasoning as every other log
 * table in this feature family.
 *
 * `rental_fault_report_id` carries the COLUMN here but not the FK
 * constraint — `rental_fault_reports` is created by a LATER-dated
 * migration (2026_09_25_100000), so a fresh migrate:fresh (filename
 * order) would fail here with "Failed to open the referenced table"
 * before that table exists. The constraint itself (same name, same
 * cascade rule) is added once that table exists — see
 * 2026_09_30_100600_add_deferred_foreign_keys_for_later_created_tables.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_fault_report_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')
                ->constrained('agencies', 'id', 'rfru_agency_fk')->cascadeOnDelete();
            $table->foreignId('rental_fault_report_id');

            $table->string('update_type', 40);
            // logged | approval_requested | approval_recorded | work_order_raised |
            // outcome_set | status_change | note | archived | restored
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users', 'id', 'rfru_created_by_fk')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['agency_id', 'rental_fault_report_id'], 'rfru_agency_report_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_fault_report_updates');
    }
};
