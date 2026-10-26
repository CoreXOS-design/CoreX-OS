<?php

declare(strict_types=1);

namespace App\Services\MarketReports;

use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportLearnedLayout;
use App\Services\MarketReports\DTOs\AiReadOutcome;

/**
 * Learns a publisher's layout from a successful AI read, so the next report in that
 * layout is read deterministically, for free. Spec: mic-complete-spec §8.9.
 *
 * It looks for every value the AI returned inside the PDF's own text, works out which
 * column / label each came from, and builds a mapping. The mapping is then run through
 * the deterministic reader on the SAME text and compared with what the AI returned: a
 * layout is saved only if it reproduces at least `report_layout_selftest_min` of it.
 * A layout that cannot reproduce the AI is never saved — that publisher keeps going to
 * the AI (still cached per file) rather than being read badly for free.
 */
final class LayoutLearner
{
    private const COMP_FIELDS = ['sale_price', 'list_price', 'sale_date', 'extent_m2', 'r_per_m2', 'municipal_valuation',
        'distance_to_subject_m', 'days_on_market', 'address', 'scheme_name', 'section_number', 'property_type', 'suburb'];

    private const PRICE_METRICS = ['median_price', 'average_price', 'median_price_12m'];

    /** Descriptors a report commonly prints once per table rather than in every row (see rowFieldsAgree). */
    private const DOCUMENT_LEVEL_FIELDS = ['property_type', 'suburb'];

