<?php

namespace App\Services\Presentations\Evidence;

use App\Models\PresentationActiveListing;
use App\Models\PresentationListingPriceHistory;

/**
 * Normalises parsed listing rows and upserts them into presentation_active_listings
 * with deterministic dedupe keys and lifecycle tracking (Phase B1).
 *
 * Deterministic only. No AI. No guesswork.
 *
 * Dedupe strategy (in order):
 *   1. external_key  — "{platform}:{external_id}" when the parser supplies an external_id
 *      Platform prefix is shared across search+listing (e.g. "p24:" for both p24_search and p24_listing)
 *      so the same property dedupes across source types.
 *   2. fingerprint   — SHA-256 of normalised fields when no external_id is available
 *
 * Source rank (lower = more authoritative):
 *   internal_import = 5, p24_listing = 10, private_property_listing = 10,
 *   p24_search = 20, private_property_search = 20, other = 50
 */
class ListingNormalizationService
{
    public const SERVICE_VERSION = 'listing_norm_v1';

    /** Source rank map — lower = better quality */
    private const SOURCE_RANKS = [
        'internal_import'           => 5,
        'p24_listing'               => 10,
        'private_property_listing'  => 10,
        'p24_search'                => 20,
        'private_property_search'   => 20,
        'private_property'          => 20,
    ];

    /** Platform prefix map — shared across search+listing so cross-source dedupe works */
    private const PLATFORM_PREFIXES = [
        'p24_search'                => 'p24',
        'p24_listing'               => 'p24',
        'private_property_search'   => 'pp',
        'private_property_listing'  => 'pp',
        'private_property'          => 'pp',
        'internal_import'           => 'internal',
    ];

    /**
     * Build the external_key for a row, or null if no external_id.
     *
     * Format: "{platform}:{external_id}"
     * Uses platform prefix (not source_type) so search + listing pages share keys.
     */
    public function buildExternalKey(array $row, string $sourceType): ?string
    {
        $externalId = $row['external_id'] ?? null;

        if ($externalId === null || (string) $externalId === '') {
            return null;
        }

        $platform = self::PLATFORM_PREFIXES[$sourceType] ?? $sourceType;

        return $platform . ':' . $externalId;
    }

    /**
     * Build a fingerprint hash from normalised fields when no external_id is available.
     *
     * Inputs: suburb (lowered), property_type, beds, baths, size_m2, price bucket (5k).
     * Price bucket: round to nearest R5 000 for fingerprinting only.
     */
    public function buildFingerprint(array $row): string
    {
        $suburb       = mb_strtolower(trim($row['suburb'] ?? ''));
        $propertyType = mb_strtolower(trim($row['property_type'] ?? ''));
        $beds         = isset($row['beds']) ? (int) $row['beds'] : '';
        $baths        = isset($row['baths']) ? (int) $row['baths'] : '';
        $sizeM2       = isset($row['size_m2']) ? (int) $row['size_m2'] : '';
        $priceBucket  = isset($row['list_price_inc']) ? $this->priceBucket((int) $row['list_price_inc']) : '';

        $seed = implode('|', [$suburb, $propertyType, $beds, $baths, $sizeM2, $priceBucket]);

        return hash('sha256', $seed);
    }

    /**
     * Resolve source rank for a source type. Lower = more authoritative.
     */
    public function sourceRank(string $sourceType): int
    {
        return self::SOURCE_RANKS[$sourceType] ?? 50;
    }

