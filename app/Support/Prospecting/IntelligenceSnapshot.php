<?php

declare(strict_types=1);

namespace App\Support\Prospecting;

use Illuminate\Support\Collection;

/**
 * Composite read returned by ProspectingIntelligenceService::snapshot().
 *
 * Everything the prospecting tab needs to render in a single object.
 * All counts are agency-scoped.
 *
 * Segment arrays use shape: array<int, array{id:?int, label:string, count:int}>
 * Stock-gap entries use:    array<int, array{label:string, buyers:int, listings:int, gap:int}>
 * Unmapped suburbs entries: array{suburb:string, wishlist_count:int, listing_count:int, total:int}
 */
final class IntelligenceSnapshot
{
    public function __construct(
        // Headlines
        public readonly int $activeBuyers,
        public readonly int $activeListings,
        public readonly int $openMandates,

        /**
         * @var array<string, array<int, array{id:?int, label:string, count:int}>>
         * Keys: 'town' | 'property_type' | 'bedrooms' | 'price_band'
         */
        public readonly array $buyerSegments,

        /** @var array<string, array<int, array{id:?int, label:string, count:int}>> */
        public readonly array $listingSegments,

        /**
         * @var array<string, array<string, int>>
         * Shape: ['last_7_days' => ['new'=>N, 'warm'=>N, 'cold'=>N, 'lost'=>N], ...]
         */
        public readonly array $buyerFunnel,

        /** @var array<string, array<int, array{label:string, buyers:int, listings:int, gap:int}>> */
        public readonly array $stockGap,

        /** @var Collection<int, array{suburb:string, wishlist_count:int, listing_count:int, total:int}> */
        public readonly Collection $unmappedSuburbs,

        public readonly array $appliedFilters,
        public readonly \DateTimeImmutable $generatedAt,
    ) {}
}