    public function __construct(
        private readonly LearnedLayoutReader $reader,
        private readonly ReportReadSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed> $clean  sanitised canonical read (from the AI)
     * @param  array<string, mixed> $hints  the AI's `layout` block
     * @return array{ok: bool, reason?: string, mapping?: array<string, mixed>, anchors?: array<int, string>, score?: float, kind?: string}
     */
    public function learn(string $text, array $clean, array $hints): array
    {
        if (trim($text) === '') {
            return ['ok' => false, 'reason' => 'no text could be extracted from the PDF'];
        }
        $anchors = LayoutFingerprint::anchorsFromHints($hints, $text);
        if ($anchors === []) {
            return ['ok' => false, 'reason' => 'the layout has too few distinctive phrases to recognise it safely'];
        }

        $cellLines = array_map(fn (string $l) => LearnedLayoutReader::cells($l), LearnedLayoutReader::lines($text));
        $rawLines  = LearnedLayoutReader::lines($text);

        // Tables, first the way that has always worked (cells split on runs of 2+ spaces, every row the same
        // number of cells); then, only if that does not reproduce the AI, the same tables by character position,
        // which copes with blank cells and tight columns. The first variant that passes the self-test is saved.
        $others = [];
        $stats = array_values(array_filter($clean['suburb_stats'] ?? [], fn ($s) => isset($s['year'])));
        if ($stats !== []) {
            $t = $this->learnStatsTable($cellLines, $stats);
            if ($t !== null) {
                $others[] = $t;
            }
            // Figures printed as chart labels, not in a table row: the metrics the table above did not cover.
            $covered = $t === null ? [] : array_column($t['metrics'], 'metric');
            foreach ($this->learnCharts($rawLines, $stats, $covered, (array) ($hints['header_phrases'] ?? [])) as $chart) {
                $others[] = $chart;
            }
        }
        $scalars = $this->learnScalars($rawLines, $cellLines, $clean);

        $byKind = [];
        foreach (['comp', 'listing'] as $kind) {
            $byKind[$kind] = array_values(array_filter($clean['comps'] ?? [], fn ($c) => ($c['kind'] ?? 'comp') === $kind));
        }
        $cellComps = [];
        $posComps  = [];
        foreach ($byKind as $kind => $comps) {
            if ($comps === []) {
                continue;
            }
            $t = $this->learnCompTable($cellLines, $comps, $kind);
            if ($t !== null) {
                $cellComps[] = $t;
            }
            $p = $this->learnCompTablePositional($rawLines, $comps, $kind);
            if ($p !== null) {
                $posComps[] = $p;
            }
        }

        $hasValuation = (bool) array_filter($scalars, fn ($s) => str_starts_with($s['field'], 'valuation.'));
        $variants = [['tables' => array_merge($cellComps, $others), 'positional' => false]];
        if ($posComps !== []) {
            $variants[] = ['tables' => array_merge($posComps, $others), 'positional' => true];
        }

        $best = null;
        foreach ($variants as $variant) {
            if ($variant['tables'] === [] && !$hasValuation) {
                continue;
            }
            $mapping = ['version' => $variant['positional'] ? 2 : 1, 'tables' => $variant['tables'], 'scalars' => $scalars];
            $recon = $this->reader->read($mapping, $text);
            $score = $this->score($clean, $recon);
            if ($variant['positional']) {
                // the score above compares prices and dates only; a version-2 table must also read every other value the AI read
                $score = min($score, $this->rowFieldsAgree($clean, $recon));
            }
            if ($best === null || $score > $best['score']) {
                $best = ['mapping' => $mapping, 'score' => $score, 'tables' => $variant['tables']];
            }
            if ($score >= $this->settings->selftestMin()) {
                break;
            }
        }

        if ($best === null) {
            return ['ok' => false, 'reason' => 'no table or labelled figure could be located in the PDF text'];
        }
        $min = $this->settings->selftestMin();
        if ($best['score'] < $min) {
            return ['ok' => false, 'reason' => 'the learned mapping reproduced only ' . round($best['score'] * 100) . '% of the AI reading (needs ' . round($min * 100) . '%)', 'score' => $best['score']];
        }

        $kinds = [];
        foreach ($best['tables'] as $t) {
            $kinds[] = in_array($t['type'], ['stats', 'chart'], true) ? 'suburb_stats' : ($t['kind'] === 'listing' ? 'listings' : 'comps');
        }
        if ($hasValuation) {
            $kinds[] = 'valuation';
        }

        return ['ok' => true, 'mapping' => $best['mapping'], 'anchors' => $anchors, 'score' => $best['score'], 'kind' => implode('+', array_unique($kinds))];
    }

    /**
     * Learn from a finished AI read and save the layout. Returns the saved layout, the
     * existing one if this fingerprint is already known, or null with $why set.
     */
    public function learnAndSave(MarketReport $report, AiReadOutcome $outcome, string $text, ?string &$why = null): ?MarketReportLearnedLayout
    {
        $learned = $this->learn($text, $outcome->clean, $outcome->layoutHints);
        if (!$learned['ok']) {
            $why = $learned['reason'] ?? 'not learnable';

            return null;
        }

        $fingerprint = LayoutFingerprint::hash($learned['anchors']);
        $existing = MarketReportLearnedLayout::withTrashed()->where('fingerprint', $fingerprint)->first();
        if ($existing) {
            $why = $existing->trashed() ? 'this layout was retired by a CoreX admin and is not re-learned' : 'this layout is already known';

            return $existing->trashed() ? null : $existing;
        }

        $publisher = $outcome->publisher ?: null;
        $title = $outcome->clean['report_title'] ?? null;

        return MarketReportLearnedLayout::create([
            'name'                   => mb_substr(trim(($publisher ?: 'Unknown publisher') . ' — ' . ($title ?: $learned['kind'])), 0, 160),
            'publisher'              => $publisher,
            'status'                 => MarketReportLearnedLayout::STATUS_LEARNED,
            'fingerprint'            => $fingerprint,
            'anchors'                => $learned['anchors'],
            'mapping'                => $learned['mapping'],
            'report_kind'            => $learned['kind'],
            'selftest_score'         => $learned['score'],
            'learned_from_report_id' => $report->id,
            'learned_from_agency_id' => $report->agency_id,
        ]);
    }

    // ── tables ───────────────────────────────────────────────────────────

    /**
     * @param  array<int, array<int, string>> $cellLines
     * @param  array<int, array<string, mixed>> $comps
     * @return array<string, mixed>|null
     */
    private function learnCompTable(array $cellLines, array $comps, string $kind): ?array
    {
        $priceField = $kind === 'comp' ? 'sale_price' : 'list_price';
        $used = [];
        $votes = [];     // field => idx => n
        $formats = [];   // idx => format => n
        $counts = [];    // cell count => n
        $matched = 0;

        foreach ($comps as $comp) {
            $best = null;
            foreach ($cellLines as $li => $cells) {
                if (isset($used[$li]) || count($cells) < 2) {
                    continue;
                }
                $hit = $this->matchCompLine($cells, $comp);
                if (!isset($hit['fields'][$priceField])) {
                    continue;
                }
                if ($best === null || count($hit['fields']) > count($best['hit']['fields'])) {
                    $best = ['li' => $li, 'hit' => $hit, 'n' => count($cells)];
                }
            }
            if ($best === null) {
                continue;
            }
            $used[$best['li']] = true;
            $matched++;
            $counts[$best['n']] = ($counts[$best['n']] ?? 0) + 1;
            foreach ($best['hit']['fields'] as $field => $idx) {
                $votes[$field][$idx] = ($votes[$field][$idx] ?? 0) + 1;
            }
            foreach ($best['hit']['formats'] as $idx => $fmt) {
                $formats[$idx][$fmt] = ($formats[$idx][$fmt] ?? 0) + 1;
            }
        }

        if ($matched === 0 || $matched / count($comps) < 0.5) {
            return null;
        }
        arsort($counts);
        $cells = (int) array_key_first($counts);
        if ($counts[$cells] / $matched < 0.6) {
            return null;
        }

        $threshold = max(1, (int) ceil(0.6 * $matched));
        $claimed = [];
        $columns = [];
        foreach (self::COMP_FIELDS as $field) {
            if (empty($votes[$field])) {
                continue;
            }
            arsort($votes[$field]);
            foreach ($votes[$field] as $idx => $n) {
                if ($n < $threshold || isset($claimed[$idx]) || $idx >= $cells) {
                    continue;
                }
                $claimed[$idx] = true;
                $col = ['i' => (int) $idx, 'field' => $field];
                if ($field === 'sale_date' && !empty($formats[$idx])) {
                    arsort($formats[$idx]);
                    $col['format'] = (string) array_key_first($formats[$idx]);
                }
                $columns[] = $col;
                break;
            }
        }
        if (!array_filter($columns, fn ($c) => $c['field'] === $priceField)) {
            return null;
        }

        return ['type' => 'comps', 'kind' => $kind, 'cells' => $cells, 'columns' => $columns];
    }

    /**
     * @param  array<int, string> $cells
     * @param  array<string, mixed> $comp
     * @return array{fields: array<string, int>, formats: array<int, string>}
     */
    private function matchCompLine(array $cells, array $comp): array
    {
        $fields = [];
        $formats = [];
        foreach (self::COMP_FIELDS as $field) {
            if (!isset($comp[$field])) {
                continue;
            }
            $expected = $comp[$field];
            foreach ($cells as $idx => $cell) {
                if ($field === 'sale_date') {
                    $fmt = LearnedLayoutReader::dateFormatFor($cell, (string) $expected);
                    if ($fmt !== null) {
                        $fields[$field] = $idx;
                        $formats[$idx] = $fmt;
                        break;
                    }
                } elseif (in_array($field, LearnedLayoutReader::TEXT_FIELDS, true)) {
                    if ($this->textMatches($cell, (string) $expected)) {
                        $fields[$field] = $idx;
                        break;
                    }
                } elseif (LearnedLayoutReader::parseCell($field, $cell) === $expected) {
                    $fields[$field] = $idx;
                    break;
                }
            }
        }

        return ['fields' => $fields, 'formats' => $formats];
    }

    // ── tables, read by what is in the row (mapping version 2) ────────────

    /**
     * The same table learned without counting cells. A 12-column table whose never-listed sales leave List date /
     * List price / Days on market / Change blank has rows of different cell counts, and tight columns merge into
     * one cell; neither matters here. The text columns are remembered as character windows, every dated / amount /
     * size / percentage value as an ordered typed column (see LearnedLayoutReader).
     *
     * @param  array<int, string> $rawLines
     * @param  array<int, array<string, mixed>> $comps
     * @return array<string, mixed>|null
     */
    private function learnCompTablePositional(array $rawLines, array $comps, string $kind): ?array
    {
        $priceField = $kind === 'comp' ? 'sale_price' : 'list_price';
        $atomLines  = array_map(fn (string $l) => LearnedLayoutReader::atoms($l), $rawLines);
        $used = [];
        $matchedRows = [];   // [li, spans]
        $wrapped = false;

        foreach ($comps as $comp) {
            $best = null;
            foreach ($atomLines as $li => $atoms) {
                if (isset($used[$li]) || count($atoms) < 2) {
                    continue;
                }
                $hit = $this->matchCompLineSpans($atoms, $comp, $atomLines, $li);
                if (!isset($hit['spans'][$priceField])) {
                    continue;
                }
                if ($best === null || count($hit['spans']) > count($best['hit']['spans'])) {
                    $best = ['li' => $li, 'hit' => $hit];
                }
            }
            if ($best === null) {
                continue;
            }
            $used[$best['li']] = true;
            $matchedRows[] = ['li' => $best['li'], 'spans' => $best['hit']['spans'], 'comp' => $comp];
            $wrapped = $wrapped || $best['hit']['wrapped'];
        }
        $matched = count($matchedRows);
        if ($matched === 0 || $matched / count($comps) < 0.5) {
            return null;
        }
        $need = max(1, (int) ceil(0.6 * $matched));

        // 1. Text columns: the window each text field was found in.
        $textSlots = [];
        foreach (LearnedLayoutReader::TEXT_FIELDS as $field) {
            $list = array_values(array_filter(array_map(fn ($r) => $r['spans'][$field] ?? null, $matchedRows)));
            if (count($list) < $need) {
                continue;
            }
            $froms = array_column($list, 0);
            $tos = array_column($list, 1);
            sort($froms);
            sort($tos);
            $medFrom = $froms[intdiv(count($froms), 2)];
            $medTo = $tos[intdiv(count($tos), 2)];
            $kept = array_values(array_filter($list, fn ($sp) => $sp[0] < $medTo && $sp[1] > $medFrom));
            if (count($kept) < $need) {
                continue;
            }
            $textSlots[] = ['field' => $field, 'from' => min(array_column($kept, 0)), 'to' => max(array_column($kept, 1)), 'votes' => count($kept)];
        }
        usort($textSlots, fn ($a, $b) => $a['from'] <=> $b['from']);
        $slots = [];
        foreach ($textSlots as $slot) {
            $last = end($slots);
            if ($last !== false && $slot['from'] < $last['to']) {
                if ($slot['to'] <= $last['to'] || $slot['from'] <= $last['from']) {
                    // one wholly inside the other: a false match, the one more rows agreed on stays
                    if ($slot['votes'] > $last['votes']) {
                        array_pop($slots);
                        $slots[] = $slot;
                    }

                    continue;
                }
                // neighbours that touch (a line is nudged a character or two either way): split at the middle
                $mid = intdiv($last['to'] + $slot['from'], 2);
                $slots[count($slots) - 1]['to'] = $mid;
                $slot['from'] = $mid;
            }
            $slots[] = $slot;
        }
        $skip = array_map(fn ($s) => [$s['from'], $s['to']], $slots);
        $slotFields = array_flip(array_column($slots, 'field'));
        $lead = isset($slotFields['address']) ? null : $this->learnLead($atomLines, $matchedRows);

        // 2. Typed columns: the typed values of every matched row, in order. The fullest row's sequence is the
        //    layout; each column's position is the average over the rows that share that sequence, and a column
        //    carries a field when most of those rows found that field's value in it.
        $sequences = [];   // signature => list of [tokens, spans]
        foreach ($matchedRows as $r) {
            $tokens = LearnedLayoutReader::typedTokens($atomLines[$r['li']], $skip);
            $sequences[implode(',', array_column($tokens, 'type'))][] = [$tokens, $r['spans'], $atomLines[$r['li']]];
        }
        if ($sequences === []) {
            return null;
        }
        uksort($sequences, fn ($a, $b) => [substr_count($b, ',') + 1, count($sequences[$b])] <=> [substr_count($a, ',') + 1, count($sequences[$a])]);
        $signature = array_key_first($sequences);
        $group = $sequences[$signature];
        if ($signature === '') {
            return null;
        }

        // The same table is often printed at more than one scale (page 3's columns sit at other characters than page 4's).
        // Rows are grouped by where their last value sits; each group of 2+ rows gives its own set of column positions.
        $order = array_keys($group);
        usort($order, fn ($x, $y) => end($group[$x][0])['c'] <=> end($group[$y][0])['c']);
        $scales = [];
        foreach ($order as $gi) {
            $last = end($group[$gi][0])['c'];
            $k = count($scales) - 1;
            if ($k >= 0 && $last - $scales[$k]['first'] <= 0.15 * $scales[$k]['first']) {
                $scales[$k]['rows'][] = $gi;
            } else {
                $scales[] = ['first' => $last, 'rows' => [$gi]];
            }
        }
        $scales = array_values(array_filter($scales, fn ($s) => count($s['rows']) >= 2));

        $entries = [];
        $count = count($group[0][0]);
        for ($e = 0; $e < $count; $e++) {
            $entry = ['type' => $group[0][0][$e]['type'], 'c' => (int) round(array_sum(array_map(fn ($g) => $g[0][$e]['c'], $group)) / count($group))];
            if (count($scales) > 1) {
                $entry['cs'] = array_map(fn ($s) => (int) round(array_sum(array_map(fn ($gi) => $group[$gi][0][$e]['c'], $s['rows'])) / count($s['rows'])), $scales);
            }
            $votes = [];
            $formats = [];
            foreach ($group as [$tokens, $spans, $atoms]) {
                foreach ($spans as $field => $span) {
                    if (isset($slotFields[$field])) {
                        continue;   // read from its text window
                    }
                    // The span lies on this token when it covers the token's middle. (A text value that is a single code,
                    // such as a unit number "00000", is a typed column when no stable window could be found for it.)
                    $mid = $tokens[$e]['c'];
                    if ($span[0] <= $mid && $mid <= $span[1]) {
                        $votes[$field] = ($votes[$field] ?? 0) + 1;
                        if ($span[2] !== null) {
                            $formats[$span[2]] = ($formats[$span[2]] ?? 0) + 1;
                        }
                    }
                }
            }
            arsort($votes);
            if ($votes !== [] && reset($votes) >= max(1, (int) ceil(0.6 * count($group)))) {
                $entry['field'] = (string) array_key_first($votes);
                if ($entry['field'] === 'sale_date' && $formats !== []) {
                    arsort($formats);
                    $entry['format'] = (string) array_key_first($formats);
                }
            }
            $entries[] = $entry;
        }
        // a field belongs to one column: keep the first claim
        $claimed = [];
        foreach ($entries as $e => $entry) {
            if (isset($entry['field'])) {
                if (isset($claimed[$entry['field']])) {
                    unset($entries[$e]['field'], $entries[$e]['format']);
                }
                $claimed[$entry['field']] = true;
            }
        }
        $entries = array_values($entries);
        if (!array_filter($entries, fn ($e) => ($e['field'] ?? null) === $priceField)) {
            return null;
        }
        $slots = array_map(function ($s) {
            unset($s['votes']);

            return $s;
        }, $slots);
        $fields = count($slots) + count(array_filter($entries, fn ($e) => isset($e['field']))) + ($lead === null ? 0 : 1);
        $textMapped = (bool) array_filter($slots, fn ($s) => in_array($s['field'], ['address', 'scheme_name'], true));

        $table = [
            'type'       => 'comps',
            'kind'       => $kind,
            'text_slots' => $slots,
            'entries'    => $entries,
            'min_fields' => max(2, intdiv($fields, 2)),
            'wrap'       => $wrapped && $textMapped,
        ];
        if ($lead !== null) {
            $table['lead'] = $lead;
        }

        return $table;
    }

    /**
     * A table whose rows take two lines: the property (usually behind a row number) on the line above, its figures
     * on the line below. Learned when, for at least 60% of the rows, the line above (less its row number) is exactly
     * the AI's address.
     *
     * @param  array<int, array<int, array{0: string, 1: int, 2: int}>> $atomLines
     * @param  array<int, array{li: int, spans: array<string, mixed>, comp: array<string, mixed>}> $matchedRows
     * @return array{field: string, index: bool}|null
     */
    private function learnLead(array $atomLines, array $matchedRows): ?array
    {
        $dataLines = array_fill_keys(array_column($matchedRows, 'li'), true);
        $rows = array_values(array_filter($matchedRows, fn ($r) => !empty($r['comp']['address'])));
        if ($rows === []) {
            return null;
        }
        $need = max(1, (int) ceil(0.6 * count($rows)));
        foreach ([true, false] as $needIndex) {
            $hit = 0;
            foreach ($rows as $r) {
                $text = LearnedLayoutReader::leadText($atomLines, $r['li'], $dataLines, $needIndex);
                if ($text !== null && $this->words($text) === $this->words((string) $r['comp']['address'])) {
                    $hit++;
                }
            }
            if ($hit >= $need) {
                return ['field' => 'address', 'index' => $needIndex];
            }
        }

        return null;
    }

    /**
     * Where, on one line, each of this comp's values sits.
     *
     * @param  array<int, array{0: string, 1: int, 2: int}> $atoms
     * @param  array<string, mixed> $comp
     * @param  array<int, array<int, array{0: string, 1: int, 2: int}>> $atomLines
     * @return array{spans: array<string, array{0: int, 1: int, 2: ?string}>, wrapped: bool}
     */
    private function matchCompLineSpans(array $atoms, array $comp, array $atomLines, int $li): array
    {
        $spans = [];
        $taken = [];   // atom indexes already holding a value on this line
        $wrapped = false;

        foreach (self::COMP_FIELDS as $field) {
            if (!isset($comp[$field])) {
                continue;
            }
            $expected = $comp[$field];

            if (in_array($field, LearnedLayoutReader::TEXT_FIELDS, true)) {
                $found = $this->findTextRun($atoms, (string) $expected, $taken);
                if ($found === null && in_array($field, ['address', 'scheme_name'], true)) {
                    $found = $this->findWrappedRun($atoms, (string) $expected, $atomLines, $li, $taken);
                    $wrapped = $wrapped || $found !== null;
                }
                if ($found !== null) {
                    $spans[$field] = [$found[0], $found[1], null];
                    foreach (range($found[2], $found[3]) as $k) {
                        $taken[$k] = true;
                    }
                }

                continue;
            }

            for ($i = 0, $n = count($atoms); $i < $n; $i++) {
                if (isset($taken[$i])) {
                    continue;
                }
                for ($len = 1; $len <= 5 && $i + $len <= $n; $len++) {
                    if (isset($taken[$i + $len - 1])) {
                        break;
                    }
                    $text = implode(' ', array_map(fn ($a) => $a[0], array_slice($atoms, $i, $len)));
                    $fmt = null;
                    if ($field === 'sale_date') {
                        $fmt = LearnedLayoutReader::dateFormatFor($text, (string) $expected);
                        $ok = $fmt !== null;
                    } else {
                        $ok = LearnedLayoutReader::parseCell($field, $text) === $expected;
                    }
                    if ($ok) {
                        $spans[$field] = [$atoms[$i][1], $atoms[$i + $len - 1][2], $fmt];
                        foreach (range($i, $i + $len - 1) as $k) {
                            $taken[$k] = true;
                        }

                        continue 3;
                    }
                }
            }
        }

        return ['spans' => $spans, 'wrapped' => $wrapped];
    }

    /** @return array{0: int, 1: int, 2: int, 3: int}|null [from, to, firstAtom, lastAtom] of the words of $expected on this line */
    private function findTextRun(array $atoms, string $expected, array $taken): ?array
    {
        $words = $this->words($expected);
        if ($words === []) {
            return null;
        }
        $norm = array_map(fn ($a) => $this->wordKey($a[0]), $atoms);
        for ($i = 0, $n = count($atoms); $i + count($words) <= $n; $i++) {
            $ok = true;
            foreach ($words as $k => $w) {
                if (isset($taken[$i + $k]) || $norm[$i + $k] !== $w) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $last = $i + count($words) - 1;

                return [$atoms[$i][1], $atoms[$last][2], $i, $last];
            }
        }

        return null;
    }

    /**
     * An address printed over two or three lines: the first words end this line's text, the rest are on the
     * lines just below. Returns the first-line run (the slot comes from that).
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    private function findWrappedRun(array $atoms, string $expected, array $atomLines, int $li, array $taken): ?array
    {
        $words = $this->words($expected);
        $norm = array_map(fn ($a) => $this->wordKey($a[0]), $atoms);
        for ($k = count($words) - 1; $k >= 1; $k--) {
            $head = array_slice($words, 0, $k);
            for ($i = 0, $n = count($atoms); $i + $k <= $n; $i++) {
                $ok = true;
                foreach ($head as $j => $w) {
                    if (isset($taken[$i + $j]) || $norm[$i + $j] !== $w) {
                        $ok = false;
                        break;
                    }
                }
                if (!$ok) {
                    continue;
                }
                $rest = array_slice($words, $k);
                foreach ([1, 2] as $d) {
                    foreach (($atomLines[$li + $d] ?? []) as $a) {
                        // the remaining words, in order, on the following lines
                        if ($rest !== [] && $this->wordKey($a[0]) === $rest[0]) {
                            array_shift($rest);
                        }
                    }
                }
                if ($rest === []) {
                    $last = $i + $k - 1;

                    return [$atoms[$i][1], $atoms[$last][2], $i, $last];
                }
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function words(string $s): array
    {
        return array_values(array_filter(array_map(fn ($w) => $this->wordKey($w), preg_split('/\s+/u', trim($s)) ?: []), fn ($w) => $w !== ''));
    }

    private function wordKey(string $w): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($w));
    }

    /**
     * Share of the values the AI read in each comp row (price, dates, size, days on market, address, type…) that the
     * version-2 reader read back identically. Rows are paired by kind + price + date. A layout that finds the prices
     * but puts a days-on-market figure in the wrong column, or cannot say where the property is, must not be saved:
     * it would import wrong rows silently.
     *
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $recon
     */
    private function rowFieldsAgree(array $clean, array $recon): float
    {
        $mapper = new CanonicalReadMapper();
        $reconRows = $mapper->sanitise($recon)['clean']['comps'] ?? [];
        $key = fn (array $c) => ($c['kind'] ?? 'comp') . '|' . ($c['sale_price'] ?? $c['list_price'] ?? '') . '|' . ($c['sale_date'] ?? '');
        $byKey = [];
        foreach ($reconRows as $r) {
            $byKey[$key($r)][] = $r;
        }
        $norm = fn (string $s) => (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s));

        // A descriptor the report prints once for the whole table ("Full Title" in a heading, the suburb in the page
        // title) is not in any row's text, so no mapping of the rows can read it: it is graded only when the mapping
        // reads it for at least one row. The fields that say WHICH property the row is (address, scheme) always count.
        $reconHas = [];
        foreach ($reconRows as $r) {
            foreach (self::DOCUMENT_LEVEL_FIELDS as $field) {
                if (isset($r[$field])) {
                    $reconHas[$field] = true;
                }
            }
        }

        $total = 0;
        $agree = 0;
        foreach ($clean['comps'] ?? [] as $c) {
            $k = $key($c);
            $pick = null;
            foreach ($byKey[$k] ?? [] as $i => $r) {
                $pick = $i;
                break;
            }
            $r = $pick !== null ? $byKey[$k][$pick] : [];
            if ($pick !== null) {
                unset($byKey[$k][$pick]);
            }
            foreach (self::COMP_FIELDS as $field) {
                if (!isset($c[$field]) || $c[$field] === '') {
                    continue;
                }
                if (in_array($field, self::DOCUMENT_LEVEL_FIELDS, true) && !isset($reconHas[$field])) {
                    continue;
                }
                $total++;
                if (!isset($r[$field])) {
                    continue;
                }
                if (in_array($field, LearnedLayoutReader::TEXT_FIELDS, true)) {
                    $a = $norm((string) $c[$field]);
                    $b = $norm((string) $r[$field]);
                    $agree += ($a !== '' && $b !== '' && (str_contains($b, $a) || str_contains($a, $b))) ? 1 : 0;
                } else {
                    $agree += $r[$field] === $c[$field] ? 1 : 0;
                }
            }
        }

        return $total === 0 ? 1.0 : $agree / $total;
    }

