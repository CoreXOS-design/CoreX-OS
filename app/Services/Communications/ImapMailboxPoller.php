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
                    'timeout'       => 30,
                ]],
            ]))->account();
            $client->connect();
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

        try {
            foreach ($folders as $folderName => $direction) {
                $folder = null;
                try {
                    $folder = $client->getFolderByName($folderName);
                } catch (\Throwable $e) {
                    $folder = null; // folder doesn't exist on this server — skip quietly
                }
                if (! $folder) {
                    continue;
                }
                $stats['folders']++;

                try {
                    $messages = $folder->query()->since($since)->get();
                } catch (\Webklex\PHPIMAP\Exceptions\GetMessagesFailedException $e) {
                    Log::info("Communication archive IMAP search empty (mailbox {$mailbox->id}, {$folderName}): {$e->getMessage()}");
                    continue;
                }

                foreach ($messages as $message) {
                    try {
                        $normalized = $this->normalize($message, $direction);
                        $result = $this->ingestor->ingest($mailbox, $normalized, $direction);
                        $stats[$result] = ($stats[$result] ?? 0) + 1;
                    } catch (\Throwable $e) {
                        // One bad message must never block the rest or crash the worker.
                        $stats['errors']++;
                        Log::error("Communication archive ingest error (mailbox {$mailbox->id}): {$e->getMessage()}");
                    }
                }
            }
        } finally {
            try { $client->disconnect(); } catch (\Throwable $e) { /* ignore */ }
            $mailbox->forceFill(['last_polled_at' => now()])->save();
        }

        return ['status' => 'success', 'stats' => $stats];
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
        return $this->safe(function () use ($message, $method) {
            $collection = $message->{$method}();
            $out = [];
            foreach ($collection as $addr) {
                $mail = is_object($addr) ? ($addr->mail ?? null) : (is_string($addr) ? $addr : null);
                if ($mail) {
                    $out[] = strtolower(trim($mail));
                }
            }
            return $out;
        }) ?? [];
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
