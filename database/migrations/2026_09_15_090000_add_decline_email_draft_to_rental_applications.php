<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-410b, 2026-09-15 — Johan: "the auth needs to report back to the agent
 * why the application has been rejected... a soft message and reasoning is
 * the right way to approach this." Mirrors applicant_notified_at's own
 * shape exactly (same migration, same reasoning): status stays 'declined'
 * the moment the authoriser decides (that decision is final and
 * unambiguous); these columns answer the separate, agent-side question of
 * WHAT will be told to the applicant and WHETHER it has actually gone out
 * yet. NULL applicant_notified_at = declined, not yet sent.
 *
 * decline_reason_template_id is deliberately NOT a DB foreign key — the
 * template table is a sibling lane's build landing in parallel (cc2, same
 * day); a hard FK would couple this migration's run order to a table that
 * may not exist yet when this one runs. Resolved at the application layer
 * instead, same posture rental_applications.document_type_id-style
 * soft-references already take elsewhere in this schema.
 *
 * decline_email_subject/body hold the FULL, MERGED, HUMAN-EDITABLE draft —
 * applicant's real name, agency name, property, and the picked template's
 * reason+guidance already resolved in, never raw placeholders — from the
 * moment the authoriser decides. The agent's own send step
 * (RentalApplicationReviewController::sendDecline()) overwrites these with
 * whatever she actually edited and sent, so once applicant_notified_at is
 * set these columns ARE the permanent record of what went out — no
 * separate "sent" copy needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('decline_reason_template_id')->nullable()->after('applicant_notified_at');
            $table->text('decline_email_subject')->nullable()->after('decline_reason_template_id');
            $table->longText('decline_email_body')->nullable()->after('decline_email_subject');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn(['decline_reason_template_id', 'decline_email_subject', 'decline_email_body']);
        });
    }
};
