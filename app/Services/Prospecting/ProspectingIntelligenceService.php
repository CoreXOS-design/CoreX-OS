<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Contact;
use App\Support\Prospecting\IntelligenceSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Aggregation engine for the prospecting tab.
 *
 * Consumes:
 *   - ProspectingListingResolver (unified listings stream from Prompt 01)
 *   - ProspectingConfigurationService (segment definitions — towns/suburbs/
 *     property types/bedroom segments/price bands)
 *
 * Produces (via snapshot()):
 *   - headlines (active buyers, listings, open mandates)
 *   - 4-dimension buyer segments (town / property type / bedroom / price band)
 *   - 4-dimension listing segments
 *   - 5-window x 4-status buyer funnel
 *   - town-level stock-gap (buyers − listings)
 *   - distinct unmapped suburbs (from buyers and listings)
 *
 * Multi-tenancy: every public method requires agency_id in filters.
 *
 * Spec adaptations recorded at Prompt 02 build time:
 *   - Contacts use `buyer_state` (not `buyer_status` as spec said). Confirmed enum:
 *     new / warm / cold / lost.
 *   - Properties pillar uses `mandate_type` (enum: sole / open / dual / multi)
 *     rather than `mandate_status` the spec speculated about.
 *   - Distinct contact_id counting per bucket. A buyer with 3 wishlists targeting
 *     Margate counts as 1, not 3.
 */
final class ProspectingIntelligenceService
{
    /** Buyer-state values considered "active in the pipeline". */
    private const ACTIVE_BUYER_STATES = ['new', 'warm'];

    /** Mandate types that represent an open / active mandate on a property. */
    private const OPEN_MANDATE_TYPES = ['sole', 'open', 'dual', 'multi'];

    /** Buyer-state values surfaced in the funnel. */
    private const FUNNEL_STATES = ['new', 'warm', 'cold', 'lost'];

    /** Funnel time windows in days. */
    private const FUNNEL_WINDOWS = [7, 30, 60, 90, 180];

    public function __construct(
        private readonly ProspectingListingResolver $resolver,
        private readonly ProspectingConfigurationService $config,
    ) {}

    public function snapshot(array $filters): IntelligenceSnapshot
    {
        $this->assertAgencyId($filters);
        $agencyId    = (int) $filters['agency_id'];
        $listingType = (string) ($filters['listing_type'] ?? 'sale');

        $listings     = $this->resolver->all($filters);
        $activeBuyers = $this->loadActiveBuyers($agencyId, $filters);

        return new IntelligenceSnapshot(
            activeBuyers:   $activeBuyers->count(),
            activeListings: $listings->count(),
            openMandates:   $this->countOpenMandates($agencyId),

            buyerSegments: [
                'town'          => $this->buyersByTown($agencyId, $activeBuyers),
                'property_type' => $this->buyersByPropertyType($agencyId, $activeBuyers),
                'bedrooms'      => $this->buyersByBedroomSegment($agencyId, $activeBuyers),
                'price_band'    => $this->buyersByPriceBand($agencyId, $activeBuyers, $listingType),
            ],

            listingSegments: [
                'town'          => $this->listingsByTown($listings),
                'property_type' => $this->listingsByPropertyType($agencyId, $listings),
                'bedrooms'      => $this->listingsByBedroomSegment($agencyId, $listings),
                'price_band'    => $this->listingsByPriceBand($agencyId, $listings),
            ],

            buyerFunnel:     $this->buildFunnel($agencyId),
            stockGap:        $this->buildStockGap($agencyId, $activeBuyers, $listings, $listingType),
            unmappedSuburbs: $this->buildUnmappedSuburbs($agencyId, $activeBuyers, $listings),
            appliedFilters:  $filters,
            generatedAt:     new \DateTimeImmutable(),
        );
    }

    /**
     * Drill-down: contact IDs for a clicked buyer segment.
     *
     * @return Collection<int, int>
     */
    public function buyersForSegment(int $agencyId, string $dimension, int|string $segmentValue, array $filters = []): Collection
    {
        $filters['agency_id'] = $agencyId;
        $listingType = (string) ($filters['listing_type'] ?? 'sale');
        $buyers      = $this->loadActiveBuyers($agencyId, $filters);

        return match ($dimension) {
            'town'            => $this->filterBuyersByTownId($agencyId, $buyers, (int) $segmentValue),
            'property_type'   => $this->filterBuyersByPropertyTypeId($agencyId, $buyers, (int) $segmentValue),
            'bedrooms'        => $this->filterBuyersByBedroomSegmentId($agencyId, $buyers, (int) $segmentValue),
            'price_band'      => $this->filterBuyersByPriceBandId($agencyId, $buyers, (int) $segmentValue, $listingType),
            'unmapped_suburb' => $this->filterBuyersByUnmappedSuburb($agencyId, $buyers, (string) $segmentValue),
            default           => collect(),
        };
    }

