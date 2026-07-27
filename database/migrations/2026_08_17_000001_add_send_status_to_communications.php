<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact-details Phase 4 — the outreach "could not send" flow.
 *
 * Today OutboundProvisionalLogger::log() writes a communications row and
 * bumps contacts.last_contacted_at the instant an agent clicks WhatsApp/Email
 * — there is no delivery signal (WhatsApp is click-to-chat; the agent finds
 * out "not on WhatsApp" from their own phone, later). So a failed send was
 * permanently recorded as "the contact was reached" — including the single-
 * number case, where the agent had no way to walk it back at all.
 *
 * `send_status` (default 'sent' — every existing row is retroactively
 * correct, since no communications row has ever been anything else) lets an
 * agent flag a specific send as `not_delivered` after the fact. Two rules
 * this must satisfy (Johan-approved 2026-07-21):
 *   1. Contact::outboundCommCount()/last_contacted_at must NOT count a
 *      not_delivered row — a failed send is kept as an audit record but does
 *      not mean "the contact was reached", even when it's the contact's ONLY
 *      send ever.
 *   2. The flag is reversible (an agent can undo a wrong flag) and a
 *      not_delivered send can be followed by resending to a DIFFERENT number
 *      — the resend is a NEW row, linked back via resent_from_communication_id
 *      so the original stays on record unmodified (append-only audit, no
 *      row ever overwritten).
 *
 * The full "who flagged/reverted/resent, when" chain is NOT extra columns —
 * it rides the existing domain-events framework (new CommunicationMarkedNot
 * Delivered/CommunicationSendStatusReverted/CommunicationResent events,
 * auto-audited to domain_event_log by the existing DomainEvent listener).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->string('send_status', 20)->default('sent')->after('direction');
            $table->foreignId('send_status_set_by_user_id')
                ->nullable()->after('send_status')
                ->constrained('users', 'id', 'comm_send_status_actor_fk')->nullOnDelete();
            $table->dateTime('send_status_set_at')->nullable()->after('send_status_set_by_user_id');
            // Self-referencing: a resend row points back at the original
            // not_delivered row it supersedes. Nullable — every normal send
            // (the overwhelming majority) has none.
            $table->unsignedBigInteger('resent_from_communication_id')->nullable()->after('send_status_set_at');

            $table->index('send_status', 'comms_send_status_idx');
            $table->foreign('resent_from_communication_id', 'comms_resent_from_fk')
                ->references('id')->on('communications')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->dropForeign('comms_resent_from_fk');
            $table->dropForeign('comm_send_status_actor_fk');
            $table->dropIndex('comms_send_status_idx');
            $table->dropColumn(['send_status', 'send_status_set_by_user_id', 'send_status_set_at', 'resent_from_communication_id']);
        });
    }
};
