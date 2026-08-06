<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Contact-details Phase 1 — country-aware WhatsApp deep-link number.
 *
 * THE BUG THIS REPLACES: every WhatsApp click-to-chat builder in the contacts
 * domain (show.blade.php's sendWa(), match-results.blade.php's waPhone) rolled
 * its own "if it starts with 0, replace with 27" heuristic — hardcoded to South
 * Africa, with no way to know a number was ever anything else. A USA number
 * typed with a local-style leading trunk digit (or copy-pasted in a form the
 * agent expects the system to disambiguate) got "27" prepended to a US number,
 * producing a WhatsApp deep link for a number that doesn't exist — the agent
 * could not reach ("load") the contact on WhatsApp. A number typed without any
 * leading 0/country code had no country context at all to fall back on.
 *
 * This is the ONE canonical builder: it takes the number's OWN dial code
 * (from contact_phones.dial_code, defaulting to +27 for legacy/ZA rows) and
 * uses THAT — never a hardcoded '27' — to fill in a missing/local-form prefix.
 * wa.me and whatsapp:// both want digits-only, country-code-prefixed, no '+'.
 */
final class WhatsAppNumberFormatter
{
    public static function forDeepLink(?string $rawPhone, ?string $dialCode = '+27'): string
    {
        $digits = preg_replace('/\D+/', '', (string) $rawPhone) ?? '';
        if ($digits === '') {
            return '';
        }

        $dialDigits = preg_replace('/\D+/', '', (string) ($dialCode ?: '+27')) ?? '27';
        if ($dialDigits === '') {
            $dialDigits = '27';
        }

        // Already carries a full international form starting with its OWN dial
        // code (e.g. "+1 415 555 2671" -> "14155552671") — use as-is. The length
        // guard avoids mistaking a bare local number for an already-prefixed one
        // when the dial code happens to be a short numeric prefix of it.
        if (str_starts_with($digits, $dialDigits) && strlen($digits) > strlen($dialDigits) + 6) {
            return $digits;
        }

        // Local trunk-prefix form (leading 0) — replace it with the number's OWN
        // dial code, never a hardcoded one.
        if (str_starts_with($digits, '0')) {
            return $dialDigits . substr($digits, 1);
        }

        // Bare local number, no leading 0 and no country code — prefix the
        // number's own dial code.
        return $dialDigits . $digits;
    }
}
