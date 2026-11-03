<?php

declare(strict_types=1);

namespace App\Support\Geocoding;

/**
 * The one rule for reusing a coordinate that belongs to another record (a report's subject, a portal
 * capture): the street NUMBER, the street NAME and the SUBURB must all match. Spec: geocoding-spec.md
 * "Reusing a stored coordinate".
 *
 * Bug class (27 Oct 2026): the resolver matched a report subject on the street name alone, so "14 Manikie
 * Street" was pinned exactly on the subject "11 Manikie Street" and the comparable pool then dropped it as
 * the subject itself. A different number on the same street is a different house — it goes to the real
 * geocoder, it never inherits the neighbour's pin. An address with no number, or a stored address with no
 * number, is never reused either.
 *
 * No street-type canonicalisation ("St" vs "Street"): a miss falls through to Google, which is safe; a
 * false hit puts a house on its neighbour.
 */
final class StreetAddressMatch
{
    /**
     * The "number street" part of a free-form address: the first comma-separated piece that starts with a
     * house number and has a street name after it. "Blue Valley Golf Estate, 1936 Mininkie Street" gives
     * ['number' => '1936', 'street' => 'mininkie street'].
     *
     * @return array{number: string, street: string}|null
     */
    public static function parse(string $address): ?array
    {
        foreach (explode(',', $address) as $piece) {
            $piece = self::normalise($piece);
            if (preg_match('/^(\d+[a-z]?)\s+([a-z][a-z0-9 ]{2,})$/', $piece, $m)) {
                return ['number' => $m[1], 'street' => $m[2]];
            }
        }

        return null;
    }

    /**
     * Does stored text (a report's subject address, a capture's title + location) carry exactly this number
     * and street, and — when a suburb is given — that suburb? A missing suburb never matches: the third
     * leg of the rule cannot be confirmed.
     *
     * @param array{number: string, street: string} $parsed
     */
    public static function matches(string $stored, array $parsed, ?string $suburb, string $extra = ''): bool
    {
        $suburb = trim((string) $suburb);
        if ($suburb === '') {
            return false;
        }

        $stored = self::normalise($stored);
        $street = '(?<![a-z0-9])' . preg_quote($parsed['number'], '/') . '\s+' . str_replace(' ', '\s+', preg_quote($parsed['street'], '/')) . '(?![a-z0-9])';
        if (!preg_match('/' . $street . '/', $stored)) {
            return false;
        }

        $where = $stored . ' ' . self::normalise($extra);

        return (bool) preg_match('/(?<![a-z0-9])' . str_replace(' ', '\s+', preg_quote(self::normalise($suburb), '/')) . '(?![a-z0-9])/', $where);
    }

    public static function normalise(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
