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
    public const RESULT_BODY_FILLED = 'body_filled'; // AT-135 — backfilled an unreadable body

    public function __construct(
        private CommunicationStorageService $storage,
        private ContactIdentifierResolver $resolver,
        private ProvisionalReconciler $reconciler,
        private AgentCaptureConsentService $consent,
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

        // AT-135 — BODY BACKFILL: if this message was already archived with an
        // unreadable body (envelope captured by AT-133; body not yet rendered) and
        // we now have the rendered text, fill it IN PLACE (enrichment of a known
        // gap, not a history rewrite — analogous to provisional reconciliation).
        // Returns a result if the message already exists; null if it's brand new.
        $existingResult = $this->backfillBodyIfArchived($agencyId, $externalId, $msg);
        if ($existingResult !== null) {
            return $existingResult;
        }
        // Not in the archive — still guard against a legacy parked-pending duplicate.
        if ($this->alreadySeen($agencyId, $externalId)) {
            return self::RESULT_DUPLICATE;
        }

        $direction = $this->normalizeDirection($msg['direction'] ?? 'in');
        $sender = (string) ($msg['sender'] ?? '');
        // 1:1 chat: the counterpart is the other party — the sender on inbound,
        // the chat number on outbound.
        $counterpartRaw = $direction === Communication::DIRECTION_INBOUND ? ($sender ?: $chatId) : $chatId;

        // AT-133 — resolve the number to MATCH ON, with the @lid SAFETY GUARD.
        // Modern WhatsApp Web identifies chats/senders by an @lid (e.g.
        // 222758646611979@lid) that carries NO phone — its digits MUST NEVER be
        // normalised into a phone match (they'd false-match an unrelated contact
        // whose last-9 collides). Resolution order:
        //   1. counterpart_phone — the …@c.us the EXTENSION resolved from WA Web's
        //      contact store (Q1 proved 26/26 resolve). The real number.
        //   2. else the raw counterpart, but ONLY if it's already a real number
        //      (not an @lid, not a display name) — back-compat for chats WA still
        //      exposes by phone, and for older extensions that don't send it.
        $counterpartLid = (string) ($msg['counterpart_lid'] ?? '');
        $cpPhoneRaw     = (string) ($msg['counterpart_phone'] ?? '');
        $matchNumber = '';
        if ($cpPhoneRaw !== '' && ! $this->isLidIdentifier($cpPhoneRaw)) {
            $n = $this->numberFromJid($cpPhoneRaw);
            if ($this->looksLikePhone($n)) {
                $matchNumber = $n;
            }
        }
        if ($matchNumber === '' && ! $this->isLidIdentifier($counterpartRaw)) {
            $n = $this->numberFromJid($counterpartRaw);
            if ($this->looksLikePhone($n)) {
                $matchNumber = $n;
            }
        }

        // An @lid chat with no resolvable real number is a RESOLUTION FAILURE, not a
        // genuine non-contact — tag it distinctly so resolution failures are visible
        // (a worklist) vs real non-contacts. Either direction: the chat jid is the @lid.
        $isLidOnly = $matchNumber === ''
            && ($this->isLidIdentifier($counterpartRaw) || $this->isLidIdentifier($chatId)
                || $this->isLidIdentifier($cpPhoneRaw) || $counterpartLid !== '');

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

        // AT-136 — a WA↔CoreX match is the agent's per-contact capture decision.
        // Register it as PENDING (idempotent; this IS the periodic re-check — a new
        // match surfaces automatically). The BODY then flows ONLY when the agent has
        // opted IN; pending/opted-out keep the ENVELOPE (FICA floor) but withhold the
        // body. (No CoreX match never reaches here — the hard floor above.)
        $agentUserId = (int) $device->user_id;
        $this->consent->ensurePending($agencyId, $agentUserId, (int) $contact->id);
        $captureOptedIn = $this->consent->isCaptureOptedIn($agentUserId, (int) $contact->id);

        // AT-136 body gate: opted_in → normal body; otherwise withhold the body and
        // mark consent_pending (envelope still archived for FICA).
        $bodyText   = $captureOptedIn ? ($msg['text'] ?? null) : null;
        $bodyStatus = $captureOptedIn ? $this->bodyStatusFor($msg) : 'consent_pending';

        // The has-attachment FLAG is envelope (kept either way); the media BYTES are
        // body content — stored ONLY when opted in. The raw payload is REDACTED of
        // text/caption/media when not opted in, so no body is ever written to disk
        // for a pending/opted-out contact (no backdoor).
        $hasMedia = (bool) ($msg['has_media'] ?? (count($msg['media'] ?? []) > 0));
        $media    = $captureOptedIn ? ($msg['media'] ?? []) : [];
        $rawMsg   = $captureOptedIn ? $msg : array_merge($msg, ['text' => null, 'caption' => null, 'media' => []]);

        // Matched → now (and only now) persist the (consent-redacted) raw + index row.
        $raw = json_encode($rawMsg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $stored = $this->storage->store($agencyId, 'whatsapp', $raw);

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
            // AT-135 — the chat's @lid digits (matchable key) so the body-backfill
            // sweep can target an @lid chat DIRECTLY, without reverse-resolving it
            // to a phone (the asymmetry that left bodies unrecovered). Null for
            // chats WA still exposes by phone (@c.us). Consent gate unaffected.
            'counterpart_lid'        => $this->lidDigits($counterpartLid !== '' ? $counterpartLid : $chatId),
            'occurred_at'            => $this->toDate($msg['timestamp'] ?? null),
            'captured_at'            => now(),
            'subject'                => null,
            // AT-135 body / AT-136 gate: present only when the agent opted IN to
            // capturing this contact; otherwise withheld (envelope-only, FICA floor).
            'body_text'              => $bodyText,
            'body_preview'           => ($bodyText !== null && $bodyText !== '') ? Str::limit((string) $bodyText, 160) : null,
            // 'captured' = body present; 'unreadable' = opted-in but not yet rendered
            // (backfill targets it); 'consent_pending' = withheld until the agent
            // opts in; null = nothing to capture (media-only / empty).
            'body_status'            => $bodyStatus,
            'raw_path'               => $stored['path'],
            'content_hash'           => $stored['content_hash'],
            'text_hash'              => MessageTextHasher::hash(
                Communication::CHANNEL_WHATSAPP,
                null,
                $bodyText
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

    /**
     * AT-135 — coverage marker for a freshly-ingested message.
     *   captured   → has body text
     *   unreadable → extension flagged body_unreadable (IDB opaque + bubble absent)
     *   null       → nothing to capture (media-only / genuinely empty)
     */
    private function bodyStatusFor(array $msg): ?string
    {
        $text = $msg['text'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return 'captured';
        }
        if (!empty($msg['body_unreadable'])) {
            return 'unreadable';
        }
        return null;
    }

    /**
     * AT-135 — if this message is ALREADY archived, fill its body when it was
     * 'unreadable' and we now have rendered text (the backfill flip). Returns:
     *   RESULT_BODY_FILLED — was unreadable, now filled;
     *   RESULT_DUPLICATE   — already archived, nothing to fill;
     *   null               — not in the archive (caller proceeds to normal ingest).
     * Body fields only — identity, links, owner, thread all untouched.
     */
    private function backfillBodyIfArchived(int $agencyId, string $externalId, array $msg): ?string
    {
        $existing = Communication::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('external_id', $externalId)
            ->whereNull('purged_at')
            ->first();

        if (!$existing) {
            return null;
        }

        // AT-136 — only fill the body if the agent (owner) has opted IN to capturing
        // this contact. A pending/opted-out contact keeps its envelope; the body is
        // never written (even on a re-POST that carries text).
        $existingContactId = CommunicationLink::query()
            ->where('communication_id', $existing->id)
            ->where('linkable_type', Contact::class)
            ->value('linkable_id');
        if (! $existingContactId
            || ! $this->consent->isCaptureOptedIn((int) $existing->owner_user_id, (int) $existingContactId)) {
            return self::RESULT_DUPLICATE; // not opted in → leave envelope-only
        }

        // Fill the body when the archived row has NO body text yet (whether tagged
        // 'unreadable'/'consent_pending', or a legacy blank archived before this
        // column) and we now have rendered text. Never overwrite a captured body.
        $text         = $msg['text'] ?? null;
        $existingText = (string) $existing->body_text;
        $bodyMissing  = $existing->body_status !== 'captured' && trim($existingText) === '';
        if ($bodyMissing && is_string($text) && trim($text) !== '') {
            $existing->update([
                'body_text'    => $text,
                'body_preview' => Str::limit($text, 160),
                'body_status'  => 'captured',
                'text_hash'    => MessageTextHasher::hash(Communication::CHANNEL_WHATSAPP, null, $text),
            ]);

            return self::RESULT_BODY_FILLED;
        }

        return self::RESULT_DUPLICATE;
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
     * AT-135 — the bare digits of an @lid (its matchable key), or null when the
     * value is not an @lid. Stored on the row so the body-backfill sweep can match
     * an @lid chat directly off WA Web's list (no reverse @lid→phone resolution).
     */
    private function lidDigits(string $s): ?string
    {
        if (! $this->isLidIdentifier($s)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $s);

        return $digits !== '' ? $digits : null;
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
        // The instant must be expressed in the APP timezone before it is stored —
        // Laravel persists a Carbon's wall-clock as-is (no UTC conversion), so a
        // UTC-based Carbon would land hours behind created_at (now() is app-tz).
        // createFromTimestamp() defaults to UTC; setTimezone() preserves the instant
        // and rebases the wall-clock onto app-tz so occurred_at matches created_at.
        $tz = config('app.timezone');
        try {
            // Unix seconds (10 digits) or millis (13) vs ISO string.
            if (is_numeric($ts)) {
                $n = (int) $ts;
                return ($n > 9999999999
                    ? \Illuminate\Support\Carbon::createFromTimestampMs($n)
                    : \Illuminate\Support\Carbon::createFromTimestamp($n))->setTimezone($tz);
            }
            return \Illuminate\Support\Carbon::parse((string) $ts)->setTimezone($tz);
        } catch (\Throwable $e) {
            return now();
        }
    }
}
