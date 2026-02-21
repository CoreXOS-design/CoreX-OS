<?php

namespace App\Services\Presentations\Evidence;

use App\Models\PresentationActiveListing;
use App\Models\PresentationUrlSnapshot;
use App\Services\Presentations\Evidence\Parsers\PrivatePropertyListingParserV1;
use App\Services\Presentations\Evidence\Parsers\PrivatePropertySearchParserV1;
use App\Services\Presentations\Evidence\Parsers\Property24ListingParserV1;
use App\Services\Presentations\Evidence\Parsers\Property24SearchParserV1;

/**
 * Ingests structured listing rows from a stored URL snapshot.
 *
 * Flow:
 *   1. Load snapshot HTML
 *   2. Dispatch to the correct deterministic parser by source_type
 *   3. If zero rows → attempt AI fallback (gated by feature flag)
 *   4. Upsert rows via ListingNormalizationService (idempotent — Phase B1)
 *   5. Run deactivation sweep for search snapshots
 *   6. Return summary array
 *
 * When listing_dedupe_v1 feature flag is disabled, falls back to prior
 * insert-per-row behaviour (no upsert, no deactivation).
 *
 * Supported source_types: p24_search, p24_listing,
 *                         private_property_search, private_property_listing
 */
class UrlIngestionService
{
    public function __construct(
        private AIExtractionService $ai = new AIExtractionService(),
        private ?ListingNormalizationService $normalizer = null,
    ) {
        $this->normalizer ??= new ListingNormalizationService();
    }

    /**
     * Ingest listings from a stored URL snapshot.
     *
     * @return array{
     *   source_type: string,
     *   snapshot_id: int,
     *   extraction_method: string,
     *   rows_extracted: int,
     *   rows_persisted: int,
     *   rows_updated: int,
     *   rows_deactivated: int,
     *   skipped: bool,
     *   skip_reason: string|null
     * }
     */
    public function ingest(int $presentationId, int $snapshotId): array
    {
        $snapshot   = PresentationUrlSnapshot::findOrFail($snapshotId);
        $html       = $snapshot->snapshot_html ?? '';
        $sourceType = $snapshot->source_type;

        $parser = $this->resolveParser($sourceType);

        if ($parser === null) {
            return $this->skipResult($sourceType, $snapshotId, 'no_parser_for_source_type');
        }

        if (empty($html)) {
            return $this->skipResult($sourceType, $snapshotId, 'empty_html');
        }

        // ── Deterministic extraction ─────────────────────────────────────────
        $rows             = $parser->parseHtml($html);
        $extractionMethod = 'deterministic_v1';

        // ── AI fallback (only when zero rows and feature is enabled) ─────────
        if (count($rows) === 0 && config('features.p24_ingestion', false)) {
            $suburb  = '';
            $aiRows  = $this->ai->extractListings($html, $sourceType, $suburb);
            if (count($aiRows) > 0) {
                $rows             = $aiRows;
                $extractionMethod = 'ai_v1';
            }
        }

        // ── Persist (dedupe-aware when feature flag is on) ──────────────────
        $dedupeEnabled = config('features.listing_dedupe_v1', false);

        if ($dedupeEnabled) {
            return $this->persistWithDedupe(
                $presentationId, $snapshotId, $sourceType,
                $rows, $extractionMethod, $parser::PARSER_VERSION,
            );
        }

        return $this->persistLegacy(
            $presentationId, $snapshotId, $sourceType,
            $rows, $extractionMethod, $parser::PARSER_VERSION,
        );
    }

