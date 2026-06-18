<?php

namespace App\Services\Communications;

/**
 * Normalised message-text hash (AT-59) — the deterministic match key between a
 * PROVISIONAL outbound communication (created when the agent clicks send) and
 * the real message later ingested from the mailbox Sent folder / WA capture.
 *
 * This is intentionally separate from Communication::content_hash, which hashes
 * the raw .eml / .json payload and therefore can never match across the two
 * capture paths. The text hash normalises the human-visible text so a click and
 * its eventual send line up when the agent did NOT edit the message.
 *
 * Normalisation is forgiving (lowercase, whitespace-collapsed, trimmed) but not
 * lossy enough to collide unrelated messages. When the agent edits before
 * sending, the hashes differ and reconciliation falls back to the time window.
 */
class MessageTextHasher
{
    /**
     * @param string      $channel Communication::CHANNEL_EMAIL|CHANNEL_WHATSAPP
     * @param string|null $subject email subject (ignored for WhatsApp)
     * @param string|null $body    message body / WhatsApp text
     */
    public static function hash(string $channel, ?string $subject, ?string $body): string
    {
        $parts = [strtolower(trim($channel))];

        // Subject only carries signal on email; WhatsApp has none.
        if (strtolower(trim($channel)) === 'email') {
            $parts[] = self::normalize((string) $subject);
        }

        $parts[] = self::normalize((string) $body);

        return hash('sha256', implode("\x1f", $parts));
    }

    private static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value);

        return $value ?? '';
    }
}
