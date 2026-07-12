<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-181 — Mailbox health tracking (hotfix).
 *
 * The "Active" badge on the mailboxes screen is only the manual on/off flag — a mailbox with
 * a wrong password/host shows "Active" forever while ingesting nothing. These columns let the
 * poller record connect/auth/read failures so the screen can show an HONEST health badge and
 * so admins get alerted after N consecutive failures.
 *
 *  - communication_mailboxes.last_error            sanitized failure reason (connect_failed /
 *                                                  auth_failed / incomplete_credentials /
 *                                                  read_timeout); NULL = last poll succeeded.
 *  - communication_mailboxes.last_error_at         when that failure was recorded.
 *  - communication_mailboxes.consecutive_failures  streak of failed polls; reset to 0 on any
 *                                                  successful poll. Drives the alert threshold.
 *  - communication_mailboxes.failure_notified_at   set when the admin alert fired for the
 *                                                  current failure episode; cleared on recovery
 *                                                  so one episode = one alert (no storms).
 *  - agencies.communication_failure_alert_threshold  agency override for N (NULL → config
 *                                                  communications.failure_alert_threshold = 3).
 *
 * last_polled_at semantics are UNCHANGED — it still only advances on a successful connect, so it
 * stays the ground-truth signal that the mailbox reached the server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_mailboxes', function (Blueprint $table) {
            if (!Schema::hasColumn('communication_mailboxes', 'last_error')) {
                $table->string('last_error', 100)->nullable()->after('last_polled_at');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'last_error_at')) {
                $table->timestamp('last_error_at')->nullable()->after('last_error');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'consecutive_failures')) {
                $table->unsignedInteger('consecutive_failures')->default(0)->after('last_error_at');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'failure_notified_at')) {
                $table->timestamp('failure_notified_at')->nullable()->after('consecutive_failures');
            }
        });

        Schema::table('agencies', function (Blueprint $table) {
            if (Schema::hasColumn('agencies', 'communication_failure_alert_threshold')) {
                return;
            }

            $column = $table->unsignedSmallInteger('communication_failure_alert_threshold')->nullable();

            // This migration's filename sorts BEFORE 2026_07_15_000001, which creates
            // communication_first_poll_days. On a fresh ordered replay the anchor does not
            // exist yet, so only position after it when present (incrementally-migrated
            // envs) and otherwise append — column order is cosmetic.
            if (Schema::hasColumn('agencies', 'communication_first_poll_days')) {
                $column->after('communication_first_poll_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('communication_mailboxes', function (Blueprint $table) {
            $table->dropColumn(['last_error', 'last_error_at', 'consecutive_failures', 'failure_notified_at']);
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('communication_failure_alert_threshold');
        });
    }
};