    /**
     * @param  array<int, array<int, string>> $cellLines
     * @param  array<int, array<string, mixed>> $stats  rows that carry a year
     * @return array<string, mixed>|null
     */
    private function learnStatsTable(array $cellLines, array $stats): ?array
    {
        $byYear = [];
        foreach ($stats as $s) {
            $byYear[(int) $s['year']][] = $s;
        }

        $yearVotes = [];
        $metricVotes = [];
        $counts = [];
        $linesMatched = 0;

        foreach ($byYear as $year => $rows) {
            $best = null;
            foreach ($cellLines as $cells) {
                $yearIdx = array_search((string) $year, $cells, true);
                if ($yearIdx === false) {
                    continue;
                }
                $found = [];
                foreach ($rows as $s) {
                    foreach ($cells as $idx => $cell) {
                        if ($idx !== $yearIdx && $this->statMatches((string) $s['metric'], $cell, $s['value'])) {
                            $found[$s['metric']] = $idx;
                            break;
                        }
                    }
                }
                if ($found !== [] && ($best === null || count($found) > count($best['found']))) {
                    $best = ['found' => $found, 'yearIdx' => $yearIdx, 'n' => count($cells)];
                }
            }
            if ($best === null) {
                continue;
            }
            $linesMatched++;
            $yearVotes[$best['yearIdx']] = ($yearVotes[$best['yearIdx']] ?? 0) + 1;
            $counts[$best['n']] = ($counts[$best['n']] ?? 0) + 1;
            foreach ($best['found'] as $metric => $idx) {
                $metricVotes[$metric][$idx] = ($metricVotes[$metric][$idx] ?? 0) + 1;
            }
        }

        if ($linesMatched === 0 || $linesMatched / count($byYear) < 0.5) {
            return null;
        }
        arsort($yearVotes);
        arsort($counts);
        $yearCol = (int) array_key_first($yearVotes);
        $cellsN = (int) array_key_first($counts);

        $metrics = [];
        $claimed = [$yearCol => true];
        foreach ($metricVotes as $metric => $byIdx) {
            arsort($byIdx);
            foreach ($byIdx as $idx => $n) {
                if (isset($claimed[$idx]) || $idx >= $cellsN) {
                    continue;
                }
                $claimed[$idx] = true;
                $metrics[] = ['i' => (int) $idx, 'metric' => (string) $metric];
                break;
            }
        }
        if ($metrics === []) {
            return null;
        }

        return ['type' => 'stats', 'cells' => $cellsN, 'year_col' => $yearCol, 'metrics' => $metrics];
    }

