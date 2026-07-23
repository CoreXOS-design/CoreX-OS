<?php

declare(strict_types=1);

namespace App\Services\MarketReports\Parsers;

use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Services\MarketReports\DTOs\MarketReportParseResult;
use App\Services\MarketReports\DTOs\ParserConfidence;

/**
 * V1 parser for CMA Info "Vicinity Sales" reports — covers BOTH the
 * Residential freehold variant ("Residential sales within. 300m") and
 * the Vacant Land variant ("Vacant land sales within. 2000m"). Table
 * structure is identical: Dist | Erf No | Address | Erf Usage | Type |
 * Extent | Last Sale Date | Last Sale | R/m².
 *
 * Distinguished from CmaInfoSectionalTitleSalesParser by:
 *   - Header text: "Residential sales within" / "Vacant land sales
 *     within" (NOT "Sectional Title sales within")
 *   - Subject line: "27 HIBISCUS AVENUE, MARGATE BEACH" (2-part:
 *     street + suburb, no scheme name)
 *   - Row prefix: Dist column (distance to subject, metres or km),
 *     populated to market_report_comp_rows.distance_to_subject_m
 *   - Erf identifier: single erf_number column (no ss/yr/section)
 *   - Property descriptor: Erf Usage + Type columns ("Residential" /
 *     "Vacant Land" / etc.)
 *   - Summary stats: 5 values (Lower / Middle / Upper Range +
 *     Average + Average R/m²)
 *
 * The subject_property_type metadata flags 'residential' or
 * 'vacant_land' on market_reports.subject_meta so downstream
 * surfaces (map filters, MIC analysis) can route per-type.
 *
 * Spec: .ai/specs/mic-complete-spec.md §8.4 (Vicinity Sales family).
 */
final class CmaInfoVicinitySaleParser extends AbstractCmaInfoParser
{
    public const PARSER_VERSION = 'cma_info_vicinity_sale_v1';

