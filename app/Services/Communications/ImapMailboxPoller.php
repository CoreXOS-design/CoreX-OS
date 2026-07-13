<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationMailbox;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;

/**
 * IMAP poller for one mailbox (AT-33). Copies the proven connect/poll/dedup
 * pattern from app/Services/P24/P24ImapImportService.php — agency-held creds
 * from the communication_mailboxes row, polls Inbox (inbound) + Sent (outbound),
 * and hands each message to EmailArchiveIngestor.
 *
 * Resilience (BUILD_STANDARD): a connection failure logs + returns; a single
 * malformed/oversized message logs + is skipped without blocking the rest or
 * crashing the worker.
 */
class ImapMailboxPoller
{
    /** Skip attachments larger than this many bytes (keep, but don't store the blob). */
    private const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private EmailArchiveIngestor $ingestor,
        private MailboxHealthRecorder $health = new MailboxHealthRecorder(),
    ) {
    }

    public function poll(CommunicationMailbox $mailbox): array
    {
        $stats = ['archived' => 0, 'pending' => 0, 'duplicate' => 0, 'errors' => 0, 'folders' => 0];

        if (! $mailbox->active) {
            return ['status' => 'skipped', 'reason' => 'inactive', 'stats' => $stats];
        }
        if (empty($mailbox->imap_host) || empty($mailbox->username) || empty($mailbox->encrypted_password)) {
            // Health (AT-181): a config-incomplete mailbox is failing — record it (no last_polled_at
            // stamp; it never connected, so that ground-truth signal stays honest).
            $this->health->recordFailure($mailbox, 'incomplete_credentials');
            return ['status' => 'error', 'reason' => 'incomplete_credentials', 'stats' => $stats];
        }

        try {
            $client = $this->connect($mailbox);
        } catch (\Throwable $e) {
            Log::error("Communication archive IMAP connect failed (mailbox {$mailbox->id}): {$e->getMessage()}");
            // Health (AT-181): distinguish a login rejection from an unreachable/failed connect so
            // the admin sees the actionable reason. Recorded BEFORE any last_polled_at stamp.
            $this->health->recordFailure($mailbox, $this->classifyConnectError($e));
            return ['status' => 'error', 'reason' => 'connect_failed', 'stats' => $stats];
        }

        // Incremental polls read since last_polled_at (1-day overlap so a message
        // near the boundary is never missed). The FIRST poll backfills a small,
        // agency-configurable window (default 7d) so the initial read fits the
        // budget instead of trapping the mailbox in a never-completing 30-day pull.
        $since = $mailbox->last_polled_at
            ? Carbon::parse($mailbox->last_polled_at)->subDay()->startOfDay()
            : now()->subDays($this->firstPollBackfillDays($mailbox));

        // Stamp progress up-front, now that the connection succeeded and $since is
        // fixed from the PRE-poll value. If the folder read later exceeds the budget
        // and the worker is killed before `finally`, the mailbox must not re-attempt
        // the full backfill every cycle — the next poll reads incrementally instead.
        $mailbox->forceFill(['last_polled_at' => now()])->save();

        // Resolve actual Folder objects up front. Sent is resolved by its IMAP
        // SPECIAL-USE \Sent flag (RFC 6154), not by name-guessing — a mailbox
        // can have several "Sent"-named folders (e.g. Afrihost INBOX.Sent + an
        // empty client-local one), and leaf-name matching grabbed the wrong one,
        // losing all outbound capture (AT-43).
        $folders = []; // [ ['folder'=>Folder, 'direction'=>..., 'label'=>string] ]
        if ($mailbox->poll_inbox) {
            $inbox = $this->resolveFolder($client, ['INBOX']);
            if ($inbox) {
                $folders[] = ['folder' => $inbox, 'direction' => Communication::DIRECTION_INBOUND, 'label' => $inbox->path ?? 'INBOX'];
            }
        }
        if ($mailbox->poll_sent) {
            $sent = $this->resolveSentFolder($client);
            if ($sent) {
                $folders[] = ['folder' => $sent, 'direction' => Communication::DIRECTION_OUTBOUND, 'label' => $sent->path ?? 'Sent'];
            } else {
                Log::warning("Communication archive: no Sent folder resolved (mailbox {$mailbox->id})");
            }
        }

        // Hard time budget so a non-responsive folder read can never spin to the
        // queue job timeout (webklex's stream timeout is unreliable on a TLS
        // stream). The watchdog throws ImapPollTimeoutException; we log + stop.
        $budget  = max(1, (int) config('communications.imap_poll_budget_seconds', 50));
        $started = $this->startWatchdog($budget, (int) $mailbox->id);
        $status  = 'success';
        $reason  = null;

        try {
            foreach ($folders as $entry) {
                $folder    = $entry['folder'];
                $direction = $entry['direction'];
                $folderName = $entry['label'];
                $stats['folders']++;

                try {
                    // AT-257: fetch UIDs only (setFetchBody(false) — no body fetch, so the
                    // server never sets \Seen). Each body is then pulled with a TRUE BODY.PEEK
                    // below (PeekingMessageFetcher), so archiving never marks a message read —
                    // not even if a message parse / the poll budget / the connection is
                    // interrupted mid-message (the proven cause of AT-257).
                    $messages = $folder->query()->since($since)->setFetchBody(false)->get();
                } catch (ImapPollTimeoutException $e) {
                    throw $e;
                } catch (\Webklex\PHPIMAP\Exceptions\GetMessagesFailedException $e) {
                    Log::info("Communication archive IMAP search empty (mailbox {$mailbox->id}, {$folderName}): {$e->getMessage()}");
                    continue;
                }

                foreach ($messages as $liteMessage) {
                    try {
                        $uid = (int) $liteMessage->getUid();
                        // AT-257 — non-destructive read: BODY.PEEK[], never sets \Seen.
                        $message = \App\Services\Communications\PeekingMessageFetcher::peek($client, $uid);
                        if ($message === null) {
                            $stats['errors']++;
                            Log::warning("Communication archive: peek fetch returned no content (mailbox {$mailbox->id}, uid {$uid})");
                            continue;
                        }
                        $normalized = $this->normalize($message, $direction);
                        $result = $this->ingestor->ingest($mailbox, $normalized, $direction);
                        $stats[$result] = ($stats[$result] ?? 0) + 1;
                    } catch (ImapPollTimeoutException $e) {
                        throw $e; // the budget fired mid-message — abort the whole poll
                    } catch (\Throwable $e) {
                        // One bad message must never block the rest or crash the worker.
                        $stats['errors']++;
                        Log::error("Communication archive ingest error (mailbox {$mailbox->id}): {$e->getMessage()}");
                    }
                }
            }
        } catch (ImapPollTimeoutException $e) {
            // A non-responsive folder read tripped the budget. Clean, logged
            // error — never a TimeoutExceededException from the queue worker.
            Log::error("Communication archive IMAP poll timed out (mailbox {$mailbox->id}): {$e->getMessage()}");
            $status = 'error';
            $reason = 'read_timeout';
        } finally {
            $this->stopWatchdog($started);
            try { $client->disconnect(); } catch (\Throwable $e) { /* ignore */ }
            $mailbox->forceFill(['last_polled_at' => now()])->save();
        }

        // Health (AT-181). A fully successful poll clears the failure state. A read_timeout is a
        // POST-AUTH failure — the connect + login succeeded (so last_polled_at legitimately
        // advanced in `finally`), but the folder read did not complete; we still record it as a
        // failed poll (labelled 'read_timeout', distinct from an auth/connect failure) so the
        // badge shows Failing and a mailbox that stalls every cycle raises the admin alert.
        // Per-message parse errors ($stats['errors']) do NOT fail the mailbox — auth + read worked.
        if ($status === 'success') {
            $this->health->recordSuccess($mailbox);
        } else {
            $this->health->recordFailure($mailbox, $reason ?? 'poll_failed');
        }

        return ['status' => $status, 'reason' => $reason, 'stats' => $stats];
    }

    /** Classify a connect exception into an actionable reason (auth rejection vs connect failure). */
    private function classifyConnectError(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());
        foreach (['authenticat', 'login', 'credential', 'password', 'invalid user', 'auth failed'] as $needle) {
            if (str_contains($msg, $needle)) {
                return 'auth_failed';
            }
        }

        return 'connect_failed';
    }

    /**
     * First-poll backfill window (days): agency override
     * (agencies.communication_first_poll_days) ?? config default (7). Clamped to
     * [1, 90]. Mirrors CommunicationPending::graceDays. Never hardcoded.
     */
    private function firstPollBackfillDays(CommunicationMailbox $mailbox): int
    {
        $override = \App\Models\Agency::where('id', $mailbox->agency_id)->value('communication_first_poll_days');
        $days = (int) ($override ?? config('communications.first_poll_backfill_days', 7));

        return max(1, min(90, $days ?: 7));
    }

    /**
     * Connect to the mailbox and harden the live stream against a silent server.
     * Overridable seam so the read-timeout path is testable without a server.
     */
    protected function connect(CommunicationMailbox $mailbox)
    {
        $timeout = max(1, (int) config('communications.imap_timeout_seconds', 20));

        $client = (new ClientManager([
            'default'  => 'mbx',
            'accounts' => ['mbx' => [
                'host'          => $mailbox->imap_host,
                'port'          => (int) $mailbox->imap_port,
                'protocol'      => 'imap',
                'encryption'    => $mailbox->imap_port == 143 ? 'tls' : 'ssl',
                'username'      => $mailbox->username,
                'password'      => $mailbox->encrypted_password, // decrypted by the model cast
                'validate_cert' => true,
                'timeout'       => $timeout,
            ]],
        ]))->account();
        $client->connect();

        // webklex sets stream_set_timeout() on the raw socket BEFORE enabling
        // crypto, so fread() on the TLS-wrapped stream can ignore it. Re-apply
        // the read timeout on the live stream so a silent server fails the read.
        try {
            $stream = $client->getConnection()->getStream();
            if (is_resource($stream)) {
                stream_set_timeout($stream, $timeout);
            }
        } catch (\Throwable $e) {
            // best effort — the pcntl budget below is the hard backstop
        }

        return $client;
    }

    /**
     * Resolve the outbound "Sent" folder robustly across servers (AT-43).
     *
     * Strategy, in order:
     *   1. IMAP SPECIAL-USE \Sent flag (RFC 6154) from the raw LIST response —
     *      the authoritative, name-independent signal. Works for Gmail
     *      ([Gmail]/Sent Mail), Afrihost, Outlook, etc.
     *   2. Path-aware fallback over common Sent paths, preferring a SELECTABLE,
     *      NON-EMPTY folder (skips empty client-local homonyms).
     * Returns null if nothing usable is found (caller logs + skips outbound).
     */
    protected function resolveSentFolder($client): ?object
    {
        // ── 1. Special-use \Sent ─────────────────────────────────────────────
        $listed = []; // path => flags[]
        try {
            $listed = $client->getConnection()->folders('', '*')->validatedData();
        } catch (ImapPollTimeoutException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $listed = [];
        }

        $specialUse = [];
        foreach ($listed as $path => $meta) {
            $flags = array_map(fn ($f) => strtolower((string) $f), (array) ($meta['flags'] ?? []));
            if (in_array('\\sent', $flags, true) && ! in_array('\\noselect', $flags, true)) {
                $specialUse[] = (string) $path;
            }
        }
        // If multiple advertise \Sent (rare), prefer the non-empty one;
        // firstNonEmptyFolder falls back to the first that resolves at all.
        if ($specialUse) {
            $sent = $this->firstNonEmptyFolder($client, $specialUse);
            if ($sent) {
                return $sent;
            }
        }

        // ── 2. Path-aware fallback (no special-use advertised) ───────────────
        $candidates = (array) config('communications.sent_folder_candidates', [
            'INBOX.Sent', 'Sent', '[Gmail]/Sent Mail', 'Sent Items', 'Sent Mail', 'INBOX.Sent Items',
        ]);
        // Only consider candidates that actually exist + are selectable, in the
        // server's real listing; rank non-empty first so an empty homonym never wins.
        $existing = [];
        foreach ($candidates as $path) {
            if (! array_key_exists($path, $listed)) {
                continue;
            }
            $flags = array_map(fn ($f) => strtolower((string) $f), (array) ($listed[$path]['flags'] ?? []));
            if (in_array('\\noselect', $flags, true)) {
                continue;
            }
            $existing[] = $path;
        }
        // If the LIST was unavailable, fall back to trying the paths blind.
        if (empty($listed)) {
            $existing = $candidates;
        }

        return $this->firstNonEmptyFolder($client, $existing)
            ?? ($existing ? $this->getFolderByPathSafe($client, $existing[0]) : null);
    }

    /**
     * Resolve a folder by trying each path; INBOX uses the canonical lookup.
     */
    protected function resolveFolder($client, array $paths): ?object
    {
        foreach ($paths as $path) {
            $f = $this->getFolderByPathSafe($client, $path);
            if ($f) {
                return $f;
            }
        }
        return null;
    }

    /** Return the first path that resolves to a folder holding ≥1 message. */
    private function firstNonEmptyFolder($client, array $paths): ?object
    {
        $firstResolved = null;
        foreach ($paths as $path) {
            $f = $this->getFolderByPathSafe($client, $path);
            if (! $f) {
                continue;
            }
            $firstResolved = $firstResolved ?? $f;
            try {
                if ($f->query()->all()->count() > 0) {
                    return $f;
                }
            } catch (ImapPollTimeoutException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // can't count — treat as a usable candidate rather than skip
                return $f;
            }
        }
        return $firstResolved;
    }

    private function getFolderByPathSafe($client, string $path): ?object
    {
        try {
            return $client->getFolderByPath($path);
        } catch (ImapPollTimeoutException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Arm a pcntl alarm that throws ImapPollTimeoutException after $seconds.
     * Returns whether the alarm was armed (pcntl present) so stopWatchdog can
     * restore the previous handler. No-op (returns false) when pcntl is absent.
     */
    private function startWatchdog(int $seconds, int $mailboxId): bool
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_alarm')) {
            return false;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($seconds, $mailboxId) {
            throw new ImapPollTimeoutException("mailbox {$mailboxId} read exceeded {$seconds}s budget");
        });
        pcntl_alarm($seconds);

        return true;
    }

    private function stopWatchdog(bool $armed): void
    {
        if (! $armed) {
            return;
        }
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
    }

    /**
     * Extract a normalized message array from a webklex Message. Each accessor is
     * guarded so an odd encoding/header on one field cannot abort the message.
     */
    private function normalize($message, string $direction): array
    {
        $from = $this->firstAddress($message, 'getFrom');
        $to   = $this->addresses($message, 'getTo');
        $cc   = $this->addresses($message, 'getCc');
        $participants = array_values(array_unique(array_filter(array_merge([$from], $to, $cc))));

        $counterpart = $direction === Communication::DIRECTION_INBOUND
            ? $from
            : ($to[0] ?? null);

        $bodyText = $this->safe(fn () => $message->getTextBody())
            ?: $this->stripHtml($this->safe(fn () => $message->getHTMLBody()));

        return [
            'external_id'  => $this->safe(fn () => (string) $message->getMessageId()) ?: '',
            'thread_key'   => $this->threadKey($message),
            'from'         => $from,
            'counterpart'  => $counterpart,
            'participants' => $participants,
            'subject'      => $this->safe(fn () => (string) $message->getSubject()),
            'body_text'    => $bodyText,
            'occurred_at'  => $this->safe(fn () => Carbon::instance($message->getDate()->toDate())) ?: now(),
            'raw'          => $this->rawBytes($message),
            'attachments'  => $this->attachments($message),
        ];
    }

    private function threadKey($message): ?string
    {
        $refs = $this->safe(fn () => (string) $message->getReferences());
        if ($refs) {
            $parts = preg_split('/\s+/', trim($refs));
            return $parts[0] ?? null;
        }
        $inReplyTo = $this->safe(fn () => (string) $message->getInReplyTo());
        if ($inReplyTo) {
            return trim($inReplyTo);
        }
        return $this->safe(fn () => (string) $message->getMessageId()) ?: null;
    }

    private function rawBytes($message): string
    {
        $raw = $this->safe(fn () => $message->getRawBody());
        if ($raw) {
            $headers = $this->safe(fn () => $message->getHeader()->raw) ?? '';
            return $headers ? ($headers . "\r\n\r\n" . $raw) : $raw;
        }
        // Fallback: header raw + decoded body, so we never store nothing.
        $headers = $this->safe(fn () => $message->getHeader()->raw) ?? '';
        $body = $this->safe(fn () => $message->getHTMLBody()) ?: ($this->safe(fn () => $message->getTextBody()) ?? '');
        return trim($headers . "\r\n\r\n" . $body);
    }

    private function attachments($message): array
    {
        $out = [];
        $list = $this->safe(fn () => $message->getAttachments());
        if (! $list) {
            return $out;
        }
        foreach ($list as $att) {
            try {
                $size = (int) ($this->safe(fn () => $att->getSize()) ?? 0);
                if ($size > self::MAX_ATTACHMENT_BYTES) {
                    Log::info("Communication archive: skipping oversized attachment ({$size} bytes)");
                    continue;
                }
                $bytes = (string) ($this->safe(fn () => $att->getContent()) ?? '');
                if ($bytes === '') {
                    continue;
                }
                $out[] = [
                    'filename' => $this->safe(fn () => $att->getName()),
                    'mime'     => $this->safe(fn () => $att->getMimeType()) ?? $this->safe(fn () => $att->getContentType()),
                    'bytes'    => $bytes,
                ];
            } catch (\Throwable $e) {
                Log::info("Communication archive: attachment extract failed: {$e->getMessage()}");
            }
        }
        return $out;
    }

    private function firstAddress($message, string $method): ?string
    {
        $addrs = $this->addresses($message, $method);
        return $addrs[0] ?? null;
    }

    private function addresses($message, string $method): array
    {
        // webklex getFrom()/getTo()/getCc() return an Attribute that is NOT
        // Traversable — a plain foreach yields nothing (AT-40). Extract via the
        // shared EmailAddressExtractor (->all()) so this never regresses and the
        // pending-reprocess command uses identical parsing.
        return $this->safe(fn () => EmailAddressExtractor::normalize($message->{$method}())) ?? [];
    }

    private function stripHtml(?string $html): ?string
    {
        return $html ? trim(html_entity_decode(strip_tags($html))) : null;
    }

    /** Run a webklex accessor, swallowing any parse/encoding error to null. */
    private function safe(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
