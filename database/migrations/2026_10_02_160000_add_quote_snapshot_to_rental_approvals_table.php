<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md — Johan's ruling, 2026-09-22: a recorded
 * owner decision is an unanchored fact today — "approved", with nothing
 * saying approved for what, at what price, from which supplier. An agency
 * in a dispute needs to show "the landlord approved this quote, for this
 * amount, from this supplier, on this date" (decided_at already covers the
 * date) — today the record cannot say the rest.
 *
 * quote_amount_at_decision / quote_supplier_name_at_decision are a SNAPSHOT,
 * not a live join — Johan's explicit instruction: "a snapshot, not a live
 * foreign key, so later edits to the quote cannot rewrite history." Neither
 * column is ever re-derived from the live quote/supplier row at render
 * time; they are written once, at recordApproval() time, and never touched
 * again — the whole point is that they stay true to what was actually
 * approved even if the quote or supplier is later edited or archived.
 *
 * quote_id_at_decision is the one exception, and it exists for a different
 * reason: identity comparison, not display. Two quotes can coincidentally
 * share the same amount and supplier name; only a stable id can correctly
 * answer "is the currently-selected quote the SAME one this decision was
 * about" (RentalWorkOrder::selectQuote()'s supersession check). It is never
 * used to fetch amount/supplier for display — those always come from the
 * snapshot columns above, never a join through this id.
 *
 * All three nullable, additive only. Every existing rental_approvals row
 * gets NULL here and stays NULL forever — there is no way to reconstruct
 * what an already-recorded decision was about after the fact, and the
 * screen/PDF must say nothing rather than imply a snapshot that was never
 * taken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_approvals', function (Blueprint $table) {
            $table->foreignId('quote_id_at_decision')->nullable()
                ->after('rental_work_order_id')
                ->constrained('rental_work_order_quotes', indexName: 'rental_approvals_quote_at_decision_fk')
                ->nullOnDelete();
            $table->decimal('quote_amount_at_decision', 10, 2)->nullable()->after('quote_id_at_decision');
            $table->string('quote_supplier_name_at_decision')->nullable()->after('quote_amount_at_decision');
        });
    }

    public function down(): void
    {
        Schema::table('rental_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quote_id_at_decision');
            $table->dropColumn(['quote_amount_at_decision', 'quote_supplier_name_at_decision']);
        });
    }
};
