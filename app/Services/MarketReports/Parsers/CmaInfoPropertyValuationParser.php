<?php

declare(strict_types=1);

namespace App\Services\MarketReports\Parsers;

use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Services\MarketReports\DTOs\MarketReportParseResult;
use App\Services\MarketReports\DTOs\ParserConfidence;
use App\Support\MarketReports\GpsParser;
use Illuminate\Support\Facades\Log;

/**
 * V1+Phase3a parser for CMA Info "Property Valuation" — full ~11-page document
 * with subject details (page 1), CMA comp table (page 5), sold properties
 * + distance (page 11) and active listings (page 12).
 *
 * Phase 3a additions:
 *   - subject GPS extraction (page 1 "Section extent / GPS" block)
 *   - subject scheme + section number, address, extent
 *   - per-comp rows written to market_report_comp_rows (subject + comps + listings)
 *   - distance_to_subject_m captured from page 11/12 prefix
 *
 * Spec: .ai/specs/mic-complete-spec.md §8.3 + Phase 3a build prompt.
 */
final class CmaInfoPropertyValuationParser extends AbstractCmaInfoParser
{
    public const PARSER_VERSION = 'cma_info_property_valuation_v2';

    public function getReportTypeKey(): string
    {
        return 'cma_info_property_valuation';
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
        if ($pages >= 9 && $pages <= 14) { $score += 0.3; $reasons[] = "page count {$pages}"; }

        if ($this->findHeader($text, 'Property Valuation') || $this->findHeader($text, 'PROPERTY INFORMATION')) {
            $score += 0.4;
            $reasons[] = 'Property Valuation / PROPERTY INFORMATION header';
        }
        if ($this->findHeader($text, 'Sale History') || $this->findHeader($text, 'Previous Sales') || $this->findHeader($text, 'SALE INFORMATION')) {
            $score += 0.15;
            $reasons[] = 'sale history block';
        }
        if ($this->findHeader($text, 'Municipal Valuation') || $this->findHeader($text, 'MUNICIPAL VALUATION')) {
            $score += 0.1;
            $reasons[] = 'municipal valuation block';
        }
        if ($this->findHeader($text, 'Comparative Market Analysis') || $this->findHeader($text, 'CMA - Indexed Value')) {
            $score += 0.1;
            $reasons[] = 'CMA comparative table';
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

        // ── Subject property block (page 1 "PROPERTY INFORMATION") ──────────
        $subject = $this->extractSubject($text, $report);

        // Resolve report-level suburb — explicit upload first, else
        // the Suburb column captured by extractSubject(). Promote to
        // subject_meta so ParseMarketReportJob backfills the report row.
        $resolved  = $this->resolveReportSuburb($report->source_suburb, [$subject['suburb'] ?? null]);
        $suburb    = $resolved['normalised'];
        $suburbRaw = $resolved['raw'];

        // Emit a subject comp row (row_index 0).
        if (!empty($subject['address']) || !empty($subject['scheme_name'])) {
            $compRows[] = $this->buildCompRow($subject, MarketReportCompRow::ROW_SUBJECT, 0, $suburb);
        }

        // Subject-side data points (legacy MDP — keep writing for aggregates).
        // MarketDataPoint requires exactly ONE of metric_value_(numeric|date|string);
        // we encode the dated context via metric_date and keep value scalar.
        if (!empty($subject['municipal_valuation'])) {
            $points[] = [
                'metric_key'           => 'municipal_valuation',
                'metric_value_numeric' => (float) $subject['municipal_valuation'],
                'metric_date'          => !empty($subject['municipal_valuation_year']) ? $subject['municipal_valuation_year'] . '-07-01' : $today,
                'confidence'           => 'high',
                'suburb_normalised'    => $suburb,
            ];
        }

        // Sale history rows — pre-existing v1 logic (date encoded via metric_date).
        if (preg_match_all('/R\s*([\d ,]+)\s+on\s+(\d{4}[-\/]\d{2}[-\/]\d{2})/i', $text, $shMatches, PREG_SET_ORDER)) {
            foreach ($shMatches as $row) {
                $saleDate = $this->parseDate($row[2]);
                $points[] = [
                    'metric_key'           => 'subject_sale_history',
                    'metric_value_numeric' => $this->parsePrice($row[1]),
                    'metric_date'          => $saleDate ?? $today,
                    'confidence'           => 'high',
                    'suburb_normalised'    => $suburb,
                ];
            }
        }

        // ── Comp rows from the CMA Comparative Market Analysis table (page 5) ──
        $rowIndex = 1;
        foreach ($this->extractCmaCompRows($text, $subject['scheme_name'] ?? null) as $row) {
            $compRows[] = $this->buildCompRow($row, MarketReportCompRow::ROW_COMP, $rowIndex++, $suburb);
            if (!empty($row['address'])) {
                $addresses[] = $this->makeAddress([
                    'street_name' => $row['address'],
                    'suburb'      => $row['suburb_normalised'] ?? $report->source_suburb,
                    'sale_price'  => $row['sale_price'] ?? null,
                    'sale_date'   => $row['sale_date'] ?? null,
                ]);
            }
            if (!empty($row['sale_price'])) {
                $points[] = [
                    'metric_key'           => 'comparable_sale_price',
                    'metric_value_numeric' => (float) $row['sale_price'],
                    'metric_date'          => $row['sale_date'] ?? $today,
                    'confidence'           => 'medium',
                    'suburb_normalised'    => $suburb,
                ];
            }
        }

        // ── Sold properties with distance prefix (page 11) ──────────────────
        foreach ($this->extractSoldWithDistance($text) as $row) {
            $compRows[] = $this->buildCompRow($row, MarketReportCompRow::ROW_COMP, $rowIndex++, $suburb);
        }

        // ── Active listings with distance prefix (page 12) ──────────────────
        foreach ($this->extractActiveListings($text) as $row) {
            $compRows[] = $this->buildCompRow($row, MarketReportCompRow::ROW_LISTING, $rowIndex++, $suburb);
        }

        // Value range — keep legacy MDP write.
        if (preg_match('/(?:Comparative Market Analysis Value|Recommended)\s*R\s*([\d ,]+)/iu', $text, $m)) {
            $val = $this->parsePrice($m[1]);
            if ($val !== null) {
                $points[] = [
                    'metric_key' => 'cma_value_middle', 'metric_value_numeric' => $val,
                    'metric_date' => $today, 'confidence' => 'medium', 'suburb_normalised' => $suburb,
                ];
            }
        }
        if (preg_match('/Lower Range:\s*R\s*([\d ,]+).*?Middle Range:\s*R\s*([\d ,]+).*?Upper Range:\s*R\s*([\d ,]+)/s', $text, $m)) {
            $lower = $this->parsePrice($m[1]);
            $middle = $this->parsePrice($m[2]);
            $upper = $this->parsePrice($m[3]);
            if ($lower !== null) $points[] = ['metric_key' => 'cma_value_lower', 'metric_value_numeric' => $lower, 'metric_date' => $today, 'confidence' => 'medium', 'suburb_normalised' => $suburb];
            if ($middle !== null) $points[] = ['metric_key' => 'cma_value_middle', 'metric_value_numeric' => $middle, 'metric_date' => $today, 'confidence' => 'medium', 'suburb_normalised' => $suburb];
            if ($upper !== null) $points[] = ['metric_key' => 'cma_value_upper', 'metric_value_numeric' => $upper, 'metric_date' => $today, 'confidence' => 'medium', 'suburb_normalised' => $suburb];
        }

        // ── Subject metadata for market_reports row ─────────────────────────
        $subjectMeta = array_filter([
            'subject_address'        => $subject['address']        ?? null,
            'subject_scheme_name'    => $subject['scheme_name']    ?? null,
            'subject_section_number' => $subject['section_number'] ?? null,
            'subject_latitude'       => $subject['latitude']       ?? null,
            'subject_longitude'      => $subject['longitude']      ?? null,
            'subject_extent_m2'      => $subject['extent_m2']      ?? null,
            // Backfill source_suburb when blank — ParseMarketReportJob's
            // allow-list honours this key and refuses to clobber a
            // user-supplied value.
            'source_suburb'          => $suburbRaw,
        ], fn ($v) => $v !== null && $v !== '');

        if (!empty($subject['address'])) {
            $addresses[] = $this->makeAddress([
                'street_name' => $subject['address'],
                'suburb'      => $suburbRaw ?? $report->source_suburb,
                'latitude'    => $subject['latitude'] ?? null,
                'longitude'   => $subject['longitude'] ?? null,
            ]);
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

    // ── Subject extraction ──────────────────────────────────────────────────

    /**
     * Page 1 block:
     *   Scheme number     379/1985        Address       4 TUCKER AVENUE
     *   Scheme name       MADEIRA GARDENS Suburb        UVONGO
     *   Section number    1
     *   Flat number       130 m²
     *   Section extent    30.380705°E 30.844135°S
     *   GPS
     */
    private function extractSubject(string $text, MarketReport $report): array
    {
        $out = [];

        // Address — "Address  <address>"
        if (preg_match('/Address\s+([A-Z0-9][^\n]{2,80})/u', $text, $m)) {
            $out['address'] = trim($m[1]);
        }
        // Scheme name + Suburb (column-paired on one line) —
        //   "Scheme name    MADEIRA GARDENS    Suburb    UVONGO"
        if (preg_match('/Scheme\s+name\s+([A-Z][^\n]{1,80}?)\s+Suburb\s+([A-Z][A-Z \']{2,40})/iu', $text, $m)) {
            $out['scheme_name'] = trim($m[1]);
            $out['suburb']      = trim($m[2]);
        } elseif (preg_match('/Scheme\s+name\s+([A-Z][^\n]{1,80}?)\s+Suburb/iu', $text, $m)) {
            $out['scheme_name'] = trim($m[1]);
        }
        // Standalone Suburb capture for non-sectional Property Valuation reports
        // (no "Scheme name" preamble). "Suburb    UVONGO".
        if (empty($out['suburb']) && preg_match('/(?:^|\n)\s*Suburb\s+([A-Z][A-Z \']{2,40})/iu', $text, $m)) {
            $out['suburb'] = trim($m[1]);
        }
        // Scheme number → ss_number / ss_year (e.g. "379/1985")
        if (preg_match('/Scheme\s+number\s+(\d{1,5})\/(\d{4})/iu', $text, $m)) {
            $out['ss_number'] = $m[1];
            $out['ss_year']   = (int) $m[2];
        }
        // Section number
        if (preg_match('/Section\s+number\s+(\d{1,4})/iu', $text, $m)) {
            $out['section_number'] = $m[1];
        }
        // Extent (m²) — "Flat number     130 m²" OR "Section extent 130 m²"
        if (preg_match('/(?:Flat\s+number|Section\s+extent)\s+(\d{1,5})\s*m/iu', $text, $m)) {
            $out['extent_m2'] = (int) $m[1];
        }

        // GPS — find "30.380705°E 30.844135°S" pattern (degree symbols may be garbled).
        if (preg_match('/(-?\d{1,3}\.\d{2,8}\s*\S?\s*[EW]\s+-?\d{1,3}\.\d{2,8}\s*\S?\s*[NS]|-?\d{1,3}\.\d{2,8}\s*\S?\s*[NS]\s+-?\d{1,3}\.\d{2,8}\s*\S?\s*[EW])/u', $text, $m)) {
            $gps = GpsParser::fromString($m[1]);
            if ($gps !== null) {
                $out['latitude']  = $gps['lat'];
                $out['longitude'] = $gps['lng'];
            }
        }

        // Municipal valuation
        if (preg_match('/Total\s+Value\s+R\s*([\d ,]+)/iu', $text, $m)) {
            $val = $this->parsePrice($m[1]);
            if ($val !== null) {
                $out['municipal_valuation'] = (int) $val;
            }
        }
        if (preg_match('/Valuation\s+Year\s+(\d{4})/iu', $text, $m)) {
            $out['municipal_valuation_year'] = (int) $m[1];
        }

        // Sale price + date from SALE INFORMATION
        if (preg_match('/Sale\s+Price\s+R\s*([\d ,]+)/iu', $text, $m)) {
            $val = $this->parsePrice($m[1]);
            if ($val !== null) {
                $out['sale_price'] = (int) $val;
            }
        }
        if (preg_match('/Sale\s+Date\s+(\d{4}[\/\-]\d{2}[\/\-]\d{2})/iu', $text, $m)) {
            $out['sale_date'] = $this->parseDate($m[1]);
        }

        return $out;
    }

    // ── CMA table comp rows ─────────────────────────────────────────────────

    /**
     * Page 5 "CMA - Comparative Market Analysis" table. Layout (pdftotext
     * column-mode is messy; we accept rows where we can match Section + SS year
     * + Extent + at least one R-figure).
     *
     * Pattern: section, ss_number, ss_year, "Residence", extent, optional date,
     * optional last_sale, optional estimated_value, optional r_per_m².
     *
     * @param  ?string  $subjectScheme  the report subject's scheme name
     *                  (sectional reports only). In-scheme units — rows the
     *                  PDF lists under the scheme's section header with no
     *                  per-row street — carry neither a per-row scheme nor a
     *                  street, so the lookback below finds nothing and they
     *                  would otherwise lose their scheme entirely. When the
     *                  subject is sectional we attribute those rows to the
     *                  subject scheme (mirrors CmaInfoSectionalTitleSalesParser
     *                  L299). Out-of-scheme comps DO carry a per-row
     *                  "SCHEME, <num> STREET" line → the lookback fills both
     *                  $scheme and $address, so they never hit the fallback
     *                  and are never re-stamped.
     * @return array<int, array<string, mixed>>
     */
    private function extractCmaCompRows(string $text, ?string $subjectScheme = null): array
    {
        $rows = [];
        if (!preg_match('/CMA\s+-\s+Comparative\s+Market\s+Analysis(?<body>.*?)Comparative\s+Market\s+Analysis\s+Value/su', $text, $blockMatch)) {
            return $rows;
        }
        $body = $blockMatch['body'];

        // Drop the "SUBJECT PROPERTY" sub-block — the comparative table prints
        // the subject as its own header row (same scheme + section + sale) and
        // the single-line pass below would otherwise capture it as a COMPARABLE,
        // double-counting the subject (it is already emitted as ROW_SUBJECT) and
        // skewing the complex-section average. Keep only the rows after the
        // "COMPARATIVE PROPERTIES" divider when that divider is present.
        if (preg_match('/COMPARATIVE\s+PROPERTIES(?<comps>.*)$/su', $body, $cm)) {
            $body = $cm['comps'];
        }

        // Phase 3b — scan the whole block in one pass. The regex no longer
        // anchors at line start; it picks up data tuples wherever they appear,
        // including when the scheme/address text bled onto the same line.
        // For each match we look backward up to 200 chars for the nearest
        // SCHEME, ADDRESS pattern to attribute the comp to.
        // Bounded price tokens — each "thousands group" must be exactly 3
        // digits, so the regex can't bleed across column boundaries.
        $pattern = '/(?<sec>\d{1,3})\s+(?<ss>\d{1,5})\s+(?<yr>\d{4})\s+Residence\s+(?<ext>\d{1,5})\s*m\S?\s+(?<date>\d{4}[\/\-]\d{2}[\/\-]\d{2})\s+R\s*(?<sp>\d{1,3}(?:[\s,]\d{3}){0,3})(?:\s+R\s*(?<est>\d{1,3}(?:[\s,]\d{3}){0,3}))?(?:\s+R\s*(?<ppm>\d{1,3}(?:[\s,]\d{3}){0,2}))?/u';

        preg_match_all($pattern, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $m) {
            $matchStart = $m[0][1];
            $lookback   = max(0, $matchStart - 200);
            $context    = mb_substr($body, $lookback, $matchStart - $lookback);

            $scheme  = null;
            $address = null;
            // Find the LAST scheme-style line in the lookback context. Street
            // number is OPTIONAL — a scheme on a numberless road ("PUMULA, DUKE
            // ROAD") must bind its scheme too; the old mandatory-\d pattern left
            // such out-of-scheme comps scheme-less.
            if (preg_match_all('/([A-Z][A-Z0-9 \']{2,30}),\s+((?:\d{1,4}\s+)?[A-Z][A-Z0-9 \']{2,40})/', $context, $am, PREG_SET_ORDER)) {
                $last = end($am);
                $scheme  = trim($last[1]);
                $address = trim($last[2]);
            }

            // In-scheme unit: no per-row scheme AND no per-row street means
            // this row sits under the subject scheme's section header. Attribute
            // it to the subject scheme so the unit isn't left scheme-less.
            // (address null is the clean separator — out-of-scheme comps set
            // both fields above and so skip this branch.)
            if ($scheme === null && $address === null && is_string($subjectScheme) && trim($subjectScheme) !== '') {
                $scheme = trim($subjectScheme);
            }

            $rows[] = [
                'scheme_name'      => $scheme,
                'section_number'   => $m['sec'][0],
                'ss_number'        => $m['ss'][0],
                'ss_year'          => (int) $m['yr'][0],
                'property_type'    => 'Residence',
                'extent_m2'        => (int) $m['ext'][0],
                'sale_date'        => $this->parseDate($m['date'][0]),
                'sale_price'       => $this->parsePriceBounded($m['sp'][0], 'cma.sale_price', $m[0][0]),
                'estimated_value'  => !empty($m['est'][0]) ? $this->parsePriceBounded($m['est'][0], 'cma.estimated_value', $m[0][0]) : null,
                'r_per_m2'         => !empty($m['ppm'][0]) ? $this->parsePriceBounded($m['ppm'][0], 'cma.r_per_m2', $m[0][0], 100, 500000) : null,
                'address'          => $address,
            ];
        }

        // ── Stacked multi-section comps ─────────────────────────────────────
        // A combined-unit sale wraps its sections + extents to the lines above
        // and below the anchor, so the single-line pass above drops it entirely.
        // The comparative-table anchor carries [price, estimated_value] in
        // source order; R/m² is the combined-extent quotient. See
        // AbstractCmaInfoParser::extractStackedSectionalGroups.
        foreach ($this->extractStackedSectionalGroups($body) as $g) {
            $price = $this->parsePriceBounded((string) ($g['r_amounts'][0] ?? ''), 'cma.stacked.sale_price');
            if ($price === null) continue;

            $extent = $g['extent_sum'];
            $est    = isset($g['r_amounts'][1])
                ? $this->parsePriceBounded((string) $g['r_amounts'][1], 'cma.stacked.estimated_value')
                : null;
            $ppm    = ($extent !== null && $extent > 0) ? (int) round($price / $extent) : null;

            $scheme = $g['scheme_name'];
            if (($scheme === null || $scheme === '') && is_string($subjectScheme) && trim($subjectScheme) !== '') {
                $scheme = trim($subjectScheme);
            }

            $rows[] = [
                'scheme_name'     => $scheme,
                'section_number'  => $g['section_label'] !== '' ? $g['section_label'] : null,
                'ss_number'       => $g['ss_number'],
                'ss_year'         => $g['ss_year'],
                'property_type'   => 'Residence',
                'extent_m2'       => $extent,
                'sale_date'       => $g['sale_date'],
                'sale_price'      => $price,
                'estimated_value' => $est,
                'r_per_m2'        => $ppm,
                'address'         => null,
            ];
        }

        // Dedupe by (sale_date, sale_price) — single-line and stacked passes are
        // disjoint by construction (stacked anchors carry no inline "Residence"
        // token) but a defensive dedupe keeps a re-printed sale from doubling.
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

    /**
     * Page 11 SOLD PROPERTIES table — each row prefixed with "<N> m" distance.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractSoldWithDistance(string $text): array
    {
        $rows = [];

        // Find the SOLD PROPERTIES block to avoid matching distance tokens
        // from elsewhere in the document.
        if (!preg_match('/SOLD\s+PROPERTIES(?<body>.*?)(?=FOR\s+SALE|\Z)/su', $text, $blockMatch)) {
            return $rows;
        }
        $body = $blockMatch['body'];

        // Capture every "<dist> m" anchor anywhere in the block, then read
        // the rest of the row to the right (within the same line OR until
        // the next distance anchor).
        $distAnchorPattern = '/(?<dist>\d{2,4})\s*m\s+/u';
        if (!preg_match_all($distAnchorPattern, $body, $anchors, PREG_OFFSET_CAPTURE)) {
            return $rows;
        }

        $count = count($anchors[0]);
        for ($i = 0; $i < $count; $i++) {
            $start = $anchors[0][$i][1] + strlen($anchors[0][$i][0]);
            $end   = $i + 1 < $count ? $anchors[0][$i + 1][1] : strlen($body);
            $segment = mb_substr($body, $start, $end - $start);

            // Within the segment, capture section + Residence + ext + date + R + R.
            // Price tokens use a bounded "thousands group" pattern so they
            // can't consume the trailing diff column (e.g. "R 810 000 18.25%"
            // must yield "810 000", not "810 000 18" → R 81,000,018).
            if (preg_match(
                '/(?<addr>[A-Z][A-Z0-9 ,\']{4,80}?)?\s*(?<sec>\d{1,3})\s+Residence\s+(?<ext>\d{1,5})\s*m\S?\s+(?<date>\d{4}[\/\-]\d{2}[\/\-]\d{2})\s+(?<dom>\d{1,5})\s+R\s*(?<lp>\d{1,3}(?:[\s,]\d{3}){0,3})\s+R\s*(?<sp>\d{1,3}(?:[\s,]\d{3}){0,3})/us',
                $segment,
                $m
            )) {
                $listPrice = $this->parsePriceBounded($m['lp'], 'sold.list_price', $m[0]);
                $salePrice = $this->parsePriceBounded($m['sp'], 'sold.sale_price', $m[0]);
                [$scheme, $street] = $this->resolveDistanceRowScheme($body, $anchors[0][$i][1], $start);
                $rows[] = [
                    'scheme_name'           => $scheme,
                    'address'               => $street ?? (isset($m['addr']) ? trim($m['addr']) : null),
                    'section_number'        => $m['sec'],
                    'property_type'         => 'Residence',
                    'extent_m2'             => (int) $m['ext'],
                    'sale_date'             => $this->parseDate($m['date']),
                    'days_on_market'        => (int) $m['dom'],
                    'list_price'            => $listPrice,
                    'sale_price'            => $salePrice,
                    'distance_to_subject_m' => (int) $anchors['dist'][$i][0],
                ];
            }
        }

        return $rows;
    }

    /**
     * Page 12 FOR SALE listings — same shape, with list date + DOM instead of
     * sold date + last sale.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractActiveListings(string $text): array
    {
        $rows = [];

        if (!preg_match('/FOR\s+SALE(?<body>.*?)(?=\Z|2026\/|\f)/su', $text, $blockMatch)) {
            return $rows;
        }
        $body = $blockMatch['body'];

        $distAnchorPattern = '/(?<dist>\d{2,4})\s*m\s+/u';
        if (!preg_match_all($distAnchorPattern, $body, $anchors, PREG_OFFSET_CAPTURE)) {
            return $rows;
        }

        $count = count($anchors[0]);
        for ($i = 0; $i < $count; $i++) {
            $start = $anchors[0][$i][1] + strlen($anchors[0][$i][0]);
            $end   = $i + 1 < $count ? $anchors[0][$i + 1][1] : strlen($body);
            $segment = mb_substr($body, $start, $end - $start);

            if (preg_match(
                '/(?<addr>[A-Z][A-Z0-9 ,\']{4,80}?)?\s*(?<sec>\d{1,3})\s+Residence\s+(?<ext>\d{1,5})\s*m\S?\s+(?<date>\d{4}[\/\-]\d{2}[\/\-]\d{2})\s+R\s*(?<lp>\d{1,3}(?:[\s,]\d{3}){0,3})\s+(?<dom>\d{1,5})/us',
                $segment,
                $m
            )) {
                $listPrice = $this->parsePriceBounded($m['lp'], 'active.list_price', $m[0]);
                [$scheme, $street] = $this->resolveDistanceRowScheme($body, $anchors[0][$i][1], $start);
                $rows[] = [
                    'scheme_name'           => $scheme,
                    'address'               => $street ?? (isset($m['addr']) ? trim($m['addr']) : null),
                    'section_number'        => $m['sec'],
                    'property_type'         => 'Residence',
                    'extent_m2'             => (int) $m['ext'],
                    'sale_date'             => null,
                    'days_on_market'        => (int) $m['dom'],
                    'list_price'            => $listPrice,
                    'sale_price'            => null,
                    'distance_to_subject_m' => (int) $anchors['dist'][$i][0],
                ];
            }
        }

        return $rows;
    }

    /**
     * Resolve [scheme_name, street] for a distance-anchored SOLD / FOR SALE row.
     *
     * Each row's identity is "SCHEME, <opt number> STREET, SUBURB", printed
     * EITHER inline right after the "<dist> m" anchor ("327 m  COLONIAL SANDS,
     * 21 LAGOON DRIVE…") OR — when the scheme wraps — on the line(s) immediately
     * BEFORE the anchor ("ITHACA, 2 WILKIE ROAD\n 565 m  7  Residence…"). The
     * old row regex stuffed only whatever its inline `addr` group caught into
     * `address` (lossy — it dropped leading letters, "GOLDEN"→"OLDEN", and
     * missed wrapped schemes entirely, "ITHACA"→"") and never set scheme_name.
     *
     * We read the scheme off the FULL body text around the anchor (where the
     * spelling is intact): first the slice immediately AFTER the anchor (inline),
     * then a short window BEFORE it (wrapped). Street number stays OPTIONAL so a
     * numberless road ("DUKE ROAD") still binds.
     *
     * @return array{0: ?string, 1: ?string}  [scheme, street]  (both null when nothing parseable)
     */
    private function resolveDistanceRowScheme(string $body, int $anchorStart, int $anchorEnd): array
    {
        $line = '([A-Z][A-Z0-9 \'\-]{2,40}?),\s+((?:\d{1,4}\s+)?[A-Z][A-Z0-9 \']{2,40})';

        // (a) Inline — the scheme begins right where the segment does.
        $after = substr($body, $anchorEnd, 120);
        if (preg_match('/^\s*' . $line . '/u', $after, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        // (b) Wrapped — the scheme sits just before the anchor.
        $from   = max(0, $anchorStart - 160);
        $before = substr($body, $from, $anchorStart - $from);
        if (preg_match_all('/' . $line . '/u', $before, $am, PREG_SET_ORDER)) {
            $last = end($am);
            return [trim($last[1]), trim($last[2])];
        }

        return [null, null];
    }

    /**
     * Convert a per-row associative array into the canonical
     * market_report_comp_rows payload.
     */
    private function buildCompRow(array $row, string $type, int $idx, ?string $suburbNorm): array
    {
        return [
            'row_index'                => $idx,
            'row_type'                 => $type,
            'scheme_name'              => $row['scheme_name']              ?? null,
            'section_number'           => $row['section_number']           ?? null,
            'flat_number'              => $row['flat_number']              ?? null,
            'ss_number'                => $row['ss_number']                ?? null,
            'ss_year'                  => $row['ss_year']                  ?? null,
            'address'                  => $row['address']                  ?? null,
            'suburb_normalised'        => $row['suburb_normalised']        ?? $suburbNorm,
            'property_type'            => $row['property_type']            ?? null,
            'extent_m2'                => $row['extent_m2']                ?? null,
            'sale_date'                => $row['sale_date']                ?? null,
            'sale_price'               => $row['sale_price']               ?? null,
            'estimated_value'          => $row['estimated_value']          ?? null,
            'r_per_m2'                 => $row['r_per_m2']                 ?? null,
            'list_price'               => $row['list_price']               ?? null,
            'days_on_market'           => $row['days_on_market']           ?? null,
            'municipal_valuation'      => $row['municipal_valuation']      ?? null,
            'municipal_valuation_year' => $row['municipal_valuation_year'] ?? null,
            'condition'                => $row['condition']                ?? null,
            'distance_to_subject_m'    => $row['distance_to_subject_m']    ?? null,
            'latitude'                 => $row['latitude']                 ?? null,
            'longitude'                => $row['longitude']                ?? null,
            'raw_row_json'             => $row,
        ];
    }
}