    /**
     * Yearly figures printed as the data labels of a chart (see LearnedLayoutReader, "Charts"). For every row of years
     * (an axis) the heading above it is the nearest line, before the axis and after the previous axis, whose whole text
     * is one of the AI's header phrases; the labels between the two are the chart's. For each metric the AI read, the
     * (kind of label, rank in the year's column) that reproduces its figures best across all charts is the series; it
     * is kept only when it reproduces at least 60% of that metric's years — the self-test judges the rest.
     *
     * @param  array<int, string> $rawLines
     * @param  array<int, array<string, mixed>> $stats  the AI's rows that carry a year
     * @param  array<int, string> $skip  metrics another table already reads
     * @param  array<int, mixed> $headerPhrases
     * @return array<int, array<string, mixed>>
     */
    private function learnCharts(array $rawLines, array $stats, array $skip, array $headerPhrases): array
    {
        $phrases = [];
        foreach ($headerPhrases as $p) {
            if (is_string($p) && trim($p) !== '') {
                $phrases[LearnedLayoutReader::headingKey($p)] = true;
            }
        }
        $expected = [];   // metric => year => value
        foreach ($stats as $s) {
            if (!in_array($s['metric'], $skip, true)) {
                $expected[(string) $s['metric']][(int) $s['year']] = $s['value'];
            }
        }
        if ($phrases === [] || $expected === []) {
            return [];
        }

        $axes = [];
        foreach ($rawLines as $i => $line) {
            if (LearnedLayoutReader::yearAxis($line) !== null) {
                $axes[] = $i;
            }
        }
        $charts = [];   // [heading, nth, columns]
        $previous = -1;
        foreach ($axes as $axis) {
            $heading = null;
            for ($j = $axis - 1; $j > $previous; $j--) {
                if (isset($phrases[LearnedLayoutReader::headingKey($rawLines[$j])])) {
                    $heading = $j;
                    break;
                }
            }
            $previous = $axis;
            if ($heading === null) {
                continue;
            }
            $key = LearnedLayoutReader::headingKey($rawLines[$heading]);
            $nth = 0;
            for ($j = 0; $j < $heading; $j++) {
                $nth += LearnedLayoutReader::headingKey($rawLines[$j]) === $key ? 1 : 0;
            }
            $cols = LearnedLayoutReader::chartColumns($rawLines, $heading, $axis);
            if ($cols !== []) {
                $charts[] = ['heading' => trim($rawLines[$heading]), 'nth' => $nth, 'cols' => $cols];
            }
        }

        $moneyMetrics = ['median_price', 'average_price', 'median_price_12m'];
        $series = [];   // chart index => list of series
        foreach ($expected as $metric => $byYear) {
            $best = null;
            foreach ($charts as $ci => $chart) {
                foreach (['int', 'money_short', 'money', 'pct'] as $kind) {
                    foreach ([0, 1, -1, -2] as $rank) {
                        $hit = 0;
                        foreach ($byYear as $year => $value) {
                            $got = LearnedLayoutReader::pickRank($chart['cols'][$year][$kind] ?? [], $rank);
                            $tolerance = in_array($metric, $moneyMetrics, true) ? 0.5 : 0.005;
                            if ($got !== null && abs((float) $got - (float) $value) < $tolerance) {
                                $hit++;
                            }
                        }
                        $share = $hit / count($byYear);
                        if ($share >= 0.6 && ($best === null || $share > $best['share'])) {
                            $best = ['share' => $share, 'chart' => $ci, 'kind' => $kind, 'rank' => $rank];
                        }
                    }
                }
            }
            if ($best !== null) {
                $series[$best['chart']][] = ['metric' => $metric, 'kind' => $best['kind'], 'rank' => $best['rank']];
            }
        }

        $out = [];
        foreach ($series as $ci => $list) {
            $out[] = ['type' => 'chart', 'heading' => $charts[$ci]['heading'], 'nth' => $charts[$ci]['nth'], 'series' => $list];
        }

        return $out;
    }

