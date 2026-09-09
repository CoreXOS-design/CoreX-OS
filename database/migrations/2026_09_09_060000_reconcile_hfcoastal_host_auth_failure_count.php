<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09-09 (Johan, real-attempt-honesty incident) — data reconciliation,
 * not a schema change. Records, permanently and auditably, why the
 * mail.hfcoastal.co.za auth-failure count is 2, not 1 or 0.
 *
 * WHAT HAPPENED: Johan clicked Test Connection on mailbox 12
 * (johan@hfcoastal.co.za, imap_host === smtp_host === mail.hfcoastal.co.za)
 * on Staging at 2026-09-09 15:14 SAST. That one click made TWO real,
 * unintercepted login attempts against the real Afrihost server — one SMTP
 * (PerMailboxMailTransportBuilder::send), one IMAP
 * (ImapSentFolderAppender::append) — both failed with a genuine 535 /
 * AUTHENTICATIONFAILED. Afrihost's own absolute cap is 3 failed logins,
 * full stop, cannot be raised.
 *
 * CoreX's own counter recorded only 1: the IMAP leg's failure was correctly
 * classified 'auth_failed' and counted; the SMTP leg's failure was
 * misclassified 'unknown' by MailFailureClassifier (its phrase list didn't
 * recognise Afrihost's exact wording, "535 Incorrect authentication data" —
 * see MailFailureClassifier's containsResponseCode() fix, same commit) and
 * HostCircuitBreaker::recordAuthFailureIfApplicable()'s old
 * `$reason !== 'auth_failed'` check silently discarded anything it didn't
 * recognise — see that method's rewrite, same commit.
 *
 * TRUE FIGURE: 2 of Afrihost's real 3 attempts spent, 1 remaining. This
 * migration sets the count to that true figure — never to zero, that would
 * be re-inventing the same undercount this incident is about. As of this
 * migration the row is ALSO already emergency-locked (auth_locked_at set
 * directly on Staging and QA1 on 2026-09-09, ahead of this code landing,
 * because the risk was live and a code deploy could not wait) — this
 * migration is the durable, deployed record of that same true figure, not
 * a new action. idempotent: only advances the count, never lowers it, and
 * never touches an already-locked row's lock state.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('communication_host_circuit_breakers')
            ->where('host', 'mail.hfcoastal.co.za')
            ->first();

        if (! $existing) {
            DB::table('communication_host_circuit_breakers')->insert([
                'host' => 'mail.hfcoastal.co.za',
                'state' => 'closed',
                'auth_failure_count' => 2,
                'auth_locked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        // Never lower an existing count — only correct an undercount up to
        // the true figure, and only lock if not already locked.
        DB::table('communication_host_circuit_breakers')
            ->where('host', 'mail.hfcoastal.co.za')
            ->update([
                'auth_failure_count' => max((int) $existing->auth_failure_count, 2),
                'auth_locked_at' => $existing->auth_locked_at ?? now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Deliberately no-op. Reversing this would mean re-forgetting a real
        // spent Afrihost login attempt — exactly the mistake this migration
        // exists to correct. Clearing the lock is a human decision
        // (HostCircuitBreaker::resetAuthLock(), via the Compliance screen's
        // "Reset login lock" action), never an automatic migration rollback.
    }
};
