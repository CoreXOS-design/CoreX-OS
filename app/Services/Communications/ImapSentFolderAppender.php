<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Models\Communications\CommunicationMailbox;
use App\Support\OutboundMailGuard;
use Illuminate\Support\Facades\Log;

/**
 * AT-395 §4 — after a successful per-mailbox send, copy the raw message into
 * that mailbox's own Sent folder over IMAP, using the credentials already on
 * the record. Reuses ImapMailboxPoller's own connect()/resolveSentFolder() —
 * no new folder-detection logic (spec §4).
 *
 * NEVER throws — a Sent-folder append failure must not fail (or appear to
 * fail) the send that already succeeded. Callers check the boolean/reason
 * return instead of wrapping this in try/catch for control flow.
 *
 * AT-URGENT-2026-09-08 — this write is a raw IMAP connection with the
 * mailbox's real credentials; it has no relationship to Illuminate\Mail and
 * so cannot be seen by OutboundMailGuardServiceProvider's MessageSending
 * listener. Confirmed by packet capture: Test Connection was writing a real
 * message into a real agency mailbox's real Sent folder from QA1, despite
 * the SMTP leg of the same test being correctly blocked. Gating it here,
 * centrally, covers every current and future caller in one place — no
 * caller can reintroduce this by skipping a per-controller check.
 */
class ImapSentFolderAppender
{
    public function __construct(
        private ImapMailboxPoller $poller,
        private MailFailureClassifier $failureClassifier = new MailFailureClassifier(),
    ) {
    }

    /**
     * 2026-09-09 (Johan, diagnostics) — $detail is the RAW server/socket text
     * behind $reason, never discarded, so a caller can persist it for an
     * engineer to see exactly what the server said. Null when $ok is true or
     * when nothing was actually attempted (intercepted/incomplete_credentials).
     *
     * @return array{ok: bool, reason: ?string, detail: ?string}
     */
    public function append(CommunicationMailbox $mailbox, string $rawMime): array
    {
        if (OutboundMailGuard::isActive()) {
            // 2026-09-09 — was 'blocked_non_production', but isActive() can now
            // also be true on production (a super admin forced interception on
            // during an incident) — the old name would have been a lie exactly
            // there. 'intercepted' is true regardless of why.
            Log::warning('OUTBOUND MAIL INTERCEPTED', [
                'app_env' => config('app.env'),
                'app_url' => config('app.url'),
                'mailbox_id' => $mailbox->id,
                'action' => 'imap_sent_folder_append',
            ]);

            return ['ok' => false, 'reason' => 'intercepted', 'detail' => null];
        }

        // 2026-09-09 (Johan, real-attempt-honesty incident) — was
        // empty($x) / ($x ?: $y), both of which treat a literal "0" as
        // absent — the `?:` fallback would even silently swap in the WRONG
        // password for a resolvedSmtpPassword() of "0". blank() is the
        // correct "is this actually unset" test (null or whitespace-only),
        // not PHP falsiness.
        $imapPassword = $mailbox->resolvedSmtpPassword();
        if (blank($imapPassword)) {
            $imapPassword = $mailbox->encrypted_password;
        }
        if (blank($mailbox->imap_host) || blank($mailbox->username) || blank($imapPassword)) {
            return ['ok' => false, 'reason' => 'incomplete_credentials', 'detail' => null];
        }

        try {
            $client = $this->poller->connect($mailbox);
        } catch (\Throwable $e) {
            $real = $this->poller->unwrapRealMessage($e);
            Log::warning("AT-395 Sent-folder append: connect failed (mailbox {$mailbox->id}): {$real}");
            return ['ok' => false, 'reason' => $this->failureClassifier->classifyConnect($real), 'detail' => $real];
        }

        try {
            $sent = $this->poller->resolveSentFolder($client);
            if (! $sent) {
                return ['ok' => false, 'reason' => 'no_sent_folder', 'detail' => null];
            }

            $sent->appendMessage($rawMime, ['\\Seen']);

            return ['ok' => true, 'reason' => null, 'detail' => null];
        } catch (\Throwable $e) {
            Log::warning("AT-395 Sent-folder append: write failed (mailbox {$mailbox->id}): {$e->getMessage()}");
            return ['ok' => false, 'reason' => 'append_failed', 'detail' => $e->getMessage()];
        }
    }
}
