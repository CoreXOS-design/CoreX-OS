<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-08/09 (Johan) — the ban root cause, fixed. Enabling outgoing on 20
 * mailboxes and clicking Test Connection on each in quick succession opened
 * ~40 real SMTP+IMAP logins to the same host from one IP within minutes,
 * which is what actually tripped Afrihost's brute-force protection (cPHulk)
 * — not the poller, not bad credentials. See
 * .ai/specs/at33-incremental-poll-rebuild-2026-09-08.md §13.
 *
 * No new table is needed for the throttle STATE itself — that lives in the
 * application cache via Laravel's own RateLimiter facade
 * (App\Services\Communications\MailboxConnectionRateLimiter), keyed by host.
 * These two agency-override columns exist ONLY so the threshold and window
 * are tunable per agency rather than hardcoded, per standing rule.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'communication_test_connection_max_attempts')) {
                $table->unsignedTinyInteger('communication_test_connection_max_attempts')->nullable();
            }
            if (!Schema::hasColumn('agencies', 'communication_test_connection_window_seconds')) {
                $table->unsignedInteger('communication_test_connection_window_seconds')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (Schema::hasColumn('agencies', 'communication_test_connection_max_attempts')) {
                $table->dropColumn('communication_test_connection_max_attempts');
            }
            if (Schema::hasColumn('agencies', 'communication_test_connection_window_seconds')) {
                $table->dropColumn('communication_test_connection_window_seconds');
            }
        });
    }
};
