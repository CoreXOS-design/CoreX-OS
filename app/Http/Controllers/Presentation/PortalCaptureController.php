<?php

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\PortalCapture;
use App\Models\PortalListing;
use App\Models\Presentation;
use App\Models\PresentationLink;
use App\Services\Presentations\Evidence\Extractors\Property24SearchExtractorV1;
use App\Services\PortalListingTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PortalCaptureController extends Controller
{
    /**
     * POST /portal-captures/ingest
     * Receives capture payload from the Chrome extension.
     */
    public function ingest(Request $request)
    {
        $validated = $request->validate([
            'source_site'        => 'required|string|max:100',
            'page_type'          => 'required|string|in:search,property,unknown',
            'source_url'         => 'required|url|max:2000',
            'final_url'          => 'required|url|max:2000',
            'page_title'         => 'nullable|string|max:500',
            'captured_at'        => 'required|date',
            'extractor_version'  => 'required|string|max:50',
            'html'               => 'required|string',
            'screenshot'         => 'nullable|string', // base64 PNG
            'presentation_id'    => 'nullable|integer|exists:presentations,id',
            'parse_status'       => 'required|string|in:parsed,unparsed_jsonld_missing,unparsed_error',
            'extracted_fields'   => 'nullable|array',
            'jsonld'             => 'nullable|array',
            'found_image_urls'   => 'nullable|array',
        ]);

        $html      = $validated['html'];
        $htmlBytes  = strlen($html);
        $domHash    = hash('sha256', $html);

        $capture = PortalCapture::create([
            'user_id'                => $request->user()->id,
            'presentation_id'        => $validated['presentation_id'] ?? null,
            'source_site'            => $validated['source_site'],
            'page_type'              => $validated['page_type'],
            'source_url'             => $validated['source_url'],
            'final_url'              => $validated['final_url'],
            'page_title'             => $validated['page_title'] ?? null,
            'captured_at'            => $validated['captured_at'],
            'extractor_version'      => $validated['extractor_version'],
            'dom_hash_sha256'        => $domHash,
            'html_bytes'             => $htmlBytes,
            'raw_html_path'          => '',  // set after ID assigned
            'screenshot_path'        => null,
            'parse_status'           => $validated['parse_status'],
            'extracted_fields_json'  => $validated['extracted_fields'] ?? null,
            'jsonld_json'            => $validated['jsonld'] ?? null,
            'found_image_urls_json'  => $validated['found_image_urls'] ?? null,
        ]);

        // Store raw HTML
        $htmlPath = 'portal_captures/' . $capture->id . '.html';
        Storage::disk('local')->put($htmlPath, $html);
        $capture->raw_html_path = $htmlPath;

        // Store screenshot if provided
        if (!empty($validated['screenshot'])) {
            $pngData = base64_decode($validated['screenshot'], true);
            if ($pngData !== false) {
                $screenshotPath = 'portal_captures/' . $capture->id . '.png';
                Storage::disk('local')->put($screenshotPath, $pngData);
                $capture->screenshot_path = $screenshotPath;
            }
        }

        $capture->save();

        // Refine page_type classification server-side (more reliable than extension guess)
        $classifiedType = $this->classifyPageType($capture);
        if ($classifiedType !== $capture->page_type) {
            $capture->update(['page_type' => $classifiedType]);
        }

        // Run server-side DOM extraction based on classified page type
        $extractionResult = null;
        $trackingSummary = null;

        if ($classifiedType === 'search' && $this->isProperty24Capture($capture)) {
            // SEARCH extraction: extract listing cards from search results
            try {
                $extractor = new Property24SearchExtractorV1();
                $extractionResult = $extractor->extract($capture);

                if ($extractionResult !== null) {
                    // Add search summary fields for UI
                    $extractionResult['_page_type'] = 'search';
                    $extractionResult['listing_urls_count'] = $extractionResult['search']['items_on_page'] ?? 0;

                    // Merge extension's total_result_count if server-side didn't find one
                    $extFields = $validated['extracted_fields'] ?? [];
                    $extTotalCount = isset($extFields['total_result_count']) ? (int) $extFields['total_result_count'] : null;
                    $serverTotalCount = $extractionResult['search']['total_count'] ?? null;

                    if ($serverTotalCount === null && $extTotalCount !== null && $extTotalCount > 0) {
                        $extractionResult['search']['total_count'] = $extTotalCount;
                    }

                    $capture->update([
                        'parse_status'          => 'parsed',
                        'extracted_fields_json' => $extractionResult,
                    ]);

                    // Track listings for identity + change detection
                    $items = $extractionResult['search']['items'] ?? [];
                    if (count($items) > 0) {
                        $sourceSite = $this->normalizeSourceSite($capture->source_site);
                        $tracker = new PortalListingTrackingService();
                        $trackingSummary = $tracker->processItems(
                            $capture,
                            $sourceSite,
                            $items
                        );
                        // Universal Match-or-Create: every captured listing card feeds the TP universe.
                        $this->linkPortalItemsToTrackedProperties($capture, $sourceSite, $items);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Portal search extraction failed', [
                    'capture_id' => $capture->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        } elseif ($classifiedType === 'property' && $this->isProperty24Capture($capture)) {
            // LISTING extraction: extract listing detail fields
            try {
                $listingFields = $this->extractProperty24ListingFields($capture);
                if ($listingFields !== null) {
                    $extractionResult = $listingFields;
                    $capture->update([
                        'parse_status'          => 'parsed',
                        'extracted_fields_json' => $listingFields,
                    ]);

                    // Track listing + write primary_image_url if we have a listing ID
                    $listingId = $listingFields['listing_id'] ?? null;
                    if ($listingId) {
                        $sourceSite = $this->normalizeSourceSite($capture->source_site);
                        $itemForTracking = [
                            'portal_listing_id' => $listingId,
                            'url'               => $listingFields['url'] ?? null,
                            'price'             => $listingFields['price'] ?? null,
                            'beds'              => $listingFields['bedrooms'] ?? null,
                            'baths'             => $listingFields['bathrooms'] ?? null,
                            'size_m2'           => $listingFields['floor_m2'] ?? null,
                            'erf_m2'            => $listingFields['erf_m2'] ?? null,
                            'title'             => $listingFields['title'] ?? null,
                            'image'             => $listingFields['image'] ?? null,
                            // Address/suburb/property_type pulled through for the TP match.
                            'address'           => $listingFields['address'] ?? null,
                            'suburb'            => $listingFields['suburb'] ?? null,
                            'property_type'     => $listingFields['property_type'] ?? null,
                        ];
                        $tracker = new PortalListingTrackingService();
                        $trackingSummary = $tracker->processItems($capture, $sourceSite, [$itemForTracking]);

                        // Write primary_image_url to portal_listing
                        $imageUrl = $listingFields['image'] ?? null;
                        if ($imageUrl) {
                            PortalListing::where('source_site', $sourceSite)
                                ->where('portal_listing_id', $listingId)
                                ->whereNull('primary_image_url')
                                ->update(['primary_image_url' => $imageUrl]);
                        }

                        // Universal Match-or-Create: this listing feeds the TP universe.
                        $this->linkPortalItemsToTrackedProperties($capture, $sourceSite, [$itemForTracking]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Portal listing extraction failed', [
                    'capture_id' => $capture->id,
                    'error'      => $e->getMessage(),
                ]);
                $capture->update([
                    'parse_status' => 'unparsed_error',
                ]);
            }
        }

        // Link capture to matching presentation link by URL
        $linkedLinkId = null;
        if ($capture->presentation_id) {
            $linkedLinkId = $this->linkCaptureToLink($capture);
        }

        Log::info('[PortalCapture:ingest] Capture ingested', [
            'capture_id'      => $capture->id,
            'presentation_id' => $capture->presentation_id,
            'source_url'      => $capture->source_url,
            'parse_status'    => $capture->parse_status,
            'linked_link_id'  => $linkedLinkId,
        ]);

        return response()->json([
            'success'          => true,
            'capture_id'       => $capture->id,
            'dom_hash'         => $domHash,
            'html_bytes'       => $htmlBytes,
            'linked_link_id'   => $linkedLinkId,
            'extraction'       => $extractionResult !== null ? [
                'items_on_page' => $extractionResult['search']['items_on_page'] ?? 0,
                'extractor'     => Property24SearchExtractorV1::VERSION,
            ] : null,
            'tracking'         => $trackingSummary,
        ], 201);
    }

    /**
     * GET /presentations/{presentation}/live-snapshot
     * Returns delta captures + updated links since the given cursors.
     */
    public function liveSnapshot(Request $request, Presentation $presentation)
    {
        if (!config('features.presentation_live_updates_v1', true)) {
            return response()->json(['enabled' => false], 200);
        }

        $afterCaptureId        = (int) $request->query('after_capture_id', 0);
        $afterLinkUpdatedAtRaw = $request->query('after_link_updated_at');
        $afterCaptureUpdatedAtRaw = $request->query('after_capture_updated_at');

        // Parse cursor strings to Carbon for correct DB comparison.
        // ISO8601 strings (e.g. "2026-02-21T15:30:00.000000Z") break SQLite
        // string comparison against stored "Y-m-d H:i:s" format.
        $afterLinkUpdatedAt = null;
        if ($afterLinkUpdatedAtRaw) {
            try {
                $afterLinkUpdatedAt = \Carbon\Carbon::parse($afterLinkUpdatedAtRaw);
            } catch (\Exception $e) {
                $afterLinkUpdatedAt = null;
            }
        }
        if (!$afterLinkUpdatedAt) {
            $afterLinkUpdatedAt = now()->subMinutes(5);
        }

        $afterCaptureUpdatedAt = null;
        if ($afterCaptureUpdatedAtRaw) {
            try {
                $afterCaptureUpdatedAt = \Carbon\Carbon::parse($afterCaptureUpdatedAtRaw);
            } catch (\Exception $e) {
                $afterCaptureUpdatedAt = null;
            }
        }

        // New captures since the given cursor (max 50)
        $newCaptures = PortalCapture::where('presentation_id', $presentation->id)
            ->where('id', '>', $afterCaptureId)
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'source_site', 'page_type', 'source_url', 'captured_at',
                    'html_bytes', 'screenshot_path', 'parse_status', 'extracted_fields_json', 'updated_at'])
            ->map(function ($c) {
                return [
                    'id'                  => $c->id,
                    'page_type'           => $c->page_type,
                    'source_site'         => $c->source_site,
                    'source_url'          => $c->source_url,
                    'captured_at'         => $c->captured_at?->toIso8601String(),
                    'html_bytes'          => $c->html_bytes,
                    'screenshot_exists'   => !empty($c->screenshot_path),
                    'parse_status'        => $c->parse_status,
                    'extraction_summary'  => $this->captureExtractionSummary($c),
                    'price_change_count'  => $c->priceChangeCount(),
                    'updated_at'          => $c->updated_at->toIso8601String(),
                ];
            });

        // Updated captures: already-known captures whose fields changed (max 200)
        $updatedCaptures = collect();
        if ($afterCaptureUpdatedAt) {
            $updatedCaptures = PortalCapture::where('presentation_id', $presentation->id)
                ->where('id', '<=', $afterCaptureId)
                ->where('updated_at', '>', $afterCaptureUpdatedAt)
                ->orderBy('updated_at')
                ->limit(200)
                ->get(['id', 'parse_status', 'extracted_fields_json', 'updated_at'])
                ->map(function ($c) {
                    return [
                        'id'                  => $c->id,
                        'parse_status'        => $c->parse_status,
                        'extraction_summary'  => $this->captureExtractionSummary($c),
                        'price_change_count'  => $c->priceChangeCount(),
                        'updated_at'          => $c->updated_at->toIso8601String(),
                    ];
                });
        }

        // Updated links since the given timestamp (max 200)
        $updatedLinks = PresentationLink::where('presentation_id', $presentation->id)
            ->where('updated_at', '>', $afterLinkUpdatedAt)
            ->limit(200)
            ->get(['id', 'url', 'portal_capture_id', 'extraction_status', 'extracted_at', 'extracted_json', 'updated_at'])
            ->map(function ($link) {
                $priceChange = false;
                if ($link->portal_capture_id) {
                    $capture = PortalCapture::find($link->portal_capture_id);
                    if ($capture) {
                        $priceChange = $capture->priceChangeCount() > 0;
                    }
                }
                return [
                    'id'                   => $link->id,
                    'url'                  => $link->url,
                    'portal_capture_id'    => $link->portal_capture_id,
                    'extraction_status'    => $link->extraction_status,
                    'extracted_at'         => $link->extracted_at?->toIso8601String(),
                    'price_change_indicator' => $priceChange,
                    'updated_at'           => $link->updated_at->toIso8601String(),
                ];
            });

        // Compute latest cursors
        $latestCaptureId = $newCaptures->isNotEmpty()
            ? $newCaptures->max('id')
            : $afterCaptureId;

        $latestLinkUpdatedAt = $updatedLinks->isNotEmpty()
            ? $updatedLinks->max('updated_at')
            : $afterLinkUpdatedAt->toIso8601String();

        // Capture updated_at cursor: max across new + updated captures
        $allCaptureUpdatedAts = $newCaptures->pluck('updated_at')
            ->merge($updatedCaptures->pluck('updated_at'));
        $latestCaptureUpdatedAt = $allCaptureUpdatedAts->isNotEmpty()
            ? $allCaptureUpdatedAts->max()
            : ($afterCaptureUpdatedAt ? $afterCaptureUpdatedAt->toIso8601String() : now()->toIso8601String());

        $response = [
            'server_time'                => now()->toIso8601String(),
            'latest_capture_id'          => $latestCaptureId,
            'latest_link_updated_at'     => $latestLinkUpdatedAt,
            'latest_capture_updated_at'  => $latestCaptureUpdatedAt,
            'new_captures'               => $newCaptures->values(),
            'updated_captures'           => $updatedCaptures->values(),
            'updated_links'              => $updatedLinks->values(),
            'counts'                     => [
                'total_captures'      => PortalCapture::where('presentation_id', $presentation->id)->count(),
                'new_captures'        => $newCaptures->count(),
                'updated_captures'    => $updatedCaptures->count(),
                'updated_links'       => $updatedLinks->count(),
            ],
        ];

        // Include debug info when ?debug=1
        if ($request->query('debug')) {
            $response['debug'] = [
                'after_capture_id'                 => $afterCaptureId,
                'after_capture_updated_at_raw'     => $afterCaptureUpdatedAtRaw,
                'after_capture_updated_at_parsed'  => $afterCaptureUpdatedAt?->toIso8601String(),
                'after_link_updated_at_raw'        => $afterLinkUpdatedAtRaw,
                'after_link_updated_at_parsed'     => $afterLinkUpdatedAt->toIso8601String(),
                'new_captures'                     => $newCaptures->count(),
                'updated_captures'                 => $updatedCaptures->count(),
                'updated_links'                    => $updatedLinks->count(),
                'all_link_updated_ats'             => PresentationLink::where('presentation_id', $presentation->id)
                    ->pluck('updated_at')
                    ->map(fn($d) => $d->toIso8601String())
                    ->toArray(),
            ];
        }

        return response()->json($response);
    }

    /**
     * Build a brief extraction summary for a capture.
     */
    private function captureExtractionSummary(PortalCapture $capture): ?array
    {
        $fields = $capture->extracted_fields_json;
        if (empty($fields)) {
            return null;
        }

        // Explicit page type marker
        $pageType = $fields['_page_type'] ?? null;

        // Search page
        if ($pageType === 'search' || isset($fields['search'])) {
            return [
                'type'           => 'search',
                'items_on_page'  => $fields['search']['items_on_page'] ?? ($fields['listing_urls_count'] ?? 0),
            ];
        }

        // Listing page (new format with 'price' key)
        if ($pageType === 'listing' || isset($fields['price']) || isset($fields['asking_price'])) {
            return [
                'type'         => 'listing',
                'asking_price' => $fields['price'] ?? $fields['asking_price'] ?? null,
                'suburb'       => $fields['suburb'] ?? null,
                'bedrooms'     => $fields['bedrooms'] ?? null,
                'bathrooms'    => $fields['bathrooms'] ?? null,
            ];
        }

        return ['type' => 'raw', 'keys' => count($fields)];
    }

    /**
     * GET /presentations/{presentation}/portal-captures
     * Returns captures for a presentation + user's unattached (JSON).
     */
    public function index(Presentation $presentation)
    {
        $selectCols = [
            'id', 'source_site', 'page_type', 'source_url', 'page_title',
            'captured_at', 'extractor_version', 'html_bytes', 'dom_hash_sha256',
            'parse_status', 'extracted_fields_json', 'screenshot_path',
            'found_image_urls_json',
        ];

        $attached = PortalCapture::where('presentation_id', $presentation->id)
            ->orderByDesc('captured_at')
            ->get($selectCols);

        $unattached = PortalCapture::where('user_id', auth()->id())
            ->whereNull('presentation_id')
            ->orderByDesc('captured_at')
            ->limit(20)
            ->get($selectCols);

        return response()->json([
            'attached'   => $attached,
            'unattached' => $unattached,
        ]);
    }

    /**
     * POST /presentations/{presentation}/portal-captures/{capture}/attach
     */
    public function attach(Presentation $presentation, PortalCapture $capture)
    {
        $capture->update(['presentation_id' => $presentation->id]);

        // Attempt to link to a matching presentation link
        $this->linkCaptureToLink($capture);

        return response()->json(['success' => true, 'capture_id' => $capture->id]);
    }

    /**
     * DELETE /presentations/{presentation}/portal-captures/{capture}
     * Removes a portal capture and cleans up stored files.
     */
    public function destroy(Presentation $presentation, PortalCapture $capture)
    {
        // Ensure capture belongs to this presentation
        if ($capture->presentation_id !== $presentation->id) {
            return response()->json(['success' => false, 'error' => 'Capture does not belong to this presentation'], 403);
        }

        // Unlink from any presentation_links that reference this capture
        PresentationLink::where('portal_capture_id', $capture->id)->update([
            'portal_capture_id' => null,
            'extraction_status' => null,
            'extracted_json'    => null,
            'extracted_at'      => null,
        ]);

        // Soft delete — stored files preserved on disk for potential restore

        Log::info('[PortalCapture:destroy] Capture archived', [
            'capture_id'      => $capture->id,
            'presentation_id' => $presentation->id,
            'page_type'       => $capture->page_type,
        ]);

        $capture->delete();

        return response()->json(['success' => true]);
    }

    /**
     * POST /presentations/{presentation}/portal-captures/reclassify
     * Re-classify all captures for a presentation using server-side URL patterns.
     * Fixes captures where the extension guessed wrong page_type.
     */
    public function reclassify(Presentation $presentation)
    {
        $captures = PortalCapture::where('presentation_id', $presentation->id)->get();
        $changed = 0;
        $reExtracted = 0;

        foreach ($captures as $capture) {
            $newType = $this->classifyPageType($capture);
            $oldType = $capture->page_type;

            if ($newType !== $oldType) {
                $capture->update(['page_type' => $newType]);
                $changed++;

                // If reclassified to 'property', re-extract as listing
                if ($newType === 'property' && $this->isProperty24Capture($capture)) {
                    try {
                        $listingFields = $this->extractProperty24ListingFields($capture);
                        if ($listingFields !== null) {
                            $capture->update([
                                'parse_status'          => 'parsed',
                                'extracted_fields_json' => $listingFields,
                            ]);
                            $reExtracted++;

                            // Re-link to presentation link if applicable
                            $this->linkCaptureToLink($capture);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Reclassify re-extraction failed', [
                            'capture_id' => $capture->id,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }

                Log::info('[PortalCapture:reclassify] Changed page_type', [
                    'capture_id' => $capture->id,
                    'old_type'   => $oldType,
                    'new_type'   => $newType,
                ]);
            }
        }

        return response()->json([
            'success'      => true,
            'total'        => $captures->count(),
            'changed'      => $changed,
            're_extracted' => $reExtracted,
        ]);
    }

    /**
     * Match a capture to a presentation link by URL and update the link.
     * Returns the linked link ID or null.
     */
    private function linkCaptureToLink(PortalCapture $capture): ?int
    {
        if (!$capture->presentation_id) {
            return null;
        }

        $links = PresentationLink::where('presentation_id', $capture->presentation_id)->get();

        if ($links->isEmpty()) {
            Log::info('[PortalCapture:linkMatch] No links found for presentation', [
                'capture_id' => $capture->id,
                'presentation_id' => $capture->presentation_id,
            ]);
            return null;
        }

        $matched = null;
        $matchMethod = 'none';

        // Pass 1: Property24 listing ID match (most deterministic)
        $captureListingId = $this->extractProperty24ListingId($capture->source_url);
        if ($captureListingId) {
            foreach ($links as $link) {
                $linkListingId = $this->extractProperty24ListingId($link->url);
                if ($linkListingId && $linkListingId === $captureListingId) {
                    $matched = $link;
                    $matchMethod = 'p24_listing_id';
                    break;
                }
            }
        }

        // Pass 2: exact normalized URL match
        if (!$matched) {
            $captureUrl = $this->normalizeUrl($capture->source_url);
            foreach ($links as $link) {
                $linkNorm = $this->normalizeUrl($link->url);
                if ($linkNorm === $captureUrl) {
                    $matched = $link;
                    $matchMethod = 'normalized_exact';
                    break;
                }
            }

            // Pass 3: match without query string
            if (!$matched) {
                $captureUrlBase = strtok($captureUrl, '?');
                foreach ($links as $link) {
                    $linkNormBase = strtok($this->normalizeUrl($link->url), '?');
                    if ($linkNormBase === $captureUrlBase) {
                        $matched = $link;
                        $matchMethod = 'base_url_no_query';
                        break;
                    }
                }
            }
        }

        if (!$matched) {
            Log::info('[PortalCapture:linkMatch] No URL match found', [
                'capture_id'          => $capture->id,
                'presentation_id'     => $capture->presentation_id,
                'capture_source_url'  => $capture->source_url,
                'capture_listing_id'  => $captureListingId,
                'link_urls'           => $links->map(fn($l) => ['id' => $l->id, 'url' => $l->url])->toArray(),
            ]);
            return null;
        }

        // Build extracted_json from capture fields
        $extractedJson = $capture->extracted_fields_json;
        if ($extractedJson) {
            $extractedJson['_capture_source'] = 'portal_extension';
            $extractedJson['_capture_id'] = $capture->id;

            // For listing captures, also map to link-standard keys for compatibility
            $pageType = $extractedJson['_page_type'] ?? ($capture->page_type === 'property' ? 'listing' : null);
            if ($pageType === 'listing') {
                if (!empty($extractedJson['price']) && empty($extractedJson['asking_price'])) {
                    $extractedJson['asking_price'] = $extractedJson['price'];
                }
                if (!empty($extractedJson['bedrooms']) && empty($extractedJson['beds'])) {
                    $extractedJson['beds'] = $extractedJson['bedrooms'];
                }
                if (!empty($extractedJson['bathrooms']) && empty($extractedJson['baths'])) {
                    $extractedJson['baths'] = $extractedJson['bathrooms'];
                }
            }
        }

        $matched->update([
            'portal_capture_id' => $capture->id,
            'extraction_status' => 'ok',
            'extracted_json'    => $extractedJson ?: $matched->extracted_json,
            'extraction_error'  => null,
            'extracted_at'      => now(),
        ]);

        Log::info('[PortalCapture:linkMatch] Linked capture to link', [
            'capture_id'         => $capture->id,
            'presentation_id'    => $capture->presentation_id,
            'link_id'            => $matched->id,
            'link_url'           => $matched->url,
            'capture_source_url' => $capture->source_url,
            'capture_listing_id' => $captureListingId,
            'match_method'       => $matchMethod,
            'updated_at'         => $matched->updated_at->toIso8601String(),
        ]);

        return $matched->id;
    }

    /**
     * Normalize a URL for comparison: lowercase host, strip utm_* / tracking params, remove fragment, trim trailing slash.
     */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return rtrim(strtolower($url), '/');
        }

        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host   = strtolower($parsed['host']);
        $path   = rtrim($parsed['path'] ?? '', '/');

        // Parse query string and remove tracking params
        $query = '';
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
            // Remove utm_*, fbclid, gclid, ref, P24 tracking params, etc.
            $stripPrefixes = ['utm_', 'fbclid', 'gclid', 'ref', 'source', 'medium', 'campaign',
                              'plid', 'plt', 'plsids', 'plloc'];
            $filtered = [];
            foreach ($params as $k => $v) {
                $dominated = false;
                foreach ($stripPrefixes as $prefix) {
                    if (str_starts_with(strtolower($k), $prefix)) {
                        $dominated = true;
                        break;
                    }
                }
                if (!$dominated) {
                    $filtered[$k] = $v;
                }
            }
            if (!empty($filtered)) {
                ksort($filtered);
                $query = '?' . http_build_query($filtered);
            }
        }

        return $scheme . '://' . $host . $path . $query;
    }

    /**
     * Extract the Property24 listing ID from a URL.
     * P24 property URLs: /for-sale/.../12345 — listing ID is the last numeric segment (5-12 digits).
     */
    private function extractProperty24ListingId(string $url): ?string
    {
        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');

        if (!str_contains($host, 'property24.com')) {
            return null;
        }

        $path = $parsed['path'] ?? '';

        if (preg_match('/\/(\d{5,12})(?:\/|$)/', $path, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check if capture is from Property24.
     */
    private function isProperty24Capture(PortalCapture $capture): bool
    {
        return str_contains(strtolower($capture->source_site), 'property24')
            || str_contains(strtolower($capture->source_url), 'property24.com');
    }

    /**
     * Classify capture page type deterministically from URL patterns.
     * Returns 'search', 'property', or 'unknown'.
     */
    private function classifyPageType(PortalCapture $capture): string
    {
        $url = $capture->source_url;

        if ($this->isProperty24Capture($capture)) {
            if ($this->isProperty24ListingUrl($url)) {
                return 'property';
            }
            if ($this->isProperty24SearchUrl($url)) {
                return 'search';
            }
        }

        // Fall back to whatever the extension sent
        return $capture->page_type;
    }

    /**
     * Determine if a URL is a Property24 individual listing page.
     * Listing URLs: /for-sale/.../12345678 (5-12 digit ID at end of path)
     * Tracking params (plId, plt, plsIds) are ignored — they indicate referral
     * from search results but the page is still a listing detail page.
     */
    private function isProperty24ListingUrl(string $url): bool
    {
        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');
        if (!str_contains($host, 'property24.com')) {
            return false;
        }

        $path = $parsed['path'] ?? '';

        // Must have a listing ID in the path (5-12 digit numeric segment at end)
        if (!preg_match('#/(\d{5,12})(?:/|$)#', $path)) {
            return false;
        }

        // Exclude advanced-search result pages which never have listing IDs
        if (str_contains(strtolower($path), '/advanced-search/')) {
            return false;
        }

        return true;
    }

    /**
     * Determine if a URL is a Property24 search results page.
     */
    private function isProperty24SearchUrl(string $url): bool
    {
        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');
        if (!str_contains($host, 'property24.com')) {
            return false;
        }

        $path = strtolower($parsed['path'] ?? '');
        $query = strtolower($parsed['query'] ?? '');

        // Explicit search patterns
        $searchParams = ['plid', 'plt', 'plsids', 'sp'];
        foreach ($searchParams as $param) {
            if (str_contains($query, $param . '=')) {
                return true;
            }
        }

        // Path patterns for search: /for-sale/suburb/region (no listing ID at end)
        if (preg_match('#^/for-sale/[^/]+/?$#', $path) || preg_match('#^/for-sale/[^/]+/[^/]+/?$#', $path)) {
            // Only if path does NOT end with a listing ID
            if (!preg_match('#/\d{5,12}/?$#', $path)) {
                return true;
            }
        }

        // Property search path
        if (str_contains($path, '/property-search') || str_contains($path, '/properties-for-sale')) {
            return true;
        }

        return false;
    }

    /**
     * Extract listing detail fields from a Property24 listing page capture.
     * Uses DOMDocument/XPath for deterministic parsing.
     *
     * @return array|null Extracted fields or null on failure
     */
    private function extractProperty24ListingFields(PortalCapture $capture): ?array
    {
        $html = $this->loadCaptureHtml($capture);
        if ($html === null || trim($html) === '') {
            return null;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $fields = [
            '_page_type' => 'listing',
            '_extractor' => 'p24_listing_dom_v1',
        ];

        // ── Listing ID from URL ──
        $listingId = $this->extractProperty24ListingId($capture->source_url);
        if ($listingId) {
            $fields['listing_id'] = $listingId;
        }

        // ── Price ──
        $fields['price'] = $this->extractListingPrice($xpath);

        // ── Title / Description ──
        $titleNodes = $xpath->query('//h1[contains(@class,"p24_listing") or contains(@class,"listingTitle")]');
        if ($titleNodes->length > 0) {
            $fields['title'] = trim($titleNodes->item(0)->textContent);
        }

        // ── Address / Suburb ──
        $addrNodes = $xpath->query('//*[contains(@class,"p24_address") or contains(@class,"address")]');
        if ($addrNodes->length > 0) {
            $addr = trim($addrNodes->item(0)->textContent);
            if ($addr !== '') {
                $fields['suburb'] = $addr;
            }
        }
        // Fallback: breadcrumb or location element
        if (empty($fields['suburb'])) {
            $locNodes = $xpath->query('//*[contains(@class,"p24_location") or contains(@class,"location")]');
            if ($locNodes->length > 0) {
                $fields['suburb'] = trim($locNodes->item(0)->textContent);
            }
        }

        // ── Property type ──
        $typeNodes = $xpath->query('//*[contains(@class,"p24_propertyType") or contains(@class,"propertyType")]');
        if ($typeNodes->length > 0) {
            $fields['property_type'] = trim($typeNodes->item(0)->textContent);
        }
        // Fallback: look in JSON-LD
        if (empty($fields['property_type']) && !empty($capture->jsonld_json)) {
            $jsonld = $capture->jsonld_json;
            if (is_array($jsonld)) {
                // Could be array of objects
                $ld = isset($jsonld['@type']) ? $jsonld : ($jsonld[0] ?? []);
                if (!empty($ld['@type'])) {
                    $fields['property_type'] = str_replace(['SingleFamilyResidence', 'Apartment', 'House'], ['House', 'Apartment', 'House'], $ld['@type']);
                }
            }
        }

        // ── Bedrooms / Bathrooms / Parking ──
        $fields['bedrooms'] = $this->extractListingFeatureInt($xpath, 'bed', 'Bedrooms');
        $fields['bathrooms'] = $this->extractListingFeatureInt($xpath, 'bath', 'Bathrooms');

        // ── Floor size / Erf size ──
        $fields['floor_m2'] = $this->extractListingSize($xpath, 'floor');
        $fields['erf_m2'] = $this->extractListingSize($xpath, 'erf');

        // ── Agent details ──
        $agentNodes = $xpath->query('//*[contains(@class,"p24_agentName") or contains(@class,"agentName")]');
        if ($agentNodes->length > 0) {
            $fields['agent_name'] = trim($agentNodes->item(0)->textContent);
        }
        $phoneNodes = $xpath->query('//*[contains(@class,"p24_agentPhone") or contains(@class,"agentPhone")]//a | //*[contains(@class,"p24_phoneNumber")]');
        if ($phoneNodes->length > 0) {
            $phone = trim($phoneNodes->item(0)->textContent);
            if ($phone !== '') {
                $fields['agent_phone'] = $phone;
            }
        }

        // ── Primary image ──
        $fields['image'] = $this->extractListingImage($xpath, $html, $capture->jsonld_json);

        // ── Canonical URL ──
        $fields['url'] = $capture->source_url;

        // ── JSON-LD enrichment (price, beds, baths if not found via DOM) ──
        if (!empty($capture->jsonld_json)) {
            $this->enrichFromJsonLd($fields, $capture->jsonld_json);
        }

        // ── Fallback: regex extraction from raw HTML for key fields ──
        if (empty($fields['price'])) {
            $fields['price'] = $this->extractPriceFromHtml($html);
        }
        if (empty($fields['bedrooms'])) {
            $fields['bedrooms'] = $this->extractIntFromHtmlPattern($html, '/(\d{1,2})\s*(?:Bedroom|Bed)/i');
        }
        if (empty($fields['bathrooms'])) {
            $fields['bathrooms'] = $this->extractIntFromHtmlPattern($html, '/(\d{1,2})\s*(?:Bathroom|Bath)/i');
        }
        if (empty($fields['floor_m2'])) {
            if (preg_match('/(\d[\d\s]*)\s*m[²2]\s*(?:floor|house)/i', $html, $m)) {
                $v = (int) preg_replace('/\s/', '', $m[1]);
                if ($v >= 10 && $v <= 99999) $fields['floor_m2'] = $v;
            }
        }
        if (empty($fields['erf_m2'])) {
            if (preg_match('/(?:erf|land|stand)[^<]{0,30}?(\d[\d\s]*)\s*m[²2]/i', $html, $m)) {
                $v = (int) preg_replace('/\s/', '', $m[1]);
                if ($v >= 10 && $v <= 99999) $fields['erf_m2'] = $v;
            }
        }

        // Remove null/empty values
        $fields = array_filter($fields, fn($v) => $v !== null && $v !== '');

        // Validate: must have at least price OR (beds AND baths)
        $hasPrice = !empty($fields['price']);
        $hasFeatures = !empty($fields['bedrooms']) || !empty($fields['bathrooms']);
        if (!$hasPrice && !$hasFeatures) {
            Log::info('[PortalCapture:listingExtract] Could not extract core listing fields', [
                'capture_id' => $capture->id,
                'keys_found' => array_keys($fields),
            ]);
            return null;
        }

        return $fields;
    }

    /**
     * Extract price from listing page DOM.
     */
    private function extractListingPrice(\DOMXPath $xpath): ?int
    {
        // Strategy 1: p24_price class
        $priceNodes = $xpath->query('//*[contains(@class,"p24_price") or contains(@class,"listingPrice")]');
        foreach ($priceNodes as $node) {
            $v = $this->parsePriceText($node->textContent);
            if ($v !== null) return $v;
        }

        // Strategy 2: any element with itemprop="price"
        $itemNodes = $xpath->query('//*[@itemprop="price" or @content][@itemprop="price"]');
        foreach ($itemNodes as $node) {
            $content = $node->getAttribute('content') ?: $node->textContent;
            $v = $this->parsePriceText($content);
            if ($v !== null) return $v;
        }

        return null;
    }

    /**
     * Parse a price text string to integer.
     */
    private function parsePriceText(string $text): ?int
    {
        $cleaned = preg_replace('/[^\d]/', '', trim($text));
        if ($cleaned !== '' && strlen($cleaned) >= 5) {
            $v = (int) $cleaned;
            if ($v >= 10000 && $v <= 999999999) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Extract a feature integer (beds, baths) from listing page DOM.
     */
    private function extractListingFeatureInt(\DOMXPath $xpath, string $iconKeyword, string $altKeyword): ?int
    {
        // Icon-based: img with icon_bed / icon_bath
        $imgNodes = $xpath->query('//img[contains(@src,"icon_' . $iconKeyword . '") or contains(@alt,"' . $altKeyword . '")]');
        foreach ($imgNodes as $img) {
            $sibling = $img->nextSibling;
            while ($sibling) {
                if ($sibling->nodeType === XML_TEXT_NODE) {
                    $txt = trim($sibling->textContent);
                    if ($txt !== '' && preg_match('/\b(\d{1,2})\b/', $txt, $m)) {
                        return (int) $m[1];
                    }
                }
                if ($sibling->nodeType === XML_ELEMENT_NODE) {
                    // Check element text
                    $txt = trim($sibling->textContent);
                    if ($txt !== '' && preg_match('/\b(\d{1,2})\b/', $txt, $m)) {
                        return (int) $m[1];
                    }
                    break;
                }
                $sibling = $sibling->nextSibling;
            }
            // Parent span fallback
            if ($img->parentNode && $img->parentNode->nodeName === 'span') {
                $spanText = trim($img->parentNode->textContent);
                if (preg_match('/\b(\d{1,2})\b/', $spanText, $m)) {
                    return (int) $m[1];
                }
            }
        }

        // Class-based fallback
        $nodes = $xpath->query('//*[contains(@class,"p24_featureDetails")]//*[contains(@class,"' . $iconKeyword . '")]');
        foreach ($nodes as $n) {
            $text = trim($n->textContent);
            if (preg_match('/\b(\d{1,2})\b/', $text, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Extract size (floor or erf) from listing page DOM.
     */
    private function extractListingSize(\DOMXPath $xpath, string $keyword): ?int
    {
        $imgNodes = $xpath->query(
            '//img[contains(@src,"icon_' . $keyword . '") or contains(@alt,"' . ucfirst($keyword) . '")]'
        );
        foreach ($imgNodes as $img) {
            $sibling = $img->nextSibling;
            while ($sibling) {
                if ($sibling->nodeType === XML_TEXT_NODE || $sibling->nodeType === XML_ELEMENT_NODE) {
                    $txt = trim($sibling->textContent);
                    if (preg_match('/(\d[\d\s]*)\s*m[²2]/i', $txt, $m)) {
                        $v = (int) preg_replace('/\s/', '', $m[1]);
                        return ($v >= 1 && $v <= 99999) ? $v : null;
                    }
                }
                if ($sibling->nodeType === XML_ELEMENT_NODE) break;
                $sibling = $sibling->nextSibling;
            }
            // Parent span fallback
            if ($img->parentNode) {
                $spanText = trim($img->parentNode->textContent);
                if (preg_match('/(\d[\d\s]*)\s*m[²2]/i', $spanText, $m)) {
                    $v = (int) preg_replace('/\s/', '', $m[1]);
                    return ($v >= 1 && $v <= 99999) ? $v : null;
                }
            }
        }

        // Feature details class fallback
        $nodes = $xpath->query('//*[contains(@class,"p24_size") or contains(@class,"' . $keyword . 'Size")]');
        foreach ($nodes as $n) {
            $text = trim($n->textContent);
            if (preg_match('/(\d[\d\s]*)\s*m[²2]/i', $text, $m)) {
                $v = (int) preg_replace('/\s/', '', $m[1]);
                return ($v >= 1 && $v <= 99999) ? $v : null;
            }
        }

        return null;
    }

    /**
     * Enrich extracted fields from JSON-LD data.
     */
    private function enrichFromJsonLd(array &$fields, array $jsonld): void
    {
        // Normalize: JSON-LD may be a single object or array
        $ld = isset($jsonld['@type']) ? $jsonld : ($jsonld[0] ?? []);

        if (empty($fields['price']) && !empty($ld['offers']['price'])) {
            $v = $this->parsePriceText((string) $ld['offers']['price']);
            if ($v !== null) $fields['price'] = $v;
        }
        if (empty($fields['bedrooms']) && !empty($ld['numberOfRooms'])) {
            $v = (int) $ld['numberOfRooms'];
            if ($v >= 1 && $v <= 20) $fields['bedrooms'] = $v;
        }
        if (empty($fields['floor_m2']) && !empty($ld['floorSize']['value'])) {
            $v = (int) $ld['floorSize']['value'];
            if ($v >= 10 && $v <= 99999) $fields['floor_m2'] = $v;
        }
        if (empty($fields['suburb']) && !empty($ld['address']['addressLocality'])) {
            $fields['suburb'] = $ld['address']['addressLocality'];
        }
    }

    /**
     * Extract price from raw HTML as fallback.
     */
    private function extractPriceFromHtml(string $html): ?int
    {
        if (preg_match('/R\s*([\d\s,]+)/', $html, $m)) {
            $cleaned = preg_replace('/[^\d]/', '', $m[1]);
            if (strlen($cleaned) >= 5) {
                $v = (int) $cleaned;
                if ($v >= 10000 && $v <= 999999999) return $v;
            }
        }
        return null;
    }

    /**
     * Extract an integer from HTML using a regex pattern.
     */
    private function extractIntFromHtmlPattern(string $html, string $pattern): ?int
    {
        if (preg_match($pattern, $html, $m)) {
            $v = (int) $m[1];
            return ($v >= 1 && $v <= 99) ? $v : null;
        }
        return null;
    }

    /**
     * Load raw HTML from capture's stored file.
     */
    private function loadCaptureHtml(PortalCapture $capture): ?string
    {
        if (empty($capture->raw_html_path)) {
            return null;
        }
        if (!Storage::disk('local')->exists($capture->raw_html_path)) {
            return null;
        }
        return Storage::disk('local')->get($capture->raw_html_path);
    }

    /**
     * Extract the primary listing image from a P24 detail page.
     * Strategies: og:image meta → JSON-LD image → lightbox/gallery img → first large img.
     */
    private function extractListingImage(\DOMXPath $xpath, string $html, ?array $jsonld): ?string
    {
        // Strategy 1: og:image meta tag (most reliable on P24 detail pages)
        $ogNodes = $xpath->query('//meta[@property="og:image"]');
        foreach ($ogNodes as $node) {
            $content = $node->getAttribute('content');
            if ($content !== '' && $this->isValidListingImageUrl($content)) {
                return $content;
            }
        }

        // Strategy 2: JSON-LD image field
        if (!empty($jsonld)) {
            $ld = isset($jsonld['@type']) ? $jsonld : ($jsonld[0] ?? []);
            $ldImage = $ld['image'] ?? null;
            if (is_string($ldImage) && $this->isValidListingImageUrl($ldImage)) {
                return $ldImage;
            }
            if (is_array($ldImage)) {
                $first = $ldImage[0] ?? null;
                if (is_string($first) && $this->isValidListingImageUrl($first)) {
                    return $first;
                }
            }
        }

        // Strategy 3: P24 gallery/lightbox image
        $galleryNodes = $xpath->query('//img[contains(@class,"js_lightboxImage") or contains(@class,"p24_mainImage") or contains(@class,"mainImage") or contains(@class,"hero")]');
        foreach ($galleryNodes as $img) {
            $src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
            if ($src !== '' && $this->isValidListingImageUrl($src)) {
                return $src;
            }
        }

        // Strategy 4: First img inside a gallery/carousel container
        $containerNodes = $xpath->query('//*[contains(@class,"gallery") or contains(@class,"carousel") or contains(@class,"slider") or contains(@class,"p24_images")]//img');
        foreach ($containerNodes as $img) {
            $src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
            if ($src !== '' && $this->isValidListingImageUrl($src)) {
                return $src;
            }
        }

        return null;
    }

    /**
     * Check if a URL looks like a valid property listing image (not an icon/logo/tracker).
     */
    private function isValidListingImageUrl(string $url): bool
    {
        if ($url === '') return false;
        $lower = strtolower($url);
        // Exclude icons, logos, tracking pixels, SVGs
        if (str_contains($lower, 'icon_') || str_contains($lower, '/logo')
            || str_contains($lower, 'tracking') || str_contains($lower, '.svg')
            || str_contains($lower, '1x1') || str_contains($lower, 'pixel')) {
            return false;
        }
        return true;
    }

    /**
     * Normalize source_site to a consistent domain (e.g. www.property24.com).
     */
    private function normalizeSourceSite(string $sourceSite): string
    {
        $site = strtolower(trim($sourceSite));

        // Ensure it has www. prefix for property24
        if ($site === 'property24.com') {
            return 'www.property24.com';
        }

        return $site;
    }

    /**
     * Universal Match-or-Create: every captured portal listing feeds the Tracked
     * Property universe. Failure-isolated so a TP write hiccup never breaks the
     * capture ingestion.
     *
     * portal_listings has no agency_id — we resolve it via the capture's user.
     *
     * Spec: CLAUDE.md HARD RULE #10 (Universal Match-or-Create Rule).
     */
    private function linkPortalItemsToTrackedProperties(PortalCapture $capture, string $sourceSite, array $items): void
    {
        try {
            $agencyId = \DB::table('users')->where('id', $capture->user_id)->value('agency_id');
            if (!$agencyId) {
                // Super-admin without an active agency context — skip; the capture
                // is preserved but TP linking waits for an agency-scoped re-process.
                return;
            }

            $service = app(\App\Services\Prospecting\TrackedPropertyMatchOrCreateService::class);

            foreach ($items as $item) {
                $portalListingId = $item['portal_listing_id'] ?? null;
                if (!$portalListingId) continue;

                $addr = isset($item['address']) ? trim((string) $item['address']) : null;
                $streetNumber = null;
                $streetName   = null;
                if ($addr && preg_match('/^(\d+\w*)\s+(.+)$/', $addr, $m)) {
                    $streetNumber = $m[1];
                    $streetName   = $m[2];
                }

                $facts = array_filter([
                    'address'                 => $addr,
                    'street_number'           => $streetNumber,
                    'street_name'             => $streetName,
                    'suburb'                  => $item['suburb'] ?? null,
                    'property_type'           => $item['property_type'] ?? null,
                    'bedrooms'                => $item['beds'] ?? $item['bedrooms'] ?? null,
                    'bathrooms'               => $item['baths'] ?? $item['bathrooms'] ?? null,
                    'floor_size_m2'           => $item['size_m2'] ?? $item['floor_m2'] ?? null,
                    'erf_size_m2'             => $item['erf_m2'] ?? null,
                    'last_known_asking_price' => $item['price'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');

                $tp = $service->matchOrCreate(
                    agencyId: (int) $agencyId,
                    facts: $facts,
                    source: [
                        'type' => 'chrome_capture',
                        'ref'  => (string) $portalListingId,
                        'payload' => [
                            'capture_id'        => $capture->id,
                            'source_site'       => $sourceSite,
                            'portal_listing_id' => $portalListingId,
                        ],
                    ],
                    actorUserId: $capture->user_id,
                );

                if ($tp) {
                    PortalListing::where('source_site', $sourceSite)
                        ->where('portal_listing_id', $portalListingId)
                        ->whereNull('tracked_property_id')
                        ->update(['tracked_property_id' => $tp->id]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('TrackedProperty link from portal capture failed', [
                'capture_id' => $capture->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
