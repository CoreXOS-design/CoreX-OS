<?php

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\P24Suburb;
use App\Models\Prospecting\TrackedPropertyAddress;

/**
 * Suburb text -> the Property24 suburb CoreX already holds (.ai/specs/structured-address-matching.md §4.4).
 *
 * Tries, in order, the text as written, its normalised form (apostrophes dropped, punctuation
 * spaced, "Saint"/"St" twins) and every spelling in its alias group (config groups + the
 * suburb_aliases reference table), asking P24Suburb::lookup() each time — which already narrows a
 * same-named suburb by province and then by distance (<= 25 km, never a "least far" guess).
 *
 * NOT resolvable => null. The caller marks the address `review`; a suburb is never guessed from
 * the nearest centroid. Read-only, never throws (an unreadable suburb list must never break a save).
 * The returned id is OUR internal `p24_suburbs.id` — the same id space `properties.p24_suburb_id`
 * has always used (see P24LocationResolver) — never Property24's external id.
 */
final class SuburbResolver
{
    /** @var array<string, array{p24_suburb_id: ?int, p24_city_id: ?int, name: ?string, method: ?string}> */
    private static array $memo = [];

    /**
     * Off by default: a lookup is 1-3 cheap queries, and a process-wide memo would go stale the moment the
     * P24 suburb list changes (it would hand back an id from a row that no longer exists). Bulk jobs
     * (the backfill) switch it on for their own run, then off again.
     */
    private static bool $memoise = false;

    public static function memoise(bool $on): void
    {
        self::$memoise = $on;
        self::$memo = [];
    }

    /** Forget memoised lookups (between tests, and after the P24 suburb list changes). */
    public static function flush(): void
    {
        self::$memo = [];
    }

    /**
     * @return array{p24_suburb_id: ?int, p24_city_id: ?int, name: ?string, method: ?string}
     */
    public static function resolve(?string $suburb, ?string $province = null, ?float $lat = null, ?float $lng = null): array
    {
        $none = ['p24_suburb_id' => null, 'p24_city_id' => null, 'name' => null, 'method' => null];
        $suburb = $suburb === null ? '' : trim($suburb);
        if ($suburb === '') {
            return $none;
        }

        $memoKey = mb_strtolower($suburb) . '|' . mb_strtolower((string) $province)
            . '|' . ($lat === null ? '' : round($lat, 2)) . '|' . ($lng === null ? '' : round($lng, 2));
        if (self::$memoise && isset(self::$memo[$memoKey])) {
            return self::$memo[$memoKey];
        }

        $result = $none;
        try {
            foreach (self::variants($suburb) as [$variant, $method]) {
                $hit = P24Suburb::lookup($variant, $province, $lat, $lng);
                if ($hit !== null) {
                    $result = [
                        'p24_suburb_id' => (int) $hit->id,
                        'p24_city_id'   => $hit->p24_city_id !== null ? (int) $hit->p24_city_id : null,
                        'name'          => (string) $hit->name,
                        'method'        => $method,
                    ];
                    break;
                }
            }
        } catch (\Throwable $e) {
            $result = $none;
        }

        if (self::$memoise) {
            self::$memo[$memoKey] = $result;
        }

        return $result;
    }

    /**
     * Candidate spellings, most literal first.
     *
     * @return array<int, array{0: string, 1: string}>  [spelling, method]
     */
    private static function variants(string $suburb): array
    {
        $out = [[$suburb, 'exact']];
        $normalised = TrackedPropertyAddress::normaliseSuburb($suburb);
        if ($normalised !== null) {
            $out[] = [$normalised, 'normalised'];
        }
        foreach (TrackedPropertyAddress::suburbSpellingKeys($suburb) as $key) {
            $out[] = [$key, 'alias_or_twin'];
        }

        $seen = [];

        return array_values(array_filter($out, function ($pair) use (&$seen) {
            $k = mb_strtolower($pair[0]);
            if (isset($seen[$k])) {
                return false;
            }
            $seen[$k] = true;

            return true;
        }));
    }
}
