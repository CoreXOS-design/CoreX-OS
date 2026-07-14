<?php

declare(strict_types=1);

namespace App\Services\Properties;

use App\Models\Property;

/**
 * AT-266 — propose a coherent address for a property whose structured columns and
 * `address` string have drifted apart.
 *
 * The drift happened because the two were independent copies of one fact: the P24
 * import wrote both consistently, then the Internal Address modal let agents edit
 * the structured columns while `address` sat frozen as a hidden passthrough.
 * Several corruptions came through that door:
 *
 *   NEWLINE-GLUE    an `<input type="text">` cannot hold the newline a two-line
 *                   imported street_name contained, so opening the modal and
 *                   pressing Save rewrote it to "Umzimkhulu Court40 Bulwer Street".
 *                   Nobody typed that; no agent could have prevented it.
 *
 *   SCHEME-IN-STREET  the scheme name sits inside street_name — either alongside a
 *                   populated complex column ("26 Stafford Close Marine Drive") or
 *                   INSTEAD of one, with the complex column left empty
 *                   ("Aqua Pearl, 55 Queen Street"). Agents were recording the
 *                   complex; the street box was simply the only one they filled.
 *
 *   UNIT-AS-NUMBER  street_number holds the UNIT ("9 Casa Montana" — unit 9,
 *                   street_number 9, street_name "Casa Montana"), or something that
 *                   is not a house number at all ("The" / "Farm Estates").
 *
 * The agent's work is never thrown away. Their enrichment — the complex, the unit —
 * was correct information in the wrong column; a proposal MOVES it to the right
 * column and recomposes the display string from the parts.
 *
 * THE SAFETY INVARIANT: a proposal may never lose information. Every alphanumeric
 * token in the original address must survive into the proposed one. If a repair
 * would drop a token, it is not a repair — it is downgraded to REVIEW and left for
 * a human. This is what stops a well-meaning rule from quietly deleting half of
 * somebody's address.
 */
final class PropertyAddressReconciler
{
    public const OK     = 'ok';      // coherent already — nothing to do
    public const HIGH   = 'high';    // confident repair
    public const REVIEW = 'review';  // broken, but repairing it would mean guessing

    /**
     * @return array{
     *   status: string, rule: string, reason: string,
     *   before: array<string,string>, after: array<string,string>
     * }
     */
    public function analyse(Property $p): array
    {
        $before = [
            'street_number' => self::s($p->street_number),
            'street_name'   => self::s($p->street_name),
            'complex_name'  => self::s($p->complex_name),
            'unit_number'   => self::s($p->unit_number),
            'address'       => self::s($p->address),
        ];

        if ($before['address'] !== '' && $before['address'] === $p->composeAddressFromParts()) {
            return $this->result(self::OK, 'none', 'address already matches its parts', $before, $before);
        }

        [$parts, $rule, $reason] = $this->repair($before);

        if ($parts === null) {
            return $this->result(self::REVIEW, $rule, $reason, $before, $before);
        }

        $after = $this->recompose($p, $parts);

        // The invariant. A repair that loses a token is not a repair.
        $lost = $this->tokensLost($before['address'], $after['address']);
        if ($lost !== []) {
            return $this->result(
                self::REVIEW, $rule,
                'a repair would drop "' . implode(' ', $lost) . '" from the address — refusing to guess',
                $before, $before
            );
        }

        return $this->result(self::HIGH, $rule, $reason, $before, $after);
    }