    /**
     * Drill-down: ResolvedListings for a clicked listing segment.
     *
     * @return Collection<int, \App\Support\Prospecting\ResolvedListing>
     */
    public function listingsForSegment(int $agencyId, string $dimension, int|string $segmentValue, array $filters = []): Collection
    {
        $filters['agency_id'] = $agencyId;

        switch ($dimension) {
            case 'town':
                $filters['town_id'] = (int) $segmentValue;
                break;
            case 'property_type':
                $option = $this->config->propertyTypes($agencyId, activeOnly: false)->firstWhere('id', (int) $segmentValue);
                if ($option) $filters['property_type_slug'] = $option->slug;
                break;
            case 'bedrooms':
                $filters['bedroom_segment_id'] = (int) $segmentValue;
                break;
            case 'price_band':
                $filters['price_band_id'] = (int) $segmentValue;
                break;
            case 'unmapped_suburb':
                $filters['suburb_normalised'] = (string) $segmentValue;
                $filters['unmapped_only'] = true;
                break;
        }

        return $this->resolver->all($filters);
    }

    /* =========================================================
     |  Buyer loading & bucketing
     * ========================================================= */

    /**
     * @return Collection<int, Contact>
     */
    private function loadActiveBuyers(int $agencyId, array $filters): Collection
    {
        // Funnel drill-down: when the user is filtering specifically by funnel
        // cell (buyer_state or buyers_since), align with funnel counts by
        // showing pipeline buyers regardless of wishlist status — the funnel
        // measures pipeline inflow, not wishlist coverage. Default view (no
        // funnel filter) keeps spec P1's "active = has at least one active
        // wishlist" definition for the headlines.
        $hasFunnelDrillDown = !empty($filters['buyer_state']) || !empty($filters['buyers_since']);

        $query = Contact::query()->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('is_buyer', 1);

        if (!$hasFunnelDrillDown) {
            $query->whereHas('matches', fn ($q) => $q->where('status', 'active')->whereNull('deleted_at'));
        }

        // Buyer-state restriction. Default is "active in pipeline" (new + warm).
        // When the user drills into a funnel cell, allow the explicit state —
        // including cold/lost — so they can see who they lost and prospect again.
        if (!empty($filters['buyer_state'])) {
            $query->where('buyer_state', $filters['buyer_state']);
        } else {
            $query->whereIn('buyer_state', self::ACTIVE_BUYER_STATES);
        }

        // Buyer-creation window (funnel drill-down).
        if (!empty($filters['buyers_since']) && $filters['buyers_since'] instanceof \DateTimeInterface) {
            $query->where('created_at', '>=', $filters['buyers_since']);
        }

        if (!empty($filters['listing_type'])) {
            $query->whereHas('matches', fn ($q) =>
                $q->where('status', 'active')
                  ->where('listing_type', $filters['listing_type'])
                  ->whereNull('deleted_at')
            );
        }

        return $query->with([
            'matches' => fn ($q) => $q->where('status', 'active')->whereNull('deleted_at'),
        ])->get();
    }

    private function buyersByTown(int $agencyId, Collection $buyers): array
    {
        return $this->bucketBuyers($buyers, function ($match) use ($agencyId): array {
            $keys = [];
            foreach ($this->matchSuburbs($match) as $sub) {
                $town = $this->config->suburbToTown($agencyId, $sub);
                $keys[] = $town
                    ? ['id' => $town->id, 'label' => $town->name]
                    : ['id' => null, 'label' => "(unmapped) {$sub}"];
            }
            if (empty($keys)) $keys[] = ['id' => null, 'label' => '(any town)'];
            return $keys;
        });
    }

    private function buyersByPropertyType(int $agencyId, Collection $buyers): array
    {
        $options = $this->config->propertyTypes($agencyId, activeOnly: false);
        return $this->bucketBuyers($buyers, function ($match) use ($options): array {
            $keys = [];
            foreach ($this->matchPropertyTypes($match) as $rawType) {
                $slug = Str::slug($rawType);
                $opt  = $options->firstWhere('slug', $slug);
                $keys[] = $opt
                    ? ['id' => $opt->id, 'label' => $opt->name]
                    : ['id' => null, 'label' => "(unmapped) {$rawType}"];
            }
            if (empty($keys)) $keys[] = ['id' => null, 'label' => '(any type)'];
            return $keys;
        });
    }

