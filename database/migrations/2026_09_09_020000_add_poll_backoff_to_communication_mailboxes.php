<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-08/09 (Johan, back-off on failure) — closes the six-hour tail of
 * today's incident. consecutive_failures already existed (AT-181) but was
 * only ever RECORDED, never used to slow anything down: PollMailboxes::
 * isDue() checked only last_polled_at vs poll_interval_minutes, so a
 * mailbox that failed every single cycle was dispatched again at the same
 * fixed cadence forever — 20 mailboxes retried every 5 minutes for six
 * hours straight is what turned a brief cPHulk trip into an all-day outage.
 *
 *  - communication_mailboxes.next_poll_earliest_at  set on each failure to
 *    an exponentially growing point in the future; PollMailboxes::isDue()
 *    will not dispatch this mailbox before it. Cleared on any success
 *    (poll, Test Connection, or a "behind" outcome — auth+connect worked).
 *  - communication_mailboxes.poll_disabled_at       set once
 *    consecutive_failures reaches the disable threshold — polling stops
 *    entirely until a human intervenes or a successful Test Connection
 *    clears it. Distinct from the manual `active` flag: this is the
 *    SYSTEM giving up, not the operator switching it off — conflating the
 *    two would repeat the exact "one field, two meanings" mistake the
 *    Behind/Failing health-state fix corrected today.
 *  - agencies.communication_poll_backoff_base_seconds    first back-off
 *    step (NULL -> config communications.poll_backoff_base_seconds).
 *  - agencies.communication_poll_backoff_max_seconds     back-off ceiling
 *    (NULL -> config communications.poll_backoff_max_seconds).
 *  - agencies.communication_poll_disable_threshold       consecutive
 *    failures before auto-disable (NULL -> config
 *    communications.poll_disable_threshold).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('communication_mailboxes', function (Blueprint $table) {
            if (!Schema::hasColumn('communication_mailboxes', 'next_poll_earliest_at')) {
                $table->timestamp('next_poll_earliest_at')->nullable()->after('consecutive_failures');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'poll_disabled_at')) {
                $table->timestamp('poll_disabled_at')->nullable()->after('next_poll_earliest_at');
            }
        });

        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'communication_poll_backoff_base_seconds')) {
                $table->unsignedInteger('communication_poll_backoff_base_seconds')->nullable();
            }
            if (!Schema::hasColumn('agencies', 'communication_poll_backoff_max_seconds')) {
                $table->unsignedInteger('communication_poll_backoff_max_seconds')->nullable();
            }
            if (!Schema::hasColumn('agencies', 'communication_poll_disable_threshold')) {
                $table->unsignedTinyInteger('communication_poll_disable_threshold')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('communication_mailboxes', function (Blueprint $table) {
            if (Schema::hasColumn('communication_mailboxes', 'next_poll_earliest_at')) {
                $table->dropColumn('next_poll_earliest_at');
            }
            if (Schema::hasColumn('communication_mailboxes', 'poll_disabled_at')) {
                $table->dropColumn('poll_disabled_at');
            }
        });

        Schema::table('agencies', function (Blueprint $table) {
            foreach (['communication_poll_backoff_base_seconds', 'communication_poll_backoff_max_seconds', 'communication_poll_disable_threshold'] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
