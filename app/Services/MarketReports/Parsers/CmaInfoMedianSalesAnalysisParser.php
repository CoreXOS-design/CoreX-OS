<?php

declare(strict_types=1);

namespace App\Services\MarketReports\Parsers;

use App\Models\MarketReports\MarketReport;
use App\Services\MarketReports\DTOs\MarketReportParseResult;
use App\Services\MarketReports\DTOs\ParserConfidence;

/**
 * V2 parser for CMA Info "Median Sales Analysis" — 4-page suburb-history PDF.
 * Layout per row: "<year> <count> R<median> <change%> <index>" with optional
 * second area (e.g. the parent municipality RAY NKONYENI alongside UVONGO).
 *
 * Phase 3a additions:
 *   - extracts BOTH areas when the table has parallel suburb/municipality cols
 *   - no comp rows (the report is purely aggregate metrics — no per-row data)
 *   - parser version bumped to v2 for audit
 *
 * Spec: .ai/specs/mic-complete-spec.md §8.3 + Phase 3a build prompt.
 */
final class CmaInfoMedianSalesAnalysisParser extends AbstractCmaInfoParser
{
    public const PARSER_VERSION = 'cma_info_median_sales_analysis_v2';

    public function getReportTypeKey(): string
    {
        return 'cma_info_median_sales_analysis';
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
        if ($pages >= 2 && $pages <= 6) { $score += 0.3; $reasons[] = "page count {$pages}"; }

        if ($this->findHeader($text, 'Median Sales Analysis') || $this->findHeader($text, 'ST Residential Sales Analysis')) {
            $score += 0.5;
            $reasons[] = 'Median Sales Analysis header';
        }
        if ($this->findHeader($text, 'Annual Change') || $this->findHeader($text, 'YoY')) {
            $score += 0.1;
            $reasons[] = 'annual change column';
        }
        if (preg_match('/\b20\d{2}\s+\d+\s+R[\s\d,]+/', $text)) {
            $score += 0.1;
            $reasons[] = 'year×sales×median row';
        }

        return ParserConfidence::high($score, $reasons);
    }

