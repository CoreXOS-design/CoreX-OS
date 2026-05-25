<?php

declare(strict_types=1);

namespace App\Support\Geocoding;

/**
 * Phase 3f A2 — pure address normalisation.
 *
 * Produces a stable canonical string from a free-form South African address.
 * Used as the cache key for the geocoder waterfall — every input that should
 * resolve to the same physical location must hash to the same normalised
 * string.
 *
 * Rules (applied in order):
 *   1. Lowercase + trim
 *   2. Strip sectional-title unit prefixes that aren't part of the address:
 *      "Ss Madeira Gardens", "Section 4", "Section No 4", "Unit 12",
 *      "Flat 7", "Apt 3" — these are unit identifiers, not street parts.
 *   3. Strip punctuation (commas, full stops, semicolons) — replace with single space.
 *   4. Collapse repeated whitespace.
 *   5. Append suburb when not already present at any position.
 *   6. Append town when not already present.
 *   7. Drop the trailing ", South Africa" / "ZA" tokens — those are added
 *      by the geocoder branch, not the cache key.
 *
 * Idempotent: normalise(normalise(x)) === normalise(x).
 *
 * Examples:
 *   "4 Ss Madeira Gardens, 4 Tucker Avenue", "Uvongo"     → "4 tucker avenue uvongo"
 *   "  4 TUCKER AVE,  Uvongo   ", null                    → "4 tucker ave uvongo"
 *   "Erf 1234, Margate", null                             → "erf 1234 margate"
 *   "Unit 12, 8 Beach Rd", "Shelly Beach"                 → "8 beach rd shelly beach"
 *   "4 Tucker Avenue, Uvongo", "Uvongo", "Ray Nkonyeni"   → "4 tucker avenue uvongo ray nkonyeni"
 *
 * NOT a replacement for full SA address standardisation — there's no street-type
 * canonicalisation ("Ave" vs "Avenue"), no suffix normalisation. Those would
 * cause false collisions where two genuinely different addresses share a
 * normalised key. We accept some duplicates in cache in exchange for safety.
 */
final class AddressNormaliser
{
    /** Strip these unit-prefix tokens before joining. */
    private const UNIT_PREFIX_PATTERNS = [
        // "4 Ss Madeira Gardens," — anchored at string start so we don't
        // accidentally eat addresses like "Tucker Avenue, Ss Madeira Gardens"
        // where Ss is mid-sentence. The leading digit (unit number) is also
        // consumed because it identifies the unit, not the street.
        '/^\s*\d{1,4}\s+ss\s+[a-z][a-z \-\']+,\s*/iu',
        // Same again without leading number, mid-string.
        '/\bss\s+[a-z][a-z \-\']+,\s*/iu',
        '/\bsection(?:\s+no)?\s*\d+\s*,?\s*/iu',     // "Section 4" / "Section No 4"
        '/\bunit\s+\w{1,8}\s*,?\s*/iu',              // "Unit 12"
        '/\bflat\s+\w{1,8}\s*,?\s*/iu',              // "Flat 7"
        '/\bapt\s+\w{1,8}\s*,?\s*/iu',               // "Apt 3"
    ];

    /** Trailing tokens we drop because they're added downstream. */
    private const TRAILING_DROP = [
        ', south africa', ' south africa', ', za', ' za',
    ];

    public static function normalise(string $raw, ?string $suburb = null, ?string $town = null): string
    {
        $work = mb_strtolower(trim($raw));
        if ($work === '') {
            $work = '';
        }

        // Step 2 — strip unit prefixes.
        foreach (self::UNIT_PREFIX_PATTERNS as $p) {
            $work = preg_replace($p, ' ', $work) ?? $work;
        }

        // Step 7 (early) — drop trailing country tokens so they don't get re-added.
        foreach (self::TRAILING_DROP as $tail) {
            if (str_ends_with($work, $tail)) {
                $work = substr($work, 0, -strlen($tail));
            }
        }

        // Step 3 — replace punctuation with single space.
        $work = preg_replace('/[,;]+/u', ' ', $work) ?? $work;
        $work = preg_replace('/\.+/u', ' ', $work) ?? $work;

        // Step 4 — collapse whitespace.
        $work = preg_replace('/\s+/u', ' ', $work) ?? $work;
        $work = trim($work);

        // Step 5 — append suburb if not already present.
        $suburbLower = $suburb !== null ? mb_strtolower(trim($suburb)) : null;
        if ($suburbLower !== null && $suburbLower !== '' && !self::tokenAppearsIn($work, $suburbLower)) {
            $work = trim($work . ' ' . $suburbLower);
        }

        // Step 6 — append town if not already present.
        $townLower = $town !== null ? mb_strtolower(trim($town)) : null;
        if ($townLower !== null && $townLower !== '' && !self::tokenAppearsIn($work, $townLower)) {
            $work = trim($work . ' ' . $townLower);
        }

        // Final whitespace pass — appends may have introduced doubles.
        $work = preg_replace('/\s+/u', ' ', $work) ?? $work;

        return trim($work);
    }

