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
        $suburb    = $this->normaliseSuburb($report->source_suburb);

        // ── Subject + radius + property-type detection ────────────────────
        $subjectMeta = [];

        $isResidential = (bool) preg_match('/Residential\s+sales\s+within/i', $text);
        $isVacantLand  = (bool) preg_match('/Vacant\s+land\s+sales\s+within/i', $text);
        $subjectMeta['subject_property_type'] = $isVacantLand
            ? 'vacant_land'
            : ($isResidential ? 'residential' : 'unknown');

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

        // Subject address — "27 HIBISCUS AVENUE, MARGATE BEACH" pattern.
        // Allow optional 'A'/'B' unit suffix on the street number.
        if (preg_match('/^(\d{1,4}[A-Z]?\s+[A-Z][A-Z \']{2,40}),\s+([A-Z][A-Z \']{2,40})$/m', $text, $m)) {
            $subjectMeta['subject_address'] = trim($m[1]) . ', ' . trim($m[2]);
        }

        // Suburb scope — "Limited to. MARGATE BEACH"
        if (preg_match('/Limited\s+to\.?\s+([A-Z][A-Z \']{2,40})/i', $text, $m)) {
            $subjectMeta['suburb_scope'] = trim($m[1]);
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
                    'suburb'      => $report->source_suburb,
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
     * Extract rows from the vicinity-sale table. Real pdftotext -layout
     * row shape (decoded 2026-05-28 from the residential + vacant land
     * fixtures):
     *
     *   <row_idx> <dist> m <erf> <street> <SUBURB> <usage> [<extent> m²] <date> [R<price>] [R<r/m²>]
     *
     * Example rows (each is one physical line):
     *   "1 116 m 1223 8 GARDEN TERRACE, UVONGO    Residential   1 442 m²   2026/02/11   R 1 100 000   R 763"
     *   "3 237 m 2385 14 OSTEND DRIVE, UVONGO     Residential                          2025/10/13   R 1 800 000"
     *   "5 191 m 37 63 EFFINGHAM PARADE, TRAFALGAR Vacant land  2 322 m²   2021/09/13   R 600 000     R 418"
     *
     * Extent, sale_price, r_per_m2 are all optional per-row. Erf may
     * carry a hyphenated section suffix ("46-1", "1241-1").
     *
     * Note on "m²" rendering: pdftotext often emits the ² as the UTF-8
     * replacement character (0xEF 0xBF 0xBD). Our regex tolerates "m"
     * followed by any single non-whitespace token (`m\S?`).
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractCompRows(string $text): array
    {
        $rows = [];

        // The full row pattern. Extent block is optional (some rows ship
        // without an extent value). Sale price + r_per_m2 are also
        // optional independently.
        $pattern = '/^\s*'
                 . '(?<idx>\d{1,3})\s+'                                            // row index (ignored)
                 . '(?<dist>\d{1,5})\s*m\s+'                                       // distance: "116 m"
                 . '(?<erf>\d{1,6}(?:-\d{1,3})?)\s+'                               // erf: "1223" or "46-1"
                 . '(?<addr>\d{1,4}\s+[^,\n]{2,60}?,\s+[A-Z][A-Z \'\-]{2,40})\s+'  // "8 GARDEN TERRACE, UVONGO"
                 . '(?<usage>Residential|Vacant\s+land|Commercial|Other|Agricultural)' // erf usage column
                 . '(?:\s+(?<ext>\d{1,3}(?:\s+\d{3})?)\s*m\S?)?'                   // optional "1 442 m²" or "974 m²"
                 . '\s+(?<date>\d{4}\/\d{2}\/\d{2})'                               // sale date
                 . '(?:\s+R\s+(?<sp>\d{1,3}(?:\s+\d{3}){1,3}))?'                   // optional "R 1 100 000"
                 . '(?:\s+R\s+(?<ppm>\d{1,3}(?:\s+\d{3}){0,2}))?'                  // optional "R 763"
                 . '/um';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $rows[] = $this->buildRow($m);
            }
        }

        // Dedupe (defensive — should be rare with the line-anchored regex).
        return array_values($this->dedupeBySalePrice($rows));
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