    /**
     * Upsert a normalised listing row.
     *
     * Rules:
     *  - If match found by external_key (presentation_id + external_key):
     *      - Update last_seen_at, is_active = true
     *      - If new source_rank is better (lower) or fields are more complete, merge
     *  - Else if match found by fingerprint (presentation_id + fingerprint):
     *      - Same merge rules
     *  - Else insert new row
     *
     * Never wipes existing non-null fields with null.
     */
    public function upsertNormalizedRow(
        int     $presentationId,
        ?int    $snapshotId,
        array   $row,
        string  $sourceType,
        string  $parserVersion,
        string  $extractionMethod,
    ): PresentationActiveListing {
        $externalKey = $this->buildExternalKey($row, $sourceType);
        $fingerprint = $this->buildFingerprint($row);
        $rank        = $this->sourceRank($sourceType);
        $now         = now();

        // ── Try to find existing by external_key ──────────────────────────
        $existing = null;

        if ($externalKey !== null) {
            $existing = PresentationActiveListing::where('presentation_id', $presentationId)
                ->where('external_key', $externalKey)
                ->first();
        }

        // ── Fallback: try fingerprint ─────────────────────────────────────
        if ($existing === null) {
            $existing = PresentationActiveListing::where('presentation_id', $presentationId)
                ->where('fingerprint', $fingerprint)
                ->first();
        }

        if ($existing !== null) {
            return $this->mergeIntoExisting($existing, $row, $snapshotId, $externalKey, $fingerprint, $rank, $parserVersion, $extractionMethod, $now);
        }

        // ── Insert new ────────────────────────────────────────────────────
        $listing = PresentationActiveListing::create([
            'presentation_id'    => $presentationId,
            'source_upload_id'   => null,
            'source_snapshot_id' => $snapshotId,
            'listing_date'       => $row['listing_date'] ?? null,
            'list_price_inc'     => isset($row['list_price_inc']) ? (int) $row['list_price_inc'] : null,
            'suburb'             => $row['suburb'] ?? null,
            'property_type'      => $row['property_type'] ?? null,
            'beds'               => isset($row['beds']) ? (int) $row['beds'] : null,
            'baths'              => isset($row['baths']) ? (int) $row['baths'] : null,
            'size_m2'            => isset($row['size_m2']) ? (int) $row['size_m2'] : null,
            'status'             => 'active',
            'raw_row_json'       => json_encode($row['raw_data'] ?? $row, JSON_THROW_ON_ERROR),
            'parser_version'     => $parserVersion,
            'extraction_method'  => $extractionMethod,
            'external_key'       => $externalKey,
            'fingerprint'        => $fingerprint,
            'first_seen_at'      => $now,
            'last_seen_at'       => $now,
            'is_active'          => true,
            'source_rank'        => $rank,
        ]);

        // Record initial price in history (feature-flagged)
        if (config('features.listing_lifecycle_v1') && $listing->list_price_inc !== null) {
            $this->recordPriceHistory($listing, $listing->list_price_inc, $snapshotId, $now);
        }

        // Compute data quality score for new listing (feature-flagged)
        if (config('features.listing_data_quality_v1')) {
            $listing->update([
                'data_quality_score' => $this->computeDataQualityScore($listing),
                'merge_confidence'   => 100,
            ]);
            $listing = $listing->fresh();
        }

        return $listing;
    }

    /**
     * Deactivation sweep: after ingesting a search snapshot, mark previously-active
     * listings that were NOT seen in this batch as inactive.
     *
     * @param  int      $presentationId
     * @param  string   $sourceTypeFamily  e.g. 'p24_search'
     * @param  array    $seenExternalKeys  external_keys seen in current snapshot
     * @param  array    $seenFingerprints  fingerprints seen in current snapshot (for rows without external_key)
     * @return int      Number of rows deactivated
     */
    public function deactivateMissing(
        int    $presentationId,
        string $sourceTypeFamily,
        array  $seenExternalKeys,
        array  $seenFingerprints,
    ): int {
        $rankRange = $this->rankRangeForFamily($sourceTypeFamily);

        $query = PresentationActiveListing::where('presentation_id', $presentationId)
            ->where('is_active', true)
            ->whereBetween('source_rank', $rankRange);

        // Exclude rows we just saw
        if (!empty($seenExternalKeys)) {
            $query->where(function ($q) use ($seenExternalKeys, $seenFingerprints) {
                $q->whereNotIn('external_key', $seenExternalKeys);
                if (!empty($seenFingerprints)) {
                    $q->whereNotIn('fingerprint', $seenFingerprints);
                }
            });
        } elseif (!empty($seenFingerprints)) {
            $query->whereNotIn('fingerprint', $seenFingerprints);
        }

        return $query->update(['is_active' => false]);
    }

    /**
     * Round price to nearest R5 000 bucket for fingerprinting only.
     */
    public function priceBucket(int $price): int
    {
        return (int) (round($price / 5000) * 5000);
    }