    /**
     * True when $needle appears in $haystack as a whitespace-bounded token,
     * not as a substring of another word. Multi-word needles match if their
     * full sequence is present.
     */
    private static function tokenAppearsIn(string $haystack, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '') return true;
        $hay = ' ' . $haystack . ' ';
        $ndl = ' ' . $needle . ' ';
        return mb_strpos($hay, $ndl) !== false;
    }

    /**
     * Phase 11a C — structured address parser.
     *
     * Splits a raw SA address into the components that matter for geocoding —
     * specifically, separates the sectional-title preamble ("Ss <SchemeName>")
     * from the underlying street address so that one cache entry can serve
     * all units in the scheme.
     *
     * Returns:
     *   unit_number        — string|null   "36"  (the unit/door number, if any)
     *   scheme_name        — string|null   "Topanga"
     *   street_address     — string|null   "2587 Colin Road"
     *   suburb             — string|null   "Uvongo" (from $suburb arg OR trailing token)
     *   is_sectional_title — bool          true when the "Ss <Scheme>" pattern matched
     *   is_geocodable      — bool          true when a usable street_address survived
     *   geocode_target     — string|null   "2587 Colin Road, Uvongo" — what to send to Google
     *
     * Examples (real Uvongo data):
     *   "36 Ss Topanga, 2587 Colin Road"           + "Uvongo" →
     *     ['unit_number'=>'36','scheme_name'=>'Topanga','street_address'=>'2587 Colin Road',
     *      'suburb'=>'Uvongo','is_sectional_title'=>true,'is_geocodable'=>true,
     *      'geocode_target'=>'2587 Colin Road, Uvongo']
     *
     *   "4 Ss Madeira Gardens, 4 Tucker Avenue, Uvongo" →
     *     ['unit_number'=>'4','scheme_name'=>'Madeira Gardens','street_address'=>'4 Tucker Avenue',
     *      'suburb'=>'Uvongo','is_sectional_title'=>true,'is_geocodable'=>true,
     *      'geocode_target'=>'4 Tucker Avenue, Uvongo']
     *
     *   "2587 Colin Road, Uvongo" →
     *     ['unit_number'=>null,'scheme_name'=>null,'street_address'=>'2587 Colin Road',
     *      'suburb'=>'Uvongo','is_sectional_title'=>false,'is_geocodable'=>true,
     *      'geocode_target'=>'2587 Colin Road, Uvongo']
     *
     *   "Ss Madeira Gardens" (no street part — scheme name only) →
     *     ['unit_number'=>null,'scheme_name'=>'Madeira Gardens','street_address'=>null,
     *      'suburb'=>null,'is_sectional_title'=>true,'is_geocodable'=>false,
     *      'geocode_target'=>null]
     *
     * @return array{
     *   unit_number:?string, scheme_name:?string, street_address:?string,
     *   suburb:?string, is_sectional_title:bool, is_geocodable:bool,
     *   geocode_target:?string
     * }
     */
    public static function parse(string $raw, ?string $suburb = null): array
    {
        $out = [
            'unit_number'        => null,
            'scheme_name'        => null,
            'street_address'     => null,
            'suburb'             => $suburb !== null && trim($suburb) !== '' ? trim($suburb) : null,
            'is_sectional_title' => false,
            'is_geocodable'      => false,
            'geocode_target'     => null,
        ];

        $work = trim($raw);
        if ($work === '') {
            return $out;
        }

        // Strip trailing country tokens so they don't confuse suburb detection.
        $lower = mb_strtolower($work);
        foreach (self::TRAILING_DROP as $tail) {
            if (str_ends_with($lower, $tail)) {
                $work = trim(substr($work, 0, mb_strlen($work) - strlen($tail)), " ,");
                $lower = mb_strtolower($work);
            }
        }

        // Try the canonical Ss pattern: "[unit?] Ss <Scheme>, <rest>"
        // The unit number is optional (some captures store the scheme without it).
        // Scheme name runs until the first comma — sectional schemes are commonly
        // multi-word ("Madeira Gardens", "Sea Spray", "Ocean View Towers").
        $ssPattern = '/^\s*(?:(?<unit>\d{1,5})\s+)?ss\s+(?<scheme>[A-Za-z][A-Za-z0-9 \-\']{1,80}?)\s*(?:,\s*(?<rest>.+))?$/iu';
        if (preg_match($ssPattern, $work, $m)) {
            $out['is_sectional_title'] = true;
            $out['unit_number']        = isset($m['unit']) && $m['unit'] !== '' ? $m['unit'] : null;
            $out['scheme_name']        = isset($m['scheme']) ? trim($m['scheme']) : null;
            $rest                      = isset($m['rest']) ? trim($m['rest']) : '';
            self::extractStreetAndSuburb($rest, $out);
        } else {
            // Plain address — no Ss preamble. Treat the whole thing as the
            // street + (optional) suburb, with suburb separated by a trailing
            // comma if present.
            self::extractStreetAndSuburb($work, $out);
        }

        // Geocode target: street_address + suburb (when we have both),
        // street_address alone (when no suburb), or null (when there's no street).
        if ($out['street_address'] !== null && $out['street_address'] !== '') {
            $out['is_geocodable'] = true;
            $out['geocode_target'] = $out['suburb']
                ? $out['street_address'] . ', ' . $out['suburb']
                : $out['street_address'];
        }

        return $out;
    }

    /**
     * Split "<street>, <suburb>" — when there is no comma the whole input is
     * treated as the street and the existing suburb (passed via parse()) is
     * left untouched. Updates $out in-place.
     *
     * @param array<string,mixed> $out
     */
    private static function extractStreetAndSuburb(string $rest, array &$out): void
    {
        $rest = trim($rest);
        if ($rest === '') {
            return;
        }

        if (str_contains($rest, ',')) {
            $parts = array_map('trim', explode(',', $rest));
            // Last non-empty token = candidate suburb; everything before = street.
            $last = array_pop($parts);
            $street = implode(', ', array_filter($parts, fn ($p) => $p !== ''));
            if ($street !== '') {
                $out['street_address'] = $street;
                // Only adopt the trailing token as suburb if caller didn't supply one.
                if ($out['suburb'] === null && $last !== '') {
                    $out['suburb'] = $last;
                }
            } else {
                // No street before the comma — treat the whole rest as street.
                $out['street_address'] = $rest;
            }
        } else {
            $out['street_address'] = $rest;
        }
    }
}