    /**
     * Dedupe-aware persist via ListingNormalizationService (Phase B1).
     */
    private function persistWithDedupe(
        int    $presentationId,
        int    $snapshotId,
        string $sourceType,
        array  $rows,
        string $extractionMethod,
        string $parserVersion,
    ): array {
        $persisted      = 0;
        $updated        = 0;
        $seenExtKeys    = [];
        $seenPrints     = [];

        foreach ($rows as $row) {
            try {
                $extKey = $this->normalizer->buildExternalKey($row, $sourceType);
                $fp     = $this->normalizer->buildFingerprint($row);

                // Track what we've seen for deactivation sweep
                if ($extKey !== null) {
                    $seenExtKeys[] = $extKey;
                }
                $seenPrints[] = $fp;

                // Check if this is an update or new insert
                $existsBefore = $this->existsByKey($presentationId, $extKey, $fp);

                $this->normalizer->upsertNormalizedRow(
                    $presentationId, $snapshotId, $row,
                    $sourceType, $parserVersion, $extractionMethod,
                );

                if ($existsBefore) {
                    $updated++;
                } else {
                    $persisted++;
                }
            } catch (\Throwable) {
                // Continue — partial success is acceptable
            }
        }

        // ── Deactivation sweep (search snapshots only) ──────────────────────
        $deactivated = 0;
        $searchTypes = ['p24_search', 'private_property_search', 'private_property'];
        if (in_array($sourceType, $searchTypes, true) && (count($seenExtKeys) > 0 || count($seenPrints) > 0)) {
            $deactivated = $this->normalizer->deactivateMissing(
                $presentationId, $sourceType, $seenExtKeys, $seenPrints,
            );
        }

        return [
            'source_type'       => $sourceType,
            'snapshot_id'       => $snapshotId,
            'extraction_method' => $extractionMethod,
            'rows_extracted'    => count($rows),
            'rows_persisted'    => $persisted,
            'rows_updated'      => $updated,
            'rows_deactivated'  => $deactivated,
            'skipped'           => false,
            'skip_reason'       => null,
        ];
    }

    /**
     * Legacy persist — direct insert, no dedupe (feature flag off).
     */
    private function persistLegacy(
        int    $presentationId,
        int    $snapshotId,
        string $sourceType,
        array  $rows,
        string $extractionMethod,
        string $parserVersion,
    ): array {
        $count = 0;
        foreach ($rows as $row) {
            try {
                PresentationActiveListing::create([
                    'presentation_id'   => $presentationId,
                    'source_upload_id'  => null,
                    'source_snapshot_id'=> $snapshotId,
                    'listing_date'      => $row['listing_date'] ?? null,
                    'list_price_inc'    => isset($row['list_price_inc']) ? (int) $row['list_price_inc'] : null,
                    'suburb'            => $row['suburb'] ?? null,
                    'property_type'     => $row['property_type'] ?? null,
                    'beds'              => isset($row['beds']) ? (int) $row['beds'] : null,
                    'baths'             => isset($row['baths']) ? (int) $row['baths'] : null,
                    'size_m2'           => isset($row['size_m2']) ? (int) $row['size_m2'] : null,
                    'status'            => 'active',
                    'raw_row_json'      => json_encode($row['raw_data'] ?? $row, JSON_THROW_ON_ERROR),
                    'parser_version'    => $parserVersion,
                    'extraction_method' => $extractionMethod,
                ]);
                $count++;
            } catch (\Throwable) {
                // Continue — partial success is acceptable
            }
        }

        return [
            'source_type'       => $sourceType,
            'snapshot_id'       => $snapshotId,
            'extraction_method' => $extractionMethod,
            'rows_extracted'    => count($rows),
            'rows_persisted'    => $count,
            'rows_updated'      => 0,
            'rows_deactivated'  => 0,
            'skipped'           => false,
            'skip_reason'       => null,
        ];
    }

    /**
     * Check if a row already exists by external_key or fingerprint.
     */
    private function existsByKey(int $presentationId, ?string $externalKey, string $fingerprint): bool
    {
        if ($externalKey !== null) {
            $exists = PresentationActiveListing::where('presentation_id', $presentationId)
                ->where('external_key', $externalKey)
                ->exists();
            if ($exists) {
                return true;
            }
        }

        return PresentationActiveListing::where('presentation_id', $presentationId)
            ->where('fingerprint', $fingerprint)
            ->exists();
    }

    /**
     * Build a skip result array.
     */
    private function skipResult(string $sourceType, int $snapshotId, string $reason): array
    {
        return [
            'source_type'       => $sourceType,
            'snapshot_id'       => $snapshotId,
            'extraction_method' => 'none',
            'rows_extracted'    => 0,
            'rows_persisted'    => 0,
            'rows_updated'      => 0,
            'rows_deactivated'  => 0,
            'skipped'           => true,
            'skip_reason'       => $reason,
        ];
    }

    /**
     * Returns the appropriate parser for the given source_type, or null.
     */
    private function resolveParser(string $sourceType): object|null
    {
        return match ($sourceType) {
            'p24_search'                => new Property24SearchParserV1(),
            'p24_listing'               => new Property24ListingParserV1(),
            'private_property_search',
            'private_property'          => new PrivatePropertySearchParserV1(),
            'private_property_listing'  => new PrivatePropertyListingParserV1(),
            default                     => null,
        };
    }
}
