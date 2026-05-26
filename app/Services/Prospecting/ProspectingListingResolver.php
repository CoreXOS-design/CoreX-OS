<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Support\Prospecting\ResolvedListing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves prospecting opportunities from the agency's portal-discovered
 * listings into a unified, paginated, filterable stream of ResolvedListing
 * DTOs.
 *
 * READ-ONLY. Never writes to any source table.
 *
 * Spec adaptation (recorded at Prompt 01 build time):
 *   The intelligence spec speculated 4 separate source tables (captured,
 *   p24_alert, pp_alert, portal_scrape). The actual schema has ONE table —
 *   `prospecting_listings` — discriminated by a `portal_source` enum with
 *   two values: 'p24' (Property24) and 'pp' (Private Property). The
 *   resolver treats these as logical sources within a single query path.
 *
 * Filter shape (all optional except agency_id):
 *   [
 *     'agency_id'          => 1,                    // REQUIRED
 *     'sources'            => ['p24', 'pp'],        // null = both
 *     'town_id'            => 5,
 *     'suburb_normalised'  => 'margate',
 *     'unmapped_only'      => false,                // suburbs with no town mapping
 *     'property_type_slug' => 'house',
 *     'bedroom_segment_id' => 3,
 *     'price_band_id'      => 7,                    // requires listing_type
 *     'listing_type'       => 'sale',               // always 'sale' for v1
 *     'status'             => 'active' | 'archived',
 *     'sourced_since'      => \DateTimeInterface,
 *     'sort'               => 'recent' | 'price_asc' | 'price_desc',
 *   ]
 *
 * Multi-tenancy is STRUCTURAL: agency_id is required and bypassing it is
 * an exception, not a silent fall-back. The resolver does NOT use any
 * implicit auth-derived agency.
 */
final class ProspectingListingResolver
{
    private const SOURCE_TABLE   = 'prospecting_listings';
    private const ALL_SOURCES    = ['p24', 'pp'];
    private const STATUS_ACTIVE  = 'active';
    private const STATUS_ARCHIVED = 'archived';

    public function __construct(
        private readonly ProspectingConfigurationService $config,
    ) {}

    /**
     * Paginated read.
     *
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters, int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        $resolved = $this->all($filters);

        $total = $resolved->count();
        $items = $resolved->slice(($page - 1) * $perPage, $perPage)->values();

        return new Paginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: ['path' => request()?->url() ?? '', 'pageName' => 'page'],
        );
    }

    /**
     * Full read.
     *
     * @param array<string,mixed> $filters
     * @return Collection<int, ResolvedListing>
     */
    public function all(array $filters): Collection
    {
        $this->assertAgencyId($filters);
        $agencyId = (int) $filters['agency_id'];

        $sources = $this->resolveActiveSources($filters['sources'] ?? null);
        if (empty($sources)) {
            return collect();
        }

        $rows = $this->loadRows($agencyId, $sources, $filters);

        $resolved = collect();
        foreach ($rows as $row) {
            $resolved->push($this->resolveRow($agencyId, $row));
        }

        $resolved = $this->applyPostResolutionFilters($resolved, $filters);

        return $this->applySort($resolved, $filters['sort'] ?? 'recent');
    }

