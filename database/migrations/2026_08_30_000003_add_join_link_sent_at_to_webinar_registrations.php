<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * join_link_sent_at (AT-383) — when this registrant was last sent the joining link.
 *
 * Spec: .ai/specs/webinar-registration.md §4.4
 *
 * A webinar is created before its Zoom link exists, so everyone who registers in that
 * window gets a confirmation email carrying no joining link at all. POST
 * /api/v1/webinars/{slug}/join-link closes that gap by mailing the whole cohort at
 * once, and this column is the only record that it happened.
 *
 * DELIBERATELY OVERWRITTEN, NOT ACCUMULATED. Re-sending is expected — Zoom links get
 * regenerated, and the reason to press the button twice is that the link changed. A
 * history table of "every time we mailed this person a link" answers a question nobody
 * asks; the question that IS asked, on the morning of a webinar, is "was this person
 * told, and when" — which is the last send.
 *
 * Distinct from reminder_sent_at, which is a one-shot latch (NULL = still owed). This
 * one is never read to decide whether to send; it is read to answer for what was sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webinar_registrations')) {
            return;
        }

        if (Schema::hasColumn('webinar_registrations', 'join_link_sent_at')) {
            return;
        }

        Schema::table('webinar_registrations', function (Blueprint $table) {
            // Beside the other two send-stamps, because they are read together.
            $table->timestamp('join_link_sent_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('webinar_registrations')) {
            return;
        }

        if (! Schema::hasColumn('webinar_registrations', 'join_link_sent_at')) {
            return;
        }

        Schema::table('webinar_registrations', function (Blueprint $table) {
            $table->dropColumn('join_link_sent_at');
        });
    }
};
