<?php

namespace App\Services\Presentations\Evidence;

use App\Models\PresentationActiveListing;
use App\Models\PresentationLink;
use App\Services\Presentations\UrlSnapshotService;

/**
 * Deterministic extractor for PresentationLink records.
 *
 * For known listing sites (Property24, PrivateProperty):
 *   1. Classifies URL as listing vs search results
 *   2. Fetches + snapshots HTML via UrlSnapshotService
 *   3. Ingests listings via UrlIngestionService
 *   4. Summarises extracted data into extracted_json
 *
 * For other URLs: packages existing metadata fields.
 *
 * Never throws. Never calls AI.
 */
class LinkExtractionService
{
    public const SERVICE_VERSION = 'link_extraction_v2';

    public function run(PresentationLink $link): void
    {
        // Feature-flag guard: when disabled, skip entirely
        if (!config('features.presentation_link_extraction_v2', true)) {
            return;
        }

        try {
            $extracted = $this->extract($link);

            // Check if we got any useful data beyond base keys
            $hasUseful = $this->hasUsefulData($extracted);

            if ($hasUseful) {
                $link->update([
                    'extraction_status' => 'ok',
                    'extracted_json'    => $extracted,
                    'extracted_at'      => now(),
                    'extraction_error'  => null,
                ]);
            } else {
                // Snapshot fetch issues (blocked, timed out, service offline) → 'pending' (retryable)
                // Genuine extraction failures (HTML fetched, no data found) → 'failed'
                $isSnapshotIssue = !empty($extracted['snapshot_error']) || !empty($extracted['blocked_reason']) || !empty($extracted['timed_out']);
                $link->update([
                    'extraction_status' => $isSnapshotIssue ? 'pending' : 'failed',
                    'extracted_json'    => $extracted,
                    'extracted_at'      => now(),
                    'extraction_error'  => $extracted['snapshot_error'] ?? 'No extractable fields found (check link type / URL)',
                ]);
            }
        } catch (\Throwable $e) {
            // Exception during extraction → keep pending (retryable), log error
            $link->update([
                'extraction_status' => 'pending',
                'extraction_error'  => $e->getMessage(),
                'extracted_at'      => now(),
            ]);
        }
    }

    private function extract(PresentationLink $link): array
    {
        $classification = $this->classifyUrl($link->url);

        $base = [
            'extractor_version' => self::SERVICE_VERSION,
            'link_type'         => $link->type,
            'url'               => $link->url,
            'source_domain'     => $this->extractDomain($link->url),
            'source_site'       => $classification['site'],
            'link_subtype'      => $classification['subtype'],
        ];

        // For known listing sites, try snapshot-based extraction
        if ($classification['site'] !== 'other') {
            $snapshotData = $this->extractFromSnapshot($link, $classification);
            if (!empty($snapshotData)) {
                return array_merge($base, $snapshotData);
            }
        }

        // Fall back to metadata-based extraction
        return match ($link->type) {
            'property24', 'active_listing', 'competitor_listing' => array_merge($base, $this->extractListing($link)),
            'market_article'                                     => array_merge($base, $this->extractArticle($link)),
            default                                              => array_merge($base, $this->extractGeneric($link)),
        };
    }

    /**
     * Fetch URL via UrlSnapshotService, ingest via UrlIngestionService,
     * then summarise the extracted rows into a flat array for extracted_json.
     */
    private function extractFromSnapshot(PresentationLink $link, array $classification): array
    {
        $sourceType = $this->resolveSourceType($classification);
        if ($sourceType === null) {
            return [];
        }

        try {
            $snapshotService  = new UrlSnapshotService();
            $snapshot         = $snapshotService->storeSnapshot($link->presentation_id, $link->url, $sourceType);

            // Check if the snapshot was blocked
            if ($snapshot->blocked_reason) {
                return [
                    'snapshot_error'  => $snapshot->blocked_reason,
                    'http_status'     => $snapshot->http_status,
                    'blocked_reason'  => $snapshot->blocked_reason,
                    'timed_out'       => $snapshot->timed_out,
                    'content_bytes'   => $snapshot->content_bytes,
                ];
            }

            if (empty($snapshot->snapshot_html)) {
                return [
                    'snapshot_error' => $snapshot->timed_out
                        ? 'Connection timed out'
                        : 'Could not fetch URL (empty response)',
                    'http_status'    => $snapshot->http_status,
                    'timed_out'      => $snapshot->timed_out,
                ];
            }

            $ingestionService = new UrlIngestionService();
            $ingestion        = $ingestionService->ingest($link->presentation_id, $snapshot->id);

            if ($ingestion['skipped'] ?? false) {
                return ['snapshot_error' => $ingestion['skip_reason'] ?? 'Ingestion skipped'];
            }

            $result = [
                'snapshot_id'       => $snapshot->id,
                'extraction_method' => $ingestion['extraction_method'] ?? 'none',
                'rows_extracted'    => $ingestion['rows_extracted'] ?? 0,
                'http_status'       => $snapshot->http_status,
            ];

            // Build summary from persisted rows
            if ($classification['subtype'] === 'listing') {
                $result = array_merge($result, $this->summariseListing($link->presentation_id, $snapshot->id));
            } elseif ($classification['subtype'] === 'search_results') {
                $result = array_merge($result, $this->summariseSearch($link->presentation_id, $snapshot->id, $ingestion));
            }

            return $result;
        } catch (\Throwable $e) {
            return ['snapshot_error' => 'Fetch failed: ' . $e->getMessage()];
        }
    }

