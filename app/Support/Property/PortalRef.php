<?php

declare(strict_types=1);

namespace App\Support\Property;

/**
 * The ONE spelling of a portal advert number (.ai/specs/property-identity.md §4.1).
 *
 *   p24:116123456     pp:T1014486     pp:RR4719094
 *
 * Pure, no DB, idempotent: normalise(normalise(x)) === normalise(x). Every raw spelling found in the
 * audit ("P24-123", "123", "PP-T123", "T123", a portal URL, a spreadsheet float "123.0") maps to this.
 * Bare digits are NEVER guessed from the digits alone — the portal must come from the column or source
 * the value was read from (the hint). No hint, or a value that is not a plausible advert id (a test
 * string, a prospecting-row marker) => null.
 */
final class PortalRef
{
    public const P24 = 'p24';
    public const PP  = 'pp';

    private const P24_ID = '/^\d{6,12}$/';
    private const PP_ID  = '/^[A-Za-z]{1,3}\d{5,10}$/';

    /** @return string|null canonical `portal:id`, or null when the value is not a readable advert number */
    public static function normalise(?string $raw, ?string $portalHint = null): ?string
    {
        if ($raw === null) {
            return null;
        }

        $s = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $raw) ?? $raw;
        $s = trim(preg_replace('/\x{00A0}/u', ' ', $s) ?? $s);
        $s = trim($s, " \t\n\r\"'`");
        if ($s === '') {
            return null;
        }
        $s = preg_replace('/^(\d+)\.0+$/', '$1', $s) ?? $s; // spreadsheet float

        $hint = self::portal($portalHint);

        // A portal URL: the id is the last path segment.
        if (preg_match('~(?:https?://|www\.|property24\.com|privateproperty\.co\.za)~i', $s)) {
            if (stripos($s, 'property24.') !== false) {
                return preg_match_all('~/(\d{6,12})(?=[/?#]|$)~', $s, $m) ? self::P24 . ':' . end($m[1]) : null;
            }
            if (stripos($s, 'privateproperty.') !== false) {
                return preg_match_all('~/([A-Za-z]{1,3}\d{5,10})(?=[/?#]|$)~', $s, $m)
                    ? self::PP . ':' . strtoupper(end($m[1])) : null;
            }
            return null;
        }

        // An explicit prefix wins over the hint: "P24-123", "p24:123", "PP-T123", "pp T123".
        if (preg_match('/^(P24|PP)[-:_ ]\s*(.+)$/i', $s, $m)) {
            return self::build(strtolower($m[1]), trim($m[2]));
        }

        // "P24123456": the prefix without a separator (a Private Property id never starts "P24" + 6+ digits).
        if (preg_match('/^P24(\d{6,12})$/i', $s, $m)) {
            return self::build(self::P24, $m[1]);
        }

        // Letters + digits with no prefix is a Private Property id ("T5663557", "RR4719094").
        if (preg_match(self::PP_ID, $s)) {
            return $hint === self::P24 ? null : self::build(self::PP, $s);
        }

        // Bare digits: only with a hint.
        if (preg_match('/^\d+$/', $s)) {
            return $hint === self::P24 ? self::build(self::P24, $s) : null;
        }

        return null;
    }

    /** 'p24' | 'pp' | null from any of the strings the codebase uses for a portal. */
    public static function portal(?string $hint): ?string
    {
        if ($hint === null) {
            return null;
        }
        $h = strtolower(trim($hint));
        if ($h === '') {
            return null;
        }
        if (str_contains($h, 'property24') || $h === 'p24' || str_starts_with($h, 'p24')) {
            return self::P24;
        }
        if (str_contains($h, 'privateproperty') || str_contains($h, 'private_property') || str_contains($h, 'private property')
            || $h === 'pp' || str_starts_with($h, 'pp_') || str_starts_with($h, 'pp-')) {
            return self::PP;
        }

        return null;
    }

    /** @return array{0:string,1:string}|null [portal, listing id] of a canonical reference */
    public static function split(?string $canonical): ?array
    {
        if ($canonical === null || ! preg_match('/^(p24|pp):(.+)$/', $canonical, $m)) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    public static function isCanonical(?string $value): bool
    {
        return $value !== null && self::normalise($value) === $value;
    }

    private static function build(string $portal, string $id): ?string
    {
        if ($portal === self::P24) {
            return preg_match(self::P24_ID, $id) ? self::P24 . ':' . $id : null;
        }
        if ($portal === self::PP) {
            return preg_match(self::PP_ID, $id) ? self::PP . ':' . strtoupper($id) : null;
        }

        return null;
    }
}
