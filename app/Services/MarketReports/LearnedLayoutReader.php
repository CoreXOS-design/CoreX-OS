<?php

declare(strict_types=1);

namespace App\Services\MarketReports;

/**
 * Deterministic reader for a learned layout: applies a saved field mapping to a PDF's
 * text (`pdftotext -layout`) and returns the canonical read (the same shape the AI
 * returns, see CanonicalReadMapper). No AI, no cost. Spec: mic-complete-spec §8.9.
 *
 * Mapping shape (built by LayoutLearner):
 *   tables[] : {type:'comps', kind:'comp'|'listing', cells:int, columns:[{i,field,format?}]}
 *              {type:'comps', kind, text_slots:[{field,from,to}], entries:[{type:'date'|'num'|'pct', c:int, field?, format?}],
 *               min_fields:int, wrap:bool, lead?:{field:'address', index:bool}}   (an entry may carry `cs`: its column
 *               position at each scale the table is printed at, when pages differ; version 2, for tables whose rows have
 *               blank cells or tight columns, where a cell COUNT is not stable; `lead`: the row's text is printed on the
 *               line above its figures, behind a row number when `index`)
 *              {type:'stats', cells:int, year_col:int, metrics:[{i,metric}]}
 *              {type:'chart', heading:string, nth:int, series:[{metric, kind:'int'|'money_short'|'money'|'pct', rank:int}]}
 *                (figures printed as data labels on a chart: see "Charts" below)
 *   scalars[]: {field:'valuation.lower'|'suburb'|…, label:string, type:'money'|'text', placement?:'below'}
 *              (placement 'below': the label is a column heading and the figure is printed under it, in the same columns)
 *
 * Charts: pdftotext -layout puts every data label of a chart on a line of its own, at the character column where it was
 * drawn, above a line of axis labels (a row of years). A chart is found by its heading line (the nth line with exactly
 * that text) and the first row of years after it. Every line between them made only of chart labels (R 4.58 M, 44, 12 %)
 * is read; each label belongs to the year whose axis label is nearest to it (labels beside the axis, further than half a
 * year-gap from every year, belong to none). A series is "the label of this kind at this rank from the top of the
 * column" (rank -1 = the lowest): which one carries which metric is learned from the AI read and proven by the self-test.
 *
 * A table row is a line that splits (on runs of 2+ spaces) into exactly `cells` cells
 * AND whose price/date/year cells parse. Anything else — headers, footers, wrapped
 * rows — is skipped, never guessed.
 *
 * A version-2 table reads a row by what is IN it, not by how many cells it splits into. The text columns
 * (address, property type…) are the character windows they occupied when the layout was learned; every other
 * word on the line that is a date, an amount, a size, a percentage or a number is a typed token, and the tokens
 * are matched, in order and to the nearest by position, to the layout's typed columns (`entries`). A column left
 * blank in this row (a sale that was never listed has no list date / list price / days on market) simply has no
 * token. A row is a data row only when every token fits a column, its price (and sale date, if the layout has
 * one) parse, and at least `min_fields` fields hold a value. With `wrap`, a line just below a data row that
 * holds text only inside the address-type windows is that row's wrapped address and is joined onto it.
 */
final class LearnedLayoutReader
{
    public const MONEY_FIELDS = ['sale_price', 'list_price', 'municipal_valuation'];
    public const INT_FIELDS   = ['extent_m2', 'r_per_m2', 'distance_to_subject_m', 'days_on_market'];
    public const TEXT_FIELDS  = ['address', 'scheme_name', 'section_number', 'property_type', 'suburb'];

    private const DATE_FORMATS = ['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j M Y', 'd M Y', 'j F Y', 'd F Y', 'M j, Y', 'F j, Y', 'j-M-Y', 'd-M-Y'];

