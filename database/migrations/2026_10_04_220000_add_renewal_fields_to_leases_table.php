<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-renewals.md §6 — AT-444 renewal outcomes. `previous_lease_id`/
 * `renewed_lease_id`/`source_document_id` already exist (2026_09_17_090000); this
 * migration adds only the columns rental-renewals.md §6/§7 actually introduces:
 * notice tracking (tenant/landlord notice, move-out date) and a pointer to the
 * in-progress renewal e-sign draft so the Lease Hub never creates a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->date('notice_date')->nullable()->after('is_month_to_month');
            // 'tenant' | 'landlord' — who gave notice. Free-form string, not an FK,
            // matching the free-form 'source' column's own convention on this table.
            $table->string('notice_given_by', 20)->nullable()->after('notice_date');
            $table->string('notice_note', 500)->nullable()->after('notice_given_by');
            // Distinct from end_date: a fixed-term lease's end_date is the ORIGINAL
            // term; move_out_date is the date actually recorded via the "Tenant gave
            // notice"/"Landlord not renewing" one-click outcomes (rental-renewals.md
            // §7) and can differ from end_date (early move-out, or the only known date
            // at all for a month-to-month lease with no end_date).
            $table->date('move_out_date')->nullable()->after('notice_note');
            // flows.id — no FK constraint across the Docuperfect module boundary,
            // matching the existing convention leases.source_document_id already uses
            // for the same reason (2026_09_17_090000). Set when a renewal draft (copy-
            // forward or template-draft path) is created for this lease, so the Lease
            // Hub's "Review renewal" action resumes the existing draft instead of
            // creating a second one on every click.
            $table->unsignedBigInteger('renewal_draft_flow_id')->nullable()->after('renewed_lease_id');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn(['notice_date', 'notice_given_by', 'notice_note', 'move_out_date', 'renewal_draft_flow_id']);
        });
    }
};
