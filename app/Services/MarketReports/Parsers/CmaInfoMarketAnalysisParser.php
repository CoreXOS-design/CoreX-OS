<?php

declare(strict_types=1);

namespace App\Services\MarketReports\Parsers;

use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Services\MarketReports\DTOs\MarketReportParseResult;
use App\Services\MarketReports\DTOs\ParserConfidence;
use App\Support\MarketReports\GpsParser;

/**
 * V2 parser for the CMA Info "Market Analysis" report.
 *
 * Phase 3a additions:
 *   - subject GPS / scheme / section / extent extraction
 *   - comparable rows persisted to market_report_comp_rows
 *
 * Spec: .ai/specs/mic-complete-spec.md §8.3 + Phase 3a build prompt.
 */
final class CmaInfoMarketAnalysisParser extends AbstractCmaInfoParser
{
    public const PARSER_VERSION = 'cma_info_market_analysis_v2';

    public function getReportTypeKey(): string
    {
        return 'cma_info_market_analysis';
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function canParse(string $filePath): ParserConfidence
    {
        $text = $this->extractText($filePath);
        if ($text === '') return ParserConfidence::none('extractor returned empty text');
        if (!$this->looksLikeCmaInfo($text)) return ParserConfidence::none('no CMA Info signature');

        $score = 0.0;
        $reasons = ['cma info signature'];

        $pages = $this->pageCount($text);
        if ($pages >= 8 && $pages <= 14) { $score += 0.4; $reasons[] = "page count {$pages}"; }

        if ($this->findHeader($text, 'Market Analysis')) {
            $score += 0.3;
            $reasons[] = 'Market Analysis header';
        }
        if ($this->findHeader($text, 'Subject Property')) {
            $score += 0.1;
            $reasons[] = 'Subject Property block';
        }
        if ($this->findHeader($text, 'Vicinity Sales') || $this->findHeader($text, 'Comparable Sales')) {
            $score += 0.1;
            $reasons[] = 'comparable sales table';
        }
        if ($this->findHeader($text, '12 Month')) {
            $score += 0.1;
            $reasons[] = '12-month suburb stats panel';
        }

        return ParserConfidence::high($score, $reasons);
    }

    public function parse(string $filePath, MarketReport $report): MarketReportParseResult
    {
        $text = $this->extractText($filePath);
        if ($text === '') {
            return new MarketReportParseResult(
                dataPoints: [],
                extractedAddresses: [],
                rawJson: ['note' => 'No text extracted — pdftotext unavailable or PDF is image-only.'],
            );
        }

        $points    = [];
        $addresses = [];
        $compRows  = [];
        $today     = now()->toDateString();

        // ── Subject extraction ─────────────────────────────────────────────
        $subjectMeta = [];
        $subjectAddrSuburb = null;

        if (preg_match('/Subject Property[^\n]*\n(?<line>[^\n]{6,180})/i', $text, $m)) {
            $sub = $this->parseAddressLine($m['line']);
            if ($sub) {
                $addresses[] = $sub;
                $subjectMeta['subject_address'] = trim($m['line']);
                $subjectAddrSuburb = $sub['suburb'] ?? null;
            }
        }
        // Resolve report-level suburb — explicit upload first, else
        // subject_address trailing token captured above. Promote to
        // subject_meta so ParseMarketReportJob backfills the report row.
        $resolved  = $this->resolveReportSuburb($report->source_suburb, [$subjectAddrSuburb]);
        $suburb    = $resolved['normalised'];
        $suburbRaw = $resolved['raw'];
        if ($suburbRaw !== null) {
            $subjectMeta['source_suburb'] = $suburbRaw;
        }

        // GPS pair anywhere on page 1.
        if (preg_match('/(-?\d{1,3}\.\d{2,8}\s*\S?\s*[EW]\s+-?\d{1,3}\.\d{2,8}\s*\S?\s*[NS]|-?\d{1,3}\.\d{2,8}\s*\S?\s*[NS]\s+-?\d{1,3}\.\d{2,8}\s*\S?\s*[EW])/u', $text, $gm)) {
            $gps = GpsParser::fromString($gm[1]);
            if ($gps !== null) {
                $subjectMeta['subject_latitude']  = $gps['lat'];
                $subjectMeta['subject_longitude'] = $gps['lng'];
            }
        }
        if (preg_match('/Scheme\s+name\s+([A-Z][^\n]{1,80}?)\s+Suburb/iu', $text, $m)) {
            $subjectMeta['subject_scheme_name'] = trim($m[1]);
        }
        if (preg_match('/Section\s+number\s+(\d{1,4})/iu', $text, $m)) {
            $subjectMeta['subject_section_number'] = $m[1];
        }
        if (preg_match('/(?:Flat\s+number|Section\s+extent)\s+(\d{1,5})\s*m/iu', $text, $m)) {
            $subjectMeta['subject_extent_m2'] = (int) $m[1];
        }

        // ── Legacy v1 metric writes (kept) ─────────────────────────────────
        if (preg_match('/Median (?:Price|Sale Price)[^\n]*?(R\s*[\d ,]+)/i', $text, $m)) {
            $price = $this->parsePrice($m[1]);
            if ($price !== null) {
                $points[] = [
                    'metric_key'           => 'suburb_median_price_12m',
                    'metric_value_numeric' => $price,
                    'metric_date'          => $today,
                    'confidence'           => 'high',
                    'suburb_normalised'    => $suburb,
                    'town'                 => $report->source_town,
                ];
            }
        }
        if (preg_match('/(?:Total Sales|No\.?\s*of Sales)[^\n]*?(\d{1,5})/i', $text, $m)) {
            $points[] = [
                'metric_key'           => 'suburb_total_sales_12m',
                'metric_value_numeric' => (float) $m[1],
                'metric_date'          => $today,
                'confidence'           => 'high',
                'suburb_normalised'    => $suburb,
                'town'                 => $report->source_town,
            ];
        }
        if (preg_match('/(?:Recommended|Suggested) (?:Range|Price)[^\n]*?(R\s*[\d ,]+)[^\n]*?(R\s*[\d ,]+)/i', $text, $m)) {
            $lower = $this->parsePrice($m[1]);
            $upper = $this->parsePrice($m[2]);
            if ($lower !== null) {
                $points[] = ['metric_key' => 'cma_value_lower', 'metric_value_numeric' => $lower, 'metric_date' => $today, 'confidence' => 'medium', 'suburb_normalised' => $suburb];
            }
            if ($upper !== null) {
                $points[] = ['metric_key' => 'cma_value_upper', 'metric_value_numeric' => $upper, 'metric_date' => $today, 'confidence' => 'medium', 'suburb_normalised' => $suburb];
            }
        }

        // ── Comp rows (legacy regex + comp_rows write) ─────────────────────
        $rowIndex = 0;
        if (preg_match_all('/(\d{1,4})\s+([A-Z][A-Za-z\' ]{2,40})[,\s]+([A-Z][A-Za-z\' ]{2,30}).{0,40}R\s*([\d ,]+)\s+(\d{4}[-\/]\d{2}[-\/]\d{2})/m', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $row) {
                $price = $this->parsePrice($row[4]);
                $date  = $this->parseDate($row[5]);
                $addr  = trim($row[1]) . ' ' . trim($row[2]);

                $compRows[] = [
                    'row_index'         => $rowIndex++,
                    'row_type'          => MarketReportCompRow::ROW_COMP,
                    'address'           => $addr,
                    'suburb_normalised' => $this->normaliseSuburb($row[3]),
                    'property_type'     => null,
                    'extent_m2'         => null,
                    'sale_date'         => $date,
                    'sale_price'        => $price !== null ? (int) $price : null,
                    'raw_row_json'      => ['raw' => $row[0]],
                ];

                $addresses[] = $this->makeAddress([
                    'street_number' => $row[1],
                    'street_name'   => trim($row[2]),
                    'suburb'        => trim($row[3]),
                    'sale_price'    => $price,
                    'sale_date'     => $date,
                ]);
                $points[] = [
                    'metric_key'           => 'comparable_sale_price',
                    'metric_value_numeric' => $price,
                    'metric_date'          => $date ?? $today,
                    'confidence'           => 'medium',
                    'suburb_normalised'    => $this->normaliseSuburb($row[3]),
                ];
            }
        }

        return new MarketReportParseResult(
            dataPoints:        $points,
            extractedAddresses: $addresses,
            rawJson:           [
                'parser_version' => self::PARSER_VERSION,
                'pages'          => $this->pageCount($text),
                'comp_rows'      => count($compRows),
            ],
            subjectMeta:       $subjectMeta,
            compRows:          $compRows,
        );
    }

    private function parseAddressLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') return null;
        if (preg_match('/^(\d{1,4})\s+([A-Za-z\' ]+),\s*([A-Za-z\' ]+)/', $line, $m)) {
            return $this->makeAddress([
                'street_number' => $m[1],
                'street_name'   => trim($m[2]),
                'suburb'        => trim($m[3]),
            ]);
        }
        if (preg_match('/^([A-Za-z\' ]+),\s*([A-Za-z\' ]+)/', $line, $m)) {
            return $this->makeAddress([
                'street_name' => trim($m[1]),
                'suburb'      => trim($m[2]),
            ]);
        }
        return null;
    }
}