    private function statMatches(string $metric, string $cell, mixed $expected): bool
    {
        if (in_array($metric, self::PRICE_METRICS, true)) {
            return LearnedLayoutReader::parseMoney($cell) === (int) $expected;
        }
        $v = LearnedLayoutReader::parseFloat($cell);

        return $v !== null && abs($v - (float) $expected) < 0.005;
    }

    // ── single figures ───────────────────────────────────────────────────

    /**
     * @param  array<int, string> $rawLines
     * @param  array<int, array<int, string>> $cellLines
     * @param  array<string, mixed> $clean
     * @return array<int, array{field: string, label: string, type: string}>
     */
    private function learnScalars(array $rawLines, array $cellLines, array $clean): array
    {
        $out = [];

        foreach (['lower', 'middle', 'upper', 'average', 'average_r_per_m2'] as $k) {
            $value = $clean['valuation'][$k] ?? null;
            if ($value === null) {
                continue;
            }
            $label = $this->moneyLabel($rawLines, (int) $value);
            if ($label !== null) {
                $out[] = ['field' => 'valuation.' . $k, 'label' => $label, 'type' => 'money'];

                continue;
            }
            // No label in front of the amount: it may be printed under its own column heading.
            $heading = $this->headingAbove($rawLines, (int) $value);
            if ($heading !== null) {
                $out[] = ['field' => 'valuation.' . $k, 'label' => $heading, 'type' => 'money', 'placement' => 'below'];
            }
        }

        $texts = [
            'suburb'                 => $clean['suburb'] ?? null,
            'town'                   => $clean['town'] ?? null,
            'subject.address'        => $clean['subject']['address'] ?? null,
            'subject.scheme_name'    => $clean['subject']['scheme_name'] ?? null,
            'subject.section_number' => $clean['subject']['section_number'] ?? null,
        ];
        foreach ($texts as $field => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            $label = $this->textLabel($rawLines, $cellLines, $value);
            if ($label !== null) {
                $out[] = ['field' => $field, 'label' => $label, 'type' => 'text'];
            }
        }

        // The subject's coordinates, when the report prints them ("Latitude: -25.94173"). Learned like any
        // other subject scalar so a layout read for free keeps delivering them to the property fallback
        // (spec: geocoding-spec.md "Report coordinates"). Only a label-then-number pair is learned.
        foreach (['subject.latitude', 'subject.longitude'] as $field) {
            $value = $clean['subject'][substr($field, 8)] ?? null;
            if (!is_numeric($value)) {
                continue;
            }
            $label = $this->coordinateLabel($rawLines, $cellLines, (float) $value);
            if ($label !== null) {
                $out[] = ['field' => $field, 'label' => $label, 'type' => 'text'];
            }
        }

        return $out;
    }

