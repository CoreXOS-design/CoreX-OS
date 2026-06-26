<?php

declare(strict_types=1);

namespace App\Services\Map;

/**
 * Phase 3g B1 — bounding-box + filter spec for MapPinService.
 *
 * Constructed from an HTTP request by MapController. Validation lives in
 * the controller's form-request layer; this DTO assumes valid input.
 */
final class MapBoundsRequest
{
    /** Hard cap so a malformed request can't ask for 1m pins. */
    public const MAX_LIMIT = 5000;

    /** @var string[] */
    public const VALID_LAYERS = [
        'hfc_listings', 'hfc_sold', 'hfc_off_market',
        'sold_comps', 'active_listings', 'mic_subjects', 'scheme_owners',
        'tracked_properties',
    ];

    public function __construct(
        public readonly float  $north,
        public readonly float  $south,
        public readonly float  $east,
        public readonly float  $west,
        /** @var string[] */
        public readonly array  $layers,
        public readonly string $viewMode,
        public readonly int    $agencyId,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        /** @var string[] */
        public readonly array  $propertyTypes = [],
        public readonly ?int   $priceMin = null,
        public readonly ?int   $priceMax = null,
        public readonly int    $limit = 2000,
        // Phase 3h Step 9.5 — when false, hide is_demo=true rows from
        // the map. Defaults to true so demo pins are visible until
        // someone explicitly toggles them off (the left-rail switch
        // wires this up from localStorage).
        public readonly bool   $includeDemo = true,
        // Phase 3g V2 — extended filter fields. All optional, defaults
        // preserve V1 behaviour (no filtering when null).
        public readonly ?int   $dateFromYear = null,
        public readonly ?int   $dateToYear   = null,
        /** @var int[] */
        public readonly array  $bedrooms     = [],
        // Phase 3g V2 Part E — radius post-filter for the embedded views.
        // When all 3 set, pins outside Haversine(center, pin) <= radiusM
        // are dropped after the bounding-box pre-filter. The standalone
        // map doesn't pass these, so V1 behaviour is unchanged.
        public readonly ?float $radiusCenterLat = null,
        public readonly ?float $radiusCenterLng = null,
        public readonly ?int   $radiusM         = null,
        // Phase A.3.1 — stock scope. 'my' (responsible agent = current user)
        // / 'agency' (current agency only — default) / 'all' (no agency
        // narrowing, admin-only). Service layer enforces actor-id wiring
        // since this struct doesn't carry user id.
        public readonly ?string $scope = null,
        public readonly ?int    $actorUserId = null,
        // Phase A.3.1 — free-text search across address / scheme / agent /
        // agency / portal_ref / contact name (Agent View only for the last).
        // Case-insensitive LIKE on each layer's per-source columns.
        public readonly ?string $search = null,
        // Phase A.3.1 — extra range filters. Each is null when not narrowed
        // (no filter); pairs may set just min or just max.
        public readonly ?int    $bedroomsMin   = null,
        public readonly ?int    $bedroomsMax   = null,
        public readonly ?int    $bathroomsMin  = null,
        public readonly ?int    $bathroomsMax  = null,
        public readonly ?int    $standMin      = null,
        public readonly ?int    $standMax      = null,
        public readonly ?int    $buildingMin   = null,
        public readonly ?int    $buildingMax   = null,
        /** @var string[] Listing status enum values (active, sold, draft, ...). */
        public readonly array   $listingStatus = [],
        // Phase A.3.1 — sold-date window for sold comps / sold properties.
        // Values: '3mo' | '6mo' | '12mo' | '24mo' | 'all'.
        public readonly ?string $soldWindow    = null,
        public readonly ?int    $domMin        = null,
        public readonly ?int    $domMax        = null,
        // Map fixes — specific-agent filter (agency-stock layers) + area/suburb
        // multi-select (matches properties.p24_suburb_id). Null/empty = no narrowing.
        public readonly ?int    $agentId       = null,
        /** @var int[] p24_suburbs.id values */
        public readonly array   $suburbIds     = [],
    ) {}

    public function hasRadiusFilter(): bool
    {
        return $this->radiusCenterLat !== null
            && $this->radiusCenterLng !== null
            && $this->radiusM !== null;
    }

    public function isSellerView(): bool
    {
        return $this->viewMode === 'seller';
    }

    public function wantsLayer(string $key): bool
    {
        return in_array($key, $this->layers, true);
    }

    public function effectiveLimit(): int
    {
        $max = (int) config('map.defaults.caps.max_limit', self::MAX_LIMIT);
        return min(max(1, $this->limit), $max);
    }

    /**
     * Phase 9a hardening — degree-span heuristic for "how zoomed in is the
     * caller". Wider boxes (country/region view) cap pins more aggressively
     * because the view doesn't gain detail from 2000 pins at country zoom.
     *
     *   span < 0.05° (street/suburb) → no extra cap, use effectiveLimit()
     *   span < 0.5°  (town/district) → cap 500 / layer
     *   span ≥ 0.5°  (region+)       → cap 200 / layer
     */
    public function zoomAwarePerLayerLimit(int $layerCount): int
    {
        // Caps are config-driven (config/map.php) — no hardcoded magic numbers.
        $minPer    = (int) config('map.defaults.caps.min_per_layer', 50);
        $regionCap = (int) config('map.defaults.caps.region_cap', 200);
        $townCap   = (int) config('map.defaults.caps.town_cap', 500);

        $base = max($minPer, (int) floor($this->effectiveLimit() / max(1, $layerCount)));
        $span = max(abs($this->north - $this->south), abs($this->east - $this->west));
        if ($span >= 0.5)  return min($base, $regionCap);
        if ($span >= 0.05) return min($base, $townCap);
        return $base;
    }

    /**
     * Per-key cap override on top of zoomAwarePerLayerLimit().
     *
     * Two layers get the same dense-zoom bump (floor 1000, ceiling 1500):
     *
     *   - tracked_properties — Bumped originally after the 2026-05-27 Google
     *     geocoding backfill made this the most numerous layer. The base
     *     (effectiveLimit / layerCount = 2000/6 = 333) was truncating dense
     *     coast bands like Ballito / Margate; tripling fixes that.
     *
     *   - active_listings (MAP-CAP, post-MAP-FIX) — Now reads
     *     prospecting_listings GPS directly. Live has ~5,912 geocoded
     *     prospecting_listings; the un-bumped zoom-aware cap (200 at region
     *     zoom, 500 at district zoom) was returning ~tens of pins and the
     *     UI badge read as "this agency has few properties". Bumping to
     *     the same 1000-floor / 1500-ceiling profile gives an honest
     *     density signal at every zoom level without ballooning beyond
     *     what Leaflet can cluster smoothly.
     *
     * Other layers (hfc_listings, sold_comps, mic_subjects, scheme_owners)
     * use the zoom-aware base verbatim — none has the row-count profile
     * that warrants a bump in V1.
     */
    public function perLayerLimitFor(string $key, int $layerCount): int
    {
        $base = $this->zoomAwarePerLayerLimit($layerCount);
        if (in_array($key, ['tracked_properties', 'active_listings'], true)) {
            $floor   = (int) config('map.defaults.caps.dense_layer_floor', 1000);
            $ceiling = (int) config('map.defaults.caps.dense_layer_ceiling', 1500);
            return min(max($base * 3, $floor), $ceiling);
        }
        return $base;
    }
}
