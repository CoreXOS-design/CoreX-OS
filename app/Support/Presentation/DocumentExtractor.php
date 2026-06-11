<?php

namespace App\Support\Presentation;

use App\Models\PresentationUpload;
use Illuminate\Support\Facades\Storage;

/**
 * Deterministic document field extractor (doc_extract_v1).
 *
 * Extracts structured key/value fields from PDF text using regex patterns
 * anchored on known labels. Supports CMA, suburb sales, and vicinity sales.
 *
 * Never throws. Never calls AI. Deterministic only.
 */
class DocumentExtractor
{
    public const EXTRACTED_VERSION = 'doc_extract_v1';

    /**
     * Detect if text is from a Sectional Title vicinity report.
     */
    public function isSectionalTitle(string $text): bool
    {
        return (bool) preg_match('/Sectional\s+Title\s+sales\s+within/i', $text);
    }

    /**
     * Run extraction on an upload. Returns field_key => field_value pairs.
     * Uses already-extracted text if available; falls back to PHP-based extraction.
     */
    public function extract(PresentationUpload $upload): array
    {
        $text = $upload->text_extracted ?? '';

        // If text_extracted is empty (e.g. pdftotext not available), try PHP-based extraction
        if ($text === '' && $upload->storage_path) {
            $absolutePath = Storage::disk('local')->path($upload->storage_path);
            if (file_exists($absolutePath)) {
                $text = $this->extractTextFromPdf($absolutePath);
                if ($text !== '') {
                    $upload->update([
                        'text_extracted'    => $text,
                        'extraction_status' => 'ok',
                    ]);
                }
            }
        }

        if ($text === '') {
            return [];
        }

        return match ($upload->type) {
            'cma'            => $this->parseCma($text),
            'suburb_stats'   => $this->parseSuburbSales($text),
            'vicinity_sales' => $this->parseVicinitySales($text),
            default          => [],
        };
    }

