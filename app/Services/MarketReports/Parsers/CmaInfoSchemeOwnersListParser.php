<?php

declare(strict_types=1);

namespace App\Services\MarketReports\Parsers;

use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Services\MarketReports\DTOs\MarketReportParseResult;
use App\Services\MarketReports\DTOs\ParserConfidence;

/**
 * V1 parser for CMA Info "Sectional Title Scheme Owners List" — a single-page
 * roster of every owner registered against a sectional title scheme.
 *
 * Source layout:
 *   Sectional Title Scheme Owners List - MADEIRA GARDENS, UVONGO
 *
 *   Section No  Flat No  Owner's Name                       Extent   Type
 *      1                 MTONGANA SANDILE FREEMAN           130 m²   Residence
 *      1                 MTONGANA COCEKA MAGAQA-            130 m²   Residence
 *      3                 COETZEE DEWALD                     124 m²   Residence
 *      ...
 *
 * Sections may have multiple owners (joint ownership) — each line is its own
 * scheme_owners row. The first column ("Section No") may be blank on the
 * second-owner line; we carry the previous section forward.
 *
 * Spec: Phase 3a build prompt §3.
 */
final class CmaInfoSchemeOwnersListParser extends AbstractCmaInfoParser
{
    public const PARSER_VERSION = 'cma_info_scheme_owners_list_v1';

    public function getReportTypeKey(): string
    {
        return 'cma_info_scheme_owners_list';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function canParse(string $filePath): ParserConfidence
    {
        $text = $this->extractText($filePath);
        if ($text === '') return ParserConfidence::none('empty text');
        if (!$this->looksLikeCmaInfo($text)) return ParserConfidence::none('no CMA Info signature');

        $score = 0.0;
        $reasons = ['cma info signature'];

        if ($this->findHeader($text, 'Sectional Title Scheme Owners List')) {
            $score += 0.8;
            $reasons[] = 'Scheme Owners List header';
        }
        if ($this->findHeader($text, "Owner's Name")) {
            $score += 0.1;
            $reasons[] = 'Owner column header';
        }

        return ParserConfidence::high($score, $reasons);
    }

    public function parse(string $filePath, MarketReport $report): MarketReportParseResult
    {
        $text = $this->extractText($filePath);
        if ($text === '') {
            return new MarketReportParseResult(rawJson: ['note' => 'No text extracted.']);
        }

        $today = now()->toDateString();

        // Header: "Sectional Title Scheme Owners List - MADEIRA GARDENS, UVONGO"
        $schemeName = null;
        $schemeSuburb = null;
        if (preg_match('/Sectional\s+Title\s+Scheme\s+Owners\s+List\s*[-–]\s*([A-Z][A-Z \']{2,40})(?:,\s*([A-Z][A-Z \']{2,40}))?/i', $text, $hm)) {
            $schemeName   = trim($hm[1]);
            $schemeSuburb = isset($hm[2]) ? trim($hm[2]) : null;
        }
        // Resolve report-level suburb from explicit upload value, else
        // header trailing token. Promote to subject_meta for backfill.
        $resolved   = $this->resolveReportSuburb($report->source_suburb, [$schemeSuburb]);
        $suburbNorm = $resolved['normalised'];
        $suburbRaw  = $resolved['raw'];

        $subjectMeta = array_filter([
            'subject_scheme_name' => $schemeName,
            'subject_address'     => $schemeSuburb ? $schemeName . ', ' . $schemeSuburb : null,
            'source_suburb'       => $suburbRaw,
        ], fn ($v) => $v !== null && $v !== '');

        $owners   = [];
        $compRows = [];

        $lines = preg_split('/\r?\n/', $text);
        $currentSection = null;
        $currentFlat    = null;
        $rowIndex = 0;

        foreach ($lines as $line) {
            $trim = rtrim($line);
            if ($trim === '') continue;

            // Skip the header line itself + column header.
            if (stripos($trim, 'Section No') !== false && stripos($trim, "Owner") !== false) {
                continue;
            }
            if (stripos($trim, 'Page ') !== false || stripos($trim, 'Home Finders') !== false) {
                continue;
            }

            // Pattern: optional section + optional flat + owner name + optional "<N> m²" + optional type
            // The "1 MTONGANA ..." rows start with the section number; secondary owners may have no section column.
            if (preg_match(
                '/^\s*(?<sec>\d{1,4})?\s*(?<flat>\d{1,4})?\s*(?<owner>[A-Z][A-Z \'\-]{2,80}?)(?:\s+(?<ext>\d{1,5})\s*m\S?)?(?:\s+(?<type>[A-Z][A-Za-z]{3,20}))?\s*$/u',
                $trim,
                $rm
            )) {
                // The "Section No" only appears on the first owner of a section. Carry forward.
                $section = $rm['sec'] !== '' && $rm['sec'] !== null ? $rm['sec'] : $currentSection;
                $flat    = $rm['flat'] !== '' && $rm['flat'] !== null ? $rm['flat'] : $currentFlat;

                // Owner must look like a person — 2+ tokens, mostly letters.
                $owner = trim($rm['owner']);
                $tokenCount = preg_match_all('/[A-Z][A-Z\'\-]{1,}/', $owner);
                if ($tokenCount < 2 || mb_strlen($owner) < 5) {
                    continue;
                }

                // Skip false-positive "Section No  Flat No  Owner's Name  Extent   Type"
                if (stripos($owner, "Owner's Name") !== false || stripos($owner, 'Extent') !== false) {
                    continue;
                }

                $currentSection = $section;
                $currentFlat    = $flat;

                $owners[] = [
                    'scheme_name'    => $schemeName,
                    'section_number' => $section,
                    'flat_number'    => $flat,
                    'owner_name'     => $owner,
                    'extent_m2'      => isset($rm['ext']) && $rm['ext'] !== '' ? (int) $rm['ext'] : null,
                    'property_type'  => isset($rm['type']) && $rm['type'] !== '' ? $rm['type'] : null,
                ];

                $compRows[] = [
                    'row_index'         => $rowIndex++,
                    'row_type'          => MarketReportCompRow::ROW_OWNER,
                    'scheme_name'       => $schemeName,
                    'section_number'    => $section,
                    'flat_number'       => $flat,
                    'suburb_normalised' => $suburbNorm,
                    'property_type'     => isset($rm['type']) && $rm['type'] !== '' ? $rm['type'] : null,
                    'extent_m2'         => isset($rm['ext']) && $rm['ext'] !== '' ? (int) $rm['ext'] : null,
                    'raw_row_json'      => [
                        'owner_name' => $owner,
                        'raw'        => $trim,
                    ],
                ];
            }
        }

        // Aggregate count metric for the scheme. MarketDataPoint requires
        // exactly ONE of metric_value_(numeric|date|string) — we use numeric
        // here; the scheme name is already on market_reports.subject_scheme_name.
        $points = [];
        if ($schemeName !== null && !empty($owners)) {
            $points[] = [
                'metric_key'           => 'scheme_owner_count',
                'metric_value_numeric' => (float) count($owners),
                'metric_date'          => $today,
                'confidence'           => 'high',
                'suburb_normalised'    => $suburbNorm,
            ];
        }

        return new MarketReportParseResult(
            dataPoints:        $points,
            extractedAddresses: [],
            rawJson:           [
                'parser_version' => self::PARSER_VERSION,
                'scheme_name'    => $schemeName,
                'owner_count'    => count($owners),
            ],
            subjectMeta:       $subjectMeta,
            compRows:          $compRows,
            schemeOwners:      $owners,
        );
    }
}
