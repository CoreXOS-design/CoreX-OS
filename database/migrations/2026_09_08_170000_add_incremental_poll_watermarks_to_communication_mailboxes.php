<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-08 incremental-poll fix — per-folder watermark + UID cursor so a
 * poll only asks the server for mail since the last SUCCESSFUL completion,
 * instead of re-scanning the whole backlog every run. Purely additive.
 *
 * `last_uid_seen` already existed (2026_06_26_000004) but was never read or
 * written anywhere — repurposed here as the INBOX UID cursor rather than
 * adding a redundant column. A new `sent_last_uid` covers the Sent folder,
 * which needs its own independent cursor (different folder, different UIDs).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('communication_mailboxes', function (Blueprint $table) {
            if (!Schema::hasColumn('communication_mailboxes', 'inbox_watermark_at')) {
                $table->timestamp('inbox_watermark_at')->nullable()->after('last_uid_seen');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'inbox_uid_validity')) {
                $table->unsignedBigInteger('inbox_uid_validity')->nullable()->after('inbox_watermark_at');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'sent_watermark_at')) {
                $table->timestamp('sent_watermark_at')->nullable()->after('inbox_uid_validity');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'sent_last_uid')) {
                $table->unsignedBigInteger('sent_last_uid')->nullable()->after('sent_watermark_at');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'sent_uid_validity')) {
                $table->unsignedBigInteger('sent_uid_validity')->nullable()->after('sent_last_uid');
            }
            // Fairness (item 6) — last poll's wall-clock duration, used to route a
            // chronically slow mailbox onto its own queue instead of sharing a
            // worker slot with well-behaved ones.
            if (!Schema::hasColumn('communication_mailboxes', 'last_poll_duration_seconds')) {
                $table->unsignedInteger('last_poll_duration_seconds')->nullable()->after('sent_uid_validity');
            }
            // One-time nightly backfill marker (item 7) — set once a mailbox has
            // had its first full catch-up poll; every poll after that is
            // incremental via the watermark/UID cursor above, backfill never
            // repeats.
            if (!Schema::hasColumn('communication_mailboxes', 'backfill_completed_at')) {
                $table->timestamp('backfill_completed_at')->nullable()->after('last_poll_duration_seconds');
            }
        });

        // Item 4 — real per-agency override column. The model already reads
        // $agency->communication_pending_grace_days, but the column never
        // existed, so the override silently never applied; always fell through
        // to the global config default.
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'communication_pending_grace_days')) {
                $table->unsignedSmallInteger('communication_pending_grace_days')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('communication_mailboxes', function (Blueprint $table) {
            $table->dropColumn([
                'inbox_watermark_at', 'inbox_uid_validity', 'sent_watermark_at',
                'sent_last_uid', 'sent_uid_validity', 'last_poll_duration_seconds',
                'backfill_completed_at',
            ]);
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('communication_pending_grace_days');
        });
    }
};