    /**
     * @param  array<string, mixed> $mapping
     * @return array<string, mixed> canonical read (unsanitised)
     */
    public function read(array $mapping, string $text): array
    {
        $lines = self::lines($text);
        $out = ['comps' => [], 'suburb_stats' => [], 'valuation' => [], 'subject' => []];

        foreach ((array) ($mapping['tables'] ?? []) as $table) {
            if (($table['type'] ?? null) === 'comps') {
                $rowsRead = isset($table['entries']) ? $this->readCompsPositional($lines, $table) : $this->readComps($lines, $table);
                foreach ($rowsRead as $row) {
                    $out['comps'][] = $row;
                }
            } elseif (($table['type'] ?? null) === 'stats') {
                foreach ($this->readStats($lines, $table) as $row) {
                    $out['suburb_stats'][] = $row;
                }
            } elseif (($table['type'] ?? null) === 'chart') {
                foreach ($this->readChart($lines, $table) as $row) {
                    $out['suburb_stats'][] = $row;
                }
            }
        }

        foreach ((array) ($mapping['scalars'] ?? []) as $scalar) {
            $value = ($scalar['placement'] ?? null) === 'below'
                ? $this->readScalarBelow($lines, (string) ($scalar['label'] ?? ''))
                : $this->readScalar($lines, (string) ($scalar['label'] ?? ''), (string) ($scalar['type'] ?? 'text'));
            if ($value === null) {
                continue;
            }
            $field = (string) ($scalar['field'] ?? '');
            if (str_starts_with($field, 'valuation.')) {
                $out['valuation'][substr($field, 10)] = $value;
            } elseif (str_starts_with($field, 'subject.')) {
                $out['subject'][substr($field, 8)] = $value;
            } elseif (in_array($field, ['suburb', 'town'], true)) {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    /** @return array<int, string> */
    public static function lines(string $text): array
    {
        return preg_split('/\r?\n|\f/', $text) ?: [];
    }

    /** @return array<int, string> */
    public static function cells(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return [];
        }

        return preg_split('/\s{2,}/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function parseMoney(string $s): ?int
    {
        $s = trim(str_replace("\u{00A0}", ' ', $s));
        if (!preg_match('/^R?\s*(\d[\d\s,]*)(?:\.(\d{1,2}))?$/iu', $s, $m)) {
            return null;
        }
        $digits = preg_replace('/[\s,]+/', '', $m[1]);

        return $digits === '' ? null : (int) $digits;
    }

    public static function parseSize(string $s): ?int
    {
        $s = trim(str_replace("\u{00A0}", ' ', $s));
        if (!preg_match('/^R?\s*(\d[\d\s,]*)(?:[.,]\d+)?\s*(?:m²|m2|sqm|m)?$/iu', $s, $m)) {
            return null;
        }
        $digits = preg_replace('/[\s,]+/', '', $m[1]);

        return $digits === '' ? null : (int) $digits;
    }

    public static function parseFloat(string $s): ?float
    {
        $s = trim(str_replace(["\u{00A0}", '%'], [' ', ''], $s));
        if (!preg_match('/^-?\d+(?:[.,]\d+)?$/', $s)) {
            return null;
        }

        return (float) str_replace(',', '.', $s);
    }

    public static function parseDate(string $s, ?string $format = null): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        foreach ($format !== null ? [$format] : self::DATE_FORMATS as $f) {
            $d = \DateTime::createFromFormat('!' . $f, $s);
            $errors = \DateTime::getLastErrors();
            if ($d !== false && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /** Which date format reads this cell as exactly $expected (Y-m-d)? */
    public static function dateFormatFor(string $cell, string $expected): ?string
    {
        foreach (self::DATE_FORMATS as $f) {
            if (self::parseDate($cell, $f) === $expected) {
                return $f;
            }
        }

        return null;
    }

    public static function parseCell(string $field, string $cell, ?string $format = null): mixed
    {
        if (in_array($field, self::MONEY_FIELDS, true)) {
            return self::parseMoney($cell);
        }
        if (in_array($field, self::INT_FIELDS, true)) {
            return self::parseSize($cell);
        }
        if ($field === 'sale_date') {
            return self::parseDate($cell, $format);
        }
        $t = trim($cell);

        return $t === '' ? null : $t;
    }

    /**
     * @param  array<int, string> $lines
     * @param  array<string, mixed> $table
     * @return array<int, array<string, mixed>>
     */
    private function readComps(array $lines, array $table): array
    {
        $cells = (int) ($table['cells'] ?? 0);
        $kind  = ($table['kind'] ?? 'comp') === 'listing' ? 'listing' : 'comp';
        $priceField = $kind === 'comp' ? 'sale_price' : 'list_price';
        $rows = [];

        foreach ($lines as $line) {
            $c = self::cells($line);
            if ($cells === 0 || count($c) !== $cells) {
                continue;
            }
            $row = ['kind' => $kind];
            $ok = true;
            foreach ((array) $table['columns'] as $col) {
                $field = (string) $col['field'];
                $value = self::parseCell($field, $c[(int) $col['i']] ?? '', $col['format'] ?? null);
                if ($value === null && ($field === $priceField || $field === 'sale_date')) {
                    $ok = false; // a header, a footer or a wrapped row — not a data row
                    break;
                }
                if ($value !== null) {
                    $row[$field] = $value;
                }
            }
            if ($ok && isset($row[$priceField])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The words of one line with the character columns they occupy: [text, from, to) — `to` exclusive,
     * counted in characters (not bytes), so "m²" and accented letters keep the columns straight.
     *
     * @return array<int, array{0: string, 1: int, 2: int}>
     */
    public static function atoms(string $line): array
    {
        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $atoms = [];
        $cur = '';
        $start = 0;
        foreach ($chars as $i => $ch) {
            if (preg_match('/\s/u', $ch)) {
                if ($cur !== '') {
                    $atoms[] = [$cur, $start, $i];
                    $cur = '';
                }

                continue;
            }
            if ($cur === '') {
                $start = $i;
            }
            $cur .= $ch;
        }
        if ($cur !== '') {
            $atoms[] = [$cur, $start, count($chars)];
        }

        return $atoms;
    }

    private static function inWindow(array $atom, int $from, int $to): bool
    {
        $mid = ($atom[1] + $atom[2]) / 2;

        return $mid >= $from && $mid < $to;
    }

    /** The words of $atoms whose middle lies in [from, to), joined by single spaces. */
    private static function textInWindow(array $atoms, int $from, int $to): string
    {
        $parts = [];
        foreach ($atoms as $atom) {
            if (self::inWindow($atom, $from, $to)) {
                $parts[] = $atom[0];
            }
        }

        return implode(' ', $parts);
    }

    /**
     * The typed values on a line: dates, percentages and numbers (an amount, a size such as "613 m²", a count),
     * each with the character column it sits in. Words are not tokens and are ignored; words inside $skip windows
     * (the text columns) are never looked at, so "1 Beach Road" never yields a number.
     *
     * @param  array<int, array{0: string, 1: int, 2: int}> $atoms
     * @param  array<int, array{0: int, 1: int}> $skip  [from, to) windows to leave alone
     * @return array<int, array{type: string, text: string, c: float}>
     */
    public static function typedTokens(array $atoms, array $skip = []): array
    {
        $n = count($atoms);
        $skipped = function (int $i) use ($atoms, $skip): bool {
            foreach ($skip as [$from, $to]) {
                if (self::inWindow($atoms[$i], $from, $to)) {
                    return true;
                }
            }

            return false;
        };
        $join = fn (int $from, int $to) => implode(' ', array_map(fn ($a) => $a[0], array_slice($atoms, $from, $to - $from + 1)));
        $centre = fn (int $from, int $to) => ($atoms[$from][1] + $atoms[$to][2]) / 2;

        $tokens = [];
        $i = 0;
        while ($i < $n) {
            if ($skipped($i)) {
                $i++;

                continue;
            }
            foreach ([3, 1] as $len) {
                $end = $i + $len - 1;
                if ($end >= $n) {
                    continue;
                }
                $clear = true;
                for ($k = $i; $k <= $end; $k++) {
                    $clear = $clear && !$skipped($k);
                }
                if ($clear && self::parseDate($join($i, $end)) !== null) {
                    $tokens[] = ['type' => 'date', 'text' => $join($i, $end), 'c' => $centre($i, $end)];
                    $i = $end + 1;

                    continue 2;
                }
            }
            if (preg_match('/^-?\d+(?:[.,]\d+)?%$/u', $atoms[$i][0])) {
                $tokens[] = ['type' => 'pct', 'text' => $atoms[$i][0], 'c' => $centre($i, $i)];
                $i++;

                continue;
            }
            $k = $i;
            if (preg_match('/^R$/iu', $atoms[$i][0]) && $i + 1 < $n && !$skipped($i + 1) && preg_match('/^\d/u', $atoms[$i + 1][0])) {
                $k = $i + 1;
            }
            if (preg_match('/^R?\d[\d,]*(?:\.\d+)?$/iu', $atoms[$k][0])) {
                $end = $k;
                // thousands groups ("1 015 000"): a first group of 1-3 digits, then at most two groups of exactly 3,
                // each one space apart (so a 3-digit count printed right after an amount stays its own value)
                if (preg_match('/^R?\d{1,3}$/iu', $atoms[$k][0])) {
                    $groups = 0;
                    while ($groups < 2 && $end + 1 < $n && !$skipped($end + 1) && preg_match('/^\d{3}(?:\.\d+)?$/u', $atoms[$end + 1][0]) && $atoms[$end + 1][1] - $atoms[$end][2] <= 1) {
                        $end++;
                        $groups++;
                    }
                }
                if ($end + 1 < $n && !$skipped($end + 1) && preg_match('/^(?:m²|m2|sqm|m)$/iu', $atoms[$end + 1][0])) {
                    $end++;
                }
                $tokens[] = ['type' => 'num', 'text' => $join($i, $end), 'c' => $centre($i, $end)];
                $i = $end + 1;

                continue;
            }
            $i++; // a word
        }

        return $tokens;
    }

    /**
     * Version-2 table: see the class note.
     *
     * @param  array<int, string> $lines
     * @param  array<string, mixed> $table
     * @return array<int, array<string, mixed>>
     */
    private function readCompsPositional(array $lines, array $table): array
    {
        $kind       = ($table['kind'] ?? 'comp') === 'listing' ? 'listing' : 'comp';
        $priceField = $kind === 'comp' ? 'sale_price' : 'list_price';
        $textSlots  = (array) ($table['text_slots'] ?? []);
        $entries    = array_values((array) ($table['entries'] ?? []));
        $minFields  = max(1, (int) ($table['min_fields'] ?? 2));
        $wrap       = !empty($table['wrap']);
        $skip       = array_map(fn ($s) => [(int) $s['from'], (int) $s['to']], $textSlots);
        $needsDate  = (bool) array_filter($entries, fn ($e) => ($e['field'] ?? null) === 'sale_date');
        $lead       = is_array($table['lead'] ?? null) ? $table['lead'] : null;
        $atomLines  = $lead === null ? [] : array_map(fn (string $l) => self::atoms($l), $lines);
        $dataLines  = [];

        $rows = [];
        $lastRow = null;   // index into $rows of the data row a wrapped line may belong to
        $lastLine = -9;
        $wrappedLines = 0;

        foreach ($lines as $li => $line) {
            $atoms = self::atoms($line);
            if ($atoms === []) {
                continue;
            }

            $row = $this->readRowByEntries($atoms, $textSlots, $entries, $skip, $kind);
            if ($row !== null && isset($row['row'][$priceField]) && (!$needsDate || isset($row['row']['sale_date'])) && $row['found'] >= $minFields) {
                if ($lead !== null) {
                    // numbered two-line rows: the line above is "<n>  <address>", and a row without it is not a row
                    $leadText = self::leadText($atomLines, $li, $dataLines, !empty($lead['index']));
                    if ($leadText === null) {
                        continue;
                    }
                    $row['row'][(string) $lead['field']] = mb_substr($leadText, 0, 160);
                }
                $dataLines[$li] = true;
                $rows[] = $row['row'];
                $lastRow = count($rows) - 1;
                $lastLine = $li;
                $wrappedLines = 0;

                continue;
            }

            // Not a data row. With `wrap`, a line just under a data row that holds text ONLY inside the
            // text windows is the rest of that row's address.
            if ($wrap && $lastRow !== null && $li - $lastLine <= 2 && $wrappedLines < 2) {
                $extra = $this->wrappedText($atoms, $textSlots);
                if ($extra !== null) {
                    foreach ($extra as $field => $text) {
                        $rows[$lastRow][$field] = mb_substr(trim(($rows[$lastRow][$field] ?? '') . ' ' . $text), 0, 160);
                    }
                    $wrappedLines++;
                    $lastLine = $li;
                }
            }
        }

        return $rows;
    }

    /**
     * The text of a row printed on the line ABOVE its figures (within two lines): "8 Carlyke Street", or, with
     * $needIndex, "3   8 Carlyke Street" where the leading whole number is the row number and is dropped. Null when the
     * line above is itself a data row, is missing, or (with $needIndex) does not start with a row number.
     *
     * @param  array<int, array<int, array{0: string, 1: int, 2: int}>> $atomLines
     * @param  array<int, bool> $dataLines  lines already read as data rows
     */
    public static function leadText(array $atomLines, int $li, array $dataLines, bool $needIndex): ?string
    {
        for ($p = $li - 1; $p >= max(0, $li - 2); $p--) {
            $atoms = $atomLines[$p] ?? [];
            if ($atoms === []) {
                continue;
            }
            if (isset($dataLines[$p])) {
                return null;
            }
            if ($needIndex) {
                // a row number: 1-3 digits, no leading zero (the day in "08 Mar 2007" is not one)
                if (count($atoms) < 2 || !preg_match('/^[1-9]\d{0,2}$/', $atoms[0][0])) {
                    return null;
                }
                $atoms = array_slice($atoms, 1);
            }
            foreach (self::typedTokens($atoms) as $token) {
                if ($token['type'] === 'date') {
                    return null;   // a property is not described by a date: this is another table's row
                }
            }

            return implode(' ', array_map(fn ($a) => $a[0], $atoms));
        }

        return null;
    }

    /**
     * One line, read by the layout's text windows and typed columns. Null when the line cannot be a row of this
     * table (a typed value that fits no column, or more values than the table has columns).
     *
     * @param  array<int, array{0: string, 1: int, 2: int}> $atoms
     * @param  array<int, array<string, mixed>> $textSlots
     * @param  array<int, array<string, mixed>> $entries
     * @param  array<int, array{0: int, 1: int}> $skip
     * @return array{row: array<string, mixed>, found: int}|null
     */
    private function readRowByEntries(array $atoms, array $textSlots, array $entries, array $skip, string $kind): ?array
    {
        $tokens = self::typedTokens($atoms, $skip);
        if ($tokens === []) {
            return null;
        }
        $assign = self::alignTokens($tokens, $entries);
        if ($assign === null) {
            return null;
        }

        $row = ['kind' => $kind];
        $found = 0;
        foreach ($textSlots as $slot) {
            $text = self::textInWindow($atoms, (int) $slot['from'], (int) $slot['to']);
            if ($text !== '') {
                $row[(string) $slot['field']] = mb_substr($text, 0, 160);
                $found++;
            }
        }
        foreach ($assign as $ti => $ei) {
            $field = $entries[$ei]['field'] ?? null;
            if ($field === null) {
                continue;
            }
            $value = self::parseCell((string) $field, $tokens[$ti]['text'], $entries[$ei]['format'] ?? null);
            if ($value !== null) {
                $row[(string) $field] = $value;
                $found++;
            }
        }

        return ['row' => $row, 'found' => $found];
    }

    /**
     * Match a line's typed tokens to the layout's typed columns: in order, each token to one column of its own
     * type, columns left over are blank in this row, and the nearest-by-position arrangement wins. Null when the
     * tokens cannot all be placed.
     *
     * @param  array<int, array{type: string, text: string, c: float}> $tokens
     * @param  array<int, array<string, mixed>> $entries
     * @return array<int, int>|null  token index => entry index
     */
    public static function alignTokens(array $tokens, array $entries): ?array
    {
        // A table printed at more than one scale (the columns of page 3 and of page 4 sit at different characters)
        // carries one set of column positions per scale in each entry's `cs`; the row is placed with whichever set fits it best.
        $variants = 1;
        foreach ($entries as $e) {
            $variants = max($variants, count((array) ($e['cs'] ?? [])));
        }
        $best = null;
        $bestCost = INF;
        for ($v = 0; $v < $variants; $v++) {
            $set = array_map(fn ($e) => ['c' => $e['cs'][$v] ?? $e['c']] + $e, $entries);
            $cost = INF;
            $assign = self::alignOnce($tokens, $set, $cost);
            if ($assign !== null && $cost < $bestCost) {
                $best = $assign;
                $bestCost = $cost;
            }
        }

        return $best;
    }

    /** @return array<int, int>|null */
    private static function alignOnce(array $tokens, array $entries, float &$totalCost): ?array
    {
        $a = count($tokens);
        $b = count($entries);
        if ($a === 0 || $a > $b) {
            return null;
        }
        $inf = INF;
        $dp = array_fill(0, $a + 1, array_fill(0, $b + 1, $inf));
        $from = array_fill(0, $a + 1, array_fill(0, $b + 1, null));
        for ($j = 0; $j <= $b; $j++) {
            $dp[0][$j] = 0.0;
        }
        for ($i = 1; $i <= $a; $i++) {
            for ($j = 1; $j <= $b; $j++) {
                $skipCost = $dp[$i][$j - 1];
                if ($skipCost < $dp[$i][$j]) {
                    $dp[$i][$j] = $skipCost;
                    $from[$i][$j] = 'skip';
                }
                if ($tokens[$i - 1]['type'] === ($entries[$j - 1]['type'] ?? '') && $dp[$i - 1][$j - 1] < $inf) {
                    $cost = $dp[$i - 1][$j - 1] + abs($tokens[$i - 1]['c'] - (float) $entries[$j - 1]['c']);
                    if ($cost < $dp[$i][$j]) {
                        $dp[$i][$j] = $cost;
                        $from[$i][$j] = 'take';
                    }
                }
            }
        }
        if ($dp[$a][$b] === $inf) {
            return null;
        }
        $totalCost = $dp[$a][$b];
        $assign = [];
        $i = $a;
        $j = $b;
        while ($i > 0 && $j > 0) {
            if ($from[$i][$j] === 'take') {
                $assign[$i - 1] = $j - 1;
                $i--;
                $j--;
            } else {
                $j--;
            }
        }

        if ($i !== 0) {
            return null;
        }
        ksort($assign);

        return $assign;
    }

    /**
     * @param  array<int, array{0: string, 1: int, 2: int}> $atoms
     * @param  array<int, array<string, mixed>> $slots
     * @return array<string, string>|null  text per text-slot, or null when ANY word sits outside a text slot
     */
    private function wrappedText(array $atoms, array $slots): ?array
    {
        $textSlots = array_values(array_filter($slots, fn ($s) => in_array($s['field'], ['address', 'scheme_name'], true)));
        if ($textSlots === []) {
            return null;
        }
        $out = [];
        foreach ($atoms as $atom) {
            $placed = false;
            foreach ($textSlots as $slot) {
                if (self::inWindow($atom, (int) $slot['from'], (int) $slot['to'])) {
                    $out[$slot['field']] = trim(($out[$slot['field']] ?? '') . ' ' . $atom[0]);
                    $placed = true;
                    break;
                }
            }
            if (!$placed) {
                return null;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<int, string> $lines
     * @param  array<string, mixed> $table
     * @return array<int, array<string, mixed>>
     */
    private function readStats(array $lines, array $table): array
    {
        $cells = (int) ($table['cells'] ?? 0);
        $yearCol = (int) ($table['year_col'] ?? 0);
        $rows = [];

        foreach ($lines as $line) {
            $c = self::cells($line);
            if ($cells === 0 || count($c) !== $cells) {
                continue;
            }
            if (!preg_match('/^(?:19|20)\d{2}$/', $c[$yearCol] ?? '')) {
                continue;
            }
            foreach ((array) $table['metrics'] as $m) {
                $metric = (string) $m['metric'];
                $raw = $c[(int) $m['i']] ?? '';
                $value = in_array($metric, ['median_price', 'average_price', 'median_price_12m'], true)
                    ? self::parseMoney($raw)
                    : self::parseFloat($raw);
                if ($value !== null) {
                    $rows[] = ['metric' => $metric, 'year' => (int) $c[$yearCol], 'value' => $value];
                }
            }
        }

        return $rows;
    }

    // ── charts ───────────────────────────────────────────────────────────

    /**
     * The words of one line grouped into runs: words one space apart belong together ("R 4.58 M"), a gap of 2+ spaces
     * starts a new run. [text, from, to) in characters.
     *
     * @return array<int, array{0: string, 1: int, 2: int}>
     */
    public static function runs(string $line): array
    {
        $runs = [];
        foreach (self::atoms($line) as $atom) {
            $last = count($runs) - 1;
            if ($last >= 0 && $atom[1] - $runs[$last][2] < 2) {
                $runs[$last][0] .= ' ' . $atom[0];
                $runs[$last][2] = $atom[2];
            } else {
                $runs[] = $atom;
            }
        }

        return $runs;
    }

    /**
     * A line that is only a row of years (an axis): [year, centre column] for each, at least three of them.
     *
     * @return array<int, array{0: int, 1: float}>|null
     */
    public static function yearAxis(string $line): ?array
    {
        $atoms = self::atoms($line);
        if (count($atoms) < 3) {
            return null;
        }
        $years = [];
        foreach ($atoms as $a) {
            if (!preg_match('/^(?:19|20)\d{2}$/', $a[0])) {
                return null;
            }
            $years[] = [(int) $a[0], ($a[1] + $a[2]) / 2];
        }

        return $years;
    }

    /**
     * What one chart label is: ['money_short', 4580000] for "R 4.58 M", ['int', 44], ['pct', 11.5], ['money', 1200000];
     * null for anything else (a word, a date, a caption).
     *
     * @return array{0: string, 1: int|float}|null
     */
    public static function chartToken(string $text): ?array
    {
        $text = trim($text);
        if (preg_match('/^R\s?(\d+(?:\.\d+)?)\s?([MK])$/i', $text, $m)) {
            return ['money_short', (int) round((float) $m[1] * (strtoupper($m[2]) === 'M' ? 1000000 : 1000))];
        }
        if (preg_match('/^-?\d+(?:\.\d+)?\s?%$/', $text)) {
            return ['pct', (float) $text];
        }
        if (preg_match('/^R\s?\d[\d ,]*$/', $text)) {
            $v = self::parseMoney($text);

            return $v === null ? null : ['money', $v];
        }
        if (preg_match('/^\d{1,7}$/', $text)) {
            return ['int', (int) $text];
        }

        return null;
    }

    public static function headingKey(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }

    /**
     * The chart with this heading: the heading line (the nth line whose whole text is the heading) and the first axis
     * line after it.
     *
     * @param  array<int, string> $lines
     * @return array{0: int, 1: int}|null [heading line, axis line]
     */
    public static function findChart(array $lines, string $heading, int $nth): ?array
    {
        $key = self::headingKey($heading);
        $seen = 0;
        foreach ($lines as $i => $line) {
            if (self::headingKey($line) !== $key) {
                continue;
            }
            if ($seen++ < $nth) {
                continue;
            }
            for ($j = $i + 1, $n = count($lines); $j < $n; $j++) {
                if (self::yearAxis($lines[$j]) !== null) {
                    return [$i, $j];
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Every chart label between a heading and its axis, by year and kind, top of the chart first.
     *
     * @param  array<int, string> $lines
     * @return array<int, array<string, array<int, int|float>>> year => kind => values
     */
    public static function chartColumns(array $lines, int $heading, int $axis): array
    {
        $years = self::yearAxis($lines[$axis] ?? '');
        if ($years === null) {
            return [];
        }
        $gap = null;
        for ($i = 1, $n = count($years); $i < $n; $i++) {
            $d = $years[$i][1] - $years[$i - 1][1];
            $gap = $gap === null ? $d : min($gap, $d);
        }
        $reach = ($gap ?? 0) / 2;

        $cols = [];
        for ($li = $heading + 1; $li < $axis; $li++) {
            $tokens = [];
            foreach (self::runs($lines[$li]) as [$text, $from, $to]) {
                $tok = self::chartToken($text);
                if ($tok === null) {
                    $tokens = [];   // a line with anything else on it (a caption, a table row) is not a row of chart labels
                    break;
                }
                $tokens[] = [$tok, ($from + $to) / 2];
            }
            foreach ($tokens as [$tok, $centre]) {
                $best = null;
                foreach ($years as [$year, $yc]) {
                    $d = abs($centre - $yc);
                    if ($d <= $reach && ($best === null || $d < $best[1])) {
                        $best = [$year, $d];
                    }
                }
                if ($best !== null) {
                    $cols[$best[0]][$tok[0]][] = $tok[1];
                }
            }
        }

        return $cols;
    }

    /** The label of this kind at this rank in a column (rank 0 = top, -1 = lowest), or null. */
    public static function pickRank(array $values, int $rank): int|float|null
    {
        if ($values === []) {
            return null;
        }
        $i = $rank >= 0 ? $rank : count($values) + $rank;

        return $values[$i] ?? null;
    }

    /**
     * @param  array<int, string> $lines
     * @param  array<string, mixed> $table
     * @return array<int, array<string, mixed>>
     */
    private function readChart(array $lines, array $table): array
    {
        $found = self::findChart($lines, (string) ($table['heading'] ?? ''), (int) ($table['nth'] ?? 0));
        if ($found === null) {
            return [];
        }
        $cols = self::chartColumns($lines, $found[0], $found[1]);
        $rows = [];
        foreach ((array) ($table['series'] ?? []) as $s) {
            foreach ($cols as $year => $byKind) {
                $value = self::pickRank($byKind[(string) ($s['kind'] ?? '')] ?? [], (int) ($s['rank'] ?? 0));
                if ($value !== null) {
                    $rows[] = ['metric' => (string) $s['metric'], 'year' => (int) $year, 'value' => $value];
                }
            }
        }

        return $rows;
    }

    /**
     * A figure printed under its own column heading ("Lower Boundary" over "R 4,530,000"): find the heading, then the
     * amount on the next line that sits in the same columns.
     *
     * @param array<int, string> $lines
     */
    private function readScalarBelow(array $lines, string $label): ?int
    {
        $key = self::headingKey($label);
        if ($key === '') {
            return null;
        }
        foreach ($lines as $li => $line) {
            foreach (self::runs($line) as [$text, $from, $to]) {
                if (self::headingKey($text) !== $key) {
                    continue;
                }
                $v = self::amountUnder($lines, $li, $from, $to);
                if ($v !== null) {
                    return $v;
                }
            }
        }

        return null;
    }

    /**
     * The amount on the first non-empty line below $li (within three lines) whose columns overlap [from, to).
     *
     * @param array<int, string> $lines
     */
    public static function amountUnder(array $lines, int $li, int $from, int $to): ?int
    {
        for ($j = $li + 1; $j <= $li + 3 && $j < count($lines); $j++) {
            $runs = self::runs($lines[$j]);
            if ($runs === []) {
                continue;
            }
            foreach ($runs as [$text, $f, $t]) {
                if (min($t, $to) - max($f, $from) > 0 && preg_match('/^R?\s?\d[\d ,]*$/', trim($text))) {
                    return self::parseMoney($text);
                }
            }

            return null;   // the first non-empty line is the one that counts
        }

        return null;
    }

    /** @param array<int, string> $lines */
    private function readScalar(array $lines, string $label, string $type): int|string|null
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        $pattern = '/(?<![\p{L}\d])' . str_replace(' ', '\s+', preg_quote($label, '/')) . '/iu';

        foreach ($lines as $line) {
            if (!preg_match($pattern, $line, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $after = substr($line, $m[0][1] + strlen($m[0][0]));
            $after = ltrim($after, " \t:-");
            if ($type === 'money') {
                if (preg_match('/^R?\s*(\d[\d ,]*)(?:\.\d{1,2})?(?=\s|$)/u', $after, $mm)) {
                    $v = self::parseMoney($mm[0]);
                    if ($v !== null) {
                        return $v;
                    }
                }

                continue;
            }
            $first = self::cells($after)[0] ?? '';
            $first = trim($first);
            if ($first !== '') {
                return mb_substr($first, 0, 160);
            }
        }

        return null;
    }
}