    private function assertAgencyId(array $filters): void
    {
        if (empty($filters['agency_id'])) {
            throw new \InvalidArgumentException(
                'ProspectingListingResolver requires agency_id in filters (multi-tenancy invariant).'
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolveActiveSources(?array $requested): array
    {
        if ($requested === null || $requested === []) {
            return self::ALL_SOURCES;
        }
        return array_values(array_intersect(self::ALL_SOURCES, $requested));
    }

    /**
     * @return array<int, object>
     */
    private function loadRows(int $agencyId, array $sources, array $filters): array
    {
        $q = DB::table(self::SOURCE_TABLE)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('portal_source', $sources);

        $this->applySourceSideFilters($q, $filters);

        return $q->get()->all();
    }

    private function applySourceSideFilters(Builder $q, array $filters): void
    {
        if (!empty($filters['suburb_normalised'])) {
            $q->whereRaw('LOWER(TRIM(suburb)) = ?', [strtolower(trim((string) $filters['suburb_normalised']))]);
        }

        // The source table has no listing_type column — all rows are 'sale'.
        // If a caller filters for 'rental' we return nothing rather than throwing.
        if (!empty($filters['listing_type']) && $filters['listing_type'] !== 'sale') {
            $q->whereRaw('1 = 0');
        }

        if (!empty($filters['status'])) {
            $q->where('is_active', $filters['status'] === self::STATUS_ACTIVE ? 1 : 0);
        }

        if (!empty($filters['sourced_since']) && $filters['sourced_since'] instanceof \DateTimeInterface) {
            $q->where('first_seen_at', '>=', $filters['sourced_since']);
        }

        // town_id requires resolving via the agency's town_suburbs mapping.
        if (!empty($filters['town_id'])) {
            $townId = (int) $filters['town_id'];
            $q->whereExists(function ($sq) use ($townId) {
                $sq->select(DB::raw(1))
                    ->from('town_suburbs as ts')
                    ->join('towns as t', 't.id', '=', 'ts.town_id')
                    ->whereColumn('ts.agency_id', self::SOURCE_TABLE . '.agency_id')
                    ->where('t.id', $townId)
                    ->whereRaw('ts.suburb_normalised = LOWER(TRIM(' . self::SOURCE_TABLE . '.suburb))')
                    ->whereNull('ts.deleted_at')
                    ->whereNull('t.deleted_at');
            });
        }
    }

    /**
     * @param Collection<int, ResolvedListing> $resolved
     * @return Collection<int, ResolvedListing>
     */
    private function applyPostResolutionFilters(Collection $resolved, array $filters): Collection
    {
        if (!empty($filters['property_type_slug'])) {
            $slug = (string) $filters['property_type_slug'];
            $resolved = $resolved->filter(function (ResolvedListing $r) use ($slug) {
                if ($r->propertyTypeRaw === null) {
                    return false;
                }
                return Str::slug((string) $r->propertyTypeRaw) === $slug;
            });
        }

        if (!empty($filters['bedroom_segment_id'])) {
            $segId = (int) $filters['bedroom_segment_id'];
            $resolved = $resolved->filter(fn (ResolvedListing $r) => $r->bedroomSegmentId === $segId);
        }

        if (!empty($filters['price_band_id'])) {
            $bandId = (int) $filters['price_band_id'];
            $resolved = $resolved->filter(fn (ResolvedListing $r) => $r->priceBandId === $bandId);
        }

        if (!empty($filters['unmapped_only'])) {
            $resolved = $resolved->filter(fn (ResolvedListing $r) => $r->isUnmappedSuburb());
        }

        return $resolved->values();
    }

    /**
     * @param Collection<int, ResolvedListing> $resolved
     * @return Collection<int, ResolvedListing>
     */
    private function applySort(Collection $resolved, string $sort): Collection
    {
        return match ($sort) {
            'price_asc'  => $resolved->sortBy(fn (ResolvedListing $r) => $r->price ?? PHP_INT_MAX)->values(),
            'price_desc' => $resolved->sortByDesc(fn (ResolvedListing $r) => $r->price ?? -1)->values(),
            default      => $resolved->sortByDesc(fn (ResolvedListing $r) => $r->sourcedAt?->getTimestamp() ?? 0)->values(),
        };
    }

    private function resolveRow(int $agencyId, object $row): ResolvedListing
    {
        $suburbRaw  = isset($row->suburb) ? (string) $row->suburb : null;
        $suburbNorm = $suburbRaw !== null ? strtolower(trim($suburbRaw)) : null;

        $town = ($suburbRaw !== null && $suburbRaw !== '')
            ? $this->config->suburbToTown($agencyId, $suburbRaw)
            : null;

        $propertyTypeRaw      = isset($row->property_type) ? (string) $row->property_type : null;
        $propertyTypeOptionId = null;
        if ($propertyTypeRaw !== null && $propertyTypeRaw !== '') {
            $slug = Str::slug($propertyTypeRaw);
            $match = $this->config->propertyTypes($agencyId, activeOnly: false)
                ->firstWhere('slug', $slug);
            $propertyTypeOptionId = $match?->id;
        }

        $bedrooms          = isset($row->bedrooms) && $row->bedrooms !== null ? (int) $row->bedrooms : null;
        $bedroomSegmentId  = $bedrooms !== null
            ? $this->config->bedroomBucketFor($agencyId, $bedrooms)?->id
            : null;

        $price       = isset($row->price) && $row->price !== null ? (int) $row->price : null;
        $listingType = 'sale'; // v1: source table has no rental column
        $priceBandId = ($price !== null && $price > 0)
            ? $this->config->classifyPrice($agencyId, $listingType, $price)?->id
            : null;

        $sourcedAt  = $this->parseDate($row->first_seen_at ?? $row->created_at ?? null);
        $lastSeenAt = $this->parseDate($row->last_seen_at ?? null);

        return new ResolvedListing(
            source:               (string) ($row->portal_source ?? 'p24'),
            sourceId:             (int) $row->id,
            sourceTable:          self::SOURCE_TABLE,
            agencyId:             $agencyId,
            capturedByUserId:     isset($row->captured_by_user_id) ? (int) $row->captured_by_user_id : null,
            suburbName:           $suburbRaw,
            suburbNormalised:     $suburbNorm,
            townId:               $town?->id,
            townName:             $town?->name ?? ($suburbRaw !== null ? "(unmapped) {$suburbRaw}" : null),
            propertyTypeRaw:      $propertyTypeRaw,
            propertyTypeOptionId: $propertyTypeOptionId,
            bedrooms:             $bedrooms,
            bedroomSegmentId:     $bedroomSegmentId,
            price:                $price,
            listingType:          $listingType,
            priceBandId:          $priceBandId,
            status:               (isset($row->is_active) && (int) $row->is_active === 1) ? self::STATUS_ACTIVE : self::STATUS_ARCHIVED,
            address:              isset($row->address) ? (string) $row->address : null,
            title:                isset($row->address) ? (string) $row->address : null,
            sourcedAt:            $sourcedAt,
            lastSeenAt:           $lastSeenAt,
            matchedPropertyId:    isset($row->matched_property_id) ? (int) $row->matched_property_id : null,
            sourceMeta:           [
                'portal_ref'  => $row->portal_ref  ?? null,
                'portal_url'  => $row->portal_url  ?? null,
                'agent_name'  => $row->agent_name  ?? null,
                'agency_name' => $row->agency_name ?? null,
                'thumbnail'   => $row->thumbnail_path ?? null,
                'bathrooms'   => $row->bathrooms   ?? null,
                'garages'     => $row->garages     ?? null,
                'erf_size_m2' => $row->erf_size_m2 ?? null,
            ],
        );
    }

    private function parseDate(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable((string) $raw);
        } catch (\Exception) {
            return null;
        }
    }
}
