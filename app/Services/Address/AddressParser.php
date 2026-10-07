<?php

declare(strict_types=1);

namespace App\Services\Address;

use App\Support\Address\StreetTypes;

/**
 * THE one address reader (.ai/specs/structured-address-matching.md §4).
 *
 * Pure text in, structured fields out — no database, no clock, idempotent (feed the output back in
 * and the same columns come out). It replaces the eight private normalisers the matchers each kept
 * (TrackedPropertyAddress::normaliseStreet, TPMC::streetParts/streetNumberSet, AddressNormaliser,
 * ProspectingListing::normalizeAddress, SuburbMatcher, the extension's splitStreetAddress …).
 *
 * Input keys (all optional): address, street_number, street_name, unit_number, section_number,
 * complex_name, scheme_name, scheme_number, suburb, town, city, municipality, province, lpi_code,
 * erf_number, erf_portion, cma_street_number.
 *
 * The suburb is NOT resolved here (that needs the P24 table — see SuburbResolver / AddressStructurer);
 * the suburb/town/province texts are only used to recognise and drop a "…, Umhlali Golf Estate, Ballito"
 * tail so it is never mistaken for a street.
 *
 * It never invents: no number in the text = no street number; two different numbers or two different
 * streets = `review`, never a pick; nothing readable = `unparseable`.
 */
final class AddressParser
{
    private const ABSENT = ['-', 'n/a', 'na', '—', '–'];

    private const ORDINALS = [
        'first' => '1st', 'second' => '2nd', 'third' => '3rd', 'fourth' => '4th', 'fifth' => '5th', 'sixth' => '6th',
        'seventh' => '7th', 'eighth' => '8th', 'ninth' => '9th', 'tenth' => '10th', 'eleventh' => '11th', 'twelfth' => '12th',
    ];

    private const NUMBER = '\d+[A-Za-z]?(?:\s*[\/-]\s*\d+[A-Za-z]?)?';

