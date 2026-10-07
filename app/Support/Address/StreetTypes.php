<?php

declare(strict_types=1);

namespace App\Support\Address;

/**
 * THE one street-type table (.ai/specs/structured-address-matching.md §4) — English and
 * Afrikaans, abbreviations and full words, mapped to one canonical lowercase type. Replaces the
 * three private copies of "street / road / avenue …" that the matchers each kept.
 *
 * National, not regional: nothing here is HFC- or KZN-specific (a Cape Town agency writes "Kerk
 * Straat" and "Bloekomlaan"). The type is ALWAYS the last word of a street line in SA addresses,
 * so only a trailing word is ever read as a type — "St Michaels Manor" stays a name ("st" = Saint).
 */
final class StreetTypes
{
    /** canonical type => every spelling that means it (lowercase, no punctuation). */
    private const TYPES = [
        'street'    => ['street', 'st', 'str', 'straat'],
        'road'      => ['road', 'rd', 'weg', 'pad'],
        'avenue'    => ['avenue', 'ave', 'av', 'laan'],
        'drive'     => ['drive', 'dr', 'drv', 'rylaan'],
        'close'     => ['close', 'cl', 'cls'],
        'crescent'  => ['crescent', 'cres', 'cr', 'crs', 'cresc'],
        'lane'      => ['lane', 'ln'],
        'place'     => ['place', 'pl'],
        'way'       => ['way'],
        'terrace'   => ['terrace', 'ter', 'terr'],
        'walk'      => ['walk'],
        'circle'    => ['circle', 'cir', 'circ', 'singel'],
        'court'     => ['court', 'ct'],
        'rise'      => ['rise'],
        'view'      => ['view'],
        'parade'    => ['parade', 'pde'],
        'path'      => ['path'],
        'row'       => ['row'],
        'mews'      => ['mews'],
        'boulevard' => ['boulevard', 'blvd', 'bvd'],
        'highway'   => ['highway', 'hwy'],
        'route'     => ['route', 'rte'],
        'square'    => ['square', 'sq', 'plein'],
        'gardens'   => ['gardens', 'gdns'],
        'grove'     => ['grove'],
        'loop'      => ['loop'],
        'link'      => ['link'],
        'bend'      => ['bend'],
        'heights'   => ['heights'],
        'esplanade' => ['esplanade'],
        'promenade' => ['promenade'],
    ];

    /** @var array<string, string>|null spelling => canonical */
    private static ?array $lookup = null;

    /** @return array<string, string> */
    private static function lookup(): array
    {
        if (self::$lookup === null) {
            self::$lookup = [];
            foreach (self::TYPES as $canonical => $spellings) {
                foreach ($spellings as $s) {
                    self::$lookup[$s] = $canonical;
                }
            }
        }

        return self::$lookup;
    }

    /** Canonical type for a single word, or null when the word is not a street type. */
    public static function canonical(?string $word): ?string
    {
        if ($word === null) {
            return null;
        }
        $w = rtrim(mb_strtolower(trim($word)), '.');

        return self::lookup()[$w] ?? null;
    }

    /** True when the word is any street type (canonical, abbreviation or Afrikaans). */
    public static function isType(?string $word): bool
    {
        return self::canonical($word) !== null;
    }

    /**
     * Split the trailing type off a list of lowercase name words:
     * ['grindewald', 'drive'] -> [['grindewald'], 'drive']. A line of one word is never split
     * (a street called just "Drive" keeps it as its name), and "st" only counts as the type when it
     * is the LAST word and there is a name before it.
     *
     * @param  array<int, string>  $words
     * @return array{0: array<int, string>, 1: ?string}
     */
    public static function splitTrailing(array $words): array
    {
        $words = array_values($words);
        if (count($words) > 1) {
            $type = self::canonical($words[count($words) - 1]);
            if ($type !== null) {
                array_pop($words);

                return [$words, $type];
            }
        }

        return [$words, null];
    }

    /** @return array<int, string> every canonical type (for a UI picker or a test). */
    public static function all(): array
    {
        return array_keys(self::TYPES);
    }
}
