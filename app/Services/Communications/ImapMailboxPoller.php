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

    public function __construct(private EmailArchiveIngestor $ingestor)
    {
    }

    public function poll(CommunicationMailbox $mailbox): array
    {
        $stats = ['archived' => 0, 'pending' => 0, 'duplicate' => 0, 'errors' => 0, 'folders' => 0];

        if (! $mailbox->active) {
            return ['status' => 'skipped', 'reason' => 'inactive', 'stats' => $stats];
        }
        if (empty($mailbox->imap_host) || empty($mailbox->username) || empty($mailbox->encrypted_password)) {
            return ['status' => 'error', 'reason' => 'incomplete_credentials', 'stats' => $stats];
        }

        try {
            $client = $this->connect($mailbox);
        } catch (\Throwable $e) {
            Log::error("Communication archive IMAP connect failed (mailbox {$mailbox->id}): {$e->getMessage()}");
            return ['status' => 'error', 'reason' => 'connect_failed', 'stats' => $stats];
        }

        $since = $mailbox->last_polled_at
            ? Carbon::parse($mailbox->last_polled_at)->subDay()->startOfDay()
            : now()->subDays(30);

        $folders = [];
        if ($mailbox->poll_inbox) {
            $folders['INBOX'] = Communication::DIRECTION_INBOUND;
        }
        if ($mailbox->poll_sent) {
            // Sent folder naming varies by server; try the common ones.
            foreach (['Sent', 'INBOX.Sent', 'Sent Items', 'Sent Mail'] as $name) {
                $folders[$name] = Communication::DIRECTION_OUTBOUND;
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
            foreach ($folders as $folderName => $direction) {
                $folder = null;
                try {
                    $folder = $client->getFolderByName($folderName);
                } catch (ImapPollTimeoutException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $folder = null; // folder doesn't exist on this server — skip quietly
                }
                if (! $folder) {
                    continue;
                }
                $stats['folders']++;

                try {
                    $messages = $folder->query()->since($since)->get();
                } catch (ImapPollTimeoutException $e) {
                    throw $e;
                } catch (\Webklex\PHPIMAP\Exceptions\GetMessagesFailedException $e) {
                    Log::info("Communication archive IMAP search empty (mailbox {$mailbox->id}, {$folderName}): {$e->getMessage()}");
                    continue;
                }

                foreach ($messages as $message) {
                    try {
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

        return ['status' => $status, 'reason' => $reason, 'stats' => $stats];
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