    /**
     * @param  array<string, mixed>  $in
     * @return array{
     *   street_number: ?string, street_core: ?string, street_type: ?string, street_name_clean: ?string,
     *   unit_number: ?string, section_number: ?string, complex_name: ?string, scheme_number: ?string,
     *   erf_number: ?string, erf_portion: ?string, township: ?string, lpi_code: ?string,
     *   raw: ?string, status: string, notes: array<int,string>, conflicts: array<int,string>
     * }
     */
    public function parse(array $in): array
    {
        $notes = [];
        $conflicts = [];

        // ── LPI: erf + portion + township, the strongest freehold identity ──────────────────
        $lpi = LpiCode::parse($this->str($in['lpi_code'] ?? null));
        $erfExplicit = $this->str($in['erf_number'] ?? null);
        if ($erfExplicit !== null) {
            $erfExplicit = trim((string) preg_replace('/^\s*erf\s*/i', '', $erfExplicit));
            $erfExplicit = $erfExplicit === '' ? null : $erfExplicit;
        }
        $erf = $erfExplicit ?? ($lpi['erf'] ?? null);
        if ($erfExplicit !== null && $lpi !== null && $this->numericKey($erfExplicit) !== $this->numericKey($lpi['erf'])) {
            $conflicts[] = 'erf_lpi_disagree';
            $notes[] = 'The erf number (' . $erfExplicit . ') and the LPI code (' . $lpi['erf'] . ') disagree.';
        }
        $portion = $this->str($in['erf_portion'] ?? null) ?? ($lpi['portion'] ?? null);

        // ── places to drop from the street text ("…, Umhlali Golf Estate, Ballito") ─────────
        $places = [];
        foreach (['suburb', 'town', 'city', 'municipality', 'province'] as $k) {
            $v = $this->str($in[$k] ?? null);
            if ($v !== null) {
                $places[$this->placeKey($v)] = true;
            }
        }

        // ── street text: street_name first, the full address line when that gives no street ──
        $line = $this->selectStreetLine($this->str($in['street_name'] ?? null), $places);
        if ($line['street'] === null) {
            $fromAddress = $this->selectStreetLine($this->str($in['address'] ?? null), $places);
            if ($fromAddress['street'] !== null || ($line['complex'] === [] && $line['unit'] === null)) {
                $line = $fromAddress;
            }
        }
        foreach ($line['conflicts'] as $c) {
            $conflicts[] = $c;
        }
        foreach ($line['notes'] as $n) {
            $notes[] = $n;
        }

        // ── street number: explicit > the CMA street-number row > lifted from the text ──────
        $explicitNumber = $this->validNumber($this->str($in['street_number'] ?? null));
        if ($this->str($in['street_number'] ?? null) !== null && $explicitNumber === null) {
            $notes[] = 'The street number column ("' . $this->str($in['street_number'] ?? null) . '") is not a house number — ignored.';
        }
        $cmaNumber = $this->validNumber($this->str($in['cma_street_number'] ?? null));
        $lifted = $line['street']['number'] ?? null;
        $number = $explicitNumber ?? $cmaNumber ?? $lifted;
        foreach ([$explicitNumber, $cmaNumber] as $given) {
            if ($given !== null && $lifted !== null && $this->numberKey($given) !== $this->numberKey($lifted)) {
                $conflicts[] = 'number_sources_disagree';
                $notes[] = 'The street number is ' . $given . ' in one place and ' . $lifted . ' in the street text.';
                break;
            }
        }
        if ($explicitNumber !== null && $cmaNumber !== null && $this->numberKey($explicitNumber) !== $this->numberKey($cmaNumber)) {
            $conflicts[] = 'number_sources_disagree';
            $notes[] = 'The street number differs between the address columns (' . $explicitNumber . ' / ' . $cmaNumber . ').';
        }

        // ── street name / type ───────────────────────────────────────────────────────────────
        $core = $line['street']['core'] ?? null;
        $type = $line['street']['type'] ?? null;

        // ── unit, section, complex, scheme ───────────────────────────────────────────────────
        $unit = $this->str($in['unit_number'] ?? null) ?? $line['unit'];
        $complexExplicit = $this->str($in['complex_name'] ?? null) ?? $this->str($in['scheme_name'] ?? null);
        $complex = $complexExplicit ?? ($line['complex'] !== [] ? implode(', ', $line['complex']) : null);
        if ($complexExplicit === null && $line['liftedUnitFromComplex'] !== null && $unit === null) {
            $unit = $line['liftedUnitFromComplex'];
        }

        $schemeNumber = $this->str($in['scheme_number'] ?? null);
        $section = $this->str($in['section_number'] ?? null);

        $status = 'parsed';
        if ($conflicts !== []) {
            $status = 'review';
        } elseif ($core === null && $complex === null && $schemeNumber === null && $erf === null && $lpi === null) {
            $status = 'unparseable';
            $notes[] = 'No street, scheme or erf could be read from this address.';
        }

        return [
            'street_number'     => $number,
            'street_core'       => $core,
            'street_type'       => $type,
            'street_name_clean' => $core === null ? null : $this->display($core, $type),
            'unit_number'       => $unit,
            'section_number'    => $section,
            'complex_name'      => $complex,
            'scheme_number'     => $schemeNumber,
            'erf_number'        => $erf,
            'erf_portion'       => $portion,
            'township'          => $lpi['township'] ?? null,
            'lpi_code'          => $lpi['code'] ?? null,
            'raw'               => $this->raw($in),
            'status'            => $status,
            'notes'             => array_values(array_unique($notes)),
            'conflicts'         => array_values(array_unique($conflicts)),
        ];
    }

    /**
     * [number key, street core] of a stored street_number + street_name — the number may live inside the name on
     * old rows. A stored street_core wins when given. Used by the map-pin fold and any caller that needs ONE
     * comparable "number|street" key without building a full AddressFacts.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}  [number key, street core, street type]
     */
    public function numberAndCore(?string $number, ?string $streetName, ?string $storedCore = null): array
    {
        $r = $this->parse(['street_number' => $number, 'street_name' => $streetName]);

        return [
            self::numberKey($r['street_number']),
            ($storedCore !== null && trim($storedCore) !== '') ? trim($storedCore) : $r['street_core'],
            $r['street_type'],
        ];
    }

    /** "19", "12A", "1/3", "12-14" key: lowercase, no spaces. Null for null. */
    public static function numberKey(?string $n): ?string
    {
        if ($n === null) {
            return null;
        }
        $k = strtolower((string) preg_replace('/\s+/', '', trim($n)));

        return $k === '' ? null : $k;
    }

