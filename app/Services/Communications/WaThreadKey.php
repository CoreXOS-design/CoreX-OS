<?php

namespace App\Services\Communications;

use App\Services\ContactDuplicateService;

/**
 * AT-168 Part A — the canonical WhatsApp thread grouping key.
 *
 * A WhatsApp 1:1 conversation is identified by the counterpart's PHONE NUMBER,
 * not by the opaque chat id the capture engine happens to use (`@lid` from the
 * browser extension vs `@c.us` from WAHA). Keying the archive thread by the
 * resolved, normalised number collapses both engine views of one human into a
 * single thread and makes fragmentation structurally impossible going forward.
 *
 * The key format is `wa:<last-9>` where the last-9 form is EXACTLY the
 * ContactDuplicateService::normalizePhone() match key — so the thread key aligns
 * with the identity CoreX already matched the message to (no second convention).
 *
 * Group and broadcast chats are NOT 1:1 conversations and must never be folded
 * into a person's thread by their sender's number — isGroupOrBroadcast() guards
 * that (the AT-151 noise filter already drops them at ingestion; historic rows
 * are excluded from recanonicalization by this same guard).
 */
final class WaThreadKey
{
    /**
     * Canonical thread key for a resolved counterpart number, or null when the
     * value is not a usable phone (caller falls back to the raw chat id).
     */
    public static function canonical(?string $number): ?string
    {
        $number = trim((string) $number);
        // AT-133 guard — an @lid carries NO phone; its digits must NEVER be
        // normalised into a number (they would false-match an unrelated contact
        // and mint a bogus thread key). Refuse it. Stored rows only ever pass the
        // resolved real number here, but this keeps the helper safe for any caller.
        if ($number === '' || str_ends_with(strtolower($number), '@lid')) {
            return null;
        }
        $norm = app(ContactDuplicateService::class)->normalizePhone($number);

        return $norm ? 'wa:' . $norm : null;
    }

    /**
     * True for a group (@g.us / adapter is_group) or broadcast (status@broadcast)
     * chat id — these are never canonicalized into a 1:1 thread.
     */
    public static function isGroupOrBroadcast(?string $chatId): bool
    {
        $c = strtolower(trim((string) $chatId));

        return $c !== '' && (str_contains($c, 'status@broadcast') || str_ends_with($c, '@g.us'));
    }
}
