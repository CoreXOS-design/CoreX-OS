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
use Illuminate\Support\Facades\Log;
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
    public const RESULT_DROPPED     = 'dropped';

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
        // the chat number on outbound.
        $counterpartRaw = $direction === Communication::DIRECTION_INBOUND ? ($sender ?: $chatId) : $chatId;

        // AT-133 — @lid SAFETY GUARD. Modern WhatsApp Web identifies chats/senders
        // by an @lid (e.g. 222758646611979@lid) that carries NO phone. Its digits
        // MUST NEVER be normalised into a phone match — they'd false-match an
        // unrelated contact whose last-9 collides. So we only match when the
        // counterpart is a REAL number (a …@c.us / MSISDN), never an @lid or a
        // display name. (The @lid → phone resolution itself is the held Q1=yes
        // build; this guard + the tagging below are correct in EVERY path.)
        $matchNumber = '';
        if (! $this->isLidIdentifier($counterpartRaw)) {
            $n = $this->numberFromJid($counterpartRaw);
            if ($this->looksLikePhone($n)) {
                $matchNumber = $n;
            }
        }

        // An @lid chat with no resolvable real number is a RESOLUTION FAILURE, not a
        // genuine non-contact — tag it distinctly so resolution failures are visible
        // (a worklist) vs real non-contacts. Either direction: the chat jid is the @lid.
        $isLidOnly = $matchNumber === ''
            && ($this->isLidIdentifier($counterpartRaw) || $this->isLidIdentifier($chatId));

        // AT-122 — MATCH-FIRST, store-only-on-match. The @lid guard means we only
        // ever hand a REAL number to the resolver (ContactIdentifierResolver also
        // refuses @lid as defense-in-depth). No contact, no record.
        $contact = $matchNumber !== '' ? $this->resolver->resolve($matchNumber, $agencyId) : null;

        if (! $contact) {
            $reason = $isLidOnly ? 'unresolved_lid' : 'no_contact_match';
            Log::info('Communication archive: WA ingestion dropped (not stored)', [
                'agency_id'   => $agencyId,
                'device_id'   => $device->id,
                'channel'     => Communication::CHANNEL_WHATSAPP,
                'direction'   => $direction,
                'sender'      => $matchNumber !== '' ? $matchNumber : $counterpartRaw,
                'reason'      => $reason,
                'dropped_at'  => now()->toIso8601String(),
            ]);

            // AT-133 — TEMPORARY, flag-gated probe. Dropped payloads are never
            // persisted, so to decide the fix (extension vs server) we log ONE
            // full raw payload: does any field carry the real …@c.us phone jid,
            // or is everything a @lid / display name? OFF by default; enabled on
            // staging for the probe only. Behaviour unchanged — this only reads.
            if (config('communications.debug_dropped_wa', false)) {
                $jidFields = [];
                array_walk_recursive($msg, function ($v, $k) use (&$jidFields) {
                    if (is_string($v) && str_contains($v, '@')) {
                        $jidFields[] = $k . '=' . $v;
                    }
                });
                Log::debug('AT-133 WA dropped payload probe', [
                    'device_id'           => $device->id,
                    'direction'           => $direction,
                    'chat_id'             => $chatId,
                    'sender_raw'          => $sender,
                    'author'              => $msg['author'] ?? null,
                    'counterpart_raw'     => $counterpartRaw,
                    'match_number'        => $matchNumber,
                    'drop_reason'         => $reason,
                    'jid_like_fields'     => $jidFields, // every value containing '@' (…@c.us vs …@lid)
                    'raw_payload'         => $msg,       // ENTIRE inbound message as received
                ]);
            }

            return self::RESULT_DROPPED;
        }

        // Matched → now (and only now) persist the raw JSON and build the index row.
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
            // AT-133 — store the RESOLVED real number (…@c.us / MSISDN), not the @lid
            // digits. from_identifier prefers the matched number; participants carry
            // it (+ the sender name) so the archive row shows who, not an @lid.
            'from_identifier'        => $matchNumber ?: ($sender ?: $counterpartRaw),
            'participant_identifiers' => array_values(array_unique(array_filter([$matchNumber, $sender]))),
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
            // AT-122 — provenance: the agent whose capture device ingested this.
            // Provenance only — not gated. (Device rows always carry a user_id.)
            'owner_user_id'          => $device->user_id,
        ];

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

    /**
     * AT-133 — a WhatsApp @lid (linked id) is NOT a phone. Its digits must never be
     * normalised into a phone match (they'd false-match an unrelated contact whose
     * last-9 collides). The guard: anything ending @lid is non-matchable.
     */
    private function isLidIdentifier(string $s): bool
    {
        return str_ends_with(trim($s), '@lid');
    }

    /**
     * AT-133 — does this look like a real phone (≥9 digits after stripping)? A
     * display name ("Elize Reichel") yields 0 digits → false. Used to refuse names
     * and short junk before handing anything to the phone resolver.
     */
    private function looksLikePhone(string $s): bool
    {
        return strlen(preg_replace('/\D/', '', $s)) >= 9;
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