    private function buyersByBedroomSegment(int $agencyId, Collection $buyers): array
    {
        return $this->bucketBuyers($buyers, function ($match) use ($agencyId): array {
            $bedsMin = $match->beds_min;
            $bedsMax = $match->bedrooms_max;
            if ($bedsMin === null && $bedsMax === null) {
                return [['id' => null, 'label' => '(any)']];
            }

            $start = $bedsMin ?? 1;
            $end   = $bedsMax ?? max($start, 6); // cap at 6 to avoid over-fan-out
            $keys  = [];
            $seen  = [];
            for ($b = $start; $b <= $end; $b++) {
                $seg = $this->config->bedroomBucketFor($agencyId, $b);
                if ($seg && !isset($seen[$seg->id])) {
                    $keys[] = ['id' => $seg->id, 'label' => $seg->name];
                    $seen[$seg->id] = true;
                }
            }
            return $keys ?: [['id' => null, 'label' => '(unclassified)']];
        });
    }

    private function buyersByPriceBand(int $agencyId, Collection $buyers, string $listingType): array
    {
        return $this->bucketBuyers($buyers, function ($match) use ($agencyId, $listingType): array {
            // If the buyer has a typed listing_type that differs, skip — caller restricts to one type.
            if ($match->listing_type !== null && $match->listing_type !== $listingType) {
                return [];
            }
            $min = $match->price_min;
            $max = $match->price_max;
            if ($min === null && $max === null) {
                return [['id' => null, 'label' => '(unspecified)']];
            }

            // Midpoint of the buyer's range. Use min or max if only one is set.
            if ($min !== null && $max !== null) {
                $target = (int) (($min + $max) / 2);
            } else {
                $target = (int) ($min ?? $max);
            }

            $band = $this->config->classifyPrice($agencyId, $listingType, $target);
            return $band
                ? [['id' => $band->id, 'label' => $band->name]]
                : [['id' => null, 'label' => '(unspecified)']];
        });
    }

    /**
     * Generic buyer-bucketer.
     *
     * The keyer is called per ContactMatch and returns one-or-more
     * {id, label} buckets the match contributes to. Counts are DISTINCT
     * contact_id per bucket — a buyer with 3 wishlists in Margate counts
     * once, not three times.
     *
     * @return array<int, array{id:?int, label:string, count:int}>
     */
    private function bucketBuyers(Collection $buyers, callable $keyer): array
    {
        $buckets = [];

        foreach ($buyers as $buyer) {
            $contributed = []; // bucketKey => true (for this buyer only)
            foreach ($buyer->matches as $match) {
                foreach ($keyer($match) as $k) {
                    $bk = ($k['id'] ?? 'null') . '|' . $k['label'];
                    if (isset($contributed[$bk])) continue; // already counted for this buyer

                    if (!isset($buckets[$bk])) {
                        $buckets[$bk] = ['id' => $k['id'], 'label' => $k['label'], 'count' => 0];
                    }
                    $buckets[$bk]['count']++;
                    $contributed[$bk] = true;
                }
            }
        }

        return collect($buckets)->sortByDesc('count')->values()->all();
    }

    /**
     * @return array<int, string>
     */
    private function matchSuburbs(object $match): array
    {
        $val = $match->suburbs;
        if (is_string($val)) $val = json_decode($val, true);
        if (!is_array($val))  return [];
        return array_values(array_filter(array_map('strval', $val), fn ($s) => trim($s) !== ''));
    }

    /**
     * @return array<int, string>
     */
    private function matchPropertyTypes(object $match): array
    {
        $val = $match->property_types;
        if (is_string($val)) $val = json_decode($val, true);
        $arr = is_array($val)
            ? array_values(array_filter(array_map('strval', $val), fn ($s) => trim($s) !== ''))
            : [];
        if (empty($arr) && !empty($match->property_type)) {
            $arr = [(string) $match->property_type]; // legacy single-type fallback
        }
        return $arr;
    }

    /* =========================================================
     |  Listing-side bucketing (resolved DTOs are already segmented)
     * ========================================================= */

    private function listingsByTown(Collection $listings): array
    {
        return $this->bucketListings($listings, fn ($l) => [
            'id'    => $l->townId,
            'label' => $l->townName ?? '(unknown)',
        ]);
    }

