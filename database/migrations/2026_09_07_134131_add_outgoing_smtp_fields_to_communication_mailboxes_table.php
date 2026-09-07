<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-395 Phase A — outgoing (SMTP) mail fields on the existing agency-held
 * mailbox record, alongside its existing IMAP-read fields. Spec:
 * .ai/specs/at395-outgoing-mail-per-mailbox-smtp.md §2.
 *
 * Purely additive — no existing column is touched, renamed, or re-typed.
 * Every one of the 20 existing rows on QA1 gets outgoing_enabled=false and
 * keeps polling exactly as before; nothing about IMAP reading changes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('communication_mailboxes', function (Blueprint $table) {
            if (!Schema::hasColumn('communication_mailboxes', 'outgoing_enabled')) {
                $table->boolean('outgoing_enabled')->default(false)->after('user_id');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'use_imap_credentials_for_smtp')) {
                $table->boolean('use_imap_credentials_for_smtp')->default(true)->after('outgoing_enabled');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'smtp_host')) {
                $table->string('smtp_host', 255)->nullable()->after('use_imap_credentials_for_smtp');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'smtp_port')) {
                $table->unsignedInteger('smtp_port')->default(587)->after('smtp_host');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'smtp_encryption')) {
                $table->enum('smtp_encryption', ['tls', 'ssl', 'none'])->default('tls')->after('smtp_port');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'smtp_username')) {
                $table->string('smtp_username', 255)->nullable()->after('smtp_encryption');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'smtp_encrypted_password')) {
                $table->text('smtp_encrypted_password')->nullable()->after('smtp_username');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'smtp_from_name')) {
                $table->string('smtp_from_name', 255)->nullable()->after('smtp_encrypted_password');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'outgoing_active')) {
                $table->boolean('outgoing_active')->default(true)->after('smtp_from_name');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'last_send_error')) {
                $table->string('last_send_error', 100)->nullable()->after('outgoing_active');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'last_send_error_at')) {
                $table->timestamp('last_send_error_at')->nullable()->after('last_send_error');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'consecutive_send_failures')) {
                $table->unsignedInteger('consecutive_send_failures')->default(0)->after('last_send_error_at');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'send_failure_notified_at')) {
                $table->timestamp('send_failure_notified_at')->nullable()->after('consecutive_send_failures');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'last_sent_at')) {
                $table->timestamp('last_sent_at')->nullable()->after('send_failure_notified_at');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'last_sent_folder_append_error')) {
                $table->string('last_sent_folder_append_error', 100)->nullable()->after('last_sent_at');
            }
            if (!Schema::hasColumn('communication_mailboxes', 'last_sent_folder_append_at')) {
                $table->timestamp('last_sent_folder_append_at')->nullable()->after('last_sent_folder_append_error');
            }
        });

        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'communication_send_failure_alert_threshold')) {
                $table->unsignedSmallInteger('communication_send_failure_alert_threshold')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('communication_mailboxes', function (Blueprint $table) {
            $table->dropColumn([
                'outgoing_enabled', 'use_imap_credentials_for_smtp', 'smtp_host', 'smtp_port',
                'smtp_encryption', 'smtp_username', 'smtp_encrypted_password', 'smtp_from_name',
                'outgoing_active', 'last_send_error', 'last_send_error_at', 'consecutive_send_failures',
                'send_failure_notified_at', 'last_sent_at', 'last_sent_folder_append_error',
                'last_sent_folder_append_at',
            ]);
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('communication_send_failure_alert_threshold');
        });
    }
};
