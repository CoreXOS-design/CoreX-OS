<?php

namespace App\Services\Communications;

/**
 * AT-149 — the THIN adapter that maps ONE WAHA webhook message payload into the
 * WaArchiveIngestor's messages[] item contract. It reimplements NOTHING: no
 * ingestion, no @lid resolution, no consent, no matching, no media download —
 * it only reshapes fields and applies the proven noise filter. Everything
 * behind it (AT-122 match-first, AT-133 @lid, AT-135/136 body/consent, AT-148
 * media[].url download) is the ingestor's job, unchanged.
 *
 * WAHA (GOWS) posts ONE message per webhook. The fields we consume (proven in
 * AT-138/143/148):
 *   payload.id, payload.timestamp, payload.from (@lid chat), payload.to,
 *   payload.fromMe, payload.body, payload.hasMedia,
 *   payload.media { url, mimetype, filename },
 *   payload._data.Info { SenderAlt (real phone @s.whatsapp.net), PushName,
 *                        IsGroup, IsFromMe },
 *   payload._data.Message { conversation, audioMessage.seconds }.
 */
class WahaWebhookAdapter
{
    /**
     * Map a WAHA message payload → an ingestor messages[] item, or NULL when the
     * payload is noise (status broadcast / group) or malformed (no id / no chat).
     *
     * @param array $payload the WAHA `payload` object (envelope already unwrapped)
     */
    public function map(array $payload): ?array
    {
        if ($this->isNoise($payload)) {
            return null;
        }

        $info = is_array($payload['_data']['Info'] ?? null) ? $payload['_data']['Info'] : [];
        $message = is_array($payload['_data']['Message'] ?? null) ? $payload['_data']['Message'] : [];

        $waId = $this->str($payload['id'] ?? '');
        $chatFrom = $this->str($payload['from'] ?? '');   // the @lid (or jid) of the chat
        if ($waId === '' || $chatFrom === '') {
            return null; // malformed — nothing to key on
        }

        $fromMe = (bool) ($payload['fromMe'] ?? $info['IsFromMe'] ?? false);

        // COUNTERPART identity (the other party — the contact we match/thread on).
        //   inbound  → the sender: SenderAlt carries their REAL phone (AT-138).
        //   outbound → the RECIPIENT (payload.to); SenderAlt would be the agent's
        //              own phone here, so it must NOT drive the match.
        // Phone is PRIMARY; the @lid resolver (AT-133) is the FALLBACK when the
        // real phone is absent — so we only ever hand the ingestor a real number
        // when we actually have one, else an empty phone + the @lid.
        if ($fromMe) {
            $counterpartJid = $this->str($payload['to'] ?? '') ?: $chatFrom;
            $phone = $this->phoneFromJid($payload['to'] ?? '');
        } else {
            $counterpartJid = $chatFrom;
            $phone = $this->phoneFromJid($info['SenderAlt'] ?? '');
        }

        $body = $this->str($payload['body'] ?? '');
        if ($body === '') {
            $body = $this->str($message['conversation'] ?? '');
        }

        $media = $this->mapMedia($payload, $message);

        return [
            'message_id'        => $waId,
            'chat_id'           => $counterpartJid,           // thread key (the @lid chat)
            'direction'         => $fromMe ? 'out' : 'in',
            'sender'            => $phone !== '' ? $phone : null,
            'timestamp'         => $payload['timestamp'] ?? ($info['Timestamp'] ?? null),
            'text'              => $body !== '' ? $body : null,
            'has_media'         => $media !== [],
            'media'             => $media,
            // PRIMARY match number (real phone). Empty → ingestor falls back to the
            // @lid resolver via counterpart_lid (AT-133 becomes fallback, not primary).
            'counterpart_phone' => $phone,
            'counterpart_lid'   => $counterpartJid,
            'is_group'          => false, // groups already dropped by isNoise()
            'name'              => $this->str($info['PushName'] ?? ($payload['notifyName'] ?? '')) ?: null,
        ];
    }

    /**
     * A payload we must NEVER archive: WhatsApp status broadcasts and group
     * messages (proven required in AT-138). Checked BEFORE mapping/ingestion.
     */
    public function isNoise(array $payload): bool
    {
        $from = strtolower($this->str($payload['from'] ?? ''));
        $to   = strtolower($this->str($payload['to'] ?? ''));
        $info = is_array($payload['_data']['Info'] ?? null) ? $payload['_data']['Info'] : [];

        if (str_contains($from, 'status@broadcast') || str_contains($to, 'status@broadcast')) {
            return true;
        }
        if (str_ends_with($from, '@g.us') || str_ends_with($to, '@g.us')) {
            return true;
        }

        return (bool) ($info['IsGroup'] ?? $payload['isGroup'] ?? false);
    }

    /** Map WAHA's single media object → the ingestor's AT-148 media[].url seam. */
    private function mapMedia(array $payload, array $message): array
    {
        $media = is_array($payload['media'] ?? null) ? $payload['media'] : [];
        $url = $this->str($media['url'] ?? '');
        if ($url === '') {
            return [];
        }

        $duration = null;
        $seconds = $message['audioMessage']['seconds'] ?? null;
        if (is_numeric($seconds)) {
            $duration = (int) $seconds;
        }

        return [[
            'url'      => $url,
            'mimetype' => $this->str($media['mimetype'] ?? '') ?: null,
            'filename' => $this->str($media['filename'] ?? '') ?: null,
            'duration' => $duration,
        ]];
    }

    /**
     * The bare phone digits of a WhatsApp phone jid ("27831234567@s.whatsapp.net"
     * or "…@c.us" → "27831234567"), or '' when the value is empty or an @lid
     * (which carries NO phone — never treat its digits as a number).
     */
    private function phoneFromJid($jid): string
    {
        $jid = trim($this->str($jid));
        if ($jid === '' || str_ends_with($jid, '@lid')) {
            return '';
        }
        $number = str_contains($jid, '@') ? substr($jid, 0, strpos($jid, '@')) : $jid;
        // Guard: only return something that actually looks like a phone (≥9 digits).
        return strlen(preg_replace('/\D/', '', $number)) >= 9 ? $number : '';
    }

    private function str($v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