    /** "Grindewald Drive" from core "grindewald" + type "drive". */
    public static function display(?string $core, ?string $type): ?string
    {
        if ($core === null || $core === '') {
            return null;
        }

        return trim(ucwords($core) . ($type !== null ? ' ' . ucfirst($type) : ''));
    }

    /** Lowercase, apostrophes dropped, punctuation spaced, whitespace collapsed — for place comparisons. */
    public static function placeKey(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace(["'", '’', '`'], '', $s);
        $s = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    // ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Pick the street line out of free text, plus the unit and complex lines around it.
     *
     * "[complex/unit], [street]" is the convention every SA portal and the CMA page use, so the
     * street is the LAST segment of the best kind present: a numbered line ending in a street type
     * ("35 Grindewald Drive") beats a line ending in a type ("Sea View Court") beats a numbered line
     * beats a bare name ("Grindewald"). Segments that are just the suburb/town/province are dropped.
     *
     * @param  array<string, true>  $places
     * @return array{street: ?array{number: ?string, core: ?string, type: ?string}, unit: ?string, complex: array<int,string>, liftedUnitFromComplex: ?string, conflicts: array<int,string>, notes: array<int,string>}
     */
    private function selectStreetLine(?string $text, array $places): array
    {
        $out = ['street' => null, 'unit' => null, 'complex' => [], 'liftedUnitFromComplex' => null, 'conflicts' => [], 'notes' => []];
        if ($text === null) {
            return $out;
        }

        $segments = [];
        foreach ($this->segments($this->stripExtentPollution($text)) as $seg) {
            if (isset($places[$this->placeKey($seg)])) {
                continue;
            }
            $segments[] = $seg;
        }
        if ($segments === []) {
            return $out;
        }

        $classified = [];
        foreach ($segments as $i => $seg) {
            $c = $this->classify($seg);
            $c['index'] = $i;
            $classified[] = $c;
        }

        // A "Unit 5" / "Flat 12" segment is the unit, never the street.
        $streetCandidates = [];
        foreach ($classified as $c) {
            if ($c['kind'] === 'unit') {
                $out['unit'] ??= $c['unit'];
                continue;
            }
            $streetCandidates[] = $c;
        }
        if ($streetCandidates === []) {
            return $out;
        }

        $best = null;
        foreach ($streetCandidates as $c) {
            if ($best === null || $c['rank'] <= $best['rank']) {
                $best = $c; // lowest rank number = best kind; <= keeps the LAST of equals
            }
        }

        // Two different fully-formed street lines in one address cannot be told apart — flag, never pick.
        $strong = array_values(array_filter($streetCandidates, fn ($c) => $c['rank'] === 1));
        if (count($strong) > 1) {
            $distinct = array_unique(array_map(fn ($c) => ($c['number'] ?? '') . '|' . $c['core'] . '|' . $c['type'], $strong));
            if (count($distinct) > 1) {
                $out['conflicts'][] = 'multiple_streets';
                $out['notes'][] = 'The address names more than one street (' . implode(' / ', array_map(fn ($c) => $c['text'], $strong)) . ').';
            }
        }

        $out['street'] = ['number' => $best['number'], 'core' => $best['core'], 'type' => $best['type']];

        foreach ($streetCandidates as $c) {
            if ($c['index'] === $best['index'] || $c['rank'] === 1 && $c['core'] === $best['core']) {
                continue;
            }
            if ($c['index'] > $best['index']) {
                continue; // after the street: a stray tail, not a complex
            }
            if ($c['kind'] === 'numbered' && $c['number'] !== null && $c['core'] !== null) {
                // "4 Villa-Del-Mei" before the street: unit 4 of the complex "Villa Del Mei"
                $out['complex'][] = trim($c['rest']);
                $out['liftedUnitFromComplex'] ??= $c['number'];
                $out['notes'][] = 'A unit number was read from the complex line "' . $c['text'] . '".';
            } else {
                $out['complex'][] = $c['text'];
            }
        }

        return $out;
    }

    /**
     * @return array{kind: string, text: string, number: ?string, rest: string, core: ?string, type: ?string, unit: ?string, rank: int}
     */
    private function classify(string $seg): array
    {
        $base = ['kind' => 'name', 'text' => $seg, 'number' => null, 'rest' => $seg, 'core' => null, 'type' => null, 'unit' => null, 'rank' => 4];

        if (preg_match('/^(?:unit|flat|apartment|apt|door|section)\.?\s*#?\s*([A-Za-z0-9\/-]+)$/iu', $seg, $m)) {
            return array_merge($base, ['kind' => 'unit', 'unit' => $m[1]]);
        }

        $number = null;
        $rest = $seg;
        if (preg_match('/^\s*(' . self::NUMBER . ')\s+(.+)$/u', $seg, $m)) {
            $number = $this->tidyNumber($m[1]);
            $rest = trim($m[2]);
        }

        $words = $this->words($rest);
        [$nameWords, $type] = StreetTypes::splitTrailing($words);
        $core = $nameWords === [] ? null : implode(' ', $nameWords);
        if ($core !== null && ctype_digit(str_replace(' ', '', $core))) {
            $core = null; // "5" alone is not a street name
        }

        $kind = $number !== null ? 'numbered' : 'name';
        $rank = match (true) {
            $core === null => 5,
            $number !== null && $type !== null => 1,
            $type !== null => 2,
            $number !== null => 3,
            default => 4,
        };

        return array_merge($base, ['kind' => $kind, 'number' => $number, 'rest' => $rest, 'core' => $core, 'type' => $type, 'rank' => $rank]);
    }

    /** Lowercase name words: apostrophes dropped, punctuation spaced, ordinals numeric, a leading "Saint" = "St". */
    private function words(string $s): array
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace(["'", '’', '`'], '', $s);
        $s = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
        $words = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_map(fn ($w) => self::ORDINALS[$w] ?? $w, $words);
        if (($words[0] ?? null) === 'saint' && count($words) > 1) {
            $words[0] = 'st';
        }

        return array_values($words);
    }

