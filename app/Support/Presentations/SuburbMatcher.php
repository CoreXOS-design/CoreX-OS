<?php

declare(strict_types=1);

namespace App\Support\Presentations;

/**
 * Suburb-string matcher for the comp pool.
 *
 * SA suburbs in CoreX arrive from several sources with inconsistent suffix
 * conventions. CMA Info parsers normalise to the locality root ("uvongo");
 * P24 alert imports + agent-typed subject addresses commonly carry a
 * directional/area suffix ("Uvongo Beach", "Margate North"). Without
 * normalisation the comp filters drop every match — the hydrator's
 * `str_contains(comp, subject)` requires the comp to be a superset of the
 * subject; the deal/badge SQL uses exact `LOWER(suburb) =` equality. Both
 * fail when subject="Uvongo Beach" and comp="uvongo".
 *
 * This helper provides:
 *
 *   normaliseSuburbToken(?string)  — lowercase + trim + collapse whitespace
 *                                    + strip recognised trailing locality
 *                                    suffixes (recursively, leaving ≥1 word).
 *
 *   matches(?string, ?string)      — true when two suburb strings refer to
 *                                    the same locality. Compares normalised
 *                                    forms; if neither side strips down to
 *                                    the same token sequence, also accepts
 *                                    one being a whitespace-bounded subset
 *                                    of the other (e.g. when a future suffix
 *                                    we don't yet list appears).
 *
 * Conservative by design: only the TRAILING token is stripped, and only if
 * it appears in the curated SA suffix list. "Margate" never collapses with
 * "Ramsgate". Distinct suburbs stay distinct. Test coverage proves both
 * directions ("Uvongo"↔"Uvongo Beach" match; "Uvongo"↔"Ramsgate" don't).
 */
final class SuburbMatcher
{
    /**
     * SA locality suffix tokens. When one of these is the trailing word of
     * a multi-word suburb, it is dropped during normalisation.
     *
     * Curated specifically for the KZN South Coast portfolio + general SA
     * conventions. Additions need a PR — wider lists risk merging distinct
     * suburbs (e.g. "town" was deliberately omitted because "Cape Town" is a
     * city, not a suffix).
     */
    private const TRAILING_SUFFIXES = [
        'beach',
        'bay',
        'park',
        'heights',
        'estate',
        'extension',
        'ext',
        'north',
        'south',
        'east',
        'west',
        'central',
        'village',
    ];

    /**
     * Normalise a suburb string to its locality root.
     *
     *   "Uvongo Beach"        → "uvongo"
     *   "Margate North"       → "margate"
     *   "Port Shepstone"      → "port shepstone"      (no suffix to strip)
     *   "Port Shepstone South"→ "port shepstone"
     *   "Shelly Beach"        → "shelly"
     *   "  Beach  "           → "beach"               (single word — left intact)
     *   null / ""             → ""
     */
    public static function normaliseSuburbToken(?string $suburb): string
    {
        if ($suburb === null) {
            return '';
        }
        $work = mb_strtolower(trim($suburb));
        if ($work === '') {
            return '';
        }
        // Collapse internal whitespace.
        $work = preg_replace('/\s+/u', ' ', $work) ?? $work;

        // Recursively strip recognised trailing suffix while ≥2 tokens remain.
        while (true) {
            $tokens = explode(' ', $work);
            if (count($tokens) < 2) {
                break;
            }
            $last = end($tokens);
            if (!in_array($last, self::TRAILING_SUFFIXES, true)) {
                break;
            }
            array_pop($tokens);
            $work = implode(' ', $tokens);
        }

        return $work;
    }

    /**
     * True when two suburb strings refer to the same locality.
     *
     * Both sides go through normaliseSuburbToken first. If the roots are
     * equal, match. As a safety net for unknown suffixes, also accept one
     * normalised form being a whitespace-bounded subset of the other.
     *
     * Either side null or empty returns false (no suburb = no decision).
     */
    public static function matches(?string $a, ?string $b): bool
    {
        $na = self::normaliseSuburbToken($a);
        $nb = self::normaliseSuburbToken($b);
        if ($na === '' || $nb === '') {
            return false;
        }
        if ($na === $nb) {
            return true;
        }
        return self::tokenContains($na, $nb) || self::tokenContains($nb, $na);
    }

    /**
     * True when $needle appears in $haystack as a whitespace-bounded token
     * sequence. Single-token whole match counts. Prevents "uvongo" from
     * matching a hypothetical "uvongo-something" (no overlap).
     */
    private static function tokenContains(string $haystack, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '') {
            return false;
        }
        $hay = ' ' . $haystack . ' ';
        $ndl = ' ' . $needle . ' ';
        return mb_strpos($hay, $ndl) !== false;
    }
}