    public function parse(string $filePath, MarketReport $report): MarketReportParseResult
    {
        $text = $this->extractText($filePath);
        if ($text === '') {
            return new MarketReportParseResult(rawJson: ['note' => 'No text extracted.']);
        }

        $points = [];
        $today  = now()->toDateString();

        // Phase 3e A2 — derive subject suburb + municipality from the PDF
        // column header instead of relying on $report->source_suburb (the
        // bulk-import path doesn't set it). Header layout:
        //     "Year      UVONGO            RAY NKONYENI"
        // The first all-caps token is the subject suburb; the second is the
        // municipality (when the report has parallel columns).
        $firstAreaName  = null;
        $secondAreaName = null;
        if (preg_match('/Year\s+(?<sub>[A-Z][A-Z \']{2,30}?)\s{2,}(?<muni>[A-Z][A-Z \']{3,30})/u', $text, $hm)) {
            $firstAreaName  = trim($hm['sub']);
            $secondAreaName = trim($hm['muni']);
        } elseif (preg_match('/Year\s+(?<sub>[A-Z][A-Z \']{2,30})/u', $text, $hm)) {
            $firstAreaName = trim($hm['sub']);
        }

        // Fallback — derive suburb from filename (e.g.
        // "Median.Sales.Analysis.UVONGO.pdf" → "UVONGO"). The bulk-import
        // path stores the original filename on $report->file_name, so this
        // is a reliable secondary source.
        if ($firstAreaName === null && !empty($report->file_name)) {
            $stem = pathinfo((string) $report->file_name, PATHINFO_FILENAME);
            // Split on common separators; pick the last all-caps token of
            // length 4-30 — that's almost always the suburb in CMA Info filenames.
            $tokens = preg_split('/[\.\-_\s]+/', $stem) ?: [];
            foreach (array_reverse($tokens) as $tok) {
                if (preg_match('/^[A-Z][A-Z \']{3,29}$/', $tok)) {
                    $firstAreaName = $tok;
                    break;
                }
            }
        }

        // Subject suburb resolution: explicit source_suburb wins; otherwise
        // use the derived name. We keep $town as a fallback municipality
        // label when source_town is set on the report.
        $subjectSuburb = $report->source_suburb !== null && $report->source_suburb !== ''
            ? $report->source_suburb
            : $firstAreaName;
        $suburbNorm = $this->normaliseSuburb($subjectSuburb);
        $town       = $report->source_town ?? $secondAreaName;

        // Split text into per-year blocks: each block begins at `^20YY` and
        // extends to (but not including) the next `^20YY`. Within each block
        // we look for one or two (count, R<median>, change%) triplets — first
        // is the subject suburb column, second (when present) is the
        // municipality column. Indices are optional. This is far more tolerant
        // than the previous "all on one line" pattern.
        // AT-22 R3 — track which years the Sales-Analysis triplet already
        // produced a median for, so the Residential Price Ranges fallback
        // below only fills the GAPS (no double-write).
        $medianYears = [];
        if (preg_match_all('/(?:^|\n)(?<year>20\d{2})(?<body>.*?)(?=(?:\n20\d{2})|\nPlease|\Z)/su', $text, $blocks, PREG_SET_ORDER)) {
            foreach ($blocks as $block) {
                $year = (int) $block['year'];
                if ($year < 2000 || $year > 2099) continue;
                $body = (string) $block['body'];

                // Bounded "thousands group" pattern so the median price
                // can't bleed into the change% column.
                if (!preg_match_all('/(?<c>\d{1,5})\s+R\s*(?<m>\d{1,3}(?:[\s,]\d{3}){0,3})\s+(?<chg>-?\d{1,3}\.\d{1,2})\s*%/u', $body, $triplets, PREG_SET_ORDER)) {
                    continue;
                }

                // MarketDataPoint validation requires exactly ONE of
                // metric_value_(numeric|date|string). Encode the year via
                // metric_date (Y-12-31) and leave metric_value_date null.
                $metricDate = $year . '-12-31';

                // First triplet = subject column
                $t1 = $triplets[0];
                $medianYears[$year] = true; // covered — ranges fallback skips it
                $points[] = ['metric_key' => 'suburb_median_price_year', 'metric_value_numeric' => $this->parsePrice($t1['m']), 'metric_date' => $metricDate, 'confidence' => 'high', 'suburb_normalised' => $suburbNorm, 'town' => $town];
                $points[] = ['metric_key' => 'suburb_sales_count_year', 'metric_value_numeric' => (float) $t1['c'], 'metric_date' => $metricDate, 'confidence' => 'high', 'suburb_normalised' => $suburbNorm, 'town' => $town];
                $points[] = ['metric_key' => 'suburb_annual_change_pct', 'metric_value_numeric' => (float) $t1['chg'], 'metric_date' => $metricDate, 'confidence' => 'medium', 'suburb_normalised' => $suburbNorm, 'town' => $town];

                // Second triplet (when present) = municipality column
                if (isset($triplets[1])) {
                    $t2 = $triplets[1];
                    $secondNorm = $secondAreaName !== null ? $this->normaliseSuburb($secondAreaName) : null;
                    $points[] = ['metric_key' => 'suburb_median_price_year', 'metric_value_numeric' => $this->parsePrice($t2['m']), 'metric_date' => $metricDate, 'confidence' => 'high', 'suburb_normalised' => $secondNorm, 'town' => $secondAreaName];
                    $points[] = ['metric_key' => 'suburb_sales_count_year', 'metric_value_numeric' => (float) $t2['c'], 'metric_date' => $metricDate, 'confidence' => 'high', 'suburb_normalised' => $secondNorm, 'town' => $secondAreaName];
                    $points[] = ['metric_key' => 'suburb_annual_change_pct', 'metric_value_numeric' => (float) $t2['chg'], 'metric_date' => $metricDate, 'confidence' => 'medium', 'suburb_normalised' => $secondNorm, 'town' => $secondAreaName];
                }
            }
        }

        // Phase 3e A3 — parse the "Residential Price Ranges" table
        // (per-year Low / Median / High / Maximum). Pattern:
        //   "<year> <count> R <low> R <median> R <high> R <max>"
        // The columns share a year with the Sales Analysis triplet block above
        // — we don't override the median there; we just add low/high/max.
        $priceTok    = 'R\s*(\d{1,3}(?:[\s,]\d{3}){0,3})';
        $rangePattern = '/\b(?<year>20\d{2})\s+(?<count>\d{1,4})\s+' . $priceTok
                      . '\s+' . $priceTok . '\s+' . $priceTok . '\s+' . $priceTok . '/u';
        if (preg_match_all($rangePattern, $text, $rangeMatches, PREG_SET_ORDER)) {
            foreach ($rangeMatches as $rm) {
                $year = (int) $rm['year'];
                if ($year < 2000 || $year > 2099) continue;
                $metricDate = $year . '-12-31';
                $low    = $this->parsePriceBounded($rm[3], 'msa.suburb_low_year');
                $median = $this->parsePriceBounded($rm[4], 'msa.suburb_median_range');
                $high   = $this->parsePriceBounded($rm[5], 'msa.suburb_high_year');
                $max    = $this->parsePriceBounded($rm[6], 'msa.suburb_max_year');

                // AT-22 R3 — the Residential Price Ranges row is
                // "<year> <count> R<low> R<median> R<high> R<max>", so it ALSO
                // carries the sales count and the median. When the Sales-
                // Analysis triplet above did NOT resolve a median for this year
                // (e.g. the source omits the change-% column the triplet keys
                // on), fall back to the ranges row for the median + count.
                // Without this, suburb_median_price_year / suburb_sales_count_
                // year were never produced and the presentation Market Overview
                // could never populate (PRES 87 / Uvongo).
                $rangeFallback = [
                    'suburb_low_year'  => $low,
                    'suburb_high_year' => $high,
                    'suburb_max_year'  => $max,
                ];
                if (!isset($medianYears[$year])) {
                    if ($median !== null) {
                        $rangeFallback['suburb_median_price_year'] = $median;
                    }
                    $rangeCount = isset($rm['count']) ? (int) $rm['count'] : 0;
                    if ($rangeCount > 0) {
                        $rangeFallback['suburb_sales_count_year'] = $rangeCount;
                    }
                }

                foreach ($rangeFallback as $key => $value) {
                    if ($value === null) continue;
                    $points[] = [
                        'metric_key'           => $key,
                        'metric_value_numeric' => (float) $value,
                        'metric_date'          => $metricDate,
                        'confidence'           => 'high',
                        'suburb_normalised'    => $suburbNorm,
                        'town'                 => $town,
                    ];
                }
            }
        }

        // Phase 3e A2 — surface derived suburb/town so the orchestrator can
        // back-fill the MarketReport row. Hydrator + UI lookups rely on
        // source_suburb being populated.
        $subjectMeta = array_filter([
            'source_suburb' => $firstAreaName ?? $subjectSuburb,
            'source_town'   => $secondAreaName,
        ], fn ($v) => $v !== null && $v !== '');

        return new MarketReportParseResult(
            dataPoints: $points,
            rawJson: [
                'parser_version'   => self::PARSER_VERSION,
                'pages'            => $this->pageCount($text),
                'second_area_name' => $secondAreaName,
                'first_area_name'  => $firstAreaName,
            ],
            subjectMeta: $subjectMeta,
        );
    }
}
