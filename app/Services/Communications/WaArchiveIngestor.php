<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationLink;
use App\Models\Communications\CommunicationPending;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The testable core of the WhatsApp adapter (AT-34). Mirrors EmailArchiveIngestor:
 * dedup on WA message id, raw .json through the content-addressed writer,
 * known-contact gate via ContactIdentifierResolver (match → archive + link;
 * no match → inbound grace buffer). channel=whatsapp, thread_key=chat id.
 */
class WaArchiveIngestor
{
    public const RESULT_ARCHIVED    = 'archived';
    public const RESULT_RECONCILED  = 'reconciled';
    public const RESULT_PENDING     = 'pending';
    public const RESULT_DUPLICATE   = 'duplicate';
    public const RESULT_INVALID     = 'invalid';

    public function __construct(
        private CommunicationStorageService $storage,
        private ContactIdentifierResolver $resolver,
        private ProvisionalReconciler $reconciler,
    ) {
    }

    /**
     * @param array $msg keys: message_id, chat_id, direction ('in'|'out'|inbound|outbound),
     *                   sender, timestamp (unix|iso), text, has_media (bool),
     *                   media[] (each: filename, mime, data_base64)
     */
    public function ingest(CommunicationWaDevice $device, array $msg): string
    {
        $agencyId = (int) $device->agency_id;
        $externalId = (string) ($msg['message_id'] ?? '');
        $chatId = (string) ($msg['chat_id'] ?? '');
        if ($externalId === '' || $chatId === '') {
            return self::RESULT_INVALID;
        }

        if ($this->alreadySeen($agencyId, $externalId)) {
            return self::RESULT_DUPLICATE;
        }

        $direction = $this->normalizeDirection($msg['direction'] ?? 'in');
        $sender = (string) ($msg['sender'] ?? '');
        // 1:1 chat: the counterpart is the other party — the sender on inbound,
        // the chat number on outbound. Strip the WA jid suffix before resolving.
        $counterpartRaw = $direction === Communication::DIRECTION_INBOUND ? ($sender ?: $chatId) : $chatId;
        $counterpartNumber = $this->numberFromJid($counterpartRaw);

        $raw = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $stored = $this->storage->store($agencyId, 'whatsapp', $raw);

        $media = $msg['media'] ?? [];
        $hasMedia = (bool) ($msg['has_media'] ?? (count($media) > 0));

        $common = [
            'agency_id'              => $agencyId,
            'channel'                => Communication::CHANNEL_WHATSAPP,
            'direction'              => $direction,
            'external_id'            => $externalId,
            'thread_key'             => $chatId,
            'from_identifier'        => $sender ?: $counterpartNumber,
            'participant_identifiers' => array_values(array_unique(array_filter([$sender, $this->numberFromJid($chatId)]))),
            'occurred_at'            => $this->toDate($msg['timestamp'] ?? null),
            'captured_at'            => now(),
            'subject'                => null,
            'body_text'              => $msg['text'] ?? null,
            'body_preview'           => isset($msg['text']) ? Str::limit((string) $msg['text'], 160) : null,
            'raw_path'               => $stored['path'],
            'content_hash'           => $stored['content_hash'],
            'text_hash'              => MessageTextHasher::hash(
                Communication::CHANNEL_WHATSAPP,
                null,
                $msg['text'] ?? null
            ),
            'has_attachments'        => $hasMedia,
            'source_ref'             => 'wa_device:' . $device->id,
        ];

        $contact = $counterpartNumber !== '' ? $this->resolver->resolve($counterpartNumber, $agencyId) : null;

        if (! $contact) {
            CommunicationPending::create($common + [
                'expires_at' => now()->addDays(CommunicationPending::graceDays($device->agency)),
            ]);

            return self::RESULT_PENDING;
        }

        return DB::transaction(function () use ($contact, $direction, $common, $media, $agencyId, $device) {
            // AT-59: promote a matching provisional outbound row in place rather
            // than inserting a duplicate of the agent's click.
            if ($direction === Communication::DIRECTION_OUTBOUND) {
                $promoted = $this->reconciler->reconcileOutbound(
                    $contact,
                    Communication::CHANNEL_WHATSAPP,
                    $common,
                    $device->agency
                );

                if ($promoted) {
                    $this->storeMedia($promoted, $agencyId, $media);

                    return self::RESULT_RECONCILED;
                }
            }

            $communication = Communication::create($common);
            $this->storeMedia($communication, $agencyId, $media);

            CommunicationLink::create([
                'agency_id'        => $agencyId,
                'communication_id' => $communication->id,
                'linkable_type'    => Contact::class,
                'linkable_id'      => $contact->id,
                'link_method'      => CommunicationLink::METHOD_DETERMINISTIC,
                'confidence'       => 100,
                'confirmed_at'     => now(),
            ]);

            $contact->touchLastContacted($communication->occurred_at);

            return self::RESULT_ARCHIVED;
        });
    }

    private function alreadySeen(int $agencyId, string $externalId): bool
    {
        $inArchive = Communication::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('external_id', $externalId)->exists();

        if ($inArchive) {
            return true;
        }

        return CommunicationPending::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('external_id', $externalId)->exists();
    }

    private function storeMedia(Communication $communication, int $agencyId, array $media): void
    {
        foreach ($media as $item) {
            $b64 = $item['data_base64'] ?? null;
            if (! $b64) {
                continue;
            }
            $bytes = base64_decode($b64, true);
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $stored = $this->storage->store($agencyId, 'attachment', $bytes);

            CommunicationAttachment::create([
                'agency_id'        => $agencyId,
                'communication_id' => $communication->id,
                'filename'         => $item['filename'] ?? null,
                'mime'             => $item['mime'] ?? null,
                'size_bytes'       => strlen($bytes),
                'content_hash'     => $stored['content_hash'],
                'storage_path'     => $stored['path'],
            ]);
        }
    }

    private function normalizeDirection(string $d): string
    {
        $d = strtolower(trim($d));
        return in_array($d, ['out', 'outbound', 'sent'], true)
            ? Communication::DIRECTION_OUTBOUND
            : Communication::DIRECTION_INBOUND;
    }

    /** Strip the WA jid suffix ("27821234567@c.us" → "27821234567"). */
    private function numberFromJid(string $jid): string
    {
        $jid = trim($jid);
        if ($jid === '') {
            return '';
        }
        return str_contains($jid, '@') ? substr($jid, 0, strpos($jid, '@')) : $jid;
    }

    private function toDate($ts): \Illuminate\Support\Carbon
    {
        if ($ts === null || $ts === '') {
            return now();
        }
        try {
            // Unix seconds (10 digits) or millis (13) vs ISO string.
            if (is_numeric($ts)) {
                $n = (int) $ts;
                return $n > 9999999999 ? \Illuminate\Support\Carbon::createFromTimestampMs($n) : \Illuminate\Support\Carbon::createFromTimestamp($n);
            }
            return \Illuminate\Support\Carbon::parse((string) $ts);
        } catch (\Throwable $e) {
            return now();
        }
    }
}
