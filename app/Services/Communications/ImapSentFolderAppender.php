<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Models\Communications\CommunicationMailbox;
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
 */
class ImapSentFolderAppender
{
    public function __construct(private ImapMailboxPoller $poller)
    {
    }

    /** @return array{ok: bool, reason: ?string} */
    public function append(CommunicationMailbox $mailbox, string $rawMime): array
    {
        if (empty($mailbox->imap_host) || empty($mailbox->username) || empty($mailbox->resolvedSmtpPassword() ?: $mailbox->encrypted_password)) {
            return ['ok' => false, 'reason' => 'incomplete_credentials'];
        }

        try {
            $client = $this->poller->connect($mailbox);
        } catch (\Throwable $e) {
            Log::warning("AT-395 Sent-folder append: connect failed (mailbox {$mailbox->id}): {$e->getMessage()}");
            return ['ok' => false, 'reason' => $this->classify($e)];
        }

        try {
            $sent = $this->poller->resolveSentFolder($client);
            if (! $sent) {
                return ['ok' => false, 'reason' => 'no_sent_folder'];
            }

            $sent->appendMessage($rawMime, ['\\Seen']);

            return ['ok' => true, 'reason' => null];
        } catch (\Throwable $e) {
            Log::warning("AT-395 Sent-folder append: write failed (mailbox {$mailbox->id}): {$e->getMessage()}");
            return ['ok' => false, 'reason' => 'append_failed'];
        }
    }

    private function classify(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());
        foreach (['authenticat', 'login', 'credential', 'password', 'invalid user', 'auth failed'] as $needle) {
            if (str_contains($msg, $needle)) {
                return 'auth_failed';
            }
        }

        return 'connect_failed';
    }
}
