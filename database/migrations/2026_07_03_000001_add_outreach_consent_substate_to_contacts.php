<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-81 — outreach consent sub-state.
 *
 * Adds the two columns that expand the marketing-consent axis from a binary
 * (opted_in / opted_out) into the 5-state doctrine WITHOUT a stored "state"
 * column (the state stays derived):
 *
 *   - outreach_permission_asked_at: the PENDING marker AND the no-response
 *     clock. Stamped when an outreach consent-request is sent (only from the
 *     INITIAL state); cleared on any response (opt-in / opt-out / click /
 *     future inbound reply). While set with no opt-in/opt-out stamp the contact
 *     is PENDING — the send gate blocks a re-blast, and the timeout command
 *     ages it off into a no_response opt-out once the agency window elapses.
 *
 *   - messaging_opt_out_kind: when master messaging_opt_out_at is set, this
 *     distinguishes an explicit `declined` (never re-contact) from a silence
 *     `no_response` (re-contactable in future). It is NOT the free-text
 *     messaging_opt_out_reason (which stays human-readable); it is the
 *     structured, queryable sub-state key.
 *
 * Both columns are additive + nullable. Legacy opt-outs (kind NULL) are read as
 * `declined` — every pre-existing opt-out was an explicit one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('outreach_permission_asked_at')->nullable()->after('messaging_opted_in_at');
            $table->enum('messaging_opt_out_kind', ['declined', 'no_response'])
                ->nullable()
                ->after('messaging_opt_out_source');

            // The timeout sweep scans pending contacts per agency by clock age.
            $table->index(['agency_id', 'outreach_permission_asked_at'], 'contacts_agency_outreach_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_agency_outreach_pending_idx');
            $table->dropColumn(['outreach_permission_asked_at', 'messaging_opt_out_kind']);
        });
    }
};
