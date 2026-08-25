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
 * Median vs Average variant (2026-08-24 fix): the PDF's own title/header
 * ("ST Residential Sales Analysis") is IDENTICAL for both variants — checked
 * directly against 11 real uploaded reports, all 11 carry that exact header,
 * none carry "Median Sales Analysis" literally. The header is therefore NOT
 * a variant signal, despite canParse()'s reasons list historically implying
 * it was. The only reliable in-document signal, confirmed against real PDFs,
 * is the chart's own price-axis label: "Median Selling Price" vs "Average
 * Selling Price" — see detectPriceVariant(). Sending an average-variant
 * report through the OLD code silently stored its average prices under
 * suburb_median_price_year; there was no bug in detection, there was no
 * detection at all. Fixed by: (1) a real variant check before any price
 * write, (2) a distinct metric key per variant so they can never collide,
 * (3) refusing to write ANY price point when the variant can't be
 * determined, rather than defaulting to median — a wrong number stored
 * confidently is worse than a report that doesn't import.
 *
 * The two variants also differ structurally, not just by label: the
 * "Residential Price Ranges" table is a single year/count/low/median/high/max
 * row in the median variant, but three paired (count, average-price) bands
 * plus a maximum in the average variant — a genuinely different column
 * layout, confirmed against real PDFs of both kinds. The existing ranges
 * regex only matches the median shape, so it is gated to the median variant
 * only; the average variant's price-band table is not parsed by this
 * version (noted in rawJson, not silently dropped).
 *
 * Spec: .ai/specs/mic-complete-spec.md §8.3 + Phase 3a build prompt.
 */
final class CmaInfoMedianSalesAnalysisParser extends AbstractCmaInfoParser
{
    public const PARSER_VERSION = 'cma_info_median_sales_analysis_v3';

    /** Metric key for a year's headline price when the report's own chart labels it "Median Selling Price". */
    private const METRIC_MEDIAN = 'suburb_median_price_year';

    /** Metric key for a year's headline price when the report's own chart labels it "Average Selling Price" — distinct from METRIC_MEDIAN so the two can never be confused or overwritten. */
    private const METRIC_AVERAGE = 'suburb_average_price_year';

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

        // Informational only — does not affect acceptance. Both the median
        // and average variants are valid documents for this parser; which
        // one this is only changes which metric key parse() writes under
        // (or whether it refuses to write price data at all).
        $variant = $this->detectPriceVariant($text);
        $reasons[] = 'price variant: ' . ($variant ?? 'UNDETERMINED');

