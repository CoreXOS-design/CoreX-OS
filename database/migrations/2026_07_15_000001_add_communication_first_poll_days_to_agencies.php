<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-122 — agency-configurable first-poll backfill window for email ingestion.
 *
 * The first IMAP poll of a mailbox reads the last N days; a large N on a slow
 * INBOX exceeds the read budget and never completes. This nullable override
 * lets an agency tune N (NULL → config('communications.first_poll_backfill_days',
 * default 7)). Mirrors agencies.communication_pending_grace_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->unsignedSmallInteger('communication_first_poll_days')->nullable()
                ->after('outreach_send_window');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('communication_first_poll_days');
        });
    }
};