    /**
     * The label printed in front of a coordinate: "Latitude:" before -25.94173, on the same line or in the
     * cell to its left. The number must match the AI read to ~2 m (0.00002 degrees).
     *
     * @param array<int, string> $rawLines
     * @param array<int, array<int, string>> $cellLines
     */
    private function coordinateLabel(array $rawLines, array $cellLines, float $value): ?string
    {
        $same = static fn (string $s): bool => (bool) preg_match('/^-?\d{1,3}\.\d{3,}$/', trim($s))
            && abs((float) trim($s) - $value) < 0.00002;

        foreach ($rawLines as $li => $line) {
            if (preg_match('/^\s*(.{3,40}?)\s*:\s*(.+)$/u', $line, $m)) {
                $first = LearnedLayoutReader::cells($m[2])[0] ?? '';
                if ($same($first) && !preg_match('/\d/', $m[1])) {
                    return trim($m[1]) . ':';
                }
            }
            $cells = $cellLines[$li] ?? [];
            foreach ($cells as $k => $cell) {
                if ($k > 0 && $same($cell)) {
                    $label = $cells[$k - 1];
                    if (mb_strlen($label) <= 40 && !preg_match('/\d/', $label)
                        && mb_strlen((string) preg_replace('/[^\p{L}]+/u', '', $label)) >= 3) {
                        return $label;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The column heading printed above an amount ("Lower Boundary" over "R 4,530,000"): the text on the nearest
     * non-empty line above whose columns overlap the amount's, used when nothing precedes the amount on its own line.
     * The self-test proves the reader finds the same amount again from the heading.
     *
     * @param array<int, string> $rawLines
     */
    private function headingAbove(array $rawLines, int $value): ?string
    {
        foreach ($rawLines as $li => $line) {
            foreach (LearnedLayoutReader::runs($line) as [$text, $from, $to]) {
                if (!preg_match('/^R?\s?\d[\d ,]*$/', trim($text)) || LearnedLayoutReader::parseMoney($text) !== $value) {
                    continue;
                }
                for ($j = $li - 1; $j >= max(0, $li - 3); $j--) {
                    $above = LearnedLayoutReader::runs($rawLines[$j]);
                    if ($above === []) {
                        continue;
                    }
                    foreach ($above as [$label, $f, $t]) {
                        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
                        if (min($t, $to) - max($f, $from) > 0 && !preg_match('/\d/', $label)
                            && mb_strlen($label) <= 40 && mb_strlen((string) preg_replace('/[^\p{L}]+/u', '', $label)) >= 3) {
                            return $label;
                        }
                    }

                    break;   // only the first non-empty line above counts
                }
            }
        }

        return null;
    }

    /** @param array<int, string> $rawLines */
    private function moneyLabel(array $rawLines, int $value): ?string
    {
        foreach ($rawLines as $line) {
            if (!preg_match_all('/R?\s*\d[\d ,]*(?:\.\d{1,2})?/u', $line, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $prevEnd = 0;
            foreach ($m[0] as [$text, $offset]) {
                $segment = substr($line, $prevEnd, $offset - $prevEnd);
                $prevEnd = $offset + strlen($text);
                if (LearnedLayoutReader::parseMoney(trim($text)) !== $value) {
                    continue;
                }
                // The label is the text in front of the amount, up to the previous amount or the
                // line start; if that stretch holds several columns, only the last one is the label.
                $parts = preg_split('/\s{2,}/u', trim($segment), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $label = trim((string) preg_replace('/\s+/u', ' ', (string) end($parts)), " \t:-");
                if (preg_match('/\d/', $label) || mb_strlen((string) preg_replace('/[^\p{L}]+/u', '', $label)) < 3) {
                    continue;
                }

                return $label;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $rawLines
     * @param array<int, array<int, string>> $cellLines
     */
    private function textLabel(array $rawLines, array $cellLines, string $value): ?string
    {
        foreach ($rawLines as $li => $line) {
            if (preg_match('/^\s*(.{3,40}?)\s*:\s*(.+)$/u', $line, $m)) {
                $first = LearnedLayoutReader::cells($m[2])[0] ?? '';
                if ($this->textMatches($first, $value, true) && !preg_match('/\d/', $m[1])) {
                    // keep the colon: "Suburb:" must not also match a heading like "Suburb Sales Report"
                    return trim($m[1]) . ':';
                }
            }
            $cells = $cellLines[$li] ?? [];
            foreach ($cells as $k => $cell) {
                if ($k > 0 && $this->textMatches($cell, $value, true)) {
                    $label = $cells[$k - 1];
                    if (mb_strlen($label) <= 40 && !preg_match('/\d/', $label)
                        && mb_strlen((string) preg_replace('/[^\p{L}]+/u', '', $label)) >= 3) {
                        return $label;
                    }
                }
            }
        }

        return null;
    }

    private function textMatches(string $cell, string $expected, bool $exactOnly = false): bool
    {
        $a = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $cell)));
        $b = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $expected)));
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        return !$exactOnly && mb_strlen($b) >= 4 && str_contains($a, $b);
    }

    // ── self-test ────────────────────────────────────────────────────────

    /**
     * Share of the AI's figures the deterministic reader reproduced, counting both
     * missing and extra rows (so a mapping that reads junk lines scores low too).
     *
     * @param array<string, mixed> $clean  the AI's sanitised read
     * @param array<string, mixed> $recon  the deterministic reader's raw output
     */
    private function score(array $clean, array $recon): float
    {
        $mapper = new CanonicalReadMapper();
        $reconClean = $mapper->sanitise($recon)['clean'];

        $ai = $this->signatures($clean);
        $re = $this->signatures($reconClean);
        $matched = 0;
        foreach ($ai as $sig => $n) {
            $matched += min($n, $re[$sig] ?? 0);
        }
        $den = max(array_sum($ai), array_sum($re));

        return $den === 0 ? 0.0 : $matched / $den;
    }

    /** @return array<string, int> */
    private function signatures(array $clean): array
    {
        $sigs = [];
        $add = function (string $s) use (&$sigs) {
            $sigs[$s] = ($sigs[$s] ?? 0) + 1;
        };
        foreach ($clean['comps'] ?? [] as $c) {
            $add('c|' . ($c['kind'] ?? 'comp') . '|' . ($c['sale_price'] ?? $c['list_price'] ?? '') . '|' . ($c['sale_date'] ?? ''));
        }
        foreach ($clean['suburb_stats'] ?? [] as $s) {
            $add('s|' . $s['metric'] . '|' . ($s['year'] ?? '') . '|' . round((float) $s['value'], 2));
        }
        foreach ($clean['valuation'] ?? [] as $k => $v) {
            $add('v|' . $k . '|' . $v);
        }

        return $sigs;
    }
}
