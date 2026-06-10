<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical South African phone-number normaliser.
 *
 * Produces the digits-only, leading-zero local format that Private Property's
 * AgentImport SOAP API (and every other downstream consumer) expects. PP
 * rejects formatted numbers with `PP107 - Agent cell phone number was in an
 * incorrect format` — a number like "076 901 7397" must reach PP as
 * "0769017397" or the UpdateAgent call fails and the agent profile is never
 * created.
 *
 * Rules (applied in order):
 *   1. null / blank            → null
 *   2. Strip everything that is not a digit (spaces, dashes, parens, dots,
 *      "+", stray letters).
 *   3. International prefixes → local form with a leading 0:
 *        "0027XXXXXXXXX" (13)  → strip "00"  → "27..."  → "0..."
 *        "27XXXXXXXXX"   (11)  → "0XXXXXXXXX"
 *      ("+27 76 901 7397" loses its "+" in step 2 and lands here.)
 *   4. A bare 9-digit number (leading 0 was dropped on entry, e.g.
 *      "769017397") → prepend "0" → "0769017397". Every SA geographic and
 *      mobile number is 10 digits with a leading 0.
 *   5. Otherwise return the digits unchanged.
 *
 * Idempotent: normalize(normalize(x)) === normalize(x).
 *
 * This is canonicalisation, NOT validation — a genuinely malformed number is
 * returned as its digit string (PP will reject it on its own merits) rather
 * than discarded, so we never silently destroy data a human typed.
 *
 * Examples:
 *   "076 901 7397"      → "0769017397"
 *   "+27 76 901 7397"   → "0769017397"
 *   "27769017397"       → "0769017397"
 *   "0027769017397"     → "0769017397"
 *   "(031) 312-1234"    → "0313121234"
 *   "769017397"         → "0769017397"
 *   "0769017397"        → "0769017397"  (already canonical)
 *   ""  / null          → null
 */
final class SaPhoneNumber
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Step 2 — digits only.
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        // Step 3 — international prefixes → local "0..." form.
        if (str_starts_with($digits, '0027')) {
            $digits = substr($digits, 2); // "0027..." → "27..."
        }
        if (str_starts_with($digits, '27') && strlen($digits) === 11) {
            $digits = '0' . substr($digits, 2); // "27XXXXXXXXX" → "0XXXXXXXXX"
        }

        // Step 4 — restore a dropped leading 0 on a bare 9-digit number.
        if (strlen($digits) === 9 && $digits[0] !== '0') {
            $digits = '0' . $digits;
        }

        return $digits;
    }
}
