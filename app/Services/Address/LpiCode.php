<?php

declare(strict_types=1);

namespace App\Services\Address;

/**
 * The 21-character Surveyor-General LPI code CMA Info prints for a freehold stand
 * (.ai/specs/structured-address-matching.md §4.1):
 *
 *   n0et 0363 00001329 00000
 *   │    │    │        └ portion (5)
 *   │    │    └ erf (8)
 *   │    └ township (4)
 *   └ registration division (4: province letter + 3)
 *
 * `n0et03630000132900000` => division n0et, township 0363, erf 1329, portion 0.
 * It is the strongest identity CoreX is ever given for a freehold property and used to be thrown
 * away (kept only inside `source_ref`). An unreadable code is IGNORED — never guessed at.
 */
final class LpiCode
{
    /**
     * @return array{code: string, division: string, township: string, erf: string, portion: string}|null
     */
    public static function parse(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $code = strtolower((string) preg_replace('/[\s\-_]+/', '', trim($raw)));
        // a source_ref such as "cmainfo:n0et0363…" is tolerated
        if (str_contains($code, ':')) {
            $code = substr($code, (int) strrpos($code, ':') + 1);
        }
        if (! preg_match('/^([a-z0-9]{4})([a-z0-9]{4})(\d{8})(\d{5})$/', $code, $m)) {
            return null;
        }

        return [
            'code'     => $code,
            'division' => $m[1],
            'township' => $m[2],
            'erf'      => ltrim($m[3], '0') ?: '0',
            'portion'  => ltrim($m[4], '0') ?: '0',
        ];
    }
}