    /**
     * Get the source_rank range for a source type family (for deactivation sweep).
     * Search sources are rank 20-29; listing sources are rank 10-19.
     *
     * @return array [min, max]
     */
    private function rankRangeForFamily(string $sourceTypeFamily): array
    {
        return match ($sourceTypeFamily) {
            'p24_search', 'private_property_search', 'private_property' => [20, 29],
            'p24_listing', 'private_property_listing'                   => [10, 19],
            'internal_import'                                            => [1, 9],
            default                                                      => [0, 99],
        };
    }

    /**
     * Merge new row data into an existing record.
     * Never overwrites non-null with null.
     * Upgrades source if new rank is better.
     */
    private function mergeIntoExisting(
        PresentationActiveListing $existing,
        array   $row,
        ?int    $snapshotId,
        ?string $externalKey,
        string  $fingerprint,
        int     $rank,
        string  $parserVersion,
        string  $extractionMethod,
        $now,
    ): PresentationActiveListing {
        $updates = [
            'last_seen_at' => $now,
            'is_active'    => true,
        ];

        // Promote external_key if we now have one and existing didn't
        if ($externalKey !== null && $existing->external_key === null) {
            $updates['external_key'] = $externalKey;
        }

        // Always keep best fingerprint
        $updates['fingerprint'] = $fingerprint;

        $isBetterSource = $rank < $existing->source_rank;

        // Fill missing fields (never overwrite non-null with null)
        $fillable = [
            'listing_date'   => $row['listing_date'] ?? null,
            'list_price_inc' => isset($row['list_price_inc']) ? (int) $row['list_price_inc'] : null,
            'suburb'         => $row['suburb'] ?? null,
            'property_type'  => $row['property_type'] ?? null,
            'beds'           => isset($row['beds']) ? (int) $row['beds'] : null,
            'baths'          => isset($row['baths']) ? (int) $row['baths'] : null,
            'size_m2'        => isset($row['size_m2']) ? (int) $row['size_m2'] : null,
        ];

        foreach ($fillable as $field => $newValue) {
            if ($newValue === null) {
                continue; // never overwrite with null
            }
            $currentValue = $existing->getAttribute($field);
            if ($currentValue === null || $isBetterSource) {
                $updates[$field] = $newValue;
            }
        }

        // If better source, also update raw_row_json and metadata
        if ($isBetterSource) {
            $updates['source_rank']       = $rank;
            $updates['raw_row_json']      = json_encode($row['raw_data'] ?? $row, JSON_THROW_ON_ERROR);
            $updates['parser_version']    = $parserVersion;
            $updates['extraction_method'] = $extractionMethod;
            if ($snapshotId !== null) {
                $updates['source_snapshot_id'] = $snapshotId;
            }
        }

        // Track price change before updating (feature-flagged)
        if (config('features.listing_lifecycle_v1')) {
            $newPrice = isset($row['list_price_inc']) ? (int) $row['list_price_inc'] : null;
            if ($newPrice !== null && $newPrice !== (int) $existing->list_price_inc) {
                $this->recordPriceHistory($existing, $newPrice, $snapshotId, $now);
            }
        }

        // Compute merge confidence + conflict flags + data quality (feature-flagged)
        if (config('features.listing_data_quality_v1')) {
            $qualityResult = $this->computeMergeQuality($existing, $row);
            $updates['merge_confidence']   = $qualityResult['merge_confidence'];
            $updates['conflict_flags_json'] = $qualityResult['conflict_flags'];
        }

        $existing->update($updates);

        // Recompute data quality score after merge (needs fresh model with updated fields)
        if (config('features.listing_data_quality_v1')) {
            $fresh = $existing->fresh();
            $fresh->update(['data_quality_score' => $this->computeDataQualityScore($fresh)]);
            return $fresh->fresh();
        }

        return $existing->fresh();
    }

    /**
     * Record a price snapshot in the listing price history table.
     */
    private function recordPriceHistory(
        PresentationActiveListing $listing,
        int $priceInc,
        ?int $snapshotId,
        $capturedAt,
    ): void {
        PresentationListingPriceHistory::create([
            'presentation_id'    => $listing->presentation_id,
            'active_listing_id'  => $listing->id,
            'price_inc'          => $priceInc,
            'captured_at'        => $capturedAt,
            'source_snapshot_id' => $snapshotId,
        ]);
    }

