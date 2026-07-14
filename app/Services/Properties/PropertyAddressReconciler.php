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
 * the structured columns while `address` sat frozen as a hidden passthrough. Two
 * corruptions came through that door:
 *
 *   NEWLINE-GLUE   an `<input type="text">` cannot hold the newline that a
 *                  two-line imported street_name contained, so opening the modal
 *                  and pressing Save rewrote it to "Umzimkhulu Court40 Bulwer
 *                  Street". Nobody typed that; no agent could have prevented it.
 *
 *   COMPLEX-BLEED  agents added the complex and unit to the address — right
 *                  instinct, wrong box. The complex has its own field, so the
 *                  scheme name ended up in BOTH it and street_name.
 *
 * This class does NOT throw the agent's work away. Their enrichment (the complex,
 * the unit) was correct information in the wrong column; the proposal moves it to
 * the right column and recomposes the display string from the parts.
 *
 * Every proposal carries a confidence. HIGH means a rule matched cleanly and the
 * resulting street still appears in the address of record. REVIEW means we can see
 * the row is wrong but cannot repair it without guessing — those are never
 * auto-applied, they go to a human.
 */
final class PropertyAddressReconciler
{
    public const OK      = 'ok';        // coherent already — nothing to do
    public const HIGH    = 'high';      // confident repair
    public const REVIEW  = 'review';    // broken, but needs a human

    /**
     * @return array{
     *   status: string, rule: string, reason: string,
     *   before: array<string,?string>, after: array<string,?string>
     * }
     */
    public function analyse(Property $p): array
    {
        $before = [
            'street_number'      => self::s($p->street_number),
            'street_name'        => self::s($p->street_name),
            'complex_name'       => self::s($p->complex_name),
            'unit_number'        => self::s($p->unit_number),
            'address'            => self::s($p->address),
        ];

        // Already coherent? The address must equal what the parts compose to.
        if ($before['address'] !== '' && $before['address'] === $p->composeAddressFromParts()) {
            return $this->result(self::OK, 'none', 'address already matches its parts', $before, $before);
        }

        $after = $before;
        $rule  = 'recompose';
        $reason = 'address recomposed from its structured parts';

        // ── Rule 1 — NEWLINE-GLUE ────────────────────────────────────────
        // street_name is the address with its line break DELETED. The address of
        // record still holds the original lines, so rebuild the parts from THEM.
        $glued = preg_replace('/\R+/u', '', $before['address']);
        if (str_contains($before['address'], "\n") && self::eq($before['street_name'], (string) $glued)) {
            $lines = array_values(array_filter(array_map(
                fn ($l) => trim($l, " \t,"),
                preg_split('/\R+/u', $before['address']) ?: []
            )));

            $streetLine = null;
            $otherLines = [];
            foreach ($lines as $line) {
                // The street line is the one that opens with a house number.
                if ($streetLine === null && preg_match('/^\s*\d+[A-Za-z]?\s+\S/u', $line)) {
                    $streetLine = $line;
                } else {
                    $otherLines[] = $line;
                }
            }

            if ($streetLine !== null) {
                preg_match('/^\s*(\d+[A-Za-z]?)\s+(.+)$/u', $streetLine, $m);
                $after['street_number'] = $m[1];
                $after['street_name']   = trim($m[2]);
                // The remaining line is the scheme — keep an existing complex if set.
                if ($after['complex_name'] === '' && !empty($otherLines)) {
                    $after['complex_name'] = $this->stripUnitFrom($otherLines[0], $after['unit_number']);
                }
                $after = $this->recompose($p, $after);
                return $this->result(self::HIGH, 'newline-glue', 'the line break was deleted by a single-line input; parts rebuilt from the address of record', $before, $after);
            }

            // No line opens with a number — we can see it is broken, but which half
            // is the street is a guess. A human decides.
            return $this->result(self::REVIEW, 'newline-glue', 'multi-line address with no identifiable house number — cannot split without guessing', $before, $before);
        }

        // ── Rule 2 — COMPLEX-BLEED ───────────────────────────────────────
        // The scheme name and/or unit was typed into the street-name box. Strip it
        // back out; the complex column already holds it (or gains it).
        if ($before['street_name'] !== '') {
            $cleanedComplex = $this->stripUnitFrom($before['complex_name'], $before['unit_number']);
            $street = $this->stripSchemeFrom($before['street_name'], $cleanedComplex, $before['unit_number']);

            // Did the strip leave a real street that the address of record agrees with?
            if ($street !== '' && $street !== $before['street_name']) {
                $after['street_name']  = $street;
                $after['complex_name'] = $cleanedComplex !== '' ? $cleanedComplex : $before['complex_name'];
                $after = $this->recompose($p, $after);

                $agrees = str_contains(self::norm($before['address']), self::norm($street));
                return $this->result(
                    $agrees ? self::HIGH : self::REVIEW,
                    'complex-bleed',
                    $agrees
                        ? 'the scheme name was in the street-name box; moved to the complex column and the street kept'
                        : 'scheme name stripped from the street, but the result does not appear in the address of record',
                    $before, $after
                );
            }
        }

        // ── Fallback — the parts are believable, the string is just stale ──
        $after = $this->recompose($p, $after);
        if ($after['address'] === '' ) {
            return $this->result(self::REVIEW, 'empty', 'no structured part to compose an address from', $before, $before);
        }

        return $this->result(self::HIGH, $rule, $reason, $before, $after);
    }

