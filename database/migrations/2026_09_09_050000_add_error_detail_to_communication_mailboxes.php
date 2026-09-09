<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-09 (Johan) — "fails should tell whoever is setting it up why its
 * failing. not failed. same way outlook would do it... tell us why the
 * server is rejecting the connection." last_error / last_send_error /
 * last_sent_folder_append_error already carry a stable REASON CODE (e.g.
 * 'auth_failed'); these new columns carry the RAW server/socket text behind
 * that classification, verbatim, so an engineer never again has to
 * reconstruct it from scratch the way a bare "connect_failed" cost two days
 * this week. See MailFailureClassifier for where the friendly label comes
 * from and CommunicationMailbox::lastErrorDetail() / lastSendErrorDetail() /
 * lastSentFolderAppendErrorDetail() for how it's exposed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('communication_mailboxes', function (Blueprint $table) {
            $table->text('last_error_detail')->nullable()->after('last_error');
            $table->text('last_send_error_detail')->nullable()->after('last_send_error');
            $table->text('last_sent_folder_append_error_detail')->nullable()->after('last_sent_folder_append_error');
        });
    }

    public function down(): void
    {
        Schema::table('communication_mailboxes', function (Blueprint $table) {
            $table->dropColumn(['last_error_detail', 'last_send_error_detail', 'last_sent_folder_append_error_detail']);
        });
    }
};