    /**
     * Compute merge confidence and conflict flags when merging two sources.
     *
     * Merge confidence starts at 100 and is reduced by detected conflicts.
     * Deterministic only.
     *
     * @return array{merge_confidence: int, conflict_flags: array}
     */
    public function computeMergeQuality(PresentationActiveListing $existing, array $row): array
    {
        $confidence = 100;
        $flags      = [];

        // Price conflict: -20
        $newPrice      = isset($row['list_price_inc']) ? (int) $row['list_price_inc'] : null;
        $existingPrice = $existing->list_price_inc;
        $priceConflict = $newPrice !== null && $existingPrice !== null && $newPrice !== $existingPrice;
        $flags['price_conflict'] = $priceConflict;
        if ($priceConflict) {
            $confidence -= 20;
        }

        // Beds conflict: -10
        $newBeds      = isset($row['beds']) ? (int) $row['beds'] : null;
        $existingBeds = $existing->beds;
        $bedsConflict = $newBeds !== null && $existingBeds !== null && $newBeds !== $existingBeds;
        $flags['beds_conflict'] = $bedsConflict;
        if ($bedsConflict) {
            $confidence -= 10;
        }

        // Baths conflict: -10
        $newBaths      = isset($row['baths']) ? (int) $row['baths'] : null;
        $existingBaths = $existing->baths;
        $bathsConflict = $newBaths !== null && $existingBaths !== null && $newBaths !== $existingBaths;
        $flags['baths_conflict'] = $bathsConflict;
        if ($bathsConflict) {
            $confidence -= 10;
        }

        // Size conflict (>10% diff): -10
        $newSize      = isset($row['size_m2']) ? (int) $row['size_m2'] : null;
        $existingSize = $existing->size_m2;
        $sizeConflict = false;
        if ($newSize !== null && $existingSize !== null && $existingSize > 0) {
            $sizeConflict = abs($newSize - $existingSize) / $existingSize > 0.10;
        }
        $flags['size_conflict'] = $sizeConflict;
        if ($sizeConflict) {
            $confidence -= 10;
        }

        // Suburb mismatch: -15
        $newSuburb      = isset($row['suburb']) ? mb_strtolower(trim($row['suburb'])) : null;
        $existingSuburb = $existing->suburb !== null ? mb_strtolower(trim($existing->suburb)) : null;
        $suburbConflict = $newSuburb !== null && $existingSuburb !== null && $newSuburb !== $existingSuburb;
        $flags['suburb_conflict'] = $suburbConflict;
        if ($suburbConflict) {
            $confidence -= 15;
        }

        return [
            'merge_confidence' => max(0, $confidence),
            'conflict_flags'   => $flags,
        ];
    }

    /**
     * Compute a data quality score for a listing based on field completeness.
     *
     * Scoring:
     *   price present       +20
     *   beds present        +15
     *   baths present       +15
     *   size present        +20
     *   external_id present +10
     *   seen in >1 source   +10  (source_rank < 50 AND merge has occurred)
     *   has price history   +10  (>1 price history record)
     *
     * Capped at 100. Deterministic only.
     */
    public function computeDataQualityScore(PresentationActiveListing $listing): int
    {
        $score = 0;

        if ($listing->list_price_inc !== null) {
            $score += 20;
        }
        if ($listing->beds !== null) {
            $score += 15;
        }
        if ($listing->baths !== null) {
            $score += 15;
        }
        if ($listing->size_m2 !== null) {
            $score += 20;
        }
        if ($listing->external_key !== null) {
            $score += 10;
        }

        // Seen in >1 source: merge_confidence is set (meaning a merge occurred)
        if ($listing->merge_confidence !== null && $listing->merge_confidence < 100) {
            $score += 10;
        }

        // Has price history (>1 record means price was seen at different points)
        $historyCount = PresentationListingPriceHistory::where('active_listing_id', $listing->id)->count();
        if ($historyCount > 1) {
            $score += 10;
        }

        return min(100, $score);
    }
}
