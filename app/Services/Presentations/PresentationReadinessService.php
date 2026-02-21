<?php

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Models\PresentationUrlSnapshot;

/**
 * Deterministic readiness checklist for a Presentation (P16).
 *
 * Returns structured lists of required/optional items so the UI can
 * show what is missing and the compile endpoint can gate on can_compile.
 *
 * No engine math. Pure evidence inspection.
 */
class PresentationReadinessService
{
    private const SOLD_COMPS_THRESHOLD      = 1;
    private const ACTIVE_LISTINGS_THRESHOLD = 1;
    private const COMPETITOR_URLS_THRESHOLD = 3;

    private const LISTING_SOURCE_TYPES = [
        'p24_search',
        'p24_listing',
        'private_property',
        'private_property_search',
        'private_property_listing',
    ];

    public function evaluate(Presentation $presentation): array
    {
        $presentation->loadMissing(['uploads', 'soldComps', 'activeListings', 'links', 'articles']);

        $urlSnapshots = PresentationUrlSnapshot::where('presentation_id', $presentation->id)->get();

        // ── Required items ────────────────────────────────────────────────────

        // 1. Identity fields: suburb + property_type must be set
        $required['identity_complete'] = [
            'key'       => 'identity_complete',
            'label'     => 'Property identity fields (suburb and type)',
            'satisfied' => !empty($presentation->suburb) && !empty($presentation->property_type),
        ];

        // 2. Suburb evidence: upload with type suburb_stats OR a search URL snapshot
        $hasSuburbUpload   = $presentation->uploads->where('type', 'suburb_stats')->count() >= 1;
        $hasSuburbSnapshot = $urlSnapshots->whereIn('source_type', ['p24_search', 'private_property_search'])->count() >= 1;
        $required['suburb_evidence'] = [
            'key'       => 'suburb_evidence',
            'label'     => 'Suburb report or stats evidence',
            'satisfied' => $hasSuburbUpload || $hasSuburbSnapshot,
        ];

        // 3. Vicinity sales: upload with type vicinity_sales OR sold comps in DB
        $hasVicinityUpload = $presentation->uploads->where('type', 'vicinity_sales')->count() >= 1;
        $required['vicinity_sales'] = [
            'key'       => 'vicinity_sales',
            'label'     => 'Vicinity sales evidence',
            'satisfied' => $hasVicinityUpload || $presentation->soldComps->count() >= self::SOLD_COMPS_THRESHOLD,
        ];

        // 4. Competitive stock: link tagged active_listing/competitor_listing OR active listings in DB OR listing URL snapshot
        $hasCompetitiveLink = $presentation->links->whereIn('type', ['active_listing', 'competitor_listing'])->count() >= 1;
        $hasListingSnapshot = $urlSnapshots->whereIn('source_type', self::LISTING_SOURCE_TYPES)->count() >= 1;
        $required['competitive_stock'] = [
            'key'       => 'competitive_stock',
            'label'     => 'Competitive stock (listing link or active listings)',
            'satisfied' => $hasCompetitiveLink || $presentation->activeListings->count() >= self::ACTIVE_LISTINGS_THRESHOLD || $hasListingSnapshot,
        ];

        // ── Optional items ────────────────────────────────────────────────────

        $hasArticleUpload = $presentation->uploads->where('type', 'market_article')->count() >= 1;
        $optional['articles'] = [
            'key'       => 'articles',
            'label'     => 'Market articles ingested',
            'satisfied' => $hasArticleUpload || $presentation->articles->count() >= 1,
        ];

        $optional['cma_uploaded'] = [
            'key'       => 'cma_uploaded',
            'label'     => 'CMA report uploaded',
            'satisfied' => $presentation->uploads->where('type', 'cma')->count() >= 1,
        ];

        $competitorLinks = $presentation->links->whereIn('type', ['active_listing', 'competitor_listing'])->count();
        $optional['competitor_urls'] = [
            'key'       => 'competitor_urls',
            'label'     => '3 or more competitor listing links',
            'satisfied' => $competitorLinks >= self::COMPETITOR_URLS_THRESHOLD,
        ];

        // ── Aggregates ────────────────────────────────────────────────────────

        $requiredItems = array_values($required);
        $optionalItems = array_values($optional);

        $satisfiedRequired = count(array_filter($requiredItems, fn ($i) => $i['satisfied']));
        $satisfiedOptional = count(array_filter($optionalItems, fn ($i) => $i['satisfied']));

        $totalItems       = count($requiredItems) + count($optionalItems);
        $completedPercent = $totalItems > 0
            ? (int) round(($satisfiedRequired + $satisfiedOptional) / $totalItems * 100)
            : 0;

        $missingRequired = array_values(array_filter($requiredItems, fn ($i) => !$i['satisfied']));

        return [
            'required_items'    => $requiredItems,
            'missing_required'  => $missingRequired,
            'optional_items'    => $optionalItems,
            'completed_percent' => $completedPercent,
            'can_compile'       => count($missingRequired) === 0,
        ];
    }
}
