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
    /**
     * Fallback per-file ceiling when config is unavailable. The live value is
     * communications.attachments.max_attachment_bytes — see attachmentCap().
     */
    private const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private EmailArchiveIngestor $ingestor,
        private MailboxHealthRecorder $health = new MailboxHealthRecorder(),
        ?ContactIdentifierResolver $contactResolver = null,
        ?CommunicationIngestFilter $ingestFilter = null,
    ) {
        // 2026-09-08 (items 2/3, headers-first + filter-before-fetch) — nullable
        // with a container fallback so every existing call site (real app code
        // always resolves via the container; the two test doubles in
        // MailboxHealthTest.php/ImapPollReadTimeoutTest.php construct anonymous
        // subclasses with `new class(app(EmailArchiveIngestor::class))` — a single
        // positional arg) keeps working unchanged.
        $this->contactResolver = $contactResolver ?? app(ContactIdentifierResolver::class);
        $this->ingestFilter = $ingestFilter ?? app(CommunicationIngestFilter::class);
    }

    private ContactIdentifierResolver $contactResolver;
    private CommunicationIngestFilter $ingestFilter;

    public function poll(CommunicationMailbox $mailbox): array
    {
        $stats = ['archived' => 0, 'pending' => 0, 'duplicate' => 0, 'errors' => 0, 'folders' => 0, 'dropped' => 0];
        $pollStartedForDuration = now();

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
            // 2026-09-08 fix — webklex wraps the REAL socket/TLS error (e.g. "Connection timed
            // out", the actual $errno/$errstr from stream_socket_client) inside a generic outer
            // exception whose own message is a hardcoded "connection failed"/"connection setup
            // failed". Logging $e->getMessage() alone threw that real reason away and left every
            // failure looking identical — confirmed the root cause of this morning's live
            // incident taking hours to diagnose from logs that only ever said two words.
            $real = $this->unwrapRealMessage($e);
            Log::error("Communication archive IMAP connect failed (mailbox {$mailbox->id}, {$mailbox->imap_host}:{$mailbox->imap_port}): {$real}");
            // Health (AT-181): distinguish a login rejection from an unreachable/failed connect so
            // the admin sees the actionable reason. Recorded BEFORE any last_polled_at stamp.
            // 2026-09-08 fix — classify() now sees the REAL unwrapped message, not the generic
            // wrapper, so it can actually recognise "timed out" wording that was previously
            // invisible to it; and the returned reason was hardcoded to 'connect_failed'
            // regardless of what got classified — fixed to match what was actually recorded.
            $classified = $this->classifyConnectError($real);
            $this->health->recordFailure($mailbox, $classified);
            return ['status' => 'error', 'reason' => $classified, 'stats' => $stats];
        }

        // Stamp progress up-front so a stuck read can't re-trigger a full backfill
        // next cycle (last_polled_at drives the badge's staleness/health signal
        // ONLY — the per-folder watermarks below drive the actual query boundary
        // and are handled completely separately, see the CRITICAL note there).
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
                $isInbound = $direction === Communication::DIRECTION_INBOUND;
                $stats['folders']++;

                // 2026-09-08 (Johan, replacing the same-day timestamp+overlap design) —
                // UID-BASED INCREMENTAL POLLING. Every message has a permanent,
                // monotonically increasing UID, scoped by the folder's UIDVALIDITY —
                // the same mechanism every serious IMAP client (Outlook, Apple Mail,
                // Thunderbird) uses to synchronise without losing mail. No clock, no
                // timezone, no overlap window, no gap: a message arriving mid-read
                // simply gets a higher UID than anything we've asked for and is
                // collected on the next pass, deterministically — not "probably,
                // within the lookback window."
                //
                // UIDVALIDITY IS CHECKED EVERY POLL, not just conceptually at "first
                // poll" time — a folder can be rebuilt/migrated between any two polls.
                // If it changed, every UID we hold for this folder is meaningless and
                // MUST NOT be trusted (Johan: "getting this wrong loses mail
                // permanently and invisibly"). On a mismatch: log loudly, discard the
                // stored cursor, and fall through to the same bounded first-poll
                // backfill window used for a genuinely new folder — never an
                // unbounded rescan back to message 1, and never a silent continue
                // against numbers that may now point at completely different
                // messages.
                $status_ = $folder->status();
                $currentUidValidity = (int) ($status_['uidvalidity'] ?? 0);
                $storedUidValidity = $isInbound ? $mailbox->inbox_uid_validity : $mailbox->sent_uid_validity;
                $storedUid = $isInbound ? $mailbox->last_uid_seen : $mailbox->sent_last_uid;

                if ($storedUidValidity !== null && $currentUidValidity !== 0 && (int) $storedUidValidity !== $currentUidValidity) {
                    Log::error("Communication archive: UIDVALIDITY changed for mailbox {$mailbox->id} folder {$folderName} (stored={$storedUidValidity}, current={$currentUidValidity}) — every stored UID is now untrustworthy, forcing a bounded resync rather than risk silently skipping mail.");
                    $storedUid = null; // discard — cannot trust it against the new numbering
                }

                $usingUidCursor = $storedUid !== null && $currentUidValidity !== 0;

                try {
                    if ($usingUidCursor) {
                        // The exact resume point every serious IMAP client uses.
                        // whereUid() is NOT used here deliberately: it routes a
                        // non-numeric value (a "n:*" range) through code that wraps
                        // it in quotes, which is invalid IMAP syntax for a UID range
                        // and would break the search — confirmed by reading webklex's
                        // query-generation code directly. The "CUSTOM " prefix is the
                        // library's own escape hatch for an unquoted raw criterion.
                        $nextUid = ((int) $storedUid) + 1;
                        $messages = $folder->query()->where('CUSTOM UID ' . $nextUid . ':*')->setFetchBody(false)->get();
                    } else {
                        // No usable UID cursor (first-ever poll for this folder, or a
                        // just-detected UIDVALIDITY change) — the same bounded,
                        // agency-configurable backfill window as before (default 7
                        // days), so a first catch-up can never try to swallow a
                        // mailbox's entire history at once.
                        $since = now()->subDays($this->firstPollBackfillDays($mailbox));
                        $messages = $folder->query()->since($since)->setFetchBody(false)->get();
                    }
                } catch (ImapPollTimeoutException $e) {
                    throw $e;
                } catch (\Webklex\PHPIMAP\Exceptions\GetMessagesFailedException $e) {
                    Log::info("Communication archive IMAP search empty (mailbox {$mailbox->id}, {$folderName}): {$e->getMessage()}");
                    // An empty search still completed cleanly — advance this folder's
                    // cursor. CRITICAL: only reached because nothing threw above.
                    $this->advanceUidCursor($mailbox, $isInbound, $storedUid, $currentUidValidity, (int) ($status_['uidnext'] ?? 1));
                    continue;
                }

                // Highest UID actually returned by the search, tracked regardless of
                // what happens to each message below (kept, dropped, duplicate,
                // error) — the cursor advances on "we have looked at this range",
                // exactly like every other IMAP client's sync state, not on "we
                // archived something."
                $maxUidThisRun = $storedUid !== null ? (int) $storedUid : 0;

                foreach ($messages as $liteMessage) {
                    try {
                        $uid = (int) $liteMessage->getUid();
                        // Tracked BEFORE anything that could throw below — the cursor
                        // must reflect "we have looked at this UID" regardless of
                        // whether it turned out to be kept, dropped, a duplicate, or
                        // an error on our side. Only a budget timeout partway through
                        // this loop stops the cursor from including it (see the
                        // non-negotiable invariant at the advanceUidCursor() call
                        // site below).
                        $maxUidThisRun = max($maxUidThisRun, $uid);

                        // 2026-09-08 (items 2/3, Johan) — HEADERS FIRST. Fetch only the
                        // header (no body, no attachments — see PeekingMessageFetcher::
                        // peekHeader()) and decide from that alone before ever paying for
                        // the full message. A Property24 no-reply now costs one small
                        // header fetch, never a body+attachment download.
                        $headerMsg = PeekingMessageFetcher::peekHeader($client, $uid, $folderName);
                        if ($headerMsg === null) {
                            $stats['errors']++;
                            Log::warning("Communication archive: header peek returned no content (mailbox {$mailbox->id}, uid {$uid})");
                            continue;
                        }

                        // Dedup FIRST, on the header alone (Message-ID needs no body) —
                        // the cheapest possible check, and the one the overlap window
                        // depends on entirely. Same check ingest() itself uses
                        // (EmailArchiveIngestor::isAlreadySeen() wraps the identical
                        // private alreadySeen()) — never a second, drifting
                        // implementation of "have we seen this."
                        $messageId = $this->safe(fn () => (string) $headerMsg->getMessageId()) ?: '';
                        if ($messageId !== '' && $this->ingestor->isAlreadySeen((int) $mailbox->agency_id, $messageId)) {
                            $stats['duplicate']++;
                            continue;
                        }

                        // Known-contact gate, same matching EmailArchiveIngestor uses
                        // (ContactIdentifierResolver) — checked here, on the header
                        // alone, so a no-reply/service-domain sender who is NOT a known
                        // contact can be dropped before the full fetch. A sender who
                        // MATCHES a contact always gets the full fetch regardless (never
                        // let a real client's mail be filtered by a no-reply heuristic).
                        $from = $this->firstAddress($headerMsg, 'getFrom') ?? '';
                        $contact = $from !== '' ? $this->contactResolver->resolve($from, (int) $mailbox->agency_id) : null;

                        if (!$contact) {
                            $dropReason = $this->ingestFilter->dropReasonForUnknown($from, $mailbox->agency);
                            if ($dropReason !== null) {
                                $stats['dropped']++;
                                Log::info('Communication archive: ingestion dropped before fetch (header-stage filter)', [
                                    'agency_id'  => $mailbox->agency_id,
                                    'mailbox_id' => $mailbox->id,
                                    'direction'  => $direction,
                                    'sender'     => $from,
                                    'reason'     => $dropReason,
                                ]);
                                continue;
                            }
                        }

                        // Reserved for mail we're actually going to keep (a known
                        // contact -> archived) or might keep (genuinely unknown, not
                        // no-reply/service -> held for review, item 4). Everything that
                        // would just be dropped or was already seen never reaches here.
                        $message = PeekingMessageFetcher::peek($client, $uid, $folderName);
                        if ($message === null) {
                            $stats['errors']++;
                            Log::warning("Communication archive: peek fetch returned no content (mailbox {$mailbox->id}, uid {$uid})");
                            continue;
                        }
                        $normalized = $this->normalize($message, $direction);
                        try {
                            $result = $this->ingestor->ingest($mailbox, $normalized, $direction);
                            $stats[$result] = ($stats[$result] ?? 0) + 1;
                        } finally {
                            // Attachments are spooled to temp files (see attachments());
                            // free them whatever the outcome so a long poll cannot fill
                            // the disk with the payloads we kept out of memory.
                            $this->discardSpooled($normalized['attachments'] ?? []);
                        }
                    } catch (ImapPollTimeoutException $e) {
                        throw $e; // the budget fired mid-message — abort the whole poll
                    } catch (\Throwable $e) {
                        // One bad message must never block the rest or crash the worker.
                        $stats['errors']++;
                        Log::error("Communication archive ingest error (mailbox {$mailbox->id}): {$e->getMessage()}");
                    }
                }

                // This folder's ENTIRE message loop ran to completion with nothing
                // throwing — genuinely done, safe to advance ITS UID cursor now.
                //
                // CRITICAL (Johan, non-negotiable): if the budget watchdog fires
                // ANYWHERE above — mid-search, mid-header-peek, mid-full-peek,
                // mid-ingest — ImapPollTimeoutException propagates straight through
                // this line without ever reaching it. This folder's stored UID is
                // NOT advanced, is left exactly as it was, and the next poll asks
                // the server for the exact same "UID {old+1}:*" range again — the
                // same messages, not skipped, not silently dropped. A failed or
                // interrupted poll can only ever leave the cursor unchanged or
                // advance it on genuine completion — there is no path that advances
                // it on partial/failed work.
                $this->advanceUidCursor($mailbox, $isInbound, $maxUidThisRun > 0 ? $maxUidThisRun : null, $currentUidValidity, (int) ($status_['uidnext'] ?? 1));
            }
        } catch (ImapPollTimeoutException $e) {
            // A non-responsive folder read tripped the budget. Clean, logged
            // error — never a TimeoutExceededException from the queue worker.
            Log::error("Communication archive IMAP poll timed out (mailbox {$mailbox->id}, {$mailbox->imap_host}:{$mailbox->imap_port}): {$e->getMessage()}");
            $status = 'error';
            $reason = 'read_timeout';
        } finally {
            $this->stopWatchdog($started);
            try { $client->disconnect(); } catch (\Throwable $e) { /* ignore */ }
            $mailbox->forceFill(['last_polled_at' => now()])->save();
        }

        // One-time backfill marker (item 7) — purely informational/audit (the
        // actual incremental-vs-backfill decision is already driven by
        // per-folder UID-cursor presence above, which is more robust than a
        // single mailbox-wide flag would be). Stamped once every ENABLED
        // folder has a real UIDVALIDITY recorded, i.e. the initial catch-up
        // is genuinely done for the whole mailbox.
        if (!$mailbox->backfill_completed_at
            && (!$mailbox->poll_inbox || $mailbox->inbox_uid_validity !== null)
            && (!$mailbox->poll_sent || $mailbox->sent_uid_validity !== null)) {
            $mailbox->forceFill(['backfill_completed_at' => now()])->save();
        }

        // Fairness (item 6) — wall-clock cost of this exact poll, whatever the
        // outcome. PollMailboxes reads this to route a chronically slow
        // mailbox onto its own queue instead of sharing a worker slot with
        // well-behaved ones.
        $mailbox->forceFill(['last_poll_duration_seconds' => $pollStartedForDuration->diffInSeconds(now())])->save();

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

    /**
     * Classify a connect failure into an actionable reason (auth rejection vs a genuine
     * timeout vs an outright connect failure). Takes the already-UNWRAPPED real message
     * (see unwrapRealMessage()) — classifying webklex's generic outer wrapper text
     * ("connection failed") could never distinguish anything, since that literal string
     * carries no information about what actually happened underneath.
     *
     * 2026-09-08 fix — 'connect_timeout' added. Before this, a genuine timeout (the
     * server not answering within communications.imap_timeout_seconds) fell into the
     * same 'connect_failed' bucket as "the host is wrong"/"the server is down", which is
     * exactly how a mailbox with a large, slow-to-read backlog stayed permanently
     * mislabelled as broken even though nothing was actually wrong with its credentials.
     */
    private function classifyConnectError(string $realMessage): string
    {
        $msg = strtolower($realMessage);
        foreach (['authenticat', 'login', 'credential', 'password', 'invalid user', 'auth failed'] as $needle) {
            if (str_contains($msg, $needle)) {
                return 'auth_failed';
            }
        }
        foreach (['timed out', 'timeout', 'operation now in progress', 'etimedout'] as $needle) {
            if (str_contains($msg, $needle)) {
                return 'connect_timeout';
            }
        }

        return 'connect_failed';
    }

    /**
     * webklex wraps the real underlying exception (the actual $errno/$errstr from
     * stream_socket_client — e.g. "Connection timed out", "Connection refused") inside
     * an outer exception whose OWN message is a hardcoded generic string ("connection
     * failed"/"connection setup failed"), keeping the real one only as getPrevious().
     * Walks the full chain to the deepest available message — never just the two-word
     * wrapper — so both the log and the failure classification see what actually
     * happened, not a label that means nothing on its own.
     */
    private function unwrapRealMessage(\Throwable $e): string
    {
        $current = $e;
        while ($current->getPrevious() !== null) {
            $current = $current->getPrevious();
        }

        return $current->getMessage() !== '' ? $current->getMessage() : $e->getMessage();
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
     * 2026-09-08 (Johan) — advance ONE folder's UID cursor + UIDVALIDITY.
     * Only ever called after that folder's entire message loop ran to
     * completion with nothing throwing — see the call site's own comment for
     * the non-negotiable invariant this protects: an interrupted or failed
     * poll never reaches this method, so the stored UID can only ever stay
     * unchanged or advance on genuine completion.
     *
     * $maxUid is null when the search returned zero messages AND there was no
     * prior stored UID to fall back to (an empty folder on its first poll, or
     * immediately after a UIDVALIDITY reset with nothing new since) — in that
     * case the cursor is set to $uidNext - 1, the correct "nothing to catch up
     * on, the next genuinely new message will be $uidNext" position, per RFC
     * 3501's own guarantee about what UIDNEXT means. Never left null/0
     * outright, which would make the NEXT poll's "UID 1:*" scan the entire
     * folder history.
     */
    private function advanceUidCursor(CommunicationMailbox $mailbox, bool $isInbound, ?int $maxUid, int $uidValidity, int $uidNext): void
    {
        $uidColumn = $isInbound ? 'last_uid_seen' : 'sent_last_uid';
        $validityColumn = $isInbound ? 'inbox_uid_validity' : 'sent_uid_validity';

        $mailbox->forceFill([
            $uidColumn => $maxUid ?? max(0, $uidNext - 1),
            $validityColumn => $uidValidity,
        ])->save();
    }

    /**
     * Connect to the mailbox and harden the live stream against a silent server.
     * Overridable seam so the read-timeout path is testable without a server.
     */
    /**
     * Public (AT-395): reused by ImapSentFolderAppender for the post-send
     * Sent-folder append and Test Connection — same connect logic, not
     * duplicated. No behaviour change from widening this visibility.
     */
    public function connect(CommunicationMailbox $mailbox)
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
    /** Public (AT-395): reused by ImapSentFolderAppender — same reason as connect() above. */
    public function resolveSentFolder($client): ?object
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
            // CX-113 Phase G (Johan, 2026-08-22) — To/Cc were already parsed separately
            // above (needed for $participants); kept here too instead of being merged
            // away, so the archive can show real "To"/"Cc" instead of one flat list with
            // no role.
            'to'           => $to,
            'cc'           => $cc,
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

    /**
     * Extract this message's attachments WITHOUT holding their payloads in the
     * worker's memory.
     *
     * Two things were wrong here and both are fixed:
     *
     * 1. The oversize guard trusted the size the mail server DECLARES. webklex
     *    returns null when a part advertises no size; `?? 0` then read that as
     *    "0 bytes", i.e. never oversized, and the file was decoded in full
     *    anyway. Two 43.7MB video/mp4 attachments were archived that way on
     *    2026-07-24 despite the 25MB cap. The declared size is now a FAST
     *    REJECT ONLY — the authoritative check is strlen() of the decoded bytes.
     *
     * 2. Every attachment was carried decoded in this array while the raw MIME —
     *    which already contains all of them base64-encoded, ~1.33x their real
     *    size — was held alongside it for the same message. Two copies of one
     *    payload on a worker whose usable headroom is ~70MB. Attachments now
     *    spool to a temp file and the ingestor streams from the path, so only
     *    one attachment is ever momentarily resident.
     *
     * Over-ceiling files are not silently dropped: they come back marked
     * 'oversized' and the ingestor records the row (filename + size, no blob) so
     * the archive still shows the file existed. Caller MUST discardSpooled().
     */
    private function attachments($message): array
    {
        $out = [];
        $list = $this->safe(fn () => $message->getAttachments());
        if (! $list) {
            return $out;
        }

        $perFileCap = $this->attachmentCap();
        $totalCap   = $this->messageAttachmentCap();
        $running    = 0;

        foreach ($list as $att) {
            try {
                $filename = $this->safe(fn () => $att->getName());
                $mime     = $this->safe(fn () => $att->getMimeType()) ?? $this->safe(fn () => $att->getContentType());

                // Fast reject on the declared size — can only ever REJECT, never approve.
                $declared = (int) ($this->safe(fn () => $att->getSize()) ?? 0);
                if ($declared > $perFileCap) {
                    $out[] = $this->recordedNotStored($filename, $mime, $declared, $perFileCap, 'attachment_too_large');
                    continue;
                }

                $bytes = (string) ($this->safe(fn () => $att->getContent()) ?? '');
                $size  = strlen($bytes);
                if ($size === 0) {
                    continue;
                }

                // Authoritative cap: the real decoded length, whatever was declared.
                if ($size > $perFileCap) {
                    unset($bytes);
                    $out[] = $this->recordedNotStored($filename, $mime, $size, $perFileCap, 'attachment_too_large');
                    continue;
                }

                // Per-MESSAGE ceiling — ten 4MB files each clear the per-file cap
                // but together exceed the worker budget.
                if ($running + $size > $totalCap) {
                    unset($bytes);
                    $out[] = $this->recordedNotStored($filename, $mime, $size, $totalCap, 'message_attachment_total_exceeded');
                    continue;
                }

                $path = $this->spool($bytes);
                unset($bytes);
                if ($path === null) {
                    continue;
                }
                $running += $size;

                $out[] = [
                    'filename' => $filename,
                    'mime'     => $mime,
                    'path'     => $path,
                    'size'     => $size,
                ];
            } catch (\Throwable $e) {
                Log::info("Communication archive: attachment extract failed: {$e->getMessage()}");
            }
        }

        return $out;
    }

    /** Per-file ceiling (bytes). Config-driven; the class const is the fallback. */
    private function attachmentCap(): int
    {
        return max(1, (int) config('communications.attachments.max_attachment_bytes', self::MAX_ATTACHMENT_BYTES));
    }

    /** Per-message ceiling across all attachments — never below the per-file cap. */
    private function messageAttachmentCap(): int
    {
        return max(
            $this->attachmentCap(),
            (int) config('communications.attachments.max_message_total_bytes', 40 * 1024 * 1024)
        );
    }

    /**
     * An attachment we deliberately do not carry. The row is still created by the
     * ingestor (name, mime, size, media_status=failed) so the file is visible in
     * the archive as "was attached, too large to keep" rather than vanishing.
     */
    private function recordedNotStored(?string $filename, ?string $mime, int $size, int $cap, string $reason): array
    {
        Log::info('Communication archive: attachment recorded but not stored', [
            'filename'   => $filename,
            'size_bytes' => $size,
            'cap_bytes'  => $cap,
            'reason'     => $reason,
        ]);

        return [
            'filename'  => $filename,
            'mime'      => $mime,
            'size'      => $size,
            'oversized' => true,
            'error'     => "{$reason}: {$size} bytes exceeds cap {$cap}",
        ];
    }

    /** Write decoded bytes to a temp file so they leave the worker's heap. */
    private function spool(string $bytes): ?string
    {
        $path = @tempnam(sys_get_temp_dir(), 'corex-att-');
        if ($path === false) {
            Log::warning('Communication archive: attachment spool file could not be created');
            return null;
        }
        if (@file_put_contents($path, $bytes) === false) {
            @unlink($path);
            Log::warning('Communication archive: attachment spool write failed');
            return null;
        }

        return $path;
    }

    /** Delete this message's spooled attachment files. Always runs, success or not. */
    private function discardSpooled(array $attachments): void
    {
        foreach ($attachments as $att) {
            $path = $att['path'] ?? null;
            if (is_string($path) && $path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
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