    private function listingsByPropertyType(int $agencyId, Collection $listings): array
    {
        $options = $this->config->propertyTypes($agencyId, activeOnly: false);
        return $this->bucketListings($listings, function ($l) use ($options) {
            $opt = $l->propertyTypeOptionId ? $options->firstWhere('id', $l->propertyTypeOptionId) : null;
            return [
                'id'    => $opt?->id,
                'label' => $opt?->name ?? ($l->propertyTypeRaw ? "(unmapped) {$l->propertyTypeRaw}" : '(unknown)'),
            ];
        });
    }

    private function listingsByBedroomSegment(int $agencyId, Collection $listings): array
    {
        $segments = $this->config->bedroomSegments($agencyId);
        return $this->bucketListings($listings, function ($l) use ($segments) {
            $seg = $l->bedroomSegmentId ? $segments->firstWhere('id', $l->bedroomSegmentId) : null;
            return [
                'id'    => $seg?->id,
                'label' => $seg?->name ?? '(unclassified)',
            ];
        });
    }

    private function listingsByPriceBand(int $agencyId, Collection $listings): array
    {
        // Listings are sale-only in v1; pull sale bands once and resolve by id.
        $saleBands   = $this->config->priceBandsFor($agencyId, 'sale');
        $rentalBands = $this->config->priceBandsFor($agencyId, 'rental');
        return $this->bucketListings($listings, function ($l) use ($saleBands, $rentalBands) {
            $pool = $l->listingType === 'rental' ? $rentalBands : $saleBands;
            $band = $l->priceBandId ? $pool->firstWhere('id', $l->priceBandId) : null;
            return [
                'id'    => $band?->id,
                'label' => $band?->name ?? '(unclassified)',
            ];
        });
    }

    /**
     * @return array<int, array{id:?int, label:string, count:int}>
     */
    private function bucketListings(Collection $listings, callable $keyer): array
    {
        $buckets = [];
        foreach ($listings as $listing) {
            $k = $keyer($listing);
            $bk = ($k['id'] ?? 'null') . '|' . $k['label'];
            if (!isset($buckets[$bk])) {
                $buckets[$bk] = ['id' => $k['id'], 'label' => $k['label'], 'count' => 0];
            }
            $buckets[$bk]['count']++;
        }
        return collect($buckets)->sortByDesc('count')->values()->all();
    }

    /* =========================================================
     |  Funnel / open mandates / stock gap / unmapped suburbs
     * ========================================================= */

    private function buildFunnel(int $agencyId): array
    {
        $result = [];
        $now    = now();
        foreach (self::FUNNEL_WINDOWS as $days) {
            $key   = "last_{$days}_days";
            $since = $now->copy()->subDays($days);
            foreach (self::FUNNEL_STATES as $state) {
                $result[$key][$state] = Contact::query()->withoutGlobalScopes()
                    ->where('agency_id', $agencyId)
                    ->where('is_buyer', 1)
                    ->where('buyer_state', $state)
                    ->where('created_at', '>=', $since)
                    ->count();
            }
        }
        return $result;
    }

    private function countOpenMandates(int $agencyId): int
    {
        if (!Schema::hasColumn('properties', 'mandate_type')) {
            return 0;
        }
        return DB::table('properties')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('mandate_type', self::OPEN_MANDATE_TYPES)
            ->count();
    }

    private function buildStockGap(int $agencyId, Collection $buyers, Collection $listings, string $listingType): array
    {
        $b = collect($this->buyersByTown($agencyId, $buyers))->keyBy('label');
        $l = collect($this->listingsByTown($listings))->keyBy('label');
        $labels = $b->keys()->merge($l->keys())->unique();

        $town = $labels->map(fn ($label) => [
            'label'    => $label,
            'buyers'   => $b->get($label)['count'] ?? 0,
            'listings' => $l->get($label)['count'] ?? 0,
            'gap'      => ($b->get($label)['count'] ?? 0) - ($l->get($label)['count'] ?? 0),
        ])->sortByDesc('gap')->values()->all();

        return ['town' => $town];
    }