        return ParserConfidence::high($score, $reasons);
    }

    /**
     * The report's own title/header ("ST Residential Sales Analysis") is
     * identical for both variants — confirmed against 11 real uploaded
     * reports, all 11 carry that exact header. The chart's own price-axis
     * label is the only reliable signal, confirmed against real PDFs of
     * both kinds: "Median Selling Price" vs "Average Selling Price".
     * Returns null (undetermined) if neither phrase is found, or both are
     * (an ambiguous/unrecognised layout) — callers must refuse to write a
     * price value in that case rather than guessing.
     */
    private function detectPriceVariant(string $text): ?string
    {
        $hasMedian  = stripos($text, 'Median Selling Price') !== false;
        $hasAverage = stripos($text, 'Average Selling Price') !== false;

        if ($hasMedian && !$hasAverage) return 'median';
        if ($hasAverage && !$hasMedian) return 'average';
        return null;
    }

    public function parse(string $filePath, MarketReport $report): MarketReportParseResult
    {
        $text = $this->extractText($filePath);
        if ($text === '') {
            return new MarketReportParseResult(rawJson: ['note' => 'No text extracted.']);
        }

        // REFUSE, don't guess (2026-08-24 fix). If we can't tell whether this
        // report's headline price is a median or an average, we do not write
        // ANY price data point under either key — a wrong number stored
        // confidently is worse than a report that doesn't import. The rest
        // of this parser (suburb/municipality names, sales counts) is not
        // price-labelled and would still be safe to extract, but a partial
        // parse that silently omits every price figure the agent actually
        // came for is its own kind of confusing failure, so we refuse
        // outright and let the upload be re-checked.
        $variant = $this->detectPriceVariant($text);
        if ($variant === null) {
            return new MarketReportParseResult(rawJson: [
                'note' => 'Could not determine whether this report\'s headline price is a Median or an Average (looked for "Median Selling Price" / "Average Selling Price" in the extracted text — found both, neither, or an unrecognised layout). No price data extracted to avoid mislabelling one as the other.',
                'variant' => null,
            ]);
        }
        $priceMetricKey = $variant === 'median' ? self::METRIC_MEDIAN : self::METRIC_AVERAGE;

        $points = [];
        $today  = now()->toDateString();

        // Phase 3e A2 — derive subject suburb + municipality from the PDF
        // column header instead of relying on $report->source_suburb (the
        // bulk-import path doesn't set it). Two known header layouts:
        //     "Year      UVONGO            RAY NKONYENI"        (year-first, one line)
        //     "SHELLY BEACH            RAY NKONYENI\n  Year"    (suburb-first, Year on the next line)
        // The first all-caps token is the subject suburb; the second is the
        // municipality (when the report has parallel columns).
        //
        // 2026-08-25 — added the suburb-first pattern. Real cause of BOTH of
        // Johan's Shelly Beach uploads carrying no suburb attribution at all
        // (suburb_normalised written as blank on every data point, meaning
        // they could never surface on any suburb's report even once actual
        // price data started parsing): the only pattern this parser knew
        // about assumed "Year" appears BEFORE the suburb/municipality names
        // on the same line. Both real PDFs print it the other way round —
        // "SHELLY BEACH ... RAY NKONYENI" on its own line, then "Year" alone
        // on the NEXT line — which the old single pattern never matched.
        // Filename fallback (below) doesn't rescue it either: both real
        // filenames ("shelly avg ss.pdf", "Shelly median.pdf") are
        // lower/mixed-case, and that fallback only accepts an ALL-CAPS token.
        $firstAreaName  = null;
        $secondAreaName = null;
        if (preg_match('/Year\s+(?<sub>[A-Z][A-Z \']{2,30}?)\s{2,}(?<muni>[A-Z][A-Z \']{3,30})/u', $text, $hm)) {
            $firstAreaName  = trim($hm['sub']);
            $secondAreaName = trim($hm['muni']);
        } elseif (preg_match('/\n[ \t]*(?<sub>[A-Z][A-Z \']{2,30}?)[ \t]{2,}(?<muni>[A-Z][A-Z \']{3,30})[ \t]*\n[ \t]*Year\b/u', $text, $hm)) {
            $firstAreaName  = trim($hm['sub']);
            $secondAreaName = trim($hm['muni']);
        } elseif (preg_match('/Year\s+(?<sub>[A-Z][A-Z \']{2,30})/u', $text, $hm)) {
            $firstAreaName = trim($hm['sub']);
        }

        // Fallback — derive suburb from filename (e.g.
        // "Median.Sales.Analysis.UVONGO.pdf" → "UVONGO"). The bulk-import
        // path stores the original filename on $report->file_name, so this
        // is a reliable secondary source. Case-insensitive (2026-08-25 —
        // both of Johan's real filenames are lower/mixed-case, not the
        // ALL-CAPS the original pattern required).
        if ($firstAreaName === null && !empty($report->file_name)) {
            $stem = pathinfo((string) $report->file_name, PATHINFO_FILENAME);
            // Split on common separators; pick the last alphabetic token of
            // length 4-30 that isn't a generic report-name word — that's
            // almost always the suburb in CMA Info filenames.
            $tokens = preg_split('/[\.\-_\s]+/', $stem) ?: [];
            $stopWords = ['sales', 'analysis', 'median', 'average', 'report', 'cma', 'ss', 'st', 'residential'];
            foreach (array_reverse($tokens) as $tok) {
                if (preg_match('/^[A-Za-z][A-Za-z \']{3,29}$/', $tok) && !in_array(mb_strtolower($tok), $stopWords, true)) {
                    $firstAreaName = mb_strtoupper($tok);
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

        // Split text into per-year blocks: each block begins at a line-start
        // `20YY` and extends to (but not including) the next one. Within each
        // block we look for one or two (count, R<median>, change%) triplets —
        // first is the subject suburb column, second (when present) is the
        // municipality column. Indices are optional. This is far more tolerant
        // than the previous "all on one line" pattern.
        //
        // 2026-08-25 fix — real cause of BOTH of Johan's Shelly Beach uploads
        // (median AND average variant alike) producing zero data points despite
        // being routed to this parser correctly. pdftotext -layout indents real
        // table rows with leading spaces (e.g. "   2017          25    R 1 350
        // 000..."), so the OLD `(?:^|\n)(?<year>20\d{2})` — which requires the
        // year immediately at column 0 — never matched a single real data row.
        // It matched the page-FOOTER date stamp instead ("2026/08/25" printed
        // flush-left at the bottom of every page), which happens to also start
        // with "20\d{2}". Every "block" was therefore just the gap between two
        // footers — pure boilerplate, containing no triplet pattern — hence
        // preg_match_all succeeded (4 blocks, one per page) while silently
        // extracting nothing. Fixed two ways: (1) allow leading horizontal
        // whitespace before the year, so real indented rows match; (2) require
        // the year to be followed by whitespace-then-digit (the row's count
        // column) via a lookahead, so a footer date ("2026/08/25" — year
        // followed by "/") or a bare chart axis label ("2017" followed only by
        // a newline) still cannot match. Verified against both real files: the
        // year-boundary count changes from 4 (one per page footer) to 10 (one
        // per real data row), and the "Please note" trailer, present in both
        // real PDFs, still correctly closes the final block.
        //
        // AT-22 R3 — track which years the Sales-Analysis triplet already
        // produced a median for, so the Residential Price Ranges fallback
        // below only fills the GAPS (no double-write).
        $medianYears = [];
        if (preg_match_all('/(?:^|\n)[ \t]*(?<year>20\d{2})(?=[ \t]+\d)(?<body>.*?)(?=(?:\n[ \t]*20\d{2}(?=[ \t]+\d))|\nPlease|\Z)/su', $text, $blocks, PREG_SET_ORDER)) {
            foreach ($blocks as $block) {
                $year = (int) $block['year'];
                if ($year < 2000 || $year > 2099) continue;
                $body = (string) $block['body'];

                // Bounded "thousands group" pattern so the median price
                // can't bleed into the change% column. The change% decimal
                // is OPTIONAL (2026-08-25 fix): Shelly Beach's real median
                // report prints "0%" for 2021's subject-suburb column (an
                // exact-zero change, no decimal shown) — the old pattern
                // required a decimal point, so that single triplet failed
                // to match, silently dropping the whole subject-column row
                // for that year (price, count, AND change all lost, not
                // just the change figure) while the municipality's own
                // 2021 row (which did carry a decimal) still matched.
                if (!preg_match_all('/(?<c>\d{1,5})\s+R\s*(?<m>\d{1,3}(?:[\s,]\d{3}){0,3})\s+(?<chg>-?\d{1,3}(?:\.\d{1,2})?)\s*%/u', $body, $triplets, PREG_SET_ORDER)) {
                    continue;
                }

                // MarketDataPoint validation requires exactly ONE of
                // metric_value_(numeric|date|string). Encode the year via
                // metric_date (Y-12-31) and leave metric_value_date null.
                $metricDate = $year . '-12-31';

                // First triplet = subject column
                $t1 = $triplets[0];
                $medianYears[$year] = true; // covered — ranges fallback skips it
                $points[] = ['metric_key' => $priceMetricKey, 'metric_value_numeric' => $this->parsePrice($t1['m']), 'metric_date' => $metricDate, 'confidence' => 'high', 'suburb_normalised' => $suburbNorm, 'town' => $town];
                $points[] = ['metric_key' => 'suburb_sales_count_year', 'metric_value_numeric' => (float) $t1['c'], 'metric_date' => $metricDate, 'confidence' => 'high', 'suburb_normalised' => $suburbNorm, 'town' => $town];
                $points[] = ['metric_key' => 'suburb_annual_change_pct', 'metric_value_numeric' => (float) $t1['chg'], 'metric_date' => $metricDate, 'confidence' => 'medium', 'suburb_normalised' => $suburbNorm, 'town' => $town];

                // Second triplet (when present) = municipality column
                if (isset($triplets[1])) {
                    $t2 = $triplets[1];
                    $secondNorm = $secondAreaName !== null ? $this->normaliseSuburb($secondAreaName) : null;
                    $points[] = ['metric_key' => $priceMetricKey, 'metric_value_numeric' => $this->parsePrice($t2['m']), 'metric_date' => $metricDate, 'confidence' => 'high', 'suburb_normalised' => $secondNorm, 'town' => $secondAreaName];
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
        //
        // 2026-08-24 — this exact 6-field pattern is the MEDIAN variant's
        // table shape only. Confirmed against a real average-variant PDF:
        // its "Residential Price Ranges" table is structurally different —
        // three paired (count, average-price) bands (Low/Middle/High) plus a
        // separate Maximum, not one row of year/count/low/median/high/max.
        // The regex below does not match that shape (verified — it simply
        // produces no matches), so gating on variant is a correctness fix,
        // not just a label fix: attempting this extraction against an
        // average-variant report would silently find nothing rather than
        // silently mislabel something, but gating makes that explicit and
        // documents the average variant's price-band table as not yet
        // implemented, rather than "tried and happened to find zero."
        $priceTok    = 'R\s*(\d{1,3}(?:[\s,]\d{3}){0,3})';
        $rangePattern = '/\b(?<year>20\d{2})\s+(?<count>\d{1,4})\s+' . $priceTok
                      . '\s+' . $priceTok . '\s+' . $priceTok . '\s+' . $priceTok . '/u';
        if ($variant === 'median' && preg_match_all($rangePattern, $text, $rangeMatches, PREG_SET_ORDER)) {
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
                        $rangeFallback[$priceMetricKey] = $median;
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
            rawJson: array_filter([
                'parser_version'   => self::PARSER_VERSION,
                'pages'            => $this->pageCount($text),
                'second_area_name' => $secondAreaName,
                'first_area_name'  => $firstAreaName,
                'variant'          => $variant,
                'price_metric_key' => $priceMetricKey,
                'note'             => $variant === 'average'
                    ? 'Average variant: price-band ("Residential Price Ranges") table not parsed — its column layout differs from the median variant and is not yet implemented. Sales count / headline price / annual change / index were still extracted.'
                    : null,
            ], fn ($v) => $v !== null),
            subjectMeta: $subjectMeta,
        );
    }
}
