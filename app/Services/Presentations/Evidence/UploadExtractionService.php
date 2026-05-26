<?php

namespace App\Services\Presentations\Evidence;

use App\Models\Presentation;
use App\Models\PresentationActiveListing;
use App\Models\PresentationField;
use App\Models\PresentationSoldComp;
use App\Models\PresentationUpload;
use App\Services\Presentations\Evidence\Parsers\CmaParserV1;
use App\Services\Presentations\Evidence\Parsers\SalesReportParserV1;
use App\Services\Presentations\Evidence\Parsers\SuburbStockParserV1;
use App\Services\Presentations\Evidence\Parsers\UnknownParser;
use App\Support\Presentation\DocumentExtractor;
use Illuminate\Support\Facades\DB;

/**
 * Routes a PresentationUpload to the correct deterministic parser,
 * runs it, and persists extraction_json on the upload record.
 *
 * Called sync after UploadProcessor has already set text_extracted
 * and extraction_status on the upload.
 *
 * Never throws. Never calls AI.
 */
class UploadExtractionService
{
    public const SERVICE_VERSION = 'extraction_service_v1';

    public function run(PresentationUpload $upload): void
    {
        // Detect type: use upload's type if already set to a known value, otherwise auto-detect
        $uploadType = $upload->type;
        $needsAutoDetect = in_array($uploadType, ['unknown', 'auto', 'application/pdf', null, ''], true);

        if ($needsAutoDetect) {
            $uploadType = $this->detectDocumentType(
                $upload->original_filename ?? '',
                $upload->text_extracted
            );
            // Update the upload type column with detected type
            if ($uploadType !== 'unknown') {
                $upload->update(['type' => $uploadType]);
            }
        }

        $docType = $this->mapToParserType($uploadType);

        // If text extraction failed, try PHP-based extraction via DocumentExtractor
        if ($upload->extraction_status !== 'ok' && config('features.presentation_doc_extract_v1', false)) {
            $extractor = new DocumentExtractor();
            $fields = $extractor->extract($upload);
            $upload->refresh();

            // If DocumentExtractor recovered text, proceed with normal parsing
            if ($upload->extraction_status === 'ok' && !empty($upload->text_extracted)) {
                $docType = $this->detectDocType($upload->original_filename ?? '');
            } else {
                $result = [
                    'parser_version' => self::SERVICE_VERSION,
                    'doc_type_guess' => $docType,
                    'parsed_counts'  => [],
                    'errors'         => ['text_extraction_failed'],
                ];
                if (!empty($fields)) {
                    $result['extracted_version'] = DocumentExtractor::EXTRACTED_VERSION;
                    $result['fields'] = $fields;
                }
                $upload->update([
                    'extraction_json'  => $result,
                    'extraction_error' => empty($fields) ? 'Text extraction failed — parser could not read document.' : null,
                    'extraction_status' => empty($fields) ? 'failed' : 'ok',
                    'extracted_at'     => now(),
                ]);

                // Propagate recovered fields into presentation_fields table
                $this->propagateFields($upload);
                $this->propagateRows($upload);
                return;
            }
        } elseif ($upload->extraction_status !== 'ok') {
            $upload->update([
                'extraction_json'  => [
                    'parser_version' => self::SERVICE_VERSION,
                    'doc_type_guess' => $docType,
                    'parsed_counts'  => [],
                    'errors'         => ['text_extraction_failed'],
                ],
                'extraction_error' => 'Text extraction failed — parser could not read document.',
                'extracted_at'     => now(),
            ]);
            return;
        }

        $text = $upload->text_extracted ?? '';

        // Wrap legacy parser in try/catch — never crash the upload pipeline
        try {
            $result = $this->runParser($docType, $text, $upload);
        } catch (\Throwable $e) {
            $result = [
                'parser_version' => self::SERVICE_VERSION,
                'doc_type_guess' => $docType,
                'parsed_counts'  => [],
                'aggregates'     => [],
                'errors'         => ['parser_exception: ' . $e->getMessage()],
            ];
        }

        $hasUseful = $this->hasUsefulData($result);

        // Run doc_extract_v1 if feature flag is on
        if (config('features.presentation_doc_extract_v1', false)) {
            $this->runDocExtractor($upload, $result);
            return;
        }

        $upload->update([
            'extraction_json'   => $result,
            'extraction_status' => $hasUseful ? 'ok' : 'failed',
            'extraction_error'  => $hasUseful ? null : 'No extractable fields found (check PDF format)',
            'extracted_at'      => now(),
        ]);

        // Always propagate comp/listing rows from text (works independently of doc_extract flag)
        if ($hasUseful) {
            $this->propagateRows($upload);
        }
    }