    /** @return array<int, string> */
    private function segments(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,;]+|\s{2,}/u', $text) ?: [] as $seg) {
            $seg = trim($seg);
            if ($seg === '' || preg_match('/m²|\bm2\b|\bsqm\b/iu', $seg)) {
                continue;
            }
            $out[] = $seg;
        }

        return $out;
    }

    /** Cut a CMA "Cadastral Extent 1 375 M²" tail (and anything after it on its line) — old report-import pollution. */
    private function stripExtentPollution(string $text): string
    {
        return (string) preg_replace('/\s*\b(?:cadastral\s+)?extent\b.*$/imu', '', $text);
    }

    private function validNumber(?string $n): ?string
    {
        if ($n === null) {
            return null;
        }
        $n = trim($n);

        return preg_match('/^' . self::NUMBER . '$/u', $n) ? $this->tidyNumber($n) : null;
    }

    private function tidyNumber(string $n): string
    {
        return strtoupper((string) preg_replace('/\s*([\/-])\s*/', '$1', trim($n)));
    }

    /** Compare numeric identifiers the way the matchers do: leading zeros ignored, case ignored. */
    private function numericKey(?string $v): ?string
    {
        $v = $v === null ? '' : trim($v);
        if ($v === '') {
            return null;
        }

        return ctype_digit($v) ? (ltrim($v, '0') ?: '0') : mb_strtolower($v);
    }

    private function str(mixed $v): ?string
    {
        if ($v === null || is_array($v) || is_object($v)) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '' || in_array(mb_strtolower($s), self::ABSENT, true)) {
            return null;
        }

        return $s;
    }

    /** The address text as received — kept for audit only. */
    private function raw(array $in): ?string
    {
        $address = $this->str($in['address'] ?? null);
        if ($address !== null) {
            return $address;
        }
        $number = $this->str($in['street_number'] ?? null) ?? '';
        $name = $this->str($in['street_name'] ?? null) ?? '';
        // an old row already carries its number inside the name — never "19 19 Grindewald Drive"
        $line = ($number !== '' && ! preg_match('/^\s*' . preg_quote($number, '/') . '\b/u', $name)) ? trim($number . ' ' . $name) : trim($name !== '' ? $name : $number);

        return $line === '' ? null : $line;
    }
}
