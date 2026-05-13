<?php

namespace App\Services\Prospecting;

use App\Models\Prospecting\BedroomSegment;
use App\Models\Prospecting\PriceBand;
use App\Models\Prospecting\PropertyTypeOption;
use App\Models\Prospecting\Town;
use App\Models\Prospecting\TownSuburb;
use Illuminate\Support\Collection;

/**
 * Single read-side API for prospecting configuration. Consumed by the
 * prospecting intelligence layer, WhatsApp composer, and any feature that
 * buckets buyer/property data into the agency's configured dimensions.
 *
 * Multi-tenancy is an EXPLICIT API contract here: every public method takes
 * an $agencyId argument and bypasses the global AgencyScope. This makes the
 * service safe to call from queued jobs, super-admin operations, and any
 * other context where the implicit auth-derived agency may be absent or
 * wrong. See spec S6.
 *
 * Per-request cache: the service holds a private array keyed by agency_id.
 * Laravel's typed dependency injection resolves the same instance for the
 * lifetime of one HTTP request, so the cache is implicitly per-request.
 * Prompt 03 will wire a listener that calls clearCache() on configuration
 * writes.
 */
class ProspectingConfigurationService
{
    /**
     * @var array<int, array<string, mixed>>
     *
     * Shape:
     *   $cache[$agencyId]['towns']                       => Collection<Town>
     *   $cache[$agencyId]['suburb_to_town']              => array<string, Town>
     *   $cache[$agencyId]['property_types_active']       => Collection<PropertyTypeOption>
     *   $cache[$agencyId]['property_types_all']          => Collection<PropertyTypeOption>
     *   $cache[$agencyId]['bedroom_segments']            => Collection<BedroomSegment>
     *   $cache[$agencyId]['price_bands'][$listingType]   => Collection<PriceBand>
     */
    private array $cache = [];

    public function towns(int $agencyId): Collection
    {
        return $this->cache[$agencyId]['towns'] ??=
            Town::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->ordered()
                ->get();
    }

    public function suburbToTown(int $agencyId, string $suburb): ?Town
    {
        $normalised = TownSuburb::normaliseSuburb($suburb);

        $map = $this->cache[$agencyId]['suburb_to_town']
            ??= $this->buildSuburbMap($agencyId);

        return $map[$normalised] ?? null;
    }

    public function propertyTypes(int $agencyId, bool $activeOnly = true): Collection
    {
        $key = $activeOnly ? 'property_types_active' : 'property_types_all';

        return $this->cache[$agencyId][$key]
            ??= PropertyTypeOption::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->when($activeOnly, fn ($q) => $q->active())
                ->ordered()
                ->get();
    }

    public function bedroomSegments(int $agencyId): Collection
    {
        return $this->cache[$agencyId]['bedroom_segments'] ??=
            BedroomSegment::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->ordered()
                ->get();
    }

    public function priceBandsFor(int $agencyId, string $listingType): Collection
    {
        return $this->cache[$agencyId]['price_bands'][$listingType] ??=
            PriceBand::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->forListingType($listingType)
                ->ordered()
                ->get();
    }

    public function classifyPrice(int $agencyId, string $listingType, int $priceRand): ?PriceBand
    {
        return $this->priceBandsFor($agencyId, $listingType)
            ->first(fn (PriceBand $band) => $band->covers($priceRand));
    }

    public function bedroomBucketFor(int $agencyId, int $beds): ?BedroomSegment
    {
        return $this->bedroomSegments($agencyId)
            ->first(fn (BedroomSegment $segment) => $segment->covers($beds));
    }

    /**
     * Clear the cache for one agency, or for all if no id is given. Called
     * by the configuration-write listeners (Prompt 03) so subsequent reads
     * pick up the fresh data within the same request.
     */
    public function clearCache(?int $agencyId = null): void
    {
        if ($agencyId === null) {
            $this->cache = [];
            return;
        }
        unset($this->cache[$agencyId]);
    }

    /**
     * Suburbs found in this agency's active prospecting listings or active
     * buyer wishlists that are NOT yet mapped in town_suburbs.
     *
     * Drives the cleanup widget on the Settings → Prospecting Setup → Towns
     * tab (and the same widget when surfaced via the prospecting tab drawer).
     *
     * Returns Collection<array{suburb_raw, suburb_normalised, listing_count,
     *   wishlist_count, total}> sorted by total descending.
     */
    public function unmappedSuburbsFor(int $agencyId): Collection
    {
        $mapped = TownSuburb::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->pluck('suburb_normalised')
            ->all();
        $mappedSet = array_flip($mapped);

        $listingRows = \Illuminate\Support\Facades\DB::table('prospecting_listings')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->whereNotNull('suburb')
            ->select(\Illuminate\Support\Facades\DB::raw('suburb AS suburb_raw, LOWER(TRIM(suburb)) AS suburb_normalised, COUNT(*) AS listing_count'))
            ->groupBy('suburb', \Illuminate\Support\Facades\DB::raw('LOWER(TRIM(suburb))'))
            ->get()
            ->filter(fn ($row) => !isset($mappedSet[$row->suburb_normalised]));

        $bucket = [];
        foreach ($listingRows as $row) {
            $key = $row->suburb_normalised;
            if (!isset($bucket[$key])) {
                $bucket[$key] = [
                    'suburb_normalised' => $key,
                    'suburb_raw'        => $row->suburb_raw,
                    'listing_count'     => 0,
                    'wishlist_count'    => 0,
                ];
            }
            $bucket[$key]['listing_count'] += (int) $row->listing_count;
        }

        \App\Models\ContactMatch::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereNotNull('suburbs')
            ->select('suburbs')
            ->chunk(500, function ($chunk) use (&$bucket, $mappedSet) {
                foreach ($chunk as $match) {
                    $list = is_string($match->suburbs) ? json_decode($match->suburbs, true) : ($match->suburbs ?: []);
                    foreach ((array) $list as $sub) {
                        $raw  = (string) $sub;
                        $norm = strtolower(trim($raw));
                        if ($norm === '' || isset($mappedSet[$norm])) continue;
                        if (!isset($bucket[$norm])) {
                            $bucket[$norm] = [
                                'suburb_normalised' => $norm,
                                'suburb_raw'        => $raw,
                                'listing_count'     => 0,
                                'wishlist_count'    => 0,
                            ];
                        }
                        $bucket[$norm]['wishlist_count']++;
                    }
                }
            });

        return collect($bucket)
            ->map(fn ($r) => array_merge($r, ['total' => $r['listing_count'] + $r['wishlist_count']]))
            ->sortByDesc('total')
            ->values();
    }

    /**
     * @return array<string, Town> keyed by suburb_normalised
     */
    private function buildSuburbMap(int $agencyId): array
    {
        // The eager-load uses withoutGlobalScopes() on its own query so the
        // suburb's town hydrates even when the caller's auth context belongs
        // to a different agency.
        $suburbs = TownSuburb::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->with(['town' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        $map = [];
        foreach ($suburbs as $suburb) {
            $map[$suburb->suburb_normalised] = $suburb->town;
        }
        return $map;
    }
}
