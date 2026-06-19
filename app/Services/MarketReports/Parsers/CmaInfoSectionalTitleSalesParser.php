<?php

declare(strict_types=1);

namespace App\Services\MarketReports\Parsers;

use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Services\MarketReports\DTOs\MarketReportParseResult;
use App\Services\MarketReports\DTOs\ParserConfidence;

/**
 * V2 parser for CMA Info "Sectional Title Sales" — covers BOTH the in-scheme
 * variant ("Sectional Title sales in. MADEIRA GARDENS") and the radius variant
 * ("Sectional Title sales within. 500m"). Differs by header text only; the
 * data-row table layout is identical.
 *
 * Phase 3a additions:
 *   - per-row comp persistence to market_report_comp_rows
 *   - radius detection ("within. 500m" / "within 300 m") → subject_meta.radius_metres
 *   - subject scheme name from page-1 header
 *
 * Spec: .ai/specs/mic-complete-spec.md §8.3 + Phase 3a build prompt.
 */
final class CmaInfoSectionalTitleSalesParser extends AbstractCmaInfoParser
{
    public const PARSER_VERSION = 'cma_info_sectional_title_sales_v2';

    public function getReportTypeKey(): string
    {
        return 'cma_info_sectional_title_sales';
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function canParse(string $filePath): ParserConfidence
    {
        $text = $this->extractText($filePath);
        if ($text === '') return ParserConfidence::none('empty text');
        if (!$this->looksLikeCmaInfo($text)) return ParserConfidence::none('no CMA Info signature');

        $score = 0.0;
        $reasons = ['cma info signature'];

        $pages = $this->pageCount($text);
        if ($pages >= 2 && $pages <= 5) { $score += 0.3; $reasons[] = "page count {$pages}"; }

        if ($this->findHeader($text, 'Sectional Title sales')) {
            $score += 0.5;
            $reasons[] = 'Sectional Title sales header';
        }
        if (preg_match('/within\.?\s*\d{2,4}\s*m/i', $text)) {
            $score += 0.1;
            $reasons[] = 'radius signal';
        }

        return ParserConfidence::high($score, $reasons);
    }

    public function parse(string $filePath, MarketReport $report): MarketReportParseResult
    {
        $text = $this->extractText($filePath);
        if ($text === '') {
            return new MarketReportParseResult(rawJson: ['note' => 'No text extracted.']);
        }

        $points    = [];
        $addresses = [];
        $compRows  = [];
        $today     = now()->toDateString();

        // ── Subject + radius detection ─────────────────────────────────────
        $subjectMeta = [];

        // Page-1 first line — "MADEIRA GARDENS, 4 TUCKER AVENUE, UVONGO" OR a
        // scheme on a numberless road — "PUMULA, DUKE ROAD, MARGATE NORTH BEACH".
        // The street number is OPTIONAL: some schemes sit on a named road with no
        // number, and the old mandatory-\d pattern left subject_scheme_name NULL
        // for them (then every in-scheme comp lost its scheme — pres 98 / PUMULA).
        $subjectAddrSuburb = null;
        if (preg_match('/^([A-Z][A-Z \']{2,40}),\s+((?:\d{1,4}\s+)?[A-Z][A-Z \']{2,40}),\s+([A-Z][A-Z \']{2,40})$/m', $text, $m)) {
            $subjectMeta['subject_scheme_name'] = trim($m[1]);
            $subjectMeta['subject_address']     = trim($m[2]) . ', ' . trim($m[3]);
            $subjectAddrSuburb                  = trim($m[3]);
        }
        // Resolve report-level suburb from explicit upload value, else
        // subject_address trailing token. Promote to subject_meta so
        // ParseMarketReportJob's backfill writes it onto the report row.
        $resolved  = $this->resolveReportSuburb($report->source_suburb, [$subjectAddrSuburb]);
        $suburb    = $resolved['normalised'];
        $suburbRaw = $resolved['raw'];
        if ($suburbRaw !== null) {
            $subjectMeta['source_suburb'] = $suburbRaw;
        }

        // Radius: "Sectional Title sales within. 500m" or "300m radius" or "Within 500 m of"
        $radius = null;
        if (preg_match('/Sectional\s+Title\s+sales\s+within\.?\s*(\d{2,4})\s*m/i', $text, $m)) {
            $radius = (int) $m[1];
        } elseif (preg_match('/Within\s*(\d{2,4})\s*m\s*of/i', $text, $m)) {
            $radius = (int) $m[1];
        }
        if ($radius !== null) {
            $subjectMeta['radius_metres'] = $radius;
            $points[] = [
                'metric_key'           => 'sectional_radius_metres',
                'metric_value_numeric' => (float) $radius,
                'metric_date'          => $today,
                'confidence'           => 'high',
                'suburb_normalised'    => $suburb,
            ];
        }

        // ── Comp rows from the sales table ──────────────────────────────────
        // For the IN-SCHEME variant the leading "Scheme Name" column is the
        // header — every row implicitly belongs to subject_scheme_name.
        // For the RADIUS variant each row has its own "SCHEME, ADDRESS" prefix.
        $isInScheme = (bool) preg_match('/Sectional\s+Title\s+sales\s+in\.?\s+[A-Z]/i', $text);

        $rowIndex = 0;
        foreach ($this->extractCompRows($text, $isInScheme, $subjectMeta['subject_scheme_name'] ?? null) as $row) {
            $compRows[] = [
                'row_index'             => $rowIndex++,
                'row_type'              => MarketReportCompRow::ROW_COMP,
                'scheme_name'           => $row['scheme_name']    ?? null,
                'section_number'        => $row['section_number'] ?? null,
                'ss_number'             => $row['ss_number']      ?? null,
                'ss_year'               => $row['ss_year']        ?? null,
                'address'               => $row['address']        ?? null,
                'suburb_normalised'     => $suburb,
                'property_type'         => $row['property_type']  ?? 'Residence',
                'extent_m2'             => $row['extent_m2']      ?? null,
                'sale_date'             => $row['sale_date']      ?? null,
                'sale_price'            => $row['sale_price']     ?? null,
                'r_per_m2'              => $row['r_per_m2']       ?? null,
                'distance_to_subject_m' => null,  // not present in source table
                'raw_row_json'          => $row,
            ];

            if (!empty($row['sale_price'])) {
                $points[] = [
                    'metric_key'           => 'sectional_radius_sale_price',
                    'metric_value_numeric' => (float) $row['sale_price'],
                    'metric_date'          => $row['sale_date'] ?? $today,
                    'confidence'           => 'medium',
                    'suburb_normalised'    => $suburb,
                    'town'                 => $report->source_town,
                ];
            }
            if (!empty($row['address']) || !empty($row['scheme_name'])) {
                $addresses[] = $this->makeAddress([
                    'street_name' => $row['scheme_name'] ?? $row['address'] ?? null,
                    'suburb'      => $suburbRaw ?? $report->source_suburb,
                    'sale_price'  => $row['sale_price'] ?? null,
                    'sale_date'   => $row['sale_date'] ?? null,
                ]);
            }
        }

        // Lower/middle/upper ranges (footer)
        if (preg_match('/Lower Range:\s*R\s*([\d ,]+)\s+Middle Range:\s*R\s*([\d ,]+)\s+Upper Range:\s*R\s*([\d ,]+)/u', $text, $m)) {
            foreach (['cma_value_lower' => $m[1], 'cma_value_middle' => $m[2], 'cma_value_upper' => $m[3]] as $key => $val) {
                $price = $this->parsePrice($val);
                if ($price !== null) {
                    $points[] = ['metric_key' => $key, 'metric_value_numeric' => $price, 'metric_date' => $today, 'confidence' => 'medium', 'suburb_normalised' => $suburb];
                }
            }
        }

        return new MarketReportParseResult(
            dataPoints:        $points,
            extractedAddresses: $addresses,
            rawJson:           [
                'parser_version'  => self::PARSER_VERSION,
                'pages'           => $this->pageCount($text),
                'comp_rows'       => count($compRows),
                'radius_metres'   => $radius,
                'is_in_scheme'    => $isInScheme,
            ],
            subjectMeta:       $subjectMeta,
            compRows:          $compRows,
        );
    }

    /**
     * Extract rows from either variant. The in-scheme report wraps badly in
     * pdftotext: rows split across lines, with section + date + prices on
     * one line and ss/yr/Residence/extent on a different line (or vice
     * versa). Three complementary patterns catch the bulk of rows:
     *
     *   A — full row: section + ss + yr + Residence + extent + date + R sp + R ppm
     *   B — short row: section + date + R sp + R ppm (no ss/yr/extent on same line)
     *   C — orphan: ss + yr + Residence + extent + R sp + R ppm (no section, no date)
     *
     * After extraction we dedupe by a row fingerprint so a row that matches
     * both A and B doesn't double-count.
     *
     * Price tokens are bounded "thousands group" patterns (Phase 3e A1) so
     * they can't bleed across columns.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractCompRows(string $text, bool $isInScheme, ?string $impliedScheme): array
    {
        $rows = [];

        $priceTok = '\d{1,3}(?:[\s,]\d{3}){0,3}';
        $ppmTok   = '\d{1,3}(?:[\s,]\d{3}){0,2}';

        // ── Stacked multi-section sales (FIRST, so they win the (date|price)
        // dedupe over Pattern B's section-less capture of the same anchor) ──
        // A combined-unit sale wraps its sections + extents to the lines above
        // and below the anchor; the radius table's anchor carries [price, R/m²]
        // in source order. See AbstractCmaInfoParser::extractStackedSectionalGroups.
        foreach ($this->extractStackedSectionalGroups($text) as $g) {
            $price = $this->parsePriceBounded((string) ($g['r_amounts'][0] ?? ''), 'st.stacked.sale_price');
            if ($price === null) continue;

            $extent = $g['extent_sum'];
            // R/m² — prefer the printed figure (2nd R-amount); fall back to the
            // combined-extent quotient if it's missing or out of the sane band.
            $ppm = $g['r_amounts'][1] ?? null;
            if ($ppm === null || $ppm < 100 || $ppm > 500_000) {
                $ppm = ($extent !== null && $extent > 0) ? (int) round($price / $extent) : null;
            }

            $rows[] = [
                'scheme_name'    => $g['scheme_name'] ?? $impliedScheme,
                'address'        => null,
                'section_number' => $g['section_label'] !== '' ? $g['section_label'] : null,
                'ss_number'      => $g['ss_number'],
                'ss_year'        => $g['ss_year'],
                'property_type'  => 'Residence',
                'extent_m2'      => $extent,
                // BOTH extents preserved for display ("65/22"); extent_m2 above
                // is the summed math basis only. Rides in raw_row_json through
                // to the comp's rendered "Unit m²" cell.
                'extent_display' => $g['extent_label'],
                'sale_date'      => $g['sale_date'],
                'sale_price'     => $price,
                'r_per_m2'       => $ppm,
            ];
        }

        // ── Pattern A — full row ────────────────────────────────────────────
        $patternA = '/(?<sec>\d{1,3})\s+(?<ss>\d{2,5})\s+(?<yr>\d{4})\s+Residence\s+(?<ext>\d{1,5})\s*m\S?\s+(?<date>\d{4}[\/\-]\d{2}[\/\-]\d{2})\s+R\s*(?<sp>' . $priceTok . ')\s+R\s*(?<ppm>' . $ppmTok . ')/u';

        if (preg_match_all($patternA, $text, $matchesA, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matchesA as $m) {
                $rows[] = $this->buildRow($m, $text, $isInScheme, $impliedScheme, hasFull: true);
            }
        }

        // Track offsets matched by A so we don't double-count when B/C overlap.
        $matchedOffsets = [];
        foreach ($matchesA ?? [] as $m) {
            $start = $m[0][1];
            $matchedOffsets[] = [$start, $start + strlen($m[0][0])];
        }

        // ── Pattern B — short row (section + date + 2 R-figures) ────────────
        // Whitespace-only between section and date, so the row has lost its
        // ss/yr/extent column to a wrap.
        $patternB = '/(?<sec>\d{1,3})\s+(?<date>\d{4}[\/\-]\d{2}[\/\-]\d{2})\s+R\s*(?<sp>' . $priceTok . ')\s+R\s*(?<ppm>' . $ppmTok . ')/u';

        if (preg_match_all($patternB, $text, $matchesB, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matchesB as $m) {
                $start = $m[0][1];
                if ($this->offsetOverlaps($start, $matchedOffsets)) continue;
                $rows[] = $this->buildRow($m, $text, $isInScheme, $impliedScheme, hasFull: false);
            }
        }

        // ── Pattern C — orphan (ss + yr + Residence + extent + R sp + R ppm
        // with no section number or date on the line) ─────────────────────
        $patternC = '/(?<ss>\d{2,5})\s+(?<yr>\d{4})\s+Residence\s+(?<ext>\d{1,5})\s*m\S?\s+R\s*(?<sp>' . $priceTok . ')\s+R\s*(?<ppm>' . $ppmTok . ')/u';

        if (preg_match_all($patternC, $text, $matchesC, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matchesC as $m) {
                $start = $m[0][1];
                if ($this->offsetOverlaps($start, $matchedOffsets)) continue;
                $rows[] = $this->buildOrphanRow($m, $isInScheme, $impliedScheme);
            }
        }

        // Dedupe — same (sale_date, sale_price) is the same comp.
        return $this->dedupeBySalePrice($rows);
    }

    /**
     * Build a row from pattern A or B. For the in-scheme variant the scheme
     * name is implied (no lookback needed). For radius the lookback finds
     * the per-row scheme prefix.
     *
     * @param array<int|string, array{0:string,1:int}|string> $m
     */
    private function buildRow(array $m, string $text, bool $isInScheme, ?string $impliedScheme, bool $hasFull): array
    {
        $sName = $impliedScheme;
        $sAddress = null;

        if (!$isInScheme) {
            // Radius variant — find the nearest "SCHEME, ADDR, SUBURB" before this match.
            $matchStart = $m[0][1];
            $lookback   = max(0, $matchStart - 200);
            $context    = mb_substr($text, $lookback, $matchStart - $lookback);
            // Street number OPTIONAL — "PUMULA, DUKE ROAD" (no number) must bind
            // its scheme exactly like "LOSCONA, 1 SAINT ANDREWS AVENUE" does. The
            // old mandatory-number pattern silently dropped the scheme on every
            // numberless-road scheme, so those comps fell to vicinity instead of
            // their own complex group.
            if (preg_match_all('/([A-Z][A-Z \']{2,40}),\s+((?:[0-9]{1,4}\s+)?[A-Z][A-Z \']{2,40})(?:,\s+([A-Z][A-Z \']{2,40}))?/u', $context, $am, PREG_SET_ORDER)) {
                $last = end($am);
                $sName   = trim($last[1]);
                $sAddress = trim($last[2]);
            }
        }

        // Year-tail guard. Pattern B matches "(?<sec>\d{1,3})\s+(date)..."; on a
        // row whose section column wrapped to another line the token before the
        // date is the 4-digit "SS Year" (e.g. "1976"), so \d{1,3} captures its
        // last 3 digits ("976") as a phantom section. If the captured section is
        // immediately preceded by another digit it is a year-tail, not a real
        // section number — drop it rather than emit "Unit 976, PUMULA".
        $section    = $m['sec'][0] ?? null;
        $secOffset  = $m['sec'][1] ?? -1;
        if ($section !== null && $secOffset > 0 && ctype_digit((string) ($text[$secOffset - 1] ?? ''))) {
            $section = null;
            // The street we looked back to is the scheme's SHARED road (e.g.
            // "DUKE ROAD" for every PUMULA unit) — not unit-identifying. With no
            // section to pair it with, that street would become the comp's label
            // ("DUKE ROAD") instead of the complex name. Drop it so the comp
            // identifies by its scheme ("PUMULA").
            if ($sName !== null) {
                $sAddress = null;
            }
        }

        return [
            'scheme_name'    => $sName,
            'address'        => $sAddress,
            'section_number' => $section,
            'ss_number'      => $hasFull && !empty($m['ss'][0])  ? $m['ss'][0] : null,
            'ss_year'        => $hasFull && !empty($m['yr'][0])  ? (int) $m['yr'][0] : null,
            'property_type'  => 'Residence',
            'extent_m2'      => $hasFull && !empty($m['ext'][0]) ? (int) $m['ext'][0] : null,
            'sale_date'      => $this->parseDate($m['date'][0]),
            'sale_price'     => $this->parsePriceBounded($m['sp'][0], 'st.sale_price'),
            'r_per_m2'       => !empty($m['ppm'][0]) ? $this->parsePriceBounded($m['ppm'][0], 'st.r_per_m2', null, 100, 500_000) : null,
        ];
    }

    /**
     * Orphan row — no section + no date but has ss/yr/extent + prices.
     * We keep it for aggregate stats; section_number=null is intentional.
     */
    private function buildOrphanRow(array $m, bool $isInScheme, ?string $impliedScheme): array
    {
        return [
            'scheme_name'    => $impliedScheme,
            'address'        => null,
            'section_number' => null,
            'ss_number'      => $m['ss'][0] ?? null,
            'ss_year'        => isset($m['yr'][0]) ? (int) $m['yr'][0] : null,
            'property_type'  => 'Residence',
            'extent_m2'      => (int) $m['ext'][0],
            'sale_date'      => null,
            'sale_price'     => $this->parsePriceBounded($m['sp'][0], 'st.sale_price.orphan'),
            'r_per_m2'       => $this->parsePriceBounded($m['ppm'][0], 'st.r_per_m2.orphan', null, 100, 500_000),
        ];
    }

    private function offsetOverlaps(int $start, array $ranges): bool
    {
        foreach ($ranges as [$lo, $hi]) {
            if ($start >= $lo && $start <= $hi) return true;
        }
        return false;
    }

    /**
     * Dedupe by (sale_date, sale_price) for full/short rows; orphan rows
     * (no date) dedupe by (sale_price, r_per_m2) — they're terminal.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function dedupeBySalePrice(array $rows): array
    {
        $seen = [];
        $out  = [];
        foreach ($rows as $r) {
            if (!empty($r['sale_date'])) {
                $key = 'D|' . $r['sale_date'] . '|' . ($r['sale_price'] ?? '');
            } else {
                $key = 'O|' . ($r['sale_price'] ?? '') . '|' . ($r['r_per_m2'] ?? '');
            }
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $r;
        }
        return $out;
    }
}