    public function getReportTypeKey(): string
    {
        return 'cma_info_vicinity_sale';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function canParse(string $filePath): ParserConfidence
    {
        $text = $this->extractText($filePath);
        if ($text === '') return ParserConfidence::none('empty text');

        // Yield to the sectional title parser when its header is present —
        // both share the "within. NNNm" radius pattern so positive markers
        // alone aren't enough to disambiguate.
        if (stripos($text, 'Sectional Title sales') !== false) {
            return ParserConfidence::none('handled by CmaInfoSectionalTitleSalesParser');
        }

        if (!$this->looksLikeCmaInfo($text)) {
            return ParserConfidence::none('no CMA Info signature');
        }

        $score   = 0.0;
        $reasons = ['cma info signature', 'no sectional title header'];

        // Strong positive markers — one of these distinguishes the variant.
        $isResidential = (bool) preg_match('/Residential\s+sales\s+within/i', $text);
        $isVacantLand  = (bool) preg_match('/Vacant\s+land\s+sales\s+within/i', $text);
        if ($isResidential) {
            $score += 0.6;
            $reasons[] = 'Residential sales within';
        } elseif ($isVacantLand) {
            $score += 0.6;
            $reasons[] = 'Vacant land sales within';
        }

        // Confirming marker — suburb scope line is present on both variants.
        if (preg_match('/Limited\s+to\.?\s+[A-Z]/i', $text)) {
            $score += 0.2;
            $reasons[] = 'Limited to suburb scope line';
        }

        $pages = $this->pageCount($text);
        if ($pages >= 2 && $pages <= 5) {
            $score += 0.2;
            $reasons[] = "page count {$pages}";
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

        // ── Subject + radius + property-type detection ────────────────────
        $subjectMeta = [];

        $isResidential = (bool) preg_match('/Residential\s+sales\s+within/i', $text);
        $isVacantLand  = (bool) preg_match('/Vacant\s+land\s+sales\s+within/i', $text);
        $subjectMeta['subject_property_type'] = $isVacantLand
            ? 'vacant_land'
            : ($isResidential ? 'residential' : 'unknown');

        // Detect in-PDF suburb candidates BEFORE we write any comp rows
        // (the bug we're fixing: $suburb used to be bound from
        // $report->source_suburb at this point, which is often NULL).
        $suburbScope = null;
        if (preg_match('/Limited\s+to\.?\s+([A-Z][A-Z \']{2,40})/i', $text, $m)) {
            $suburbScope = trim($m[1]);
            $subjectMeta['suburb_scope'] = $suburbScope;  // legacy key, kept
        }
        $subjectAddrSuburb = null;
        if (preg_match('/^(\d{1,4}[A-Z]?\s+[A-Z][A-Z \']{2,40}),\s+([A-Z][A-Z \']{2,40})$/m', $text, $m)) {
            $subjectMeta['subject_address'] = trim($m[1]) . ', ' . trim($m[2]);
            $subjectAddrSuburb = trim($m[2]);
        }
        // Priority: explicit report.source_suburb → Limited-to scope →
        // subject_address trailing token. raw stays original case for
        // backfill onto market_reports.source_suburb.
        $resolved = $this->resolveReportSuburb($report->source_suburb, [$suburbScope, $subjectAddrSuburb]);
        $suburb       = $resolved['normalised'];
        $suburbRaw    = $resolved['raw'];
        if ($suburbRaw !== null) {
            $subjectMeta['source_suburb'] = $suburbRaw;
        }

        // Radius capture — variants: "within. 300m" / "within 2000 m" / "within 1.2km"
        $radius = null;
        if (preg_match('/(?:Residential|Vacant\s+land)\s+sales\s+within\.?\s*([\d.]+)\s*(km|m)/i', $text, $m)) {
            $radius = $this->normaliseMetres($m[1], strtolower($m[2]));
        }
        if ($radius !== null) {
            $subjectMeta['radius_metres'] = $radius;
            $points[] = [
                'metric_key'           => 'vicinity_radius_metres',
                'metric_value_numeric' => (float) $radius,
                'metric_date'          => $today,
                'confidence'           => 'high',
                'suburb_normalised'    => $suburb,
            ];
        }

        // ── Comp rows from the sales table ────────────────────────────────
        foreach ($this->extractCompRows($text) as $rowIndex => $row) {
            $compRows[] = [
                'row_index'             => $rowIndex,
                'row_type'              => MarketReportCompRow::ROW_COMP,
                'scheme_name'           => null,           // freehold — no scheme
                'section_number'        => null,
                'ss_number'              => null,
                'ss_year'                => null,
                'address'               => $row['address']        ?? null,
                'suburb_normalised'     => $suburb,
                'property_type'         => $row['property_type']  ?? 'Residential',
                'extent_m2'             => $row['extent_m2']      ?? null,
                'sale_date'             => $row['sale_date']      ?? null,
                'sale_price'            => $row['sale_price']     ?? null,
                'r_per_m2'              => $row['r_per_m2']       ?? null,
                'distance_to_subject_m' => $row['distance_to_subject_m'] ?? null,
                'raw_row_json'          => $row,
            ];

            if (!empty($row['sale_price'])) {
                $points[] = [
                    'metric_key'           => 'vicinity_radius_sale_price',
                    'metric_value_numeric' => (float) $row['sale_price'],
                    'metric_date'          => $row['sale_date'] ?? $today,
                    'confidence'           => 'medium',
                    'suburb_normalised'    => $suburb,
                    'town'                 => $report->source_town,
                ];
            }
            if (!empty($row['address'])) {
                $addresses[] = $this->makeAddress([
                    'street_name' => $row['address'],
                    'erf_number'  => $row['erf_number'] ?? null,
                    'suburb'      => $suburbRaw ?? $report->source_suburb,
                    'sale_price'  => $row['sale_price'] ?? null,
                    'sale_date'   => $row['sale_date'] ?? null,
                ]);
            }
        }

        // ── Summary stats (5 values per vicinity-sale footer) ─────────────
        // "Lower Range: R 1,200,000  Middle Range: R 1,750,000
        //  Upper Range: R 2,400,000  Average: R 1,820,000
        //  Average R/m²: R 8,450"
        if (preg_match(
            '/Lower\s+Range:\s*R\s*([\d ,]+)\s+Middle\s+Range:\s*R\s*([\d ,]+)\s+Upper\s+Range:\s*R\s*([\d ,]+)/u',
            $text,
            $m,
        )) {
            $rangeKeys = ['cma_value_lower' => $m[1], 'cma_value_middle' => $m[2], 'cma_value_upper' => $m[3]];
            foreach ($rangeKeys as $key => $val) {
                $price = $this->parsePrice($val);
                if ($price !== null) {
                    $points[] = [
                        'metric_key'           => $key,
                        'metric_value_numeric' => $price,
                        'metric_date'          => $today,
                        'confidence'           => 'medium',
                        'suburb_normalised'    => $suburb,
                    ];
                }
            }
        }
        if (preg_match('/Average:\s*R\s*([\d ,]+)/u', $text, $m)) {
            $avg = $this->parsePrice($m[1]);
            if ($avg !== null) {
                $points[] = [
                    'metric_key'           => 'cma_value_average',
                    'metric_value_numeric' => $avg,
                    'metric_date'          => $today,
                    'confidence'           => 'medium',
                    'suburb_normalised'    => $suburb,
                ];
            }
        }
        if (preg_match('/Average\s+R\/m\S*:\s*R\s*([\d ,]+)/u', $text, $m)) {
            $avgPerM2 = $this->parsePrice($m[1]);
            if ($avgPerM2 !== null) {
                $points[] = [
                    'metric_key'           => 'cma_value_average_r_per_m2',
                    'metric_value_numeric' => $avgPerM2,
                    'metric_date'          => $today,
                    'confidence'           => 'medium',
                    'suburb_normalised'    => $suburb,
                ];
            }
        }

        return new MarketReportParseResult(
            dataPoints:        $points,
            extractedAddresses: $addresses,
            rawJson:           [
                'parser_version'         => self::PARSER_VERSION,
                'pages'                  => $this->pageCount($text),
                'comp_rows'              => count($compRows),
                'radius_metres'          => $radius,
                'subject_property_type'  => $subjectMeta['subject_property_type'],
            ],
            subjectMeta:       $subjectMeta,
            compRows:          $compRows,
        );
    }

    /**
     * Extract comparable-sale rows from the vicinity-sale table.
     *
     * Column order (pdftotext -layout):
     *   Dist | Erf No | Address | Erf Usage | Type | Extent (m²) | Last Sale Date | Last Sale (R) | R/m²
     *
     * ROOT-CAUSE FIX — Shelly Beach / estate vicinity reports parsed 0/15
     * (doc 456 "1087 Harrison Drive"). The previous single regex REQUIRED a
     * number-prefixed street address AND a non-blank Erf Usage on one physical
     * line. Estate / vicinity reports routinely ship rows the old pattern could
     * never match:
     *   - BLANK address ("… 1619   , SHELLY BEACH …" — the number is the Erf No),
     *   - numberless street ("HARRISON DRIVE, SHELLY BEACH"),
     *   - no Erf Usage column value,
     *   - address WRAPPED across the lines above/below the numbered row
     *     ("HARRISON DRIVE, BAHARI BAY ECO ESTATE," / "SHELLY BEACH"),
     *   - Erf No itself wrapped ("730-" above, "11" below → erf 730-11).
     *
     * New approach (parse by the COLUMNS, per Johan's layout note): a row is any
     * physical line carrying "<row idx> <dist> m … <YYYY/MM/DD>". The reliable
     * keys are Erf No + suburb; the data is Extent + Sale Date + Sale Price +
     * R/m². Address and Erf Usage are captured WHEN PRESENT but never required,
     * so no legitimate comp is dropped. Fragments wrapped onto the immediately
     * adjacent lines are merged back — numeric fragments complete the Erf No,
     * text fragments complete the address. Row highlight/shading is invisible to
     * pdftotext, so it never affects extraction.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractCompRows(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $count = count($lines);

        // A sales-table ROW LINE: row index + distance-in-metres + a sale date,
        // all on one physical line. That is the ONLY hard requirement.
        $isRowLine = static fn (string $l): bool =>
            (bool) preg_match('/^\s*\d{1,3}\s+\d{1,5}\s*m\s+.*\d{4}\/\d{2}\/\d{2}/u', $l);

        // A wrapped-cell continuation fragment: has content, is NOT itself a row
        // line, carries no date, and is not a header/summary/scope line.
        $isCont = static function (string $l) use ($isRowLine): bool {
            $t = trim($l);
            if ($t === '') return false;
            if ($isRowLine($l)) return false;
            if (preg_match('/\d{4}\/\d{2}\/\d{2}/', $l)) return false;
            if (preg_match('/Lower\s+Range|Middle\s+Range|Upper\s+Range|Average|Price\s+Range|Limited\s+to|sales\s+within/i', $t)) return false;
            // Skip table header / label lines (their wrapped "Erf No" / "Date"
            // labels would otherwise be mistaken for an address fragment).
            if (preg_match('/\b(Erf|Dist|Address|Usage|Type|Extent|Last\s+Sale|Date|R\/m|Page\s+\d)\b/i', $t)) return false;
            return true;
        };

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            if (!$isRowLine($lines[$i])) {
                continue;
            }

            // Wrapped fragments immediately adjacent to this numbered row line.
            $above = ($i > 0 && $isCont($lines[$i - 1])) ? trim($lines[$i - 1]) : '';
            $below = [];
            for ($j = $i + 1; $j < $count && $isCont($lines[$j]); $j++) {
                $below[] = trim($lines[$j]);
            }

            $row = $this->parseVicinityRowLine($lines[$i], $above, $below);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return array_values($this->dedupeBySalePrice($rows));
    }

    /**
     * Parse ONE numbered sales-table line (plus any wrapped fragments) into a
     * comp row. Anchors on the sale date; pulls Erf No + Extent off the columns
     * before it and Sale Price + R/m² after it. Address and Erf Usage optional.
     *
     * @param  array<int, string>  $belowFrags
     * @return array<string, mixed>|null
     */
    private function parseVicinityRowLine(string $line, string $aboveFrag, array $belowFrags): ?array
    {
        if (!preg_match('/^\s*(?<idx>\d{1,3})\s+(?<dist>\d{1,5})\s*m\s+(?<body>.+)$/u', $line, $m)) {
            return null;
        }
        $dist = $m['dist'];
        $body = $m['body'];

        // The sale date is the pivot column.
        if (!preg_match('/\d{4}\/\d{2}\/\d{2}/', $body, $dm, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $date   = $dm[0][0];
        $before = substr($body, 0, $dm[0][1]);           // Erf | Address | Usage | Type | Extent
        $after  = substr($body, $dm[0][1] + strlen($date)); // Sale Price | R/m²

        // After the date: Last Sale (price) then R/m². Thousands are separated
        // by a SINGLE space ("1 550 000") — match \s\d{3} (not \s+) so a match
        // can never leap the wide inter-column gaps.
        $sp = null; $ppm = null;
        if (preg_match_all('/R\s+(\d{1,3}(?:\s\d{3}){0,3})/u', $after, $pm)) {
            $sp  = $pm[1][0] ?? null;
            $ppm = $pm[1][1] ?? null;
        }

        // Extent — trailing "NNN m²" on the tail of `before`. Single-space
        // thousands so the match cannot span the erf→extent whitespace gap
        // (that spanning produced "erf=1 ext=659835" on wrapped rows).
        $ext = null;
        if (preg_match('/(\d{1,3}(?:\s\d{3})?)\s*m\S?\s*$/u', $before, $em)) {
            $ext    = $em[1];
            $before = substr($before, 0, -strlen($em[0]));
        }

        // Erf No — leading numeric token (optional; may be wrapped instead).
        $erf = null;
        if (preg_match('/^\s*(\d{1,6}(?:-\d{1,3})?)\b/u', $before, $erm)) {
            $erf    = $erm[1];
            $before = substr($before, strlen($erm[0]));
        }

        // Erf Usage (optional).
        $usage = null;
        if (preg_match('/\b(Residential|Vacant\s+land|Commercial|Agricultural|Other)\b/i', $before, $um)) {
            $usage  = $um[1];
            $before = str_replace($um[0], ' ', $before);
        }

        // Did the Address cell render ANY text on the numbered line? Empty here
        // means a TRUE wrap (address is only on the adjacent lines); a ", SUBURB"
        // leftover means a street-less-but-on-line row (no wrap to borrow).
        $rawLeftoverEmpty = (trim($before) === '');

        // Inline address = the leftover street text. A leading comma means the
        // STREET was blank: keep an estate/complex segment (still has a comma)
        // but drop a bare suburb-only remainder (Erf No + suburb already identify
        // the sale).
        $inline = trim(preg_replace('/\s{2,}/', ' ', $before) ?? '');
        if ($inline !== '' && $inline[0] === ',') {
            $inner  = trim(ltrim($inline, ', '));
            $inline = (strpos($inner, ',') !== false) ? $inner : '';
        }

        // Merge wrapped fragments — but ONLY into a field that is BLANK on this
        // row's own line. A fragment sitting between two numbered rows is
        // adjacent to both; merging it only into the row whose field is blank
        // stops it being double-counted onto the neighbour that already has the
        // value inline. Numeric fragments ("730-", "11") complete a blank Erf
        // No; text fragments complete a blank address (reading order: above →
        // below).
        $aboveErf  = ($aboveFrag !== '' && $this->isErfFragment($aboveFrag)) ? trim($aboveFrag) : '';
        $aboveAddr = ($aboveFrag !== '' && !$this->isErfFragment($aboveFrag)) ? trim($aboveFrag) : '';
        $belowErf = [];
        $belowAddr = [];
        foreach ($belowFrags as $bf) {
            if ($this->isErfFragment($bf)) {
                $belowErf[] = trim($bf);
            } else {
                $belowAddr[] = trim($bf);
            }
        }

        // Erf No: keep the inline value; only when blank, complete it from the
        // wrapped numeric fragments.
        if ($erf === null) {
            $joined = trim($aboveErf . implode('', $belowErf));
            $erf    = (trim($joined, "- \t") !== '') ? $joined : null;
        }

        // Address: keep the inline street text; only when the cell was blank AND
        // the line truly wrapped (nothing rendered) assemble it from the adjacent
        // text fragments. A street-less-but-on-line row ("…, SHELLY BEACH") does
        // NOT borrow — that avoids pulling a wrapped neighbour's estate name.
        if ($inline !== '') {
            $address = $inline;
        } elseif ($rawLeftoverEmpty) {
            $address = trim($aboveAddr . ' ' . implode(' ', $belowAddr));
        } else {
            $address = '';
        }
        $address = trim(preg_replace('/\s{2,}/', ' ', $address) ?? '', ", \t");

        // A real comp carries at least a price, extent, or erf — guards junk.
        if ($sp === null && $ext === null && $erf === null) {
            return null;
        }

        return $this->buildRow([
            'dist'  => $dist,
            'erf'   => $erf,
            'addr'  => $address !== '' ? $address : null,
            'usage' => $usage,
            'ext'   => $ext,
            'date'  => $date,
            'sp'    => $sp,
            'ppm'   => $ppm,
        ]);
    }

    /**
     * True when a wrapped fragment is an Erf-No continuation (a bare number with
     * an optional hyphen + section digits) rather than address text — e.g.
     * "730-" and "11" that together form erf "730-11".
     */
    private function isErfFragment(string $frag): bool
    {
        return (bool) preg_match('/^\s*\d{1,6}-?\d{0,3}\s*$/', trim($frag));
    }

    /**
     * @param array<int|string, string> $m  Capture groups from preg_match_all SET_ORDER.
     * @return array<string, mixed>
     */
    private function buildRow(array $m): array
    {
        $distance = isset($m['dist']) ? $this->normaliseMetres($m['dist'], 'm') : null;
        return [
            'distance_to_subject_m' => $distance,
            'erf_number'            => $m['erf'] ?? null,
            'address'               => isset($m['addr']) ? trim($m['addr']) : null,
            'erf_usage'             => isset($m['usage']) ? trim($m['usage']) : null,
            'property_type'         => $this->normaliseUsageToType($m['usage'] ?? null),
            'extent_m2'             => isset($m['ext']) ? $this->parseExtent($m['ext']) : null,
            'sale_date'             => isset($m['date']) ? $this->parseDate($m['date']) : null,
            'sale_price'            => isset($m['sp']) ? $this->parsePriceBounded($m['sp'], 'vic.sale_price') : null,
            'r_per_m2'              => isset($m['ppm']) ? $this->parsePriceBounded($m['ppm'], 'vic.r_per_m2', null, 100, 500_000) : null,
        ];
    }

    /**
     * Map the freehold "Erf Usage" column ("Residential" / "Vacant land" /
     * etc.) to the property_type that downstream surfaces expect on a
     * market_report_comp_row. The sectional title parser writes
     * 'Residence' literally — we mirror its capitalised single-word
     * shape so the map's per-type filter logic stays uniform.
     */
    private function normaliseUsageToType(?string $usage): string
    {
        $u = mb_strtolower(trim((string) $usage));
        return match (true) {
            $u === 'residential'    => 'Residential',
            $u === 'vacant land'    => 'Vacant Land',
            $u === 'commercial'     => 'Commercial',
            $u === 'agricultural'   => 'Agricultural',
            default                 => 'Residential',  // safe fallback for V1
        };
    }

    /**
     * Parse the extent column, handling thousands-separated space:
     *   "1 442" → 1442
     *   "974"   → 974
     *   "810"   → 810
     */
    private function parseExtent(?string $raw): ?int
    {
        if ($raw === null) return null;
        $digits = preg_replace('/\D/', '', $raw);
        if (!is_string($digits) || $digits === '') return null;
        $n = (int) $digits;
        return $n > 0 ? $n : null;
    }

    /**
     * Convert a numeric distance + unit pair to integer metres.
     * Examples:
     *   normaliseMetres('233', 'm')  → 233
     *   normaliseMetres('1.2', 'km') → 1200
     *   normaliseMetres('101', 'm')  → 101
     */
    private function normaliseMetres(string $value, string $unit): ?int
    {
        if (!is_numeric($value)) return null;
        $n = (float) $value;
        if ($unit === 'km') $n *= 1000.0;
        $int = (int) round($n);
        return $int > 0 ? $int : null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function dedupeBySalePrice(array $rows): array
    {
        $seen = [];
        $out  = [];
        foreach ($rows as $r) {
            $key = ($r['sale_date'] ?? 'no-date') . '|' . ($r['sale_price'] ?? 'no-price');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $r;
        }
        return $out;
    }
}