    /**
     * @return Collection<int, array{suburb:string, wishlist_count:int, listing_count:int, total:int}>
     */
    private function buildUnmappedSuburbs(int $agencyId, Collection $buyers, Collection $listings): Collection
    {
        $wishlist = []; // normalised => count

        foreach ($buyers as $buyer) {
            foreach ($buyer->matches as $match) {
                foreach ($this->matchSuburbs($match) as $sub) {
                    $town = $this->config->suburbToTown($agencyId, $sub);
                    if ($town === null) {
                        $norm = strtolower(trim($sub));
                        $wishlist[$norm] = ($wishlist[$norm] ?? 0) + 1;
                    }
                }
            }
        }

        $listingMap = []; // normalised => count
        foreach ($listings as $listing) {
            if ($listing->isUnmappedSuburb()) {
                $norm = (string) $listing->suburbNormalised;
                $listingMap[$norm] = ($listingMap[$norm] ?? 0) + 1;
            }
        }

        $all = collect(array_keys($wishlist))->merge(array_keys($listingMap))->unique()->values();

        return $all->map(fn ($s) => [
            'suburb'         => $s,
            'wishlist_count' => $wishlist[$s] ?? 0,
            'listing_count'  => $listingMap[$s] ?? 0,
            'total'          => ($wishlist[$s] ?? 0) + ($listingMap[$s] ?? 0),
        ])->sortByDesc('total')->values();
    }

    /* =========================================================
     |  Drill-down filter helpers
     * ========================================================= */

    private function filterBuyersByTownId(int $agencyId, Collection $buyers, int $townId): Collection
    {
        return $buyers->filter(function ($buyer) use ($agencyId, $townId) {
            foreach ($buyer->matches as $match) {
                foreach ($this->matchSuburbs($match) as $sub) {
                    $town = $this->config->suburbToTown($agencyId, $sub);
                    if ($town && $town->id === $townId) return true;
                }
            }
            return false;
        })->pluck('id')->values();
    }

    private function filterBuyersByPropertyTypeId(int $agencyId, Collection $buyers, int $typeId): Collection
    {
        $option = $this->config->propertyTypes($agencyId, activeOnly: false)->firstWhere('id', $typeId);
        if (!$option) return collect();
        return $buyers->filter(function ($buyer) use ($option) {
            foreach ($buyer->matches as $match) {
                foreach ($this->matchPropertyTypes($match) as $raw) {
                    if (Str::slug($raw) === $option->slug) return true;
                }
            }
            return false;
        })->pluck('id')->values();
    }

    private function filterBuyersByBedroomSegmentId(int $agencyId, Collection $buyers, int $segId): Collection
    {
        $seg = $this->config->bedroomSegments($agencyId)->firstWhere('id', $segId);
        if (!$seg) return collect();
        return $buyers->filter(function ($buyer) use ($seg) {
            foreach ($buyer->matches as $match) {
                $min = $match->beds_min ?? 0;
                $max = $match->bedrooms_max ?? $min;
                if ($max >= $seg->beds_min && ($seg->beds_max === null || $min <= $seg->beds_max)) return true;
            }
            return false;
        })->pluck('id')->values();
    }

    private function filterBuyersByPriceBandId(int $agencyId, Collection $buyers, int $bandId, string $listingType): Collection
    {
        $band = $this->config->priceBandsFor($agencyId, $listingType)->firstWhere('id', $bandId);
        if (!$band) return collect();
        return $buyers->filter(function ($buyer) use ($band, $listingType) {
            foreach ($buyer->matches as $match) {
                if ($match->listing_type !== null && $match->listing_type !== $listingType) continue;
                $min = $match->price_min ?? 0;
                $max = $match->price_max ?? PHP_INT_MAX;
                if ($max >= $band->price_min && ($band->price_max === null || $min < $band->price_max)) return true;
            }
            return false;
        })->pluck('id')->values();
    }

    private function filterBuyersByUnmappedSuburb(int $agencyId, Collection $buyers, string $normalisedSuburb): Collection
    {
        $normalisedSuburb = strtolower(trim($normalisedSuburb));
        return $buyers->filter(function ($buyer) use ($agencyId, $normalisedSuburb) {
            foreach ($buyer->matches as $match) {
                foreach ($this->matchSuburbs($match) as $sub) {
                    if (strtolower(trim($sub)) === $normalisedSuburb
                        && $this->config->suburbToTown($agencyId, $sub) === null
                    ) {
                        return true;
                    }
                }
            }
            return false;
        })->pluck('id')->values();
    }

    /* =========================================================
     |  Multi-tenancy guard
     * ========================================================= */

    private function assertAgencyId(array $filters): void
    {
        if (empty($filters['agency_id'])) {
            throw new \InvalidArgumentException(
                'ProspectingIntelligenceService requires agency_id in filters.'
            );
        }
    }
}
