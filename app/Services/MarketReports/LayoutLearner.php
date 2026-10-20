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

        $tables = [];
        foreach (['comp', 'listing'] as $kind) {
            $comps = array_values(array_filter($clean['comps'] ?? [], fn ($c) => ($c['kind'] ?? 'comp') === $kind));
            if ($comps !== []) {
                $t = $this->learnCompTable($cellLines, $comps, $kind);
                if ($t !== null) {
                    $tables[] = $t;
                }
            }
        }
        $stats = array_values(array_filter($clean['suburb_stats'] ?? [], fn ($s) => isset($s['year'])));
        if ($stats !== []) {
            $t = $this->learnStatsTable($cellLines, $stats);
            if ($t !== null) {
                $tables[] = $t;
            }
        }
        $scalars = $this->learnScalars($rawLines, $cellLines, $clean);

        if ($tables === [] && !array_filter($scalars, fn ($s) => str_starts_with($s['field'], 'valuation.'))) {
            return ['ok' => false, 'reason' => 'no table or labelled figure could be located in the PDF text'];
        }

        $mapping = ['version' => 1, 'tables' => $tables, 'scalars' => $scalars];
        $score = $this->score($clean, $this->reader->read($mapping, $text));
        $min = $this->settings->selftestMin();
        if ($score < $min) {
            return ['ok' => false, 'reason' => 'the learned mapping reproduced only ' . round($score * 100) . '% of the AI reading (needs ' . round($min * 100) . '%)', 'score' => $score];
        }

        $kinds = [];
        foreach ($tables as $t) {
            $kinds[] = $t['type'] === 'stats' ? 'suburb_stats' : ($t['kind'] === 'listing' ? 'listings' : 'comps');
        }
        if (array_filter($scalars, fn ($s) => str_starts_with($s['field'], 'valuation.'))) {
            $kinds[] = 'valuation';
        }

        return ['ok' => true, 'mapping' => $mapping, 'anchors' => $anchors, 'score' => $score, 'kind' => implode('+', array_unique($kinds))];
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

        return $out;
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