    /**
     * @param  array<string,string> $b
     * @return array{0: ?array<string,string>, 1: string, 2: string}
     */
    private function repair(array $b): array
    {
        $number  = $b['street_number'];
        $street  = $b['street_name'];
        $complex = $b['complex_name'];
        $unit    = $b['unit_number'];

        // ── NEWLINE-GLUE ────────────────────────────────────────────────
        $glued = (string) preg_replace('/\R+/u', '', $b['address']);
        if (str_contains($b['address'], "\n") && self::eq($street, $glued)) {
            $lines = self::lines($b['address']);
            $streetLine = null;
            $rest = [];
            foreach ($lines as $line) {
                if ($streetLine === null && self::opensWithHouseNumber($line)) {
                    $streetLine = $line;
                } else {
                    $rest[] = $line;
                }
            }
            if ($streetLine === null) {
                return [null, 'newline-glue', 'multi-line address with no identifiable house number — cannot split without guessing'];
            }
            [$number, $street] = self::splitHouseNumber($streetLine);
            if ($complex === '' && $rest !== []) {
                $complex = self::stripUnitWord($rest[0], $unit);
            }
            return [compact('number', 'street', 'complex', 'unit'),
                'newline-glue',
                'the line break was deleted by a single-line input; parts rebuilt from the address of record'];
        }

        // ── UNIT-AS-NUMBER — street_number is not a house number ─────────
        // "The" / "Farm Estates", or the unit repeated as the street number on a
        // street_name that carries no house number of its own.
        if ($number !== '' && !self::isHouseNumber($number)) {
            $street = trim($number . ' ' . $street);
            $number = '';
        } elseif ($number !== '' && $unit !== '' && $number === $unit && !self::opensWithHouseNumber($street)) {
            // "9 Casa Montana": the 9 is the UNIT, and "Casa Montana" is a scheme,
            // not a street. Drop the duplicated number.
            $number = '';
        }

        // ── SCHEME-IN-STREET, complex column EMPTY ───────────────────────
        // "Aqua Pearl, 55 Queen Street" → complex "Aqua Pearl", street "55 Queen Street"
        // "Villa Moya, Marine Drive"    → complex "Villa Moya", street "Marine Drive"
        if ($complex === '' && str_contains($street, ',')) {
            $segments = self::segments($street);
            if (count($segments) >= 2) {
                $tail = array_pop($segments);
                $complex = trim(implode(', ', $segments));
                [$number2, $street] = self::splitHouseNumber($tail);
                if ($number2 !== '') {
                    $number = $number2;
                }
                return [compact('number', 'street', 'complex', 'unit'),
                    'scheme-in-street',
                    'the scheme name was in the street-name box with no complex recorded; moved to the complex column'];
            }
        }

        // ── SCHEME-IN-STREET, complex column POPULATED ───────────────────
        // "26 Stafford Close Marine Drive" with complex "26 Stafford Close".
        if ($complex !== '') {
            $cleanComplex = self::stripUnitWord($complex, $unit);
            $stripped = self::stripLeading($street, $cleanComplex);
            if ($stripped === null) {
                $stripped = self::stripTrailingSegment($street, $cleanComplex, $unit);
            }
            if ($stripped !== null && $stripped !== '') {
                return [['number' => $number, 'street' => $stripped, 'complex' => $cleanComplex, 'unit' => $unit],
                    'scheme-in-street',
                    'the scheme name was duplicated into the street-name box; removed from the street and kept in the complex'];
            }
        }

        // ── A scheme with no street at all ("Casa Montana") ──────────────
        if ($complex === '' && $number === '' && $street !== '' && $unit !== '' && !str_contains($street, ',')) {
            // A unit in something named, with no house number anywhere: the name is
            // the scheme, not a street.
            if (!self::opensWithHouseNumber($street)) {
                return [['number' => '', 'street' => '', 'complex' => $street, 'unit' => $unit],
                    'scheme-in-street',
                    'a unit in a named scheme with no house number — the name is the scheme, not a street'];
            }
        }

        // ── Nothing structural to repair: the parts are believable, the
        //    display string is merely stale. Recompose it.
        if ($street === '' && $complex === '' && $unit === '') {
            return [null, 'empty', 'no structured part to compose an address from'];
        }

        return [compact('number', 'street', 'complex', 'unit'),
            'recompose', 'the parts are sound — the display string was stale'];
    }

    /** @param array<string,string> $parts */
    private function recompose(Property $p, array $parts): array
    {
        $clone = $p->replicate();
        $clone->street_number = $parts['number']  !== '' ? $parts['number']  : null;
        $clone->street_name   = $parts['street']  !== '' ? $parts['street']  : null;
        $clone->complex_name  = $parts['complex'] !== '' ? $parts['complex'] : null;
        $clone->unit_number   = $parts['unit']    !== '' ? $parts['unit']    : null;

        return [
            'street_number' => $parts['number'],
            'street_name'   => $parts['street'],
            'complex_name'  => $parts['complex'],
            'unit_number'   => $parts['unit'],
            'address'       => $clone->composeAddressFromParts(),
        ];
    }