    /**
     * Extract raw text from a PDF file.
     * Tries pdftotext CLI first (faster, better quality), falls back to smalot/pdfparser.
     * Never throws.
     */
    public function extractTextFromPdf(string $absolutePath): string
    {
        if (!file_exists($absolutePath)) {
            return '';
        }

        // Try pdftotext CLI first
        $cliText = $this->tryPdftotext($absolutePath);
        if ($cliText !== '') {
            return $cliText;
        }

        // Fallback: smalot/pdfparser (pure PHP)
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            return '';
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($absolutePath);
            $text   = $pdf->getText();
            return is_string($text) ? trim($text) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function tryPdftotext(string $absolutePath): string
    {
        $check = PHP_OS_FAMILY === 'Windows'
            ? @shell_exec('where pdftotext 2>NUL')
            : @shell_exec('command -v pdftotext 2>/dev/null');

        if (empty($check)) {
            return '';
        }

        try {
            $escaped = escapeshellarg($absolutePath);
            $output  = shell_exec("pdftotext {$escaped} -");
            return is_string($output) ? trim($output) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ── CMA Parser ──────────────────────────────────────────────────────────

    public function parseCma(string $text): array
    {
        $fields = [];

        // Price ranges: "Lower Range: R 2,250,000"
        $this->matchPrice($text, 'Lower\s*Range', $fields, 'cma.lower_range');
        $this->matchPrice($text, 'Middle\s*Range', $fields, 'cma.middle_range');
        $this->matchPrice($text, 'Upper\s*Range', $fields, 'cma.upper_range');

        // Municipal valuation: "Total Value: R 1,800,000" or "Municipal Valuation: R..."
        $this->matchPrice($text, '(?:Total|Municipal)\s*(?:Municipal\s*)?(?:Value|Valuation)', $fields, 'municipal.total_value');

        // Valuation year
        if (preg_match('/(?:Valuation|Municipal)\s*(?:Year|Date)\s*[:\-]?\s*(\d{4})/i', $text, $m)) {
            $year = (int) $m[1];
            if ($year >= 1990 && $year <= 2030) {
                $fields['municipal.valuation_year'] = (string) $year;
            }
        }

        // Subject property: Address — CMA Info uses newline (not colon) as separator
        if (preg_match('/(?:Property\s*)?Address\s*[:\-\t]?\s*(.+?)(?:\r?\n|$)/i', $text, $m)) {
            $addr = trim($m[1]);
            if (strlen($addr) >= 5 && strlen($addr) <= 200) {
                $fields['subject.address'] = $addr;
            }
        }

        // Subject property: Suburb — CMA Info uses newline (not colon) as separator
        // Use [^\r\n]+ to stop at first newline and avoid grabbing multi-line junk.
        if (preg_match('/\bSuburb\s*[:\-\t]?\s*([A-Z][A-Za-z ]{1,98})[^\S\r\n]*(?:\r?\n|$)/m', $text, $m)) {
            $suburb = trim($m[1]);
            if (strlen($suburb) >= 2 && strlen($suburb) <= 100) {
                $fields['subject.suburb'] = $suburb;
            }
        }

        // GPS coordinates — "30.384764°E 30.838421°S"
        if (preg_match('/\bGPS\s*[:\-\t]?\s*([\d.]+\s*°\s*[ENSW]\s+[\d.]+\s*°\s*[ENSW])/i', $text, $m)) {
            $fields['subject.gps'] = trim($m[1]);
        }

        // Subject property: Erf / Stand
        if (preg_match('/(?:Erf|Stand)\s*(?:No\.?|Number)?\s*[:\-]?\s*(\S+)/i', $text, $m)) {
            $erf = trim($m[1]);
            if (strlen($erf) >= 1 && strlen($erf) <= 50) {
                $fields['subject.erf'] = $erf;
            }
        }

        // Extent in m²
        if (preg_match('/(?:Extent|Size|Floor\s*Area|Property\s*Size)\s*[:\-]?\s*(\d[\d\s,]*\d|\d+)\s*(?:m2|m²|sqm)/i', $text, $m)) {
            $size = (int) preg_replace('/[\s,]/', '', $m[1]);
            if ($size >= 10 && $size <= 99999) {
                $fields['subject.extent_m2'] = (string) $size;
            }
        }

        // Purchase date — try same-line first, then cross-line (pdftotext puts address between label and date)
        if (preg_match('/(?:Purchase|Transfer|Acquisition)\s*Date\s*[:\-]?\s*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4}|\d{4}[\/\-\.]\d{2}[\/\-\.]\d{2})/i', $text, $m)) {
            $fields['subject.purchase_date'] = trim($m[1]);
        } elseif (preg_match('/(?:Purchase|Transfer|Acquisition)\s*Date[\s\S]{0,200}?(\d{4}[\/\-]\d{2}[\/\-]\d{2})/i', $text, $m)) {
            $fields['subject.purchase_date'] = trim($m[1]);
        }

        // Purchase price
        $this->matchPrice($text, '(?:Purchase|Transfer|Acquisition)\s*Price', $fields, 'subject.purchase_price');

        // Indexed value
        $this->matchPrice($text, '(?:Indexed|CPI\s*Indexed|Inflation\s*Adjusted)\s*Value', $fields, 'subject.indexed_value');

        // CAGR
        if (preg_match('/(?:CAGR|Compound\s*Annual\s*Growth\s*Rate)\s*[:\-]?\s*([\d.]+)\s*%?/i', $text, $m)) {
            $cagr = (float) $m[1];
            if ($cagr > 0 && $cagr < 100) {
                $fields['subject.cagr'] = (string) $cagr;
            }
        }

        return $fields;
    }

    // ── Suburb Sales Parser ─────────────────────────────────────────────────

    public function parseSuburbSales(string $text): array
    {
        $fields = [];

        // Step 1: Parse "Residential Sales Analysis" table (Page 1 — authoritative source).
        // This table filters out extreme/abnormal sales, so its count is the correct one.
        // Pattern: Year  NoOfSales  R MedianPrice  Percentage  Index
        $salesPattern = '/\b(20\d{2})\s+(\d{1,4})\s+R\s*(\d{1,3}(?:[\s,]\d{3})+)\s+[\-\d]/i';
        $salesRows = [];
        if (preg_match_all($salesPattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $year  = (int) $m[1];
                $count = (int) $m[2];
                $med   = (int) preg_replace('/[\s,]/', '', $m[3]);
                if ($year >= 2000 && $year <= 2030 && $count > 0 && $med >= 10000) {
                    $salesRows[] = ['year' => $year, 'count' => $count, 'med' => $med];
                }
            }
        }

        $bestSales = null;
        if (!empty($salesRows)) {
            usort($salesRows, fn ($a, $b) => $b['year'] <=> $a['year']);
            foreach ($salesRows as $row) {
                if ($row['count'] >= 10) {
                    $bestSales = $row;
                    break;
                }
            }
            if ($bestSales === null) {
                $bestSales = $salesRows[0];
            }
        }

        // Step 2: Parse "Residential Price Ranges" table for supplementary fields (low, high, max).
        // This table may have a higher count (includes all sales), but we use Sales Analysis count.
        // Pattern: Year  NoOfSales  R Low  R Median  R High  R Maximum
        $priceRe = 'R\s*(\d{1,3}(?:[\s,]\d{3})+)';
        $rowPattern = '/\b(20\d{2})\s+(\d{1,4})\s+' . $priceRe . '\s+' . $priceRe . '\s+' . $priceRe . '\s+' . $priceRe . '/i';
        $rangeByYear = [];
        if (preg_match_all($rowPattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $year  = (int) $m[1];
                $count = (int) $m[2];
                $low   = (int) preg_replace('/[\s,]/', '', $m[3]);
                $med   = (int) preg_replace('/[\s,]/', '', $m[4]);
                $high  = (int) preg_replace('/[\s,]/', '', $m[5]);
                $max   = (int) preg_replace('/[\s,]/', '', $m[6]);

                if ($year >= 2000 && $year <= 2030 && $count > 0 && $med >= 10000) {
                    $rangeByYear[$year] = compact('year', 'count', 'low', 'med', 'high', 'max');
                }
            }
        }

        // Step 3: Build fields — Sales Analysis is authoritative for year/count/median.
        if ($bestSales !== null) {
            $fields['suburb.latest_year']         = (string) $bestSales['year'];
            $fields['suburb.latest_sales_count']  = (string) $bestSales['count'];
            $fields['suburb.latest_median_price'] = (string) $bestSales['med'];

            // Supplement with price range data for the same year (low, high, max only)
            if (isset($rangeByYear[$bestSales['year']])) {
                $range = $rangeByYear[$bestSales['year']];
                $fields['suburb.latest_low']  = (string) $range['low'];
                $fields['suburb.latest_high'] = (string) $range['high'];
                $fields['suburb.latest_max']  = (string) $range['max'];
            }

            return $fields;
        }

        // Step 4: Fallback — no Sales Analysis rows, use Price Ranges only.
        if (!empty($rangeByYear)) {
            $years = array_keys($rangeByYear);
            rsort($years);
            $bestYear = null;
            foreach ($years as $y) {
                if ($rangeByYear[$y]['count'] >= 10) {
                    $bestYear = $y;
                    break;
                }
            }
            if ($bestYear === null) {
                $bestYear = $years[0];
            }

            $best = $rangeByYear[$bestYear];
            $fields['suburb.latest_year']         = (string) $best['year'];
            $fields['suburb.latest_sales_count']  = (string) $best['count'];
            $fields['suburb.latest_median_price'] = (string) $best['med'];
            $fields['suburb.latest_low']          = (string) $best['low'];
            $fields['suburb.latest_high']         = (string) $best['high'];
            $fields['suburb.latest_max']          = (string) $best['max'];

            return $fields;
        }

        // Step 5: Footer-summary fallback (Home-Finders per-sale list layout).
        // When neither tabular table matched, the report is the per-sale transfer
        // list with a SUMMARY FOOTER, e.g.:
        //   "Residential (Full title) sales in RAMSGATE SOUTH for the last 12 months
        //    <per-sale lines each with a price 'R 1 450 000'>
        //    No of sales: 18   Median price: R 1 590 000   Average price: R 1 670 778"
        // Emits the SAME field keys the consumer reads — never new keys.
        $this->parseSuburbFooterSummary($text, $fields);

        return $fields;
    }

    /**
     * Footer-summary fallback for the per-sale Home-Finders suburb report layout.
     *
     * Only meaningful values are emitted; derives low/high/max from the per-sale
     * price column when the footer carries no explicit Low/High/Max labels
     * (locked decision 5: an evidence-based range beats a blank). Mutates $fields
     * in place using the existing suburb.* key convention.
     */
    private function parseSuburbFooterSummary(string $text, array &$fields): void
    {
        // Sales count — "No of sales: 18" (tolerate Number/Total, NBSP, case).
        if (preg_match('/\b(?:No(?:\.|\s+of)?|Number\s+of|Total)\s+sales\s*[:\-]?\s*(\d{1,4})/iu', $text, $m)) {
            $count = (int) $m[1];
            if ($count > 0 && $count < 100000) {
                $fields['suburb.latest_sales_count'] = (string) $count;
            }
        }

        // Median price — "Median price: R 1 590 000" (strip spaces + NBSP).
        if (preg_match('/\bMedian\s+price\s*[:\-]?\s*R?\s*([\d][\d\s\x{00A0},]*\d)/iu', $text, $m)) {
            $median = (int) preg_replace('/[\s\x{00A0},]/u', '', $m[1]);
            if ($median >= 10000) {
                $fields['suburb.latest_median_price'] = (string) $median;
            }
        }

        // Explicit Low / High / Max footer labels take precedence over derivation.
        $explicitLow  = $this->matchSuburbFooterPrice($text, '(?:Lowest|Low|Min(?:imum)?)\s+(?:price|sale)?');
        $explicitHigh = $this->matchSuburbFooterPrice($text, '(?:High|Upper)\s+(?:price|sale)?');
        $explicitMax  = $this->matchSuburbFooterPrice($text, '(?:Highest|Max(?:imum)?)\s+(?:price|sale)?');

        // Collect every "R <digits>" sale price in the body for derivation.
        $salePrices = [];
        if (preg_match_all('/R\s*([\d][\d\s\x{00A0},]*\d)/u', $text, $pm)) {
            foreach ($pm[1] as $raw) {
                $value = (int) preg_replace('/[\s\x{00A0},]/u', '', $raw);
                // Plausible residential sale prices only — filters out r/m² style figures.
                if ($value >= 100000 && $value <= 500000000) {
                    $salePrices[] = $value;
                }
            }
        }

        // Low / High / Max — prefer explicit labels, else derive from the column.
        if ($explicitLow !== null) {
            $fields['suburb.latest_low'] = (string) $explicitLow;
        } elseif (!empty($salePrices)) {
            $fields['suburb.latest_low'] = (string) min($salePrices);
        }

        if ($explicitMax !== null) {
            $fields['suburb.latest_max'] = (string) $explicitMax;
        } elseif (!empty($salePrices)) {
            $fields['suburb.latest_max'] = (string) max($salePrices);
        }

        if ($explicitHigh !== null) {
            $fields['suburb.latest_high'] = (string) $explicitHigh;
        } elseif (!empty($salePrices)) {
            // 75th percentile of the collected sale prices; max if too few to interpolate.
            $fields['suburb.latest_high'] = (string) $this->percentile($salePrices, 75);
        }

        // Year — latest sale-date year present, else the "last 12 months" → current year.
        if (preg_match_all('/\b(20\d{2})\b/', $text, $ym)) {
            $years = array_filter(array_map('intval', $ym[1]), fn ($y) => $y >= 2000 && $y <= 2030);
            if (!empty($years)) {
                $fields['suburb.latest_year'] = (string) max($years);
            }
        }
        if (!isset($fields['suburb.latest_year']) && preg_match('/last\s+12\s+months/i', $text)) {
            $fields['suburb.latest_year'] = (string) ((int) date('Y'));
        }
    }

    /**
     * Match a labelled footer price ("Low price: R 1 200 000"), normalised to int rand.
     * Returns null if the label is absent. Mirrors matchPrice() normalisation but
     * tolerates the space-grouped / NBSP rand formatting of the footer layout.
     */
    private function matchSuburbFooterPrice(string $text, string $labelPattern): ?int
    {
        $pattern = '/\b' . $labelPattern . '\s*[:\-]?\s*R?\s*([\d][\d\s\x{00A0},]*\d)/iu';
        if (preg_match($pattern, $text, $m)) {
            $value = (int) preg_replace('/[\s\x{00A0},]/u', '', $m[1]);
            if ($value >= 10000) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Linear-interpolated percentile of a numeric list (0–100). Returns max-style
     * behaviour for tiny lists where interpolation is not meaningful.
     */
    private function percentile(array $values, float $p): int
    {
        if (empty($values)) {
            return 0;
        }
        sort($values);
        $count = count($values);
        if ($count < 4) {
            return (int) end($values);
        }
        $rank  = ($p / 100) * ($count - 1);
        $low   = (int) floor($rank);
        $high  = (int) ceil($rank);
        if ($low === $high) {
            return (int) $values[$low];
        }
        $frac = $rank - $low;
        return (int) round($values[$low] + ($values[$high] - $values[$low]) * $frac);
    }

    // ── Vicinity Sales Parser ───────────────────────────────────────────────

    public function parseVicinitySales(string $text): array
    {
        $fields = [];

        // Detect sectional title and store property_type
        if ($this->isSectionalTitle($text)) {
            $fields['vicinity.property_type'] = 'sectional';
        }

        // Price ranges — "Lower Range: R 1 206 000"
        $this->matchPrice($text, 'Lower\s*Range', $fields, 'vicinity.lower_range');
        $this->matchPrice($text, 'Middle\s*Range', $fields, 'vicinity.middle_range');
        $this->matchPrice($text, 'Upper\s*Range', $fields, 'vicinity.upper_range');

        // Average price — "Average: R 1 687 000" (may not have the word "Price")
        $this->matchPrice($text, '(?:Average|Avg|Mean)\s*(?:Sale\s*)?(?:Price)?', $fields, 'vicinity.average_price');

        // Cross-line fallback: pdftotext column mangling can put "Average:" and its value
        // on separate lines with other text in between. Search after "Lower Range" for
        // a standalone price >= 500,000 that isn't already captured as another field.
        if (!isset($fields['vicinity.average_price'])) {
            $lowerPos = stripos($text, 'Lower Range');
            if ($lowerPos !== false) {
                $afterLower = substr($text, $lowerPos + 30, 600);
                $knownValues = array_values($fields);
                if (preg_match_all('/R\s*(\d{1,3}(?:[\s,]\d{3})+)/i', $afterLower, $avgMatches)) {
                    foreach ($avgMatches[1] as $pm) {
                        $cleaned = (int) preg_replace('/[\s,]/', '', $pm);
                        if ($cleaned >= 500000 && $cleaned <= 50000000 && !in_array((string) $cleaned, $knownValues, true)) {
                            $fields['vicinity.average_price'] = (string) $cleaned;
                            break;
                        }
                    }
                }
            }
        }

        // Average price per m² — "Average R/m²: R 1 232" or "Average R/m²:\tR 1 232"
        // Use [^\s:\-]* after 'm' to safely skip ²/2/sqm without UTF-8 byte issues.
        if (preg_match('/(?:Average|Avg|Mean)\s*R\s*\/\s*m[^\s:\-]*\s*[:\-]?\s*R?\s*(\d{1,3}(?:[, ]\d{3})*|\d{3,10})/i', $text, $m)) {
            if (isset($m[1])) {
                $cleaned = (int) preg_replace('/[\s,]/', '', $m[1]);
                if ($cleaned >= 100) {
                    $fields['vicinity.avg_price_per_m2'] = (string) $cleaned;
                }
            }
        }
        // Fallback: "Average price per m²: R..." or "R12,345/m²"
        if (!isset($fields['vicinity.avg_price_per_m2'])) {
            if (preg_match('/(?:Average|Avg|Mean)\s*(?:Price\s*)?(?:per|\/)\s*(?:m2|m²|sqm)\s*[:\-]?\s*R?\s*(\d{1,3}(?:[, ]\d{3})+|\d{3,10})/i', $text, $m)) {
                if (isset($m[1])) {
                    $cleaned = (int) preg_replace('/[\s,]/', '', $m[1]);
                    if ($cleaned >= 100) {
                        $fields['vicinity.avg_price_per_m2'] = (string) $cleaned;
                    }
                }
            }
        }
        if (!isset($fields['vicinity.avg_price_per_m2'])) {
            if (preg_match('/R\s*(\d{1,3}(?:[, ]\d{3})+|\d{3,10})\s*(?:per|\/)\s*(?:m2|m²|sqm)/i', $text, $m)) {
                if (isset($m[1])) {
                    $cleaned = (int) preg_replace('/[\s,]/', '', $m[1]);
                    if ($cleaned >= 100) {
                        $fields['vicinity.avg_price_per_m2'] = (string) $cleaned;
                    }
                }
            }
        }

        // Comps count — count numbered property rows: "1  292 m  792  8 SMUTS..."
        // Each row starts with a row number, then distance, then erf number
        $compsCount = preg_match_all('/^\s*(\d{1,3})\s+(?:\d+\s*m|-)\s+\d+\s+\d+\s+\w/m', $text);
        if ($compsCount && $compsCount > 0 && $compsCount < 1000) {
            $fields['vicinity.comps_count'] = (string) $compsCount;
        }

        // Fallback comps count: look for explicit labels
        if (!isset($fields['vicinity.comps_count'])) {
            if (preg_match('/(?:Comparables?|Comps?|Properties\s*(?:Sold|Found|Used|Within))\s*[:\-]?\s*(\d+)/i', $text, $m)) {
                $count = (int) $m[1];
                if ($count > 0 && $count < 1000) {
                    $fields['vicinity.comps_count'] = (string) $count;
                }
            }
        }
        if (!isset($fields['vicinity.comps_count'])) {
            if (preg_match('/(\d+)\s*(?:comparables?|comps?|properties\s*(?:sold|within|found))/i', $text, $m)) {
                $count = (int) $m[1];
                if ($count > 0 && $count < 1000) {
                    $fields['vicinity.comps_count'] = (string) $count;
                }
            }
        }

        // Sectional title comps count: count "Residence" type tokens as proxy for row count
        if (!isset($fields['vicinity.comps_count']) && isset($fields['vicinity.property_type'])) {
            $compsCount = preg_match_all('/\bResidence\b/i', $text);
            if ($compsCount && $compsCount > 0 && $compsCount < 1000) {
                $fields['vicinity.comps_count'] = (string) $compsCount;
            }
        }

        return $fields;
    }

    // ── Row Extraction Methods ───────────────────────────────────────────────
    // m² in UTF-8 = bytes 0xC2 0xB2. Use m(?:\xC2\xB2|2) in patterns.
    // CRITICAL: price/extent captures use [ ,] (space+comma) NOT [\s,] to avoid
    // consuming newlines and bleeding into the next row.

    /**
     * Extract vicinity comp rows from vicinity sales PDF text.
     * Source tag: 'vicinity_sales'.
     *
     * Uses token-based parsing: pdftotext outputs each column on its own
     * line separated by blank lines (\n\n). We anchor on date + R price + R r/m²
     * triplets and walk backwards to find extent, usage, address, erf, distance.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractVicinityRows(string $text): array
    {
        // Sectional title has a different column layout — use extractSectionalVicinityRows
        if ($this->isSectionalTitle($text)) {
            return [];
        }

        $rows = [];

        // Split text into tokens by double-newline (pdftotext column separator)
        $tokens = preg_split('/\n{2,}/', $text);
        $tokens = array_values(array_filter(array_map('trim', $tokens), fn($t) => $t !== ''));
        $numTokens = count($tokens);
        $rowNum = 0;

        for ($i = 0; $i < $numTokens; $i++) {
            // Anchor: find date token YYYY/MM/DD
            if (!preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $tokens[$i])) {
                continue;
            }

            $saleDate = $tokens[$i];

            // After date: R price (i+1), R r/m² (i+2)
            if ($i + 2 >= $numTokens) continue;
            if (!preg_match('/^R[ ]([\d ,]+\d)$/', $tokens[$i + 1], $pm)) continue;
            if (!preg_match('/^R[ ]([\d ,]+\d)$/', $tokens[$i + 2], $rm)) continue;

            $price = (int) preg_replace('/[ ,]/', '', $pm[1]);
            $rpm2  = (int) preg_replace('/[ ,]/', '', $rm[1]);

            if ($price < 10000) continue;

            // Before date: extent m² (i-1)
            $extent = null;
            if ($i >= 1 && preg_match('/^(\d[\d ,]*)\s*m(?:\xC2\xB2|2)$/i', $tokens[$i - 1], $em)) {
                $extent = (int) preg_replace('/[ ,]/', '', $em[1]);
            }
            if ($extent === null || $extent < 10) continue;

            // Before extent: search backwards for usage token (may skip optional Type token)
            $usageIdx = null;
            for ($j = $i - 2; $j >= max(0, $i - 4); $j--) {
                if (preg_match('/^(Residential|Commercial|Industrial|Agricultural)$/i', $tokens[$j])) {
                    $usageIdx = $j;
                    break;
                }
            }
            if ($usageIdx === null) continue;

            // Before usage: address (one token — e.g. "JOCELYN STREET, SHELLY BEACH")
            $address = null;
            $addrIdx = $usageIdx - 1;
            if ($addrIdx >= 0 && preg_match('/[A-Z]/', $tokens[$addrIdx])) {
                $address = $tokens[$addrIdx];
            }

            // Before address: erf number (3-5 digits)
            $erfNo = null;
            $erfIdx = ($addrIdx >= 0) ? $addrIdx - 1 : -1;
            if ($erfIdx >= 0 && preg_match('/^\d{3,5}$/', $tokens[$erfIdx])) {
                $erfNo = $tokens[$erfIdx];
            }

            // Before erf: distance ("NNN m" or "-")
            $distM = null;
            $distIdx = ($erfIdx >= 0) ? $erfIdx - 1 : -1;
            if ($distIdx >= 0) {
                if (preg_match('/^(\d+)\s*m$/', $tokens[$distIdx], $dm)) {
                    $distM = (int) $dm[1];
                }
                // "-" means no distance (subject property's own row)
            }

            $rowNum++;
            $rows[] = [
                'row_number'   => $rowNum,
                'distance_m'   => $distM,
                'erf_no'       => $erfNo,
                'address'      => $address,
                'extent_m2'    => $extent,
                'sale_date'    => $saleDate,
                'sale_price'   => $price,
                'price_per_m2' => $rpm2,
                'source'       => 'vicinity_sales',
            ];

            // Skip past the consumed tokens (date + price + rpm2)
            $i += 2;
        }

        return $rows;
    }

    /**
     * Extract sectional title vicinity comp rows from CMA Info "Sectional Title sales within" PDF.
     * Table columns: Scheme Name | Section [Flat] No | SS No | SS Year | Type | Extent | Sale Date | Sale Price | R/m²
     * Source tag: 'vicinity_sales_sectional'.
     *
     * Uses token-based parsing: pdftotext outputs each column value as a separate
     * token separated by double newlines. Anchors on date + R price + R r/m² triplets
     * and walks backwards to find extent, type, numeric metadata, and scheme name (address).
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractSectionalVicinityRows(string $text): array
    {
        $rows = [];

        if (!$this->isSectionalTitle($text)) {
            return [];
        }

        // Token-based: split by double-newline (pdftotext column separator)
        $tokens = preg_split('/\n{2,}/', $text);
        $tokens = array_values(array_filter(array_map('trim', $tokens), fn($t) => $t !== ''));
        $numTokens = count($tokens);
        $rowNum = 0;

        for ($i = 0; $i < $numTokens; $i++) {
            // Anchor: date token YYYY/MM/DD
            if (!preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $tokens[$i])) {
                continue;
            }

            $saleDate = $tokens[$i];

            // After date: R price (i+1), R r/m² (i+2)
            if ($i + 2 >= $numTokens) continue;
            if (!preg_match('/^R[ ]([\d ,]+\d)$/', $tokens[$i + 1], $pm)) continue;
            if (!preg_match('/^R[ ]([\d ,]+\d)$/', $tokens[$i + 2], $rm)) continue;

            $price = (int) preg_replace('/[ ,]/', '', $pm[1]);
            $rpm2  = (int) preg_replace('/[ ,]/', '', $rm[1]);

            if ($price < 10000) continue;

            // Before date: extent m² (i-1) — unit floor area for sectional (can be small, 30+m²)
            $extent = null;
            if ($i >= 1 && preg_match('/^(\d[\d ,]*)\s*m(?:\xC2\xB2|2)$/i', $tokens[$i - 1], $em)) {
                $extent = (int) preg_replace('/[ ,]/', '', $em[1]);
            }
            if ($extent === null || $extent < 5) continue;

            // Before extent: type token (Residence, Residential, etc.)
            $typeIdx = null;
            for ($j = $i - 2; $j >= max(0, $i - 4); $j--) {
                if (preg_match('/^(Residence|Residential|Commercial|Industrial)$/i', $tokens[$j])) {
                    $typeIdx = $j;
                    break;
                }
            }
            if ($typeIdx === null) continue;

            // Walk backwards from type: collect numeric tokens (SS year, SS no, section no)
            // then find address (scheme name — contains uppercase letters, often has comma)
            $address = null;
            $sectionNo = null;
            $ssNo = null;
            $ssYear = null;
            $numericTokens = [];

            for ($j = $typeIdx - 1; $j >= max(0, $typeIdx - 6); $j--) {
                $tok = str_replace("\n", ' ', $tokens[$j]);
                $tok = preg_replace('/\s+/', ' ', $tok);
                $tok = trim($tok);

                if (preg_match('/^\d{1,6}$/', $tok)) {
                    $numericTokens[] = $tok;
                } elseif (preg_match('/[A-Z]/', $tok) && strlen($tok) >= 3) {
                    // Skip header tokens and page markers
                    if (preg_match('/^(Scheme\s*Name|Section|Page\s+\d|Home\s+Finders|Type|Extent|Last\s+Sale)/i', $tok)) {
                        break;
                    }
                    $address = $tok;
                    break;
                } else {
                    break; // Unknown token type — stop walking
                }
            }

            // Numeric tokens collected closest-to-type first:
            // [0] = SS year (closest to type), [1] = SS number, [2] = section/flat number
            if (count($numericTokens) >= 1) $ssYear = $numericTokens[0];
            if (count($numericTokens) >= 2) $ssNo = $numericTokens[1];
            if (count($numericTokens) >= 3) $sectionNo = $numericTokens[2];

            // If no address found, skip this row
            if ($address === null || strlen($address) < 3) continue;

            // Strip leading row number from address if present (e.g. "1 TRIGER GARDENS...")
            // Only strip if the number is 1-3 digits followed by space+uppercase letter
            $address = preg_replace('/^\d{1,3}\s+(?=[A-Z])/', '', $address);
            $address = trim($address, " ,\t\r\n");

            // Append section/unit number for identification
            if ($sectionNo !== null) {
                $address = $address . ' (Unit ' . $sectionNo . ')';
            }

            $rowNum++;
            $rows[] = [
                'row_number'    => $rowNum,
                'distance_m'    => null,
                'erf_no'        => null,
                'address'       => $address,
                'extent_m2'     => $extent,
                'sale_date'     => $saleDate,
                'sale_price'    => $price,
                'price_per_m2'  => $rpm2,
                'property_type' => 'Sectional',
                'ss_no'         => $ssNo,
                'ss_year'       => $ssYear,
                'section_no'    => $sectionNo,
                'source'        => 'vicinity_sales_sectional',
            ];

            // Skip past consumed tokens (date + price + rpm2)
            $i += 2;
        }

        return $rows;
    }

    /**
     * Extract CMA comp rows from CMA PDF text (Page 4).
     * Source tag: 'cma_comps'. No R/m² column.
     *
     * Uses token-based parsing (same approach as extractVicinityRows) to handle
     * pdftotext line-by-line output and multi-word suburb names.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractCmaCompRows(string $text): array
    {
        $rows = [];

        // Limit to section between "COMPARATIVE PROPERTIES" and "Comparative Market Analysis Value"
        $startPos = stripos($text, 'COMPARATIVE PROPERTIES');
        $endPos = stripos($text, 'Comparative Market Analysis Value');
        if ($startPos === false) {
            return [];
        }
        $section = ($endPos !== false)
            ? substr($text, $startPos, $endPos - $startPos)
            : substr($text, $startPos, 3000);

        // Token-based: split by double-newline
        $tokens = preg_split('/\n{2,}/', $section);
        $tokens = array_values(array_filter(array_map('trim', $tokens), fn($t) => $t !== ''));
        $numTokens = count($tokens);
        $rowNum = 0;

        for ($i = 0; $i < $numTokens; $i++) {
            // Anchor: date YYYY/MM/DD
            if (!preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $tokens[$i])) {
                continue;
            }
            $saleDate = $tokens[$i];

            // After date: R price (no R/m² for CMA comps)
            if ($i + 1 >= $numTokens) continue;
            if (!preg_match('/^R[ ]([\d ,]+\d)$/', $tokens[$i + 1], $pm)) continue;
            $price = (int) preg_replace('/[ ,]/', '', $pm[1]);
            if ($price < 10000) continue;

            // Before date: extent m²
            $extent = null;
            if ($i >= 1 && preg_match('/^(\d[\d ,]*)\s*m(?:\xC2\xB2|2)$/i', $tokens[$i - 1], $em)) {
                $extent = (int) preg_replace('/[ ,]/', '', $em[1]);
            }
            if ($extent === null || $extent < 10) continue;

            // Before extent: usage
            $usageIdx = null;
            for ($j = $i - 2; $j >= max(0, $i - 4); $j--) {
                if (preg_match('/^(Residential|Commercial|Industrial|Agricultural)$/i', $tokens[$j])) {
                    $usageIdx = $j;
                    break;
                }
            }
            if ($usageIdx === null) continue;

            // Before usage: address
            $address = null;
            $addrIdx = $usageIdx - 1;
            if ($addrIdx >= 0 && preg_match('/[A-Z]/', $tokens[$addrIdx])) {
                $address = $tokens[$addrIdx];
            }

            // Before address: erf number
            $erfNo = null;
            $erfIdx = ($addrIdx >= 0) ? $addrIdx - 1 : -1;
            if ($erfIdx >= 0 && preg_match('/^\d{3,5}$/', $tokens[$erfIdx])) {
                $erfNo = $tokens[$erfIdx];
            }

            // Before erf: distance
            $distM = null;
            $distIdx = ($erfIdx >= 0) ? $erfIdx - 1 : -1;
            if ($distIdx >= 0 && preg_match('/^(\d+)\s*m$/', $tokens[$distIdx], $dm)) {
                $distM = (int) $dm[1];
            }

            $rowNum++;
            $rows[] = [
                'row_number'   => $rowNum,
                'distance_m'   => $distM,
                'erf_no'       => $erfNo,
                'address'      => $address,
                'extent_m2'    => $extent,
                'sale_date'    => $saleDate,
                'sale_price'   => $price,
                'price_per_m2' => null,
                'source'       => 'cma_comps',
            ];

            $i += 1; // skip past price token
        }

        return $rows;
    }

    /**
     * Extract street sales rows from CMA PDF text (Page 7).
     * No row number or distance column.
     * Source tag: 'street_sales'.
     *
     * Uses token-based parsing to handle pdftotext line-by-line output
     * and multi-word suburb names.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractStreetSalesRows(string $text): array
    {
        $rows = [];

        // Limit to section between "most recent sales in" and "Page X of Y" or "Price Ranges"
        $startPos = stripos($text, 'most recent sales in');
        if ($startPos === false) {
            return [];
        }
        $endPos = strpos($text, 'Price Ranges', $startPos);
        $section = ($endPos !== false)
            ? substr($text, $startPos, $endPos - $startPos)
            : substr($text, $startPos, 5000);

        // Token-based: split by double-newline
        $tokens = preg_split('/\n{2,}/', $section);
        $tokens = array_values(array_filter(array_map('trim', $tokens), fn($t) => $t !== ''));
        $numTokens = count($tokens);

        for ($i = 0; $i < $numTokens; $i++) {
            // Anchor: date YYYY/MM/DD
            if (!preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $tokens[$i])) {
                continue;
            }
            $saleDate = $tokens[$i];

            // After date: R price, R r/m²
            if ($i + 2 >= $numTokens) continue;
            if (!preg_match('/^R[ ]([\d ,]+\d)$/', $tokens[$i + 1], $pm)) continue;
            if (!preg_match('/^R[ ]([\d ,]+\d)$/', $tokens[$i + 2], $rm)) continue;

            $price = (int) preg_replace('/[ ,]/', '', $pm[1]);
            $rpm2  = (int) preg_replace('/[ ,]/', '', $rm[1]);
            if ($price < 10000) continue;

            // Before date: extent m²
            $extent = null;
            if ($i >= 1 && preg_match('/^(\d[\d ,]*)\s*m(?:\xC2\xB2|2)$/i', $tokens[$i - 1], $em)) {
                $extent = (int) preg_replace('/[ ,]/', '', $em[1]);
            }
            if ($extent === null || $extent < 10) continue;

            // Before extent: usage
            $usageIdx = null;
            for ($j = $i - 2; $j >= max(0, $i - 4); $j--) {
                if (preg_match('/^(Residential|Commercial|Industrial|Agricultural)$/i', $tokens[$j])) {
                    $usageIdx = $j;
                    break;
                }
            }
            if ($usageIdx === null) continue;

            // Before usage: address
            $address = null;
            $addrIdx = $usageIdx - 1;
            if ($addrIdx >= 0 && preg_match('/[A-Z]/', $tokens[$addrIdx])) {
                $address = $tokens[$addrIdx];
            }

            // Before address: erf number (no distance for street sales)
            $erfNo = null;
            $erfIdx = ($addrIdx >= 0) ? $addrIdx - 1 : -1;
            if ($erfIdx >= 0 && preg_match('/^\d{3,5}$/', $tokens[$erfIdx])) {
                $erfNo = $tokens[$erfIdx];
            }

            $rows[] = [
                'row_number'   => null,
                'distance_m'   => null,
                'erf_no'       => $erfNo,
                'address'      => $address,
                'extent_m2'    => $extent,
                'sale_date'    => $saleDate,
                'sale_price'   => $price,
                'price_per_m2' => $rpm2,
                'source'       => 'street_sales',
            ];

            $i += 2; // skip past price + rpm2 tokens
        }

        return $rows;
    }

    /**
     * Extract active listing rows from CMA PDF text (Page 9).
     * pdftotext interleaves column headers with data, so we extract
     * each field individually from the FOR SALE section.
     * Source tag: 'cma_active'.
     * @return array<int, array<string, mixed>>
     */
    public function extractCmaActiveListings(string $text): array
    {
        $rows = [];

        $startPos = stripos($text, 'FOR SALE');
        if ($startPos === false) {
            $startPos = stripos($text, 'recently listed');
        }
        if ($startPos === false) {
            return [];
        }
        $section = substr($text, $startPos, 3000);

        // Find all prices >= 100,000 in the section — each represents one listing.
        if (!preg_match_all('/R[ ]([\d ,]+\d)/i', $section, $priceMatches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($priceMatches[0] as $idx => $match) {
            $priceStr = $priceMatches[1][$idx][0];
            $pricePos = $match[1];
            $price = (int) preg_replace('/[ ,]/', '', $priceStr);
            if ($price < 100000) {
                continue;
            }

            $before = substr($section, 0, $pricePos);
            $after = substr($section, $pricePos + strlen($match[0]), 200);

            // DOM: first standalone number on its own line after the price
            $dom = null;
            if (preg_match('/\n(\d{1,4})\s*\n/', $after, $dm)) {
                $val = (int) $dm[1];
                if ($val <= 3650) {
                    $dom = $val;
                }
            }

            // List date: last YYYY/MM/DD before the price
            $listDate = null;
            if (preg_match_all('/(\d{4}\/\d{2}\/\d{2})/', $before, $dm)) {
                $listDate = end($dm[1]);
            }

            // Extent: last NUMBER m² before the price
            $extent = null;
            if (preg_match_all('/(\d[\d ,]*)\s*m(?:\xC2\xB2|2)/s', $before, $dm)) {
                $extent = (int) preg_replace('/[ ,]/', '', end($dm[1]));
            }

            // Address: NUMBER STREET, SUBURB
            $address = null;
            if (preg_match_all('/(\d+\s+[A-Z][A-Z\s]+,\s*[A-Z]+)/i', $before, $dm)) {
                $address = trim(end($dm[1]));
            }

            // Erf number: 3-5 digit number on its own line (not part of date or extent)
            $erfNo = null;
            if (preg_match_all('/\n(\d{3,5})\s*\n/', $before, $dm)) {
                foreach (array_reverse($dm[1]) as $candidate) {
                    $cInt = (int) $candidate;
                    if ($cInt >= 100 && ($cInt < 2000 || $cInt > 2030)) {
                        $erfNo = $candidate;
                        break;
                    }
                }
            }

            // Distance: first NNN m match (appears before erf in the text)
            $distM = null;
            if (preg_match('/(\d{1,4})\s*m\b/', $before, $dm)) {
                $distM = (int) $dm[1];
            }

            // Property type
            $propType = null;
            if (preg_match('/(DS\s+House|House|Unit|Flat|Townhouse|Stand|Vacant\s+Land|Apartment)/i', $before, $dm)) {
                $propType = trim($dm[1]);
            }

            if ($extent && $extent >= 10) {
                $rows[] = [
                    'distance_m'     => $distM,
                    'erf_no'         => $erfNo,
                    'address'        => $address,
                    'property_type'  => $propType,
                    'extent_m2'      => $extent,
                    'list_date'      => $listDate,
                    'list_price'     => $price,
                    'days_on_market' => $dom,
                    'source'         => 'cma_active',
                ];
            }
        }

        return $rows;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Match a label pattern followed by a price value.
     * Normalizes "R 2,250,000" → "2250000" for storage.
     */
    private function matchPrice(string $text, string $labelPattern, array &$fields, string $key): void
    {
        $pattern = '/' . $labelPattern . '\s*[:\-]?\s*R?\s*(\d{1,3}(?:[, ]\d{3})+|\d{4,10})/i';
        if (preg_match($pattern, $text, $m)) {
            $cleaned = (int) preg_replace('/[\s,]/', '', $m[1]);
            if ($cleaned >= 1000) {
                $fields[$key] = (string) $cleaned;
            }
        }
    }
}
