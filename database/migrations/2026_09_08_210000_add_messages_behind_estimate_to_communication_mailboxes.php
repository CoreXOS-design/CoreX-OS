<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-08 (Johan, part B) — the field the honest "Behind" health state
 * reads. Set when a poll is cut off by our own time budget (never by a
 * connect/auth failure — those clear it), holding an approximate count of
 * messages still unprocessed above the checkpoint at the moment the budget
 * fired. Cleared to null on a fully successful poll (nothing left to be
 * behind on) and on a genuine connect/auth failure (a broken mailbox isn't
 * "behind", it's broken — showing a stale backlog count alongside Failing
 * would just be a second, quieter version of the same lie this fix removes).
 * See .ai/specs/at33-incremental-poll-rebuild-2026-09-08.md §2B.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('communication_mailboxes', function (Blueprint $table) {
            if (!Schema::hasColumn('communication_mailboxes', 'messages_behind_estimate')) {
                $table->unsignedInteger('messages_behind_estimate')->nullable()->after('last_poll_duration_seconds');
            }
        });
    }

    public function down(): void
    {
        Schema::table('communication_mailboxes', function (Blueprint $table) {
            if (Schema::hasColumn('communication_mailboxes', 'messages_behind_estimate')) {
                $table->dropColumn('messages_behind_estimate');
            }
        });
    }
};