    /** Recompose the display string from the (possibly repaired) parts. */
    private function recompose(Property $p, array $after): array
    {
        $clone = $p->replicate();
        $clone->street_number      = $after['street_number'] ?: null;
        $clone->street_name        = $after['street_name'] ?: null;
        $clone->complex_name       = $after['complex_name'] ?: null;
        $clone->unit_number        = $after['unit_number'] ?: null;

        $after['address'] = $clone->composeAddressFromParts();

        return $after;
    }

    /**
     * Remove the scheme name and unit number from a street-name value.
     * "26 Stafford Close Marine Drive" − complex "26 Stafford Close" → "Marine Drive"
     * "Marine, Marlin 1"              − complex "Marlin Flats", unit 1 → "Marine"
     */
    private function stripSchemeFrom(string $street, string $complex, string $unit): string
    {
        $tokens = preg_split('/\s+/u', trim($street), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Tokens that belong to the scheme, not the street.
        $schemeTokens = [];
        foreach (preg_split('/\s+/u', $complex, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
            $schemeTokens[] = self::norm($t);
        }
        if ($unit !== '') {
            $schemeTokens[] = self::norm($unit);
        }
        $schemeTokens[] = 'unit';
        $schemeTokens = array_filter(array_unique($schemeTokens));

        $kept = [];
        foreach ($tokens as $t) {
            $bare = self::norm(rtrim($t, ','));
            if ($bare !== '' && in_array($bare, $schemeTokens, true)) {
                continue;   // scheme token — drop it from the street
            }
            $kept[] = rtrim($t, ',');
        }

        return trim(implode(' ', $kept), " \t,");
    }

    /** "Esmezee Unit 4" → "Esmezee"; "Del Este  unit 6" → "Del Este". */
    private function stripUnitFrom(string $complex, string $unit): string
    {
        $out = $complex;
        if ($unit !== '') {
            $out = preg_replace('/\bunit\s*' . preg_quote($unit, '/') . '\b/iu', '', $out) ?? $out;
        }
        $out = preg_replace('/\bunit\b\s*$/iu', '', $out) ?? $out;
        $out = preg_replace('/\s+/u', ' ', $out) ?? $out;

        return trim($out, " \t,");
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
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s)) ?? '');
    }

    private static function eq(string $a, string $b): bool
    {
        return self::norm($a) === self::norm($b);
    }
}