    /**
     * Check if parser result contains any useful extracted data.
     */
    private function hasUsefulData(array $result): bool
    {
        // Check parsed_counts — any value > 0 means rows were extracted
        $counts = $result['parsed_counts'] ?? [];
        foreach ($counts as $v) {
            if (is_int($v) && $v > 0) {
                return true;
            }
        }

        // Check aggregates — any non-null value means summary stats found
        $aggregates = $result['aggregates'] ?? [];
        if (count(array_filter($aggregates, fn ($v) => $v !== null)) > 0) {
            return true;
        }

        // Check CMA suggested_band
        if (!empty($result['suggested_band'])) {
            return true;
        }

        // Check CMA notes
        if (!empty($result['notes']) && is_array($result['notes']) && count($result['notes']) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Determine document type from filename keywords.
     * Deterministic; does not read file contents.
     *
     * @deprecated Use detectDocumentType() which supports content-based fallback.
     */
    public function detectDocType(string $filename): string
    {
        return $this->mapToParserType($this->detectDocumentType($filename));
    }

    /**
     * Auto-detect document type from filename and optionally text content.
     * Returns upload-namespace types: 'suburb_stats', 'vicinity_sales', 'cma', 'unknown'.
     *
     * Method A: Filename matching (fast, tried first).
     * Method B: Content matching (fallback when filename doesn't match).
     */
    public function detectDocumentType(string $filename, ?string $textContent = null): string
    {
        // ── Method A: Filename matching ──
        $lower = mb_strtolower($filename);

        // Suburb Stats: "Median" or "Sales.Analysis" or "median_sales"
        if (str_contains($lower, 'median') || str_contains($lower, 'sales.analysis') || str_contains($lower, 'median_sales')) {
            return 'suburb_stats';
        }

        // Vicinity Sales: "sales.in" or "Residential.sales.in" or "vicinity" or "within"
        // Also match "Sectional" + ("sales" or "within") for sectional title vicinity reports
        if (str_contains($lower, 'sales.in') || str_contains($lower, 'residential.sales.in')
            || str_contains($lower, 'vicinity') || str_contains($lower, 'within')) {
            return 'vicinity_sales';
        }
        if (str_contains($lower, 'sectional') && (str_contains($lower, 'sales') || str_contains($lower, 'within'))) {
            return 'vicinity_sales';
        }

        // CMA: "Valuation" or "Property.Valuation" or "CMA"
        if (str_contains($lower, 'valuation') || str_contains($lower, 'property.valuation') || str_contains($lower, 'cma')) {
            return 'cma';
        }

        // Legacy filename keywords (broader match, lower priority)
        if (str_contains($lower, 'suburb') || str_contains($lower, 'stock')) {
            return 'suburb_stats';
        }

        // Broad "sales" keyword — maps to vicinity_sales (CMA Info vicinity reports contain "sales")
        if (str_contains($lower, 'sales')) {
            return 'vicinity_sales';
        }

        // ── Method B: Content matching (fallback) ──
        if ($textContent !== null && $textContent !== '') {
            return $this->detectFromContent($textContent);
        }

        return 'unknown';
    }

    /**
     * Detect document type from PDF text content.
     * Used as fallback when filename doesn't match known patterns.
     */
    private function detectFromContent(string $text): string
    {
        // Suburb Stats: "Residential Sales Analysis" AND "Median Selling Price"
        if (str_contains($text, 'Residential Sales Analysis') && str_contains($text, 'Median Selling Price')) {
            return 'suburb_stats';
        }

        // Vicinity Sales: "Residential sales within" OR "Sectional Title sales within"
        // OR ("sales within" AND "m of")
        if (str_contains($text, 'Residential sales within')
            || str_contains($text, 'Sectional Title sales within')
            || (str_contains($text, 'sales within') && preg_match('/\d+\s*m\s+of/i', $text))) {
            return 'vicinity_sales';
        }

        // CMA: "PROPERTY INFORMATION" AND ("Comparative Market Analysis" OR "Indexed Value")
        if (str_contains($text, 'PROPERTY INFORMATION')
            && (str_contains($text, 'Comparative Market Analysis') || str_contains($text, 'Indexed Value'))) {
            return 'cma';
        }

        return 'unknown';
    }

    /**
     * Map upload-namespace type to parser DOC_TYPE constant.
     */
    private function mapToParserType(string $uploadType): string
    {
        return match ($uploadType) {
            'vicinity_sales' => SalesReportParserV1::DOC_TYPE,
            'suburb_stats'   => SuburbStockParserV1::DOC_TYPE,
            'cma'            => CmaParserV1::DOC_TYPE,
            default          => UnknownParser::DOC_TYPE,
        };
    }

    /**
     * Run doc_extract_v1: extract structured fields and merge into extraction_json.
     */
    private function runDocExtractor(PresentationUpload $upload, array $parserResult): void
    {
        try {
            $extractor = new DocumentExtractor();
            $fields = $extractor->extract($upload);

            if (!empty($fields)) {
                $parserResult['extracted_version'] = DocumentExtractor::EXTRACTED_VERSION;
                $parserResult['fields'] = $fields;
            }

            $hasUseful = $this->hasUsefulData($parserResult) || !empty($fields);

            $upload->update([
                'extraction_json'   => $parserResult,
                'extraction_status' => $hasUseful ? 'ok' : 'failed',
                'extraction_error'  => $hasUseful ? null : 'No extractable fields found (check PDF format)',
                'extracted_at'      => now(),
            ]);

            // Propagate DocumentExtractor fields into presentation_fields table
            $this->propagateFields($upload);
            $this->propagateRows($upload);
        } catch (\Throwable $e) {
            // Never throw — persist parser result without doc_extract fields
            $parserResult['extraction_error'] = 'doc_extract_v1: ' . $e->getMessage();
            $hasUseful = $this->hasUsefulData($parserResult);

            $upload->update([
                'extraction_json'   => $parserResult,
                'extraction_status' => $hasUseful ? 'ok' : 'failed',
                'extraction_error'  => 'doc_extract_v1 failed: ' . $e->getMessage(),
                'extracted_at'      => now(),
            ]);
        }
    }

    private function runParser(string $docType, string $text, PresentationUpload $upload): array
    {
        return match ($docType) {
            // Parser-namespace types
            SalesReportParserV1::DOC_TYPE  => (new SalesReportParserV1())->parse($text, $upload),
            SuburbStockParserV1::DOC_TYPE  => (new SuburbStockParserV1())->parse($text, $upload),
            CmaParserV1::DOC_TYPE          => (new CmaParserV1())->parse($text, $upload),
            // Upload-namespace types (from detectDocumentType)
            'vicinity_sales'               => (new SalesReportParserV1())->parse($text, $upload),
            'suburb_stats'                 => (new SuburbStockParserV1())->parse($text, $upload),
            default                        => (new UnknownParser())->parse($text),
        };
    }

    /**
     * Propagate extraction_json.fields into the presentation_fields table.
     *
     * Only propagates DocumentExtractor output (extraction_json.fields).
     * Legacy parser aggregates and suggested_band are NOT propagated.
     *
     * Upserts on (presentation_id, field_key):
     *   - extracted_value = value from DocumentExtractor
     *   - override_value  = preserved if already set by agent
     *   - final_value     = coalesce(override_value, extracted_value)
     *   - confidence      = 0.90 (deterministic parser, high confidence)
     *   - source_upload_id = which upload produced the field
     */
    public function propagateFields(PresentationUpload $upload): void
    {
        $json = $upload->extraction_json;
        if (is_string($json)) {
            $json = json_decode($json, true);
        }

        $fields = $json['fields'] ?? [];
        if (empty($fields)) {
            return;
        }

        $presentationId = $upload->presentation_id;

        foreach ($fields as $fieldKey => $value) {
            // Skip null/empty values
            if ($value === null || $value === '') {
                continue;
            }

            $existing = PresentationField::where('presentation_id', $presentationId)
                ->where('field_key', $fieldKey)
                ->first();

            if ($existing) {
                $existing->update([
                    'extracted_value'  => (string) $value,
                    'source_upload_id' => $upload->id,
                    'confidence'       => 0.90,
                    'final_value'      => $existing->override_value ?? (string) $value,
                ]);
            } else {
                PresentationField::create([
                    'presentation_id'  => $presentationId,
                    'field_key'        => $fieldKey,
                    'extracted_value'  => (string) $value,
                    'override_value'   => null,
                    'final_value'      => (string) $value,
                    'source_upload_id' => $upload->id,
                    'confidence'       => 0.90,
                ]);
            }
        }

        // Fire the domain event so subscribers (e.g. PropagateCmaToProperty) can
        // back-propagate the extracted fields to the Property pillar.
        // Spec: .ai/specs/market-intelligence-discovery.md Section 13.4.
        $presentation = DB::table('presentations')->where('id', $presentationId)->first();
        if ($presentation) {
            $agencyId = $presentation->agency_id
                ?? optional(DB::table('branches')->where('id', $presentation->branch_id ?? 0)->first())->agency_id;

            event(new \App\Events\Presentation\PresentationFieldsExtracted(
                presentationId: (int) $presentationId,
                agencyId: $agencyId ? (int) $agencyId : null,
                actorUserId: auth()->id(),
            ));
        }
    }

    /**
     * Extract comp rows and active listings from upload text, persist to DB.
     *
     * Clears existing rows for this upload (by source_upload_id + parser_version prefix)
     * before inserting, so re-extraction is idempotent.
     *
     * Routes by upload type:
     *   - vicinity_sales → extractVicinityRows() → presentation_sold_comps
     *   - cma → extractCmaCompRows() + extractStreetSalesRows() → presentation_sold_comps
     *           + extractCmaActiveListings() → presentation_active_listings
     */
    public function propagateRows(PresentationUpload $upload): void
    {
        $text = $upload->text_extracted ?? '';
        if ($text === '') {
            return;
        }

        $extractor = new DocumentExtractor();
        $presentationId = $upload->presentation_id;

        if ($upload->type === 'vicinity_sales') {
            // Detect sectional title first for correct column layout
            $isSectional = $extractor->isSectionalTitle($text);
            if ($isSectional) {
                $rows = $extractor->extractSectionalVicinityRows($text);
                $parserTag = 'doc_extract_v1:vicinity_sales_sectional';
            } else {
                $rows = $extractor->extractVicinityRows($text);
                $parserTag = 'doc_extract_v1:vicinity_sales';
            }
            $this->persistSoldComps($rows, $presentationId, $upload->id, $parserTag);

            // Set property_type on presentation when sectional detected (if not already set)
            if ($isSectional) {
                $presentation = Presentation::find($presentationId);
                if ($presentation && !in_array($presentation->property_type, ['sectional', 'apartment', 'unit'])) {
                    $presentation->update(['property_type' => 'sectional']);
                }
            }
        } elseif ($upload->type === 'cma') {
            $this->persistSoldComps(
                $extractor->extractCmaCompRows($text),
                $presentationId,
                $upload->id,
                'doc_extract_v1:cma_comps'
            );
            $this->persistSoldComps(
                $extractor->extractStreetSalesRows($text),
                $presentationId,
                $upload->id,
                'doc_extract_v1:street_sales'
            );
            $this->persistActiveListings(
                $extractor->extractCmaActiveListings($text),
                $presentationId,
                $upload->id,
                'doc_extract_v1:cma_active'
            );
        }
    }

    /**
     * Clear + insert sold comp rows for a given parser_version tag.
     */
    private function persistSoldComps(array $rows, int $presentationId, int $uploadId, string $parserVersion): void
    {
        // Clear existing rows from same source
        PresentationSoldComp::where('presentation_id', $presentationId)
            ->where('parser_version', $parserVersion)
            ->delete();

        foreach ($rows as $row) {
            // Parse suburb from address (text after last comma)
            $suburb = null;
            if (!empty($row['address']) && str_contains($row['address'], ',')) {
                $suburb = trim(substr($row['address'], strrpos($row['address'], ',') + 1));
            }

            PresentationSoldComp::create([
                'presentation_id'  => $presentationId,
                'source_upload_id' => $uploadId,
                'sold_date'        => $row['sale_date'] ?? null,
                'sold_price_inc'   => $row['sale_price'] ?? null,
                'suburb'           => $suburb,
                'property_type'    => $row['property_type'] ?? null,
                'size_m2'          => $row['extent_m2'] ?? null,
                'raw_row_json'     => json_encode($row),
                'parser_version'   => $parserVersion,
            ]);
        }
    }

    /**
     * Clear + insert active listing rows for a given parser_version tag.
     */
    private function persistActiveListings(array $rows, int $presentationId, int $uploadId, string $parserVersion): void
    {
        // Clear existing rows from same source
        PresentationActiveListing::where('presentation_id', $presentationId)
            ->where('parser_version', $parserVersion)
            ->delete();

        foreach ($rows as $row) {
            $suburb = null;
            if (!empty($row['address']) && str_contains($row['address'], ',')) {
                $suburb = trim(substr($row['address'], strrpos($row['address'], ',') + 1));
            }

            PresentationActiveListing::create([
                'presentation_id'    => $presentationId,
                'source_upload_id'   => $uploadId,
                'listing_date'       => $row['list_date'] ?? null,
                'list_price_inc'     => $row['list_price'] ?? null,
                'suburb'             => $suburb,
                'property_type'      => $row['property_type'] ?? null,
                'size_m2'            => $row['extent_m2'] ?? null,
                'status'             => 'active',
                'raw_row_json'       => json_encode($row),
                'parser_version'     => $parserVersion,
                'extraction_method'  => 'doc_extract_v1',
                'is_active'          => true,
                'first_seen_at'      => now(),
                'last_seen_at'       => now(),
            ]);
        }
    }
}