    /**
     * Summarise a single listing extraction — price, beds, baths, size, suburb.
     */
    private function summariseListing(int $presentationId, int $snapshotId): array
    {
        $row = PresentationActiveListing::where('presentation_id', $presentationId)
            ->where('source_snapshot_id', $snapshotId)
            ->first();

        if (!$row) {
            return [];
        }

        return array_filter([
            'asking_price'  => $row->list_price_inc,
            'beds'          => $row->beds,
            'baths'         => $row->baths,
            'floor_area_m2' => $row->size_m2,
            'suburb'        => $row->suburb,
            'property_type' => $row->property_type,
        ], fn ($v) => $v !== null);
    }

    /**
     * Summarise search results — count, price stats, top listings.
     */
    private function summariseSearch(int $presentationId, int $snapshotId, array $ingestion): array
    {
        $rows = PresentationActiveListing::where('presentation_id', $presentationId)
            ->where('source_snapshot_id', $snapshotId)
            ->whereNotNull('list_price_inc')
            ->orderBy('list_price_inc')
            ->get();

        $result = [
            'results_count' => $ingestion['rows_extracted'] ?? $rows->count(),
        ];

        if ($rows->isNotEmpty()) {
            $prices = $rows->pluck('list_price_inc')->sort()->values();
            $result['price_min']    = $prices->first();
            $result['price_max']    = $prices->last();
            $result['price_median'] = $prices->median();

            // Top 5 listings preview
            $top = $rows->take(5)->map(fn ($r) => array_filter([
                'price'  => $r->list_price_inc,
                'beds'   => $r->beds,
                'suburb' => $r->suburb,
            ], fn ($v) => $v !== null))->toArray();

            if (!empty($top)) {
                $result['top_listings'] = array_values($top);
            }
        }

        return $result;
    }

    /**
     * Classify a URL as listing vs search results for a known property site.
     *
     * Property24 listing pages: /for-sale/.../6359/116765021
     *   - Area code is 4 digits, listing ID is 6+ digits at end of path
     * Property24 search pages: /for-sale/.../6359 (no trailing listing ID)
     *
     * @return array{site: string, subtype: string}
     */
    public function classifyUrl(string $url): array
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        // Property24 (both .com and .co.za domains)
        if (str_contains($host, 'property24.co') || str_contains($host, 'property24.com')) {
            // Listing: path ends with /digits where digits is 6+ chars (listing ID)
            // e.g. /for-sale/uvongo/margate/kwazulu-natal/6359/116765021
            if (preg_match('#/(\d{6,})(?:\?|$)#', $path, $m)) {
                return ['site' => 'property24', 'subtype' => 'listing'];
            }
            // Search results: /for-sale/ without a listing ID at end
            if (str_contains($path, '/for-sale/') || str_contains($path, '/to-rent/')) {
                return ['site' => 'property24', 'subtype' => 'search_results'];
            }
            return ['site' => 'property24', 'subtype' => 'unknown'];
        }

        // Private Property
        if (str_contains($host, 'privateproperty.co.za')) {
            if (preg_match('/\/\d{5,}/', $path)) {
                return ['site' => 'private_property', 'subtype' => 'listing'];
            }
            if (str_contains($path, '/for-sale-in-') || str_contains($path, '/to-rent-in-')) {
                return ['site' => 'private_property', 'subtype' => 'search_results'];
            }
            return ['site' => 'private_property', 'subtype' => 'unknown'];
        }

        return ['site' => 'other', 'subtype' => 'unknown'];
    }

    /**
     * Map classification to UrlSnapshotService source_type.
     */
    private function resolveSourceType(array $classification): ?string
    {
        $site    = $classification['site'];
        $subtype = $classification['subtype'];

        if ($site === 'property24') {
            return $subtype === 'listing' ? 'p24_listing' : 'p24_search';
        }

        if ($site === 'private_property') {
            return $subtype === 'listing' ? 'private_property_listing' : 'private_property_search';
        }

        return null;
    }

    /**
     * Check if extracted data has useful fields beyond base metadata.
     */
    private function hasUsefulData(array $extracted): bool
    {
        $baseKeys = ['extractor_version', 'link_type', 'url', 'source_domain', 'source_site', 'link_subtype'];
        $dataKeys = array_diff_key($extracted, array_flip($baseKeys));

        // Filter out error-only results
        $meaningful = array_filter($dataKeys, fn ($v) => $v !== null && $v !== '' && $v !== 'none');

        // snapshot_error / blocked_reason alone don't count as useful
        $errorKeys = ['snapshot_error', 'blocked_reason', 'timed_out', 'http_status', 'content_bytes'];
        $nonErrorKeys = array_diff_key($meaningful, array_flip($errorKeys));

        return count($nonErrorKeys) > 0;
    }

    private function extractListing(PresentationLink $link): array
    {
        return array_filter([
            'asking_price'  => $link->asking_price_inc,
            'beds'          => $link->beds,
            'baths'         => $link->baths,
            'floor_area_m2' => $link->floor_area_m2,
            'erf_m2'        => $link->erf_m2,
            'property_type' => $link->property_type,
            'suburb'        => $link->suburb,
        ], fn ($v) => $v !== null);
    }

    private function extractArticle(PresentationLink $link): array
    {
        return array_filter([
            'headline' => $link->notes,
        ], fn ($v) => $v !== null);
    }

    private function extractGeneric(PresentationLink $link): array
    {
        return array_filter([
            'notes' => $link->notes,
        ], fn ($v) => $v !== null);
    }

    private function extractDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ?: null;
    }
}