    /** Tokens present in the original address but missing from the proposed one. */
    private function tokensLost(string $original, string $proposed): array
    {
        $want = array_filter(explode(' ', self::norm($original)));
        $have = array_filter(explode(' ', self::norm($proposed)));

        // array_unique (NOT array_count_values) — PHP coerces a numeric string ARRAY
        // KEY into an int, so "9" would come back as 9 and never strict-match the
        // string "9" in $have. Every address with a house number would then be
        // reported as losing its number.
        $lost = [];
        foreach (array_unique($want) as $token) {
            if (!in_array((string) $token, $have, true)) {
                $lost[] = (string) $token;
            }
        }

        return $lost;
    }

    // ── String helpers ───────────────────────────────────────────────────

    /** Strip "<complex> " from the FRONT of a street, preserving the remainder verbatim. */
    private static function stripLeading(string $street, string $complex): ?string
    {
        if ($complex === '') {
            return null;
        }
        $pattern = '/^' . preg_quote($complex, '/') . '[\s,]+/iu';
        $out = preg_replace($pattern, '', $street, 1, $count);

        return ($count ?? 0) > 0 ? trim((string) $out, " \t,") : null;
    }

    /** Strip a trailing ", <complex-ish> [unit]" segment ("Colin, Seeskulp" → "Colin"). */
    private static function stripTrailingSegment(string $street, string $complex, string $unit): ?string
    {
        $segments = self::segments($street);
        if (count($segments) < 2 || $complex === '') {
            return null;
        }

        $tail = array_pop($segments);
        $tailTokens = array_filter(explode(' ', self::norm($tail)));
        $schemeTokens = array_filter(explode(' ', self::norm($complex)));
        if ($unit !== '') {
            $schemeTokens[] = self::norm($unit);
        }

        // The tail belongs to the scheme when every one of its tokens does.
        foreach ($tailTokens as $t) {
            if (!in_array($t, $schemeTokens, true)) {
                return null;
            }
        }

        return trim(implode(', ', $segments), " \t,");
    }

    /** "Esmezee Unit 4" → "Esmezee"; "Del Este  unit 6" → "Del Este". */
    private static function stripUnitWord(string $complex, string $unit): string
    {
        $out = $complex;
        if ($unit !== '') {
            $out = (string) preg_replace('/\bunit\s*' . preg_quote($unit, '/') . '\b/iu', '', $out);
        }
        $out = (string) preg_replace('/\s+/u', ' ', $out);

        return trim($out, " \t,");
    }

    /** @return array{0:string,1:string} [number, name] */
    private static function splitHouseNumber(string $line): array
    {
        $line = trim($line, " \t,");
        if (preg_match('/^\s*(\d+[A-Za-z]?)\s+(.+)$/u', $line, $m)) {
            return [$m[1], trim($m[2])];
        }

        return ['', $line];
    }

    private static function isHouseNumber(string $v): bool
    {
        return (bool) preg_match('/^\d+[A-Za-z]?$/u', trim($v));
    }

    private static function opensWithHouseNumber(string $line): bool
    {
        return (bool) preg_match('/^\s*\d+[A-Za-z]?\s+\S/u', $line);
    }

    /** @return list<string> */
    private static function lines(string $raw): array
    {
        return array_values(array_filter(array_map(
            fn ($l) => trim($l, " \t,"),
            preg_split('/\R+/u', $raw) ?: []
        )));
    }

    /** @return list<string> */
    private static function segments(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($s) => $s !== ''));
    }

    private function result(string $status, string $rule, string $reason, array $before, array $after): array
    {
        return compact('status', 'rule', 'reason', 'before', 'after');
    }

    private static function s(mixed $v): string
    {
        return trim((string) ($v ?? ''));
    }

    private static function norm(string $s): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s)));
    }

    private static function eq(string $a, string $b): bool
    {
        return self::norm($a) === self::norm($b);
    }
}
