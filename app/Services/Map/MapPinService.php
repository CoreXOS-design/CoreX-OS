<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\Support\MarketAnalytics\OutlierGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3g B1/B2 — return pins for the Map module in a single bounding-box query.
 *
 * Every layer query is agency-scoped at the WHERE level (no reliance on global
 * scopes — this service is called from the routes/web.php auth context with
 * the explicit agency_id from the authenticated user).
 *
 * Architectural decisions (not pre-decided in spec, called out here):
 *   - presentation_sold_comps + presentation_active_listings carry no lat/lng
 *     columns. V1 derives GPS by joining raw_row_json.mic_comp_row_id into
 *     market_report_comp_rows. Non-MIC rows are skipped in V1 — they'd need
 *     a schema change (and a backfill via the geocoder) which is out of scope.
 *   - scheme_owners carry no lat/lng columns. V1 joins through market_reports
 *     on scheme_name → subject_scheme_name so every unit in a scheme inherits
 *     the building's GPS. Accurate to within ~10m which is fine for a map pin.
 *   - imported_listings is referenced in the spec but doesn't exist in this
 *     codebase (Phase 3f confirmed). Skip that branch.
 *   - Sensitive layers (scheme_owners) are excluded entirely in Seller View,
 *     not just stripped — they have nothing useful left after stripping owner
 *     name + contact info.
 */
final class MapPinService
{
    public function __construct(private readonly ?LocationGrouper $grouper = null) {}

    private function grouper(): LocationGrouper
    {
        return $this->grouper ?? new LocationGrouper();
    }

    /**
     * Phase A.1 — composite-pin shape.
     *
     *   {
     *     bounds, layer_counts{key→int}, totals{key→int}, capped_layers[],
     *     locations: [
     *       { location_key, latitude, longitude, geocode_target?, grouping_basis,
     *         record_count, is_composite, primary_category, categories_present,
     *         records: [ {id, category, title, subtitle, summary, deep_link, ...} ] }
     *     ]
     *   }
     *
     * Single-record locations have `is_composite=false` and `record_count=1`
     * — the UI keeps the category colour for these. Anything composite gets
     * a neutral icon + count badge in the renderer.
     *
     * @return array{bounds: array, locations: array, layer_counts: array, totals: array, capped_layers: array}
     */
    public function getPinsInBounds(MapBoundsRequest $req): array
    {
        // Phase 9a hardening — at wide-zoom (country/region) the view gains
        // no detail from 2000 pins; cap aggressively to keep query cost
        // bounded. Per-layer cap resolved via MapBoundsRequest::perLayerLimitFor()
        // which lets tracked_properties bump above the zoom-aware base
        // (most numerous layer post-Google backfill).
        $layerCount   = count($req->layers);
        $layerCounts  = [];
        $totals       = [];
        $cappedLayers = [];
        $layerLimits  = []; // MAP-CAP — populated per layer below
        $allRecords   = [];

        // MAP-CAP — closures now take the per-layer limit as a parameter so
        // the foreach below can capture it for both (a) the actual fetch
        // and (b) the cap-detection comparison further down.
        $sources = [
            'hfc_listings'       => fn (int $lim) => $this->hfcListings($req, $lim),
            // Map fixes — agency stock split into status-distinct layers (the explosion
            // fix). Active = on-market only (default ON); Sold + Off-market are separate
            // toggleable layers with distinct pins, OFF by default.
            'hfc_sold'           => fn (int $lim) => $this->hfcSold($req, $lim),
            'hfc_off_market'     => fn (int $lim) => $this->hfcOffMarket($req, $lim),
            'sold_comps'         => fn (int $lim) => $this->soldComps($req, $lim),
            'active_listings'    => fn (int $lim) => $this->activeListings($req, $lim),
            'mic_subjects'       => fn (int $lim) => $this->micSubjects($req, $lim),
            // A.2.3 Item 3 — Sectional schemes now appear in Seller View too,
            // but with owner identity redacted at the toRecord boundary below.
            // (Pre-A.2.3 the whole layer was suppressed in Seller View — that
            // was over-cautious and hid useful scheme metadata.)
            'scheme_owners'      => fn (int $lim) => $this->schemeOwners($req, $lim),
            // T layer — prospecting candidates with geocoded GPS. Sensitive:
            // suppressed entirely from Seller View (see the dispatch guard
            // below). Wired post-2026-05-27 Google geocoding backfill.
            'tracked_properties' => fn (int $lim) => $this->trackedProperties($req, $lim),
        ];

        foreach ($sources as $key => $fetch) {
            if (!$req->wantsLayer($key)) continue;
            // Seller View suppression for sensitive layers. scheme_owners
            // has per-pin redaction (A.2.3); tracked_properties is dropped
            // wholesale because prospecting intelligence is agent-only.
            if ($key === 'tracked_properties' && $req->isSellerView()) {
                $layerCounts[$key] = 0;
                $totals[$key]      = 0;
                continue;
            }
            $limit = $req->perLayerLimitFor($key, $layerCount);
            $layerLimits[$key] = $limit;
            /** @var array{0: array, 1: int} $result */
            $result = $fetch($limit);
            [$pins, $total] = $result;

            // MAP-CLUSTER — layer_counts reflects items REPRESENTED, not
            // markers on the map. For per-pin layers each pin = 1 item
            // (no aggregate_count → defaults to 1, sum == pin count). For
            // wide-zoom aggregate layers, each bucket carries
            // aggregate_count = COUNT(*) for its tile, so the sum is the
            // true total of underlying listings. The badge therefore
            // shows the honest number at every zoom.
            $layerCounts[$key] = (int) array_sum(array_map(
                fn ($p) => (int) ($p['aggregate_count'] ?? 1),
                $pins
            ));
            $totals[$key]      = $total;
            // MAP-CAP — cap detection covers two layer-fetcher patterns:
            //   1) Layers that compute a real total via a separate COUNT(*)
            //      (e.g. mic_subjects) — `$total > count($pins)` already
            //      signals that the LIMIT dropped rows.
            //   2) Layers whose internal dedup map doubles as the total
            //      (most layers — `return [$pins, count($combined)]`) —
            //      `$total === count($pins)` always, so the only honest
            //      truncation signal is "count(pins) >= limit". This is
            //      the cheaper-than-COUNT(*) path the MAP-CAP prompt
            //      explicitly preferred.
            if ($total > count($pins) || count($pins) >= $limit) {
                $cappedLayers[] = $key;
            }

            // A.2.3 — redact scheme-owner identity in Seller View. The pin
            // still appears (building location, scheme name, unit count) but
            // owner_name becomes "Owner" and phone/email are stripped.
            if ($key === 'scheme_owners' && $req->isSellerView()) {
                $pins = $this->redactSchemeOwnerIdentity($pins);
            }

            // Normalise into the record shape the grouper expects.
            foreach ($pins as $p) {
                $allRecords[] = $this->toRecord($key, $p);
            }
        }

        $locations = $this->grouper()->group($allRecords);

        return [
            'bounds'        => [
                'north' => $req->north, 'south' => $req->south,
                'east'  => $req->east,  'west'  => $req->west,
            ],
            'locations'     => $locations,
            'layer_counts'  => $layerCounts,
            'totals'        => $totals,
            'capped_layers' => $cappedLayers,
            // MAP-CAP — per-layer limit so the UI badge can render
            // "{limit}+" when a layer's result was truncated. Without
            // this the client would have to mirror perLayerLimitFor
            // heuristics, which we don't want duplicated.
            'layer_limits'  => $layerLimits,
        ];
    }

    /**
     * Map a V1 pin payload from a per-layer fetcher into the V2 record shape
     * the grouper + frontend expect.
     */
    /**
     * A.2.3 Item 3 — POPIA-safe scheme owner pins for Seller View.
     *
     * Replaces the owner identity bits (name, phone, email) with a generic
     * "Owner" label so the agent can still see the building + section number
     * in the right-panel composite list without exposing personal info to
     * a seller-side viewer.
     *
     * @param array<int, array<string, mixed>> $pins
     * @return array<int, array<string, mixed>>
     */
    private function redactSchemeOwnerIdentity(array $pins): array
    {
        foreach ($pins as &$pin) {
            $pin['subtitle']    = 'Owner';   // was the owner's name
            $pin['owner_name']  = 'Owner';
            $pin['owner_phone'] = null;
            $pin['owner_email'] = null;
        }
        unset($pin);
        return $pins;
    }

    private function toRecord(string $category, array $pin): array
    {
        // For grouping the parser needs an address — pull from the V1 title
        // because that's where each fetcher already put the human address
        // string. Suburb hint, when known, sharpens the parser's split.
        $address = (string) ($pin['title'] ?? '');
        $suburb  = $pin['suburb'] ?? null;

        return [
            'id'         => $pin['id'] ?? null,
            'category'   => $category,
            'title'      => $pin['title']    ?? '',
            'subtitle'   => $pin['subtitle'] ?? '',
            'summary'    => trim(($pin['title'] ?? '') . ($pin['subtitle'] ? ' · ' . $pin['subtitle'] : '')),
            'deep_link'  => $pin['detail_url'] ?? null,
            'lat'        => (float) $pin['lat'],
            'lng'        => (float) $pin['lng'],
            'address'    => $address,
            'suburb'     => $suburb,
            'sensitive'  => $pin['sensitive'] ?? false,
            'price'      => $pin['price']     ?? null,
            'date'       => $pin['date']      ?? null,
            // A.2.1 — per-category extras used by actionsForRecord() in the JS.
            'status'               => $pin['status']               ?? null,
            'preferred_public_url' => $pin['preferred_public_url'] ?? null,
            'internal_url'         => $pin['internal_url']         ?? null,
            'parent_report_id'     => $pin['parent_report_id']     ?? null,
            'tracked_property_id'  => $pin['tracked_property_id']  ?? null,
            'owner_phone'          => $pin['owner_phone']          ?? null,
            'owner_email'          => $pin['owner_email']          ?? null,
            // A.2.3 Item 4 — full {p24,pp,hfc} URL map for the portal strip.
            'public_listing_urls'  => $pin['public_listing_urls']  ?? null,
            // Q8 — own-vs-market provenance flag. Carried through from the
            // S layer (soldComps); other layers don't set it and it stays null.
            // Used downstream by the UI to differentiate HFC-history pins from
            // competitor-market comps without consulting the legacy `hfc_sold`
            // boolean.
            'source_class'         => $pin['source_class']         ?? null,
            // Q3 M-collapse — the LocationGrouper needs the M record's report
            // type so it can attach the right CMA-info context to the primary
            // pin when M collapses. Surfaced for mic_subjects pins; null
            // elsewhere.
            'report_type_key'      => $pin['report_type_key']      ?? null,
            'report_type_name'     => $pin['report_type_name']     ?? null,
            // MAP-CLUSTER — per-pin "how many underlying listings does this
            // pin represent". 1 for individual pins; N for aggregate-bucket
            // pins at wide zoom. The frontend cluster icon sums this across
            // children so cluster bubbles read the TRUE total, not the
            // capped sample count.
            'aggregate_count'      => $pin['aggregate_count']      ?? null,
            // MAP-CARD-FIX — fields the portal-listing inline card needs.
            // Only populated for active_listings per-pin payloads; null for
            // other layers. The card renders directly from these without a
            // follow-up fetch (mirrors the tracked_properties pattern).
            'portal_source'        => $pin['portal_source']        ?? null,
            'portal_url'           => $pin['portal_url']           ?? null,
            'bedrooms'             => $pin['bedrooms']             ?? null,
            'bathrooms'            => $pin['bathrooms']            ?? null,
            'garages'              => $pin['garages']              ?? null,
            'property_type'        => $pin['property_type']        ?? null,
            'property_size_m2'     => $pin['property_size_m2']     ?? null,
            'erf_size_m2'          => $pin['erf_size_m2']          ?? null,
            'thumbnail_url'        => $pin['thumbnail_url']        ?? null,
            'first_seen_at'        => $pin['first_seen_at']        ?? null,
        ];
    }

    /**
     * Shared agency-stock base query (bounds + scope + type/price/size + agent/suburb).
     * STATUS is applied per layer by the caller via Property::scopeOnMarket() /
     * Property::OFF_MARKET_STATUSES — the single source of truth (no forked status list).
     * Single table, no joins, so column references stay unambiguous.
     */
    private function agencyStockBaseQuery(MapBoundsRequest $req)
    {
        $q = DB::table('properties')
            ->whereNull('deleted_at')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereBetween('latitude',  [$req->south, $req->north])
            ->whereBetween('longitude', [$req->west,  $req->east]);

        $this->applyScopeFilter($q, $req, 'agency_id', 'agent_id');
        $this->applyDemoFilter($q, $req, 'is_demo');
        $this->applyPropertyTypeFilter($q, $req, 'property_type');
        $this->applyTypeFilter($q, $req, 'property_type');
        $this->applyBedroomsFilter($q, $req, 'beds');
        $this->applyPriceFilter($q, $req, 'price');
        $this->applyRangeFilter($q, $req->bedroomsMin,  $req->bedroomsMax,  'beds');
        $this->applyRangeFilter($q, $req->bathroomsMin, $req->bathroomsMax, 'baths');
        $this->applyRangeFilter($q, $req->standMin,     $req->standMax,     'erf_size_m2');
        $this->applyRangeFilter($q, $req->buildingMin,  $req->buildingMax,  'size_m2');
        $this->applySearchFilter($q, $req, ['address', 'title', 'complex_name', 'suburb']);
        // Map fixes — specific-agent + area/suburb narrowing.
        $this->applyAgentFilter($q, $req, 'agent_id');
        $this->applySuburbFilter($q, $req, 'p24_suburb_id');

        return $q;
    }

    /** The select column set every agency-stock pin needs. */
    private function agencyStockColumns(): array
    {
        return [
            'id', 'agency_id', 'address', 'property_type', 'price', 'status',
            'latitude', 'longitude', 'suburb', 'city', 'town', 'province',
            'pp_ref', 'p24_ref', 'pp_syndication_status', 'p24_syndication_status',
            'pp_suburb_id', 'listing_type',
        ];
    }

    /** Build a pin array for one agency-stock row (shared across the 3 status layers). */
    private function agencyStockPin($r, string $layer, ?string $date): array
    {
        $p = new \App\Models\Property();
        $p->setRawAttributes([
            'id'                     => $r->id,
            'agency_id'              => $r->agency_id,
            'status'                 => $r->status,
            'address'                => $r->address,
            'suburb'                 => $r->suburb,
            'city'                   => $r->town ?? $r->city,
            'town'                   => $r->town,
            'province'               => $r->province,
            'property_type'          => $r->property_type,
            'pp_ref'                 => $r->pp_ref,
            'p24_ref'                => $r->p24_ref,
            'pp_syndication_status'  => $r->pp_syndication_status,
            'p24_syndication_status' => $r->p24_syndication_status,
            'pp_suburb_id'           => $r->pp_suburb_id,
            'listing_type'           => $r->listing_type,
        ]);

        return [
            'id'                   => (int) $r->id,
            'layer'                => $layer,
            'lat'                  => (float) $r->latitude,
            'lng'                  => (float) $r->longitude,
            'title'                => $r->address ?: 'Property #' . $r->id,
            'subtitle'             => $this->formatPropertySubtitle($r),
            'price'                => $r->price !== null ? (int) $r->price : null,
            'date'                 => $date,
            'detail_url'           => route('corex.properties.map-card', ['property' => $r->id]),
            'sensitive'            => false,
            'status'               => (string) ($r->status ?? ''),
            'preferred_public_url' => $p->preferredPublicListingUrl(),
            'internal_url'         => route('corex.properties.show', $r->id),
            'public_listing_urls'  => $p->publicListingUrls(),
        ];
    }

    /**
     * Part 1 — Active agency stock. ON-MARKET ONLY by default (the explosion fix):
     * reuses Property::OFF_MARKET_STATUSES so sold/withdrawn/expired/draft/let-out no
     * longer plot as identical navy pins. An explicit listingStatus narrows within.
     *
     * @return array{0: array, 1: int}
     */
    private function hfcListings(MapBoundsRequest $req, int $limit): array
    {
        $q = $this->agencyStockBaseQuery($req);

        if (!empty($req->listingStatus)) {
            $this->applyStatusFilter($q, $req, 'status');
        } else {
            // Single source of truth — Property::scopeOnMarket() equivalent.
            $q->whereNotIn('status', \App\Models\Property::OFF_MARKET_STATUSES);
        }

        $total = (clone $q)->count();
        $rows = $q->select($this->agencyStockColumns())->orderBy('id')->limit($limit)->get();
        $pins = $rows->map(fn ($r) => $this->agencyStockPin($r, 'hfc_listings', null))->all();

        return [$this->applyRadiusFilter($pins, $req), $total];
    }

    /**
     * Part 2 — Sold agency stock as a DISTINCT, period-bounded layer. status='sold',
     * sold date from the canonical property_sold_records (properties has no sold-date
     * column), filtered by the existing sold-window (default = agency setting). Distinct
     * pin styling driven by status='sold' (S_own) client-side.
     *
     * @return array{0: array, 1: int}
     */
    private function hfcSold(MapBoundsRequest $req, int $limit): array
    {
        $q = $this->agencyStockBaseQuery($req)->where('status', 'sold');

        // Period bound — reuse the existing sold-window (falls back to the agency
        // default sold window so the Sold layer is always period-bounded).
        $window = $req->soldWindow ?: \App\Models\AgencyMapSettings::forAgency($req->agencyId)->defaultSoldWindow();
        $months = match ($window) {
            '3mo' => 3, '6mo' => 6, '12mo' => 12, '24mo' => 24, default => null,
        };
        if ($months !== null) {
            $cutoff = \Carbon\CarbonImmutable::now()->subMonths($months)->toDateString();
            $q->whereExists(function ($sub) use ($cutoff) {
                $sub->from('property_sold_records as psr')
                    ->whereColumn('psr.property_id', 'properties.id')
                    ->where('psr.sold_date', '>=', $cutoff);
            });
        }

        $total = (clone $q)->count();
        $rows = $q->select($this->agencyStockColumns())->orderBy('id')->limit($limit)->get();

        // Batch the sold date onto each pin (no join → no column ambiguity).
        $ids = $rows->pluck('id')->all();
        $soldDates = $ids
            ? DB::table('property_sold_records')->whereIn('property_id', $ids)
                ->groupBy('property_id')->selectRaw('property_id, MAX(sold_date) as sd')->pluck('sd', 'property_id')
            : collect();

        $pins = $rows->map(fn ($r) => $this->agencyStockPin($r, 'hfc_sold', $soldDates[$r->id] ?? null))->all();

        return [$this->applyRadiusFilter($pins, $req), $total];
    }

    /**
     * Part 5 — Off-market agency stock (withdrawn / expired / cancelled / let-out /
     * draft / archived / unavailable / transferred — i.e. OFF_MARKET_STATUSES minus
     * 'sold', which has its own layer). Muted pin client-side. OFF by default.
     *
     * @return array{0: array, 1: int}
     */
    private function hfcOffMarket(MapBoundsRequest $req, int $limit): array
    {
        $offMarketExceptSold = array_values(array_diff(\App\Models\Property::OFF_MARKET_STATUSES, ['sold']));

        $q = $this->agencyStockBaseQuery($req)->whereIn('status', $offMarketExceptSold);

        $total = (clone $q)->count();
        $rows = $q->select($this->agencyStockColumns())->orderBy('id')->limit($limit)->get();
        $pins = $rows->map(fn ($r) => $this->agencyStockPin($r, 'hfc_off_market', null))->all();

        return [$this->applyRadiusFilter($pins, $req), $total];
    }

    /**
     * Tracked Properties layer (T) — prospecting candidates with geocoded
     * GPS that are NOT yet on agency stock. Wired here so the 2026-05-27
     * Google geocoding backfill flows into the map per geocoding-spec.md.
     *
     * Excludes:
     *   - promoted_to_property_id IS NOT NULL (already on stock → H layer
     *     covers them; double-counting would inflate composite-pin counts)
     *   - geocode_needs_review = 1 (the SA-centroid / wrong-city pins
     *     flagged for operator review on 2026-05-27)
     *   - latitude / longitude IS NULL (not yet geocoded)
     *   - status != 'active' (archived / duplicate / promoted are out)
     *
     * Sensitive layer — Seller View suppression is handled at
     * getPinsInBounds() level (the dispatch skips this layer entirely
     * when isSellerView is true).
     *
     * @return array{0: array, 1: int}
     */
    private function trackedProperties(MapBoundsRequest $req, int $limit): array
    {
        $q = DB::table('tracked_properties')
            ->whereNull('deleted_at')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereNull('promoted_to_property_id')
            ->where('status', 'active')
            ->whereBetween('latitude',  [$req->south, $req->north])
            ->whereBetween('longitude', [$req->west,  $req->east]);

        if (Schema::hasColumn('tracked_properties', 'geocode_needs_review')) {
            $q->where(function ($qq) {
                $qq->where('geocode_needs_review', 0)
                   ->orWhereNull('geocode_needs_review');
            });
        }

        // Scope (always agency — tracked_properties are agency-scoped by
        // design; "all" admin scope is intentionally not supported here).
        $q->where('agency_id', $req->agencyId);

        if (Schema::hasColumn('tracked_properties', 'is_demo')) {
            $this->applyDemoFilter($q, $req, 'is_demo');
        }
        $this->applyPropertyTypeFilter($q, $req, 'property_type');
        $this->applyDateFilter($q, $req, 'first_seen_at');
        $this->applySearchFilter($q, $req, ['street_name', 'suburb', 'erf_number']);

        $total = (clone $q)->count();

        $rows = $q->select([
                'id', 'agency_id',
                'street_number', 'street_name', 'suburb', 'town', 'province',
                'erf_number', 'property_type',
                'latitude', 'longitude',
                'first_seen_at', 'last_enriched_at',
                'geo_source', 'geo_confidence',
            ])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $pins = $rows->map(function ($r) {
            $streetParts = array_filter([$r->street_number ?? null, $r->street_name ?? null]);
            $street      = trim(implode(' ', $streetParts));
            $title       = $street !== ''
                ? $street . ($r->suburb ? ', ' . $r->suburb : '')
                : ($r->suburb ? $r->suburb : 'Tracked #' . $r->id);

            $subParts = array_filter([
                $r->property_type ?? null,
                $r->erf_number ? 'Erf ' . $r->erf_number : null,
                $r->geo_confidence ? 'GPS: ' . $r->geo_confidence : null,
            ]);

            return [
                'id'                  => (int) $r->id,
                'layer'               => 'tracked_properties',
                'lat'                 => (float) $r->latitude,
                'lng'                 => (float) $r->longitude,
                'title'               => $title,
                'subtitle'            => implode(' · ', $subParts),
                'price'               => null,
                'date'                => $r->first_seen_at ?: null,
                // detail_url points at the MIC opportunities surface (the
                // canonical full-detail page for a tracked property). The
                // JS detail-panel short-circuits the fetch for this layer
                // and renders inline from the bounds-query record; the
                // URL is reused as the "Open in MIC →" CTA target.
                'detail_url'          => '/corex/market-intelligence/opportunities/' . $r->id,
                'sensitive'           => true,
                'status'              => null,
                'tracked_property_id' => (int) $r->id,
                'suburb'              => $r->suburb,
                // Structured fields exposed for the inline detail-panel
                // render (no separate JSON endpoint). Mirror the bounds-
                // query payload so the JS can build the card without a
                // round-trip.
                'street_number'       => $r->street_number,
                'street_name'         => $r->street_name,
                'property_type'       => $r->property_type,
                'erf_number'          => $r->erf_number,
                'geo_confidence'      => $r->geo_confidence,
                'geo_source'          => $r->geo_source,
                'first_seen_at'       => $r->first_seen_at,
            ];
        })->all();

        $pins = $this->applyRadiusFilter($pins, $req);
        return [$pins, $total];
    }

    /**
     * Sold comps from two sources (deals branch deferred — see note below),
     * deduped by (normalised address + sale_date), preference order
     * market_report_comp_rows > presentation_sold_comps.
     *
     * Architectural call-out — the spec mentioned a third "deals" branch but
     * the deals table here doesn't FK to properties (it stores
     * property_address as text) and has no sale_price column (it's
     * property_value + registration_date). Joining deals to properties
     * via address text-match for V1 would be slow + lossy; deferred to
     * V2 once property_id is back-filled on deals.
     *
     * @return array{0: array, 1: int}
     */
    private function soldComps(MapBoundsRequest $req, int $limit): array
    {
        $combined = [];

        // (a) market_report_comp_rows (row_type=comp).
        //
        // Architectural call-out — comp rows themselves don't have lat/lng
        // populated by current parsers (only subject rows do). We use a
        // COALESCE join so rows whose scheme_name matches some imported
        // report's subject_scheme_name inherit that subject's GPS. Wide
        // enough for the in-scheme ST report (all comps share the subject
        // scheme) without expanding to addresses we haven't geocoded yet.
        $mrcrQ = DB::table('market_report_comp_rows as mrcr')
            ->join('market_reports as mr', 'mr.id', '=', 'mrcr.market_report_id')
            ->leftJoin('market_reports as mr_scheme', function ($j) use ($req) {
                $j->on(DB::raw('LOWER(mr_scheme.subject_scheme_name)'), '=', DB::raw('LOWER(mrcr.scheme_name)'))
                  ->whereNotNull('mr_scheme.subject_latitude');
                // Phase 3h Step 9.5 — when demo is hidden the COALESCE
                // fallback must not pull GPS from a demo subject report
                // (would surface real comps at fake locations).
                if (!$req->includeDemo) {
                    $j->where('mr_scheme.is_demo', false);
                }
            })
            ->whereNull('mrcr.deleted_at')
            ->where('mrcr.row_type', 'comp')
            ->whereNotNull('mrcr.sale_price')
            ->whereRaw('COALESCE(mrcr.latitude, mr_scheme.subject_latitude) IS NOT NULL')
            ->whereRaw('COALESCE(mrcr.latitude, mr_scheme.subject_latitude) BETWEEN ? AND ?', [$req->south, $req->north])
            ->whereRaw('COALESCE(mrcr.longitude, mr_scheme.subject_longitude) BETWEEN ? AND ?', [$req->west, $req->east])
            ->select([
                'mrcr.id', 'mrcr.address', 'mrcr.sale_price', 'mrcr.sale_date',
                DB::raw('COALESCE(mrcr.latitude, mr_scheme.subject_latitude) as latitude'),
                DB::raw('COALESCE(mrcr.longitude, mr_scheme.subject_longitude) as longitude'),
                'mrcr.scheme_name', 'mrcr.section_number',
                // A.2.1 — parent report id surfaces "Open evaluation".
                'mrcr.market_report_id',
            ]);
        // A.3.1 — scope on the parent market_reports row (comp rows have no
        // agent_id of their own — 'my' falls back to 'agency' here).
        $this->applyScopeFilter($mrcrQ, $req, 'mr.agency_id');
        $this->applyDemoFilter($mrcrQ, $req, 'mrcr.is_demo');
        $this->applyDateFilter($mrcrQ, $req, 'mrcr.sale_date');
        $this->applySoldWindowFilter($mrcrQ, $req, 'mrcr.sale_date');
        $this->applyPriceFilter($mrcrQ, $req, 'mrcr.sale_price');
        $this->applyTypeFilter($mrcrQ, $req, 'mrcr.property_type');
        // A.3.1 — comp rows have only `extent_m2` (stand size). No beds/baths.
        $this->applyRangeFilter($mrcrQ, $req->standMin, $req->standMax, 'mrcr.extent_m2');
        $this->applySearchFilter($mrcrQ, $req, ['mrcr.address', 'mrcr.scheme_name']);

        foreach ($mrcrQ->limit($limit)->get() as $r) {
            $key = $this->dedupeKey($r->address ?? $r->scheme_name ?? '', $r->sale_date ?? '');
            if (isset($combined[$key])) continue;
            $price = OutlierGuard::price($r->sale_price);
            $title = $r->scheme_name
                ? trim($r->scheme_name . ($r->section_number ? ' § ' . $r->section_number : ''))
                : ($r->address ?? 'Comp #' . $r->id);
            $combined[$key] = [
                'id'               => 'mrcr:' . $r->id,
                'layer'            => 'sold_comps',
                'lat'              => (float) $r->latitude,
                'lng'              => (float) $r->longitude,
                'title'            => $title,
                'subtitle'         => $this->formatSoldSubtitle($price, $r->sale_date),
                'price'            => $price,
                'date'             => $r->sale_date,
                'detail_url'       => route('corex.map.sold', ['layerId' => 'mrcr:' . $r->id]),
                'sensitive'        => false,
                'parent_report_id' => (int) $r->market_report_id,
                // Q8 — own-vs-market provenance. MRCR comps are scraped market
                // data (CMA reports about other agencies' sales), never own
                // history. NOT NULL with safe default 'market'; downstream
                // visual treatment lands in a later track.
                'source_class'     => 'market',
            ];
        }

        // (b) presentation_sold_comps NOT covered by (a). Read GPS via JSON
        // join to mrcr by mic_comp_row_id. Skip when mic_comp_row_id is null.
        $pscQ = DB::table('presentation_sold_comps as psc')
            ->join('presentations as p', 'p.id', '=', 'psc.presentation_id')
            ->whereNull('psc.deleted_at')
            ->whereNotNull('psc.raw_row_json')
            ->select([
                'psc.id', 'psc.sold_date as sale_date', 'psc.sold_price_inc as sale_price',
                'psc.raw_row_json', 'psc.suburb',
            ]);
        $this->applyScopeFilter($pscQ, $req, 'p.agency_id');
        $this->applyDateFilter($pscQ, $req, 'psc.sold_date');
        $this->applySoldWindowFilter($pscQ, $req, 'psc.sold_date');
        $this->applyPriceFilter($pscQ, $req, 'psc.sold_price_inc');

        foreach ($pscQ->limit($limit * 2)->get() as $r) {
            $raw = is_string($r->raw_row_json) ? (json_decode($r->raw_row_json, true) ?: []) : ((array) $r->raw_row_json ?: []);
            $compRowId = $raw['mic_comp_row_id'] ?? null;
            if (!$compRowId) continue; // V1 — only MIC-sourced rows have lat/lng

            $gps = DB::table('market_report_comp_rows')
                ->where('id', $compRowId)
                ->whereNull('deleted_at')
                ->whereNotNull('latitude')->whereNotNull('longitude')
                ->select(['latitude', 'longitude', 'address'])
                ->first();
            if (!$gps) continue;

            $lat = (float) $gps->latitude;
            $lng = (float) $gps->longitude;
            if ($lat < $req->south || $lat > $req->north) continue;
            if ($lng < $req->west  || $lng > $req->east)  continue;

            $key = $this->dedupeKey($raw['address'] ?? $gps->address ?? '', $r->sale_date ?? '');
            if (isset($combined[$key])) continue;
            $price = OutlierGuard::price($r->sale_price);
            $combined[$key] = [
                'id'         => 'psc:' . $r->id,
                'layer'      => 'sold_comps',
                'lat'        => $lat,
                'lng'        => $lng,
                'title'      => $raw['address'] ?? $gps->address ?? 'Comp #' . $r->id,
                'subtitle'   => $this->formatSoldSubtitle($price, $r->sale_date),
                'price'      => $price,
                'date'       => $r->sale_date,
                'detail_url' => route('corex.map.sold', ['layerId' => 'psc:' . $r->id]),
                'sensitive'  => false,
                // Q8 — presentation sold comps originate from CMA-derived comp
                // rows uploaded into presentations; they're market data, not
                // own deal history.
                'source_class' => 'market',
            ];

            if (count($combined) >= $limit * 3) break;
        }

        // (c) Phase 3i — deals with property_id populated. Reads GPS from the
        // linked property. HFC's own sold history, distinct from market comps.
        $dealsQ = DB::table('deals as d')
            ->join('properties as p', 'p.id', '=', 'd.property_id')
            ->whereNull('d.deleted_at')
            ->whereNotNull('d.property_id')
            ->whereNotNull('d.registration_date')
            ->where(function ($q) {
                $q->whereNull('d.accepted_status')->orWhere('d.accepted_status', '!=', 'D');
            })
            ->whereNotNull('p.latitude')
            ->whereNotNull('p.longitude')
            ->whereBetween('p.latitude',  [$req->south, $req->north])
            ->whereBetween('p.longitude', [$req->west,  $req->east])
            ->select([
                'd.id', 'd.registration_date as sale_date',
                'd.sale_price', 'd.property_value', 'd.property_address',
                'p.address as prop_address', 'p.latitude', 'p.longitude',
            ]);
        // A.3.1 — scope on deals + property's agent_id ('my' = current
        // agent's HFC sales).
        $this->applyScopeFilter($dealsQ, $req, 'd.agency_id', 'p.agent_id');
        $this->applyDemoFilter($dealsQ, $req, 'd.is_demo');
        $this->applyDateFilter($dealsQ, $req, 'd.registration_date');
        $this->applySoldWindowFilter($dealsQ, $req, 'd.registration_date');
        $this->applyPriceFilter($dealsQ, $req, 'd.sale_price');
        $this->applyRangeFilter($dealsQ, $req->bedroomsMin,  $req->bedroomsMax,  'p.beds');
        $this->applyRangeFilter($dealsQ, $req->bathroomsMin, $req->bathroomsMax, 'p.baths');
        $this->applyRangeFilter($dealsQ, $req->standMin,     $req->standMax,     'p.erf_size_m2');
        $this->applyRangeFilter($dealsQ, $req->buildingMin,  $req->buildingMax,  'p.size_m2');
        $this->applySearchFilter($dealsQ, $req, ['p.address', 'p.title', 'p.complex_name', 'p.suburb', 'd.property_address']);

        foreach ($dealsQ->limit($limit)->get() as $r) {
            $key = $this->dedupeKey($r->prop_address ?? $r->property_address ?? '', $r->sale_date ?? '');
            if (isset($combined[$key])) continue;
            $price = OutlierGuard::price((int) ($r->sale_price ?? $r->property_value ?? 0));
            $combined[$key] = [
                'id'         => 'deal:' . $r->id,
                'layer'      => 'sold_comps',
                'lat'        => (float) $r->latitude,
                'lng'        => (float) $r->longitude,
                'title'      => $r->prop_address ?? $r->property_address ?? ('Deal #' . $r->id),
                'subtitle'   => $this->formatSoldSubtitle($price, $r->sale_date) . ' · HFC sold',
                'price'      => $price,
                'date'       => $r->sale_date,
                'detail_url' => null,
                'sensitive'  => false,
                'hfc_sold'   => true,
                // Q8 — deals.property_id ⋈ properties is HFC's own historical
                // sales; flag explicitly so the UI can later differentiate
                // own-history pins from competitor-market comps without having
                // to consult the legacy `hfc_sold` flag.
                'source_class' => 'own',
            ];
            if (count($combined) >= $limit * 3) break;
        }

        $pins = array_slice(array_values($combined), 0, $limit);
        $pins = $this->applyRadiusFilter($pins, $req);
        return [$pins, count($combined)];
    }

    /**
     * Active listings — the P (Portal Stock) layer.
     *
     * MAP-FIX (post-GEO-SCRAPE backfill): portal stock renders from
     * prospecting_listings' OWN coordinates (pl.latitude / pl.longitude),
     * not via a join to tracked_properties. The earlier model gated render
     * on `tracked_property_id IS NOT NULL` and read GPS from tp.lat/lng
     * because prospecting rows had no GPS path of their own; the
     * geocoding backfill + GEO-SCRAPE job changed that. Today the vast
     * majority of geocoded prospecting_listings have NO geocoded TP
     * partner (live: ~5,912 pl with GPS vs ~79 tp with GPS), so the old
     * gate silently hid the market.
     *
     * Taxonomy (Johan, authoritative):
     *   - prospecting_listings = PORTAL/PROSPECTING STOCK (the market).
     *     Its own visible map layer; renders on pl.latitude/pl.longitude.
     *   - tracked_properties  = stock an agent has CLAIMED (a subset /
     *     promotion). NOT where portal stock lives or sources its GPS.
     *   The tracked_property_id stays in the output (claimed-state
     *   indicator for the chip) but is ENRICHMENT only — never a render
     *   gate.
     *
     *   What we DELIBERATELY do NOT read:
     *     - market_report_comp_rows (row_type='listing') — CMA-derived
     *       listings are information, not prospecting peers. They remain
     *       accessible via the CMA report show page; they STOP rendering
     *       as map pins.
     *     - presentation_active_listings — same reasoning.
     *     - p24_listings — every row lacks an address by schema, so none
     *       can be pin-able. They flow through the "P24 alerts —
     *       awaiting address" list at
     *       `corex.market-intelligence.portal-alerts`.
     *
     *   prospecting_listings without their own GPS (no scrape geocode yet)
     *   are silently skipped here and ALSO surface in the awaiting-
     *   address list. The GEO-SCRAPE async job (dispatched per scrape
     *   batch) backfills GPS in the background; pins appear next render.
     *
     * Scope: portal stock is AGENCY-scoped (pl.agency_id explicit), NOT
     * user-scoped — the My/Agency/All axis intentionally does NOT apply
     * here (the comment block above the original applyScopeFilter calls
     * spells this out: prospecting rows belong to one agency only). The
     * "My" toggle therefore cannot hide portal stock.
     *
     * Seller-view PII: prospecting_listings has no owner_name /
     * owner_phone / owner_email / owner_id_number columns. The fields
     * surfaced — address, suburb, price, portal_url, portal_ref, listing
     * agent_name/agency_name — are all already publicly displayed on the
     * portal itself. No PII leak.
     *
     * @return array{0: array, 1: int}
     */
    private function activeListings(MapBoundsRequest $req, int $limit): array
    {
        // MAP-CLUSTER — at wide zoom (span ≥ 0.5°, same threshold the
        // zoomAwarePerLayerLimit uses to cap to 200/layer), the per-pin
        // path was sending the client a CAPPED sample (now 1000 after
        // MAP-CAP). Leaflet markercluster counted those, so cluster
        // bubbles read e.g. "204" when the true count in that area was
        // many times higher. Johan called these "fictitious values".
        //
        // Fix: at wide zoom, swap the per-pin path for a server-aggregated
        // bucket path — one synthetic pin per 0.1° geo-tile carrying
        // aggregate_count = COUNT(*) for that tile. The frontend cluster
        // icon (clusterIcon, ~line 961) sums each marker's aggregateCount
        // (default 1) so the displayed cluster total = true total.
        //
        // Per-pin path still runs at suburb / district zoom where the user
        // wants to see and click individual listings.
        $span = max(abs($req->north - $req->south), abs($req->east - $req->west));
        $isWideZoom = $span >= 0.5;

        $q = DB::table('prospecting_listings as pl')
            ->whereNull('pl.deleted_at')
            ->where('pl.is_active', true)
            ->whereIn('pl.portal_source', ['p24', 'pp'])
            ->whereNotNull('pl.latitude')
            ->whereNotNull('pl.longitude')
            ->whereBetween('pl.latitude',  [$req->south, $req->north])
            ->whereBetween('pl.longitude', [$req->west,  $req->east])
            ->where('pl.agency_id', $req->agencyId);
        $this->applyPriceFilter($q, $req, 'pl.price');
        $this->applyTypeFilter($q, $req, 'pl.property_type');
        $this->applyRangeFilter($q, $req->bedroomsMin,  $req->bedroomsMax,  'pl.bedrooms');
        $this->applyRangeFilter($q, $req->bathroomsMin, $req->bathroomsMax, 'pl.bathrooms');
        $this->applyRangeFilter($q, $req->standMin,     $req->standMax,     'pl.erf_size_m2');
        $this->applyRangeFilter($q, $req->buildingMin,  $req->buildingMax,  'pl.property_size_m2');
        $this->applySearchFilter($q, $req, ['pl.address', 'pl.suburb']);

        if ($isWideZoom) {
            // ── Wide-zoom: aggregate path ────────────────────────────
            // ROUND(latitude*10)/10 buckets at 0.1° (~11 km tiles). The
            // (latitude, longitude) bounds filter is already index-friendly
            // in MySQL; GROUP BY on derived columns is the only extra cost
            // and is bounded by tile count (a 5° province ⇒ at most 2,500
            // tiles, in practice far fewer once concentrated to coast/town).
            $buckets = (clone $q)
                ->selectRaw('ROUND(pl.latitude * 10) / 10 as bucket_lat, ROUND(pl.longitude * 10) / 10 as bucket_lng, COUNT(*) as bucket_count')
                ->groupBy('bucket_lat', 'bucket_lng')
                ->orderByDesc('bucket_count')
                ->limit($limit) // limit on TILES, not on rows — each tile carries its own count
                ->get();

            $pins = [];
            foreach ($buckets as $b) {
                $count = (int) $b->bucket_count;
                $pins[] = [
                    'id'                  => 'bucket:' . $b->bucket_lat . ':' . $b->bucket_lng,
                    'layer'               => 'active_listings',
                    'lat'                 => (float) $b->bucket_lat,
                    'lng'                 => (float) $b->bucket_lng,
                    'title'               => $count . ' listing' . ($count === 1 ? '' : 's') . ' here',
                    'suburb'              => null,
                    'subtitle'            => null,
                    'price'               => null,
                    'date'                => null,
                    'detail_url'          => null,
                    'preferred_public_url' => null,
                    'sensitive'           => false,
                    'tracked_property_id' => null,
                    // MAP-CLUSTER — read by the frontend cluster icon to
                    // sum into a TRUE per-cluster total at wide zoom.
                    'aggregate_count'     => $count,
                ];
            }

            $pins = $this->applyRadiusFilter($pins, $req);
            // For getPinsInBounds: return $total = count($pins) so the
            // existing cap-detection ($total > count($pins)) does NOT
            // misfire in aggregate mode (each bucket carries N>1 listings;
            // the SUM would always be > count(pins) and falsely flag
            // capping). The TRUE total is conveyed per-pin via
            // `aggregate_count` and re-summed in getPinsInBounds's
            // layer_counts computation. Cap detection then only fires
            // when count($pins) >= $limit — i.e. we hit the BUCKET cap,
            // which legitimately means the wide-zoom view is showing
            // fewer than the true number of tiles.
            return [$pins, count($pins)];
        }

        // ── Suburb / district zoom: per-pin path (unchanged from MAP-CAP) ──
        $combined = [];
        // MAP-CARD-FIX — pull every column the detail card needs so the
        // frontend can render inline without a follow-up fetch.
        // thumbnail_path becomes a server-resolved URL via
        // route('market-intelligence.thumbnail', $listing) at pin-build
        // time below — the frontend just does <img src="...">.
        $q = $q->select([
            'pl.id', 'pl.portal_source', 'pl.portal_url', 'pl.portal_ref',
            'pl.address', 'pl.suburb', 'pl.normalized_address',
            'pl.price', 'pl.bedrooms', 'pl.bathrooms', 'pl.garages',
            'pl.property_type', 'pl.property_size_m2', 'pl.erf_size_m2',
            'pl.thumbnail_path',
            'pl.first_seen_at',
            'pl.latitude as latitude', 'pl.longitude as longitude',
            'pl.tracked_property_id as tracked_property_id',
        ]);

        // MAP-CAP — capture the raw row count BEFORE the dedup foreach so
        // we can detect "the SQL LIMIT clipped results" honestly. Otherwise
        // cross-portal dedup shrinks count($pins) below $limit and the cap
        // check `count($pins) >= $limit` in getPinsInBounds silently
        // under-reports truncation.
        $rawRows = $q->limit($limit)->get();
        $rawCount = $rawRows->count();

        foreach ($rawRows as $r) {
            // Cross-portal dedup uses the existing normalized_address column
            // (populated by ProspectingListing::normalizeAddress at write time
            // — same p24 + pp address ALREADY collapse there). Fallback to a
            // raw address+suburb key when the normalised column is empty.
            $key = $r->normalized_address ?: $this->dedupeKey($r->address ?? '', $r->suburb ?? '');
            if (isset($combined[$key])) continue;

            $price = OutlierGuard::price($r->price);
            $title = $r->address ?: 'Listing #' . $r->id;
            // MAP-CARD-FIX — pre-resolve the thumbnail URL server-side.
            // route() needs the model; we have the bare id, so build a
            // shallow stub. Only emit a URL when thumbnail_path is set
            // (the download job is async; new captures may have no image
            // yet → frontend renders a placeholder).
            $thumbnailUrl = null;
            if (!empty($r->thumbnail_path)) {
                $thumbnailUrl = route('market-intelligence.thumbnail', ['listing' => (int) $r->id]);
            }
            $combined[$key] = [
                'id'                  => (int) $r->id,           // integer → prospect_launched handler treats as native prospecting_listing
                'layer'                => 'active_listings',
                'lat'                  => (float) $r->latitude,
                'lng'                  => (float) $r->longitude,
                'title'                => $title,
                'suburb'               => $r->suburb,             // emitted explicitly so LocationGrouper's PropertyAddressKey can decompose cleanly
                'subtitle'             => $this->formatActiveSubtitle($price, null),
                'price'                => $price,
                'date'                 => null,
                'detail_url'           => null,                    // intentionally null — MAP-CARD-FIX renders inline from the carried fields below
                'preferred_public_url' => $r->portal_url,
                'sensitive'            => false,
                'tracked_property_id'  => $r->tracked_property_id !== null ? (int) $r->tracked_property_id : null,
                // MAP-CARD-FIX — fields the detail card renders inline
                'portal_source'        => $r->portal_source,
                'portal_url'           => $r->portal_url,
                'bedrooms'             => $r->bedrooms !== null ? (int) $r->bedrooms : null,
                'bathrooms'            => $r->bathrooms !== null ? (int) $r->bathrooms : null,
                'garages'              => $r->garages !== null ? (int) $r->garages : null,
                'property_type'        => $r->property_type,
                'property_size_m2'     => $r->property_size_m2 !== null ? (float) $r->property_size_m2 : null,
                'erf_size_m2'          => $r->erf_size_m2 !== null ? (float) $r->erf_size_m2 : null,
                'thumbnail_url'        => $thumbnailUrl,
                'address'              => $r->address,
                'first_seen_at'        => $r->first_seen_at,
            ];
        }

        $pins = array_slice(array_values($combined), 0, $limit);
        $pins = $this->applyRadiusFilter($pins, $req);

        // MAP-CAP — emit a $total that flags truncation through the
        // existing `$total > count($pins)` check in getPinsInBounds. When
        // the raw SQL query hit the LIMIT, set $total to one past the
        // post-dedup count so the cap is detected even after dedup
        // shrinkage. When the raw query did not hit the LIMIT, $total
        // stays at count($combined) (== count($pins) when nothing was
        // truncated), preserving the under-cap "no plus sign" behaviour.
        $total = $rawCount >= $limit
            ? max(count($combined), $limit) + 1
            : count($combined);

        return [$pins, $total];
    }

    /** @return array{0: array, 1: int} */
    private function micSubjects(MapBoundsRequest $req, int $limit): array
    {
        // [M-TRACE-SERVER] — log the bounds we were asked for so a staging
        // log grep can correlate with the [M-TRACE] client logs for the
        // same request. The pin count is emitted at the bottom of this
        // method (after we know how many rows survived the bounds + scope
        // filters). REMOVE after staging confirms M renders correctly.
        $traceBounds = [
            'north' => $req->north, 'south' => $req->south,
            'east'  => $req->east,  'west'  => $req->west,
            'agency' => $req->agencyId, 'limit' => $limit,
        ];
        $q = DB::table('market_reports')
            ->whereNull('deleted_at')
            ->whereNotNull('subject_latitude')->whereNotNull('subject_longitude')
            ->whereBetween('subject_latitude',  [$req->south, $req->north])
            ->whereBetween('subject_longitude', [$req->west,  $req->east])
            ->leftJoin('market_report_types as mrt', 'mrt.id', '=', 'market_reports.report_type_id')
            ->select([
                'market_reports.id', 'market_reports.subject_address',
                'market_reports.subject_latitude as latitude',
                'market_reports.subject_longitude as longitude',
                'market_reports.created_at',
                'mrt.display_name as report_type_name',
                'mrt.key as report_type_key',
            ]);
        // A.3.1 — scope. market_reports has no per-row agent column, so 'my'
        // falls back to 'agency'.
        $this->applyScopeFilter($q, $req, 'market_reports.agency_id');
        $this->applyDemoFilter($q, $req, 'market_reports.is_demo');
        $this->applySearchFilter($q, $req, ['market_reports.subject_address', 'market_reports.subject_scheme_name']);

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('market_reports.id')->limit($limit)->get();

        $pins = $rows->map(fn ($r) => [
            'id'         => (int) $r->id,
            'layer'      => 'mic_subjects',
            'lat'        => (float) $r->latitude,
            'lng'        => (float) $r->longitude,
            'title'      => $r->subject_address ?: 'Report #' . $r->id,
            'subtitle'   => trim(($r->report_type_name ?: 'CMA Report') . ' · ' . $this->shortDate($r->created_at)),
            'price'      => null,
            'date'       => $r->created_at,
            'detail_url' => route('corex.map.mic-subject', ['report' => $r->id]),
            'sensitive'  => false,
        ])->all();

        $pins = $this->applyRadiusFilter($pins, $req);

        \Illuminate\Support\Facades\Log::info('[M-TRACE-SERVER] cmaSubjects: count='
            . count($pins) . ' total=' . $total
            . ' bounds=' . json_encode($traceBounds));

        return [$pins, $total];
    }

    /** @return array{0: array, 1: int} */
    private function schemeOwners(MapBoundsRequest $req, int $limit): array
    {
        // scheme_owners has no lat/lng. Join to market_reports on
        // scheme_name → subject_scheme_name to inherit the subject's GPS.
        // Aggregate (MIN) the joined values so multiple reports of the same
        // scheme don't multiply the owner rows.
        $q = DB::table('scheme_owners as so')
            ->join('market_reports as mr', function ($j) {
                $j->on(DB::raw('LOWER(mr.subject_scheme_name)'), '=', DB::raw('LOWER(so.scheme_name)'));
            })
            ->whereNull('so.deleted_at')
            ->whereNull('mr.deleted_at')
            ->whereNotNull('mr.subject_latitude')
            ->whereNotNull('mr.subject_longitude')
            ->whereBetween('mr.subject_latitude',  [$req->south, $req->north])
            ->whereBetween('mr.subject_longitude', [$req->west,  $req->east])
            ->groupBy('so.id', 'so.scheme_name', 'so.section_number', 'so.owner_name')
            ->select([
                'so.id', 'so.scheme_name', 'so.section_number', 'so.owner_name',
                DB::raw('MIN(mr.subject_latitude) as latitude'),
                DB::raw('MIN(mr.subject_longitude) as longitude'),
            ]);
        // A.3.1 — scope on scheme_owners.agency_id. No per-row agent.
        $this->applyScopeFilter($q, $req, 'so.agency_id');
        $this->applyDemoFilter($q, $req, 'so.is_demo');
        $this->applySearchFilter($q, $req, ['so.scheme_name', 'so.owner_name']);

        $totalQ = DB::table('scheme_owners as so')
            ->join('market_reports as mr', function ($j) {
                $j->on(DB::raw('LOWER(mr.subject_scheme_name)'), '=', DB::raw('LOWER(so.scheme_name)'));
            })
            ->whereNull('so.deleted_at')
            ->whereNull('mr.deleted_at')
            ->whereNotNull('mr.subject_latitude')
            ->whereBetween('mr.subject_latitude',  [$req->south, $req->north])
            ->whereBetween('mr.subject_longitude', [$req->west,  $req->east]);
        $this->applyScopeFilter($totalQ, $req, 'so.agency_id');
        $this->applyDemoFilter($totalQ, $req, 'so.is_demo');
        $this->applySearchFilter($totalQ, $req, ['so.scheme_name', 'so.owner_name']);
        $total = $totalQ->distinct('so.id')->count('so.id');

        $rows = $q->orderBy('so.id')->limit($limit)->get();

        $pins = $rows->map(fn ($r) => [
            'id'         => (int) $r->id,
            'layer'      => 'scheme_owners',
            'lat'        => (float) $r->latitude,
            'lng'        => (float) $r->longitude,
            'title'      => trim(($r->scheme_name ?? '') . ($r->section_number ? ' § ' . $r->section_number : '')),
            'subtitle'   => $r->owner_name ?: '—',
            'price'      => null,
            'date'       => null,
            'detail_url' => route('corex.map.scheme-owner', ['owner' => $r->id]),
            'sensitive'  => true,
        ])->all();

        $pins = $this->applyRadiusFilter($pins, $req);
        return [$pins, $total];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function applyPropertyTypeFilter($q, MapBoundsRequest $req, string $column): void
    {
        if (empty($req->propertyTypes)) return;
        $q->whereIn($column, $req->propertyTypes);
    }

    /**
     * Phase 3h Step 9.5 — hide is_demo=true rows when the demo toggle is
     * off. When on (default), both real and demo rows are returned. Column
     * name is variable so the same helper works for direct tables (is_demo)
     * and joined tables (mrcr.is_demo).
     */
    private function applyDemoFilter($q, MapBoundsRequest $req, string $column): void
    {
        if ($req->includeDemo) return;
        $q->where($column, false);
    }

    private function applyPriceFilter($q, MapBoundsRequest $req, string $column): void
    {
        if ($req->priceMin !== null) $q->where($column, '>=', $req->priceMin);
        if ($req->priceMax !== null) $q->where($column, '<=', $req->priceMax);
    }

    private function applyDateFilter($q, MapBoundsRequest $req, string $column): void
    {
        if ($req->dateFrom !== null) $q->where($column, '>=', $req->dateFrom);
        if ($req->dateTo !== null)   $q->where($column, '<=', $req->dateTo);
        // Phase 3g V2 — year-range filter (used by the standalone-map
        // filter panel). Year is computed from the date column.
        if ($req->dateFromYear !== null) {
            $q->whereYear($column, '>=', $req->dateFromYear);
        }
        if ($req->dateToYear !== null) {
            $q->whereYear($column, '<=', $req->dateToYear);
        }
    }

    /**
     * A.3.1 — Stock Scope narrowing.
     *   'my'     → agency_id = req->agencyId AND agent_id = req->actorUserId
     *              (falls back to 'agency' for layers without a per-row agent
     *              column — see $agentColumn=null)
     *   'agency' → agency_id = req->agencyId (default)
     *   'all'    → no agency narrowing (controller gates this on role)
     */
    private function applyScopeFilter($q, MapBoundsRequest $req, string $agencyColumn, ?string $agentColumn = null): void
    {
        $scope = $req->scope ?? 'agency';
        if ($scope === 'all') {
            return;
        }
        $q->where($agencyColumn, $req->agencyId);
        if ($scope === 'my' && $req->actorUserId && $agentColumn !== null) {
            $q->where($agentColumn, $req->actorUserId);
        }
    }

    /** A.3.1 — generic min/max range narrowing. Skipped when both null. */
    private function applyRangeFilter($q, ?int $min, ?int $max, string $column): void
    {
        if ($min !== null) $q->where($column, '>=', $min);
        if ($max !== null) $q->where($column, '<=', $max);
    }

    /** A.3.1 — multi-select listing status (active, sold, draft, ...). */
    private function applyStatusFilter($q, MapBoundsRequest $req, string $column): void
    {
        if (empty($req->listingStatus)) return;
        $q->whereIn($column, $req->listingStatus);
    }

    /** Map fixes — narrow agency stock to a specific responsible agent. */
    private function applyAgentFilter($q, MapBoundsRequest $req, string $column): void
    {
        if ($req->agentId === null) return;
        $q->where($column, $req->agentId);
    }

    /** Map fixes — narrow agency stock to one or more P24 suburbs. */
    private function applySuburbFilter($q, MapBoundsRequest $req, string $column): void
    {
        if (empty($req->suburbIds)) return;
        $q->whereIn($column, $req->suburbIds);
    }

    /**
     * A.3.1 — free-text LIKE search across the supplied columns. Case-
     * insensitive, single needle, OR-combined across the column list.
     * Columns that look numeric (agent_id) are skipped — search is meant
     * for human text. Callers pass display columns only.
     */
    private function applySearchFilter($q, MapBoundsRequest $req, array $columns): void
    {
        if ($req->search === null || trim($req->search) === '') return;
        $needle = '%' . mb_strtolower(trim($req->search)) . '%';
        $q->where(function ($sub) use ($needle, $columns) {
            foreach ($columns as $col) {
                $sub->orWhereRaw('LOWER(' . $col . ') LIKE ?', [$needle]);
            }
        });
    }

    /**
     * A.3.1 — sold-date window for sold comps (3mo / 6mo / 12mo / 24mo / all).
     * 'all' or null is a no-op.
     */
    private function applySoldWindowFilter($q, MapBoundsRequest $req, string $column): void
    {
        if ($req->soldWindow === null || $req->soldWindow === 'all') return;
        $months = match ($req->soldWindow) {
            '3mo'  => 3,
            '6mo'  => 6,
            '12mo' => 12,
            '24mo' => 24,
            default => null,
        };
        if ($months === null) return;
        $cutoff = \Carbon\CarbonImmutable::now()->subMonths($months)->toDateString();
        $q->where($column, '>=', $cutoff);
    }

    /**
     * Phase 3g V2 — match presentation-style property type buckets to the
     * variety of strings stored across our source tables. We accept a list
     * of front-end keys (house, sectional, townhouse, vacant) and translate
     * to a LIKE-friendly pattern set per source.
     */
    private function applyTypeFilter($q, MapBoundsRequest $req, string $column): void
    {
        if (empty($req->propertyTypes)) return;

        // Translate front-end keys to the messy variety of strings in the
        // database. Keep this list narrow + obvious — better to miss a
        // pin than to misclassify one in the wrong band.
        $patterns = [];
        foreach ($req->propertyTypes as $t) {
            $key = strtolower($t);
            $patterns = array_merge($patterns, match ($key) {
                'house'      => ['house', 'residence'],
                'sectional'  => ['sectional', 'apartment', 'flat', 'unit'],
                'townhouse'  => ['townhouse', 'duplex'],
                'vacant'     => ['vacant', 'land'],
                default      => [$key],
            });
        }
        $patterns = array_values(array_unique($patterns));

        $q->where(function ($sub) use ($patterns, $column) {
            foreach ($patterns as $pat) {
                $sub->orWhereRaw('LOWER(' . $column . ') LIKE ?', ['%' . $pat . '%']);
            }
        });
    }

    /**
     * Apply bedrooms filter where the column exists. 5 represents "5+".
     */
    private function applyBedroomsFilter($q, MapBoundsRequest $req, string $column): void
    {
        if (empty($req->bedrooms)) return;
        // Default = all selected (1-5). Only filter when the request narrowed it.
        if (count($req->bedrooms) >= 5) return;

        $q->where(function ($sub) use ($req, $column) {
            foreach ($req->bedrooms as $b) {
                $b = (int) $b;
                if ($b >= 5) {
                    $sub->orWhere($column, '>=', 5);
                } else {
                    $sub->orWhere($column, '=', $b);
                }
            }
        });
    }

    /**
     * Phase 3g V2 Part E — drop pins outside Haversine(center, pin) ≤ radius.
     * Called after rows are fetched (cheap; we already cap per layer).
     *
     * @param array<int, array<string, mixed>> $pins
     * @return array<int, array<string, mixed>>
     */
    private function applyRadiusFilter(array $pins, MapBoundsRequest $req): array
    {
        if (!$req->hasRadiusFilter()) return $pins;
        $cLat = (float) $req->radiusCenterLat;
        $cLng = (float) $req->radiusCenterLng;
        $rM   = (int)   $req->radiusM;
        return array_values(array_filter($pins, function ($p) use ($cLat, $cLng, $rM) {
            return \App\Support\MarketAnalytics\HaversineDistance::distanceMetres(
                $cLat, $cLng, (float) $p['lat'], (float) $p['lng']
            ) <= $rM;
        }));
    }

    private function formatPropertySubtitle($r): string
    {
        $type = $r->property_type ?: 'Property';
        if ($r->price !== null && $r->price > 0) {
            return $type . ' · R ' . number_format((int) $r->price, 0, '.', ' ');
        }
        return $type . ' · Not priced';
    }

    private function formatSoldSubtitle(?int $price, ?string $date): string
    {
        $priceStr = $price !== null ? 'R ' . number_format($price, 0, '.', ' ') : 'R —';
        $dateStr  = $this->shortDate($date);
        return trim('Sold ' . $priceStr . ($dateStr ? ' · ' . $dateStr : ''));
    }

    private function formatActiveSubtitle(?int $price, mixed $dom): string
    {
        $priceStr = $price !== null ? 'R ' . number_format($price, 0, '.', ' ') : 'R —';
        $domStr   = (is_int($dom) || (is_numeric($dom) && (int) $dom > 0))
            ? ' · DOM ' . (int) $dom : '';
        return $priceStr . $domStr;
    }

    private function shortDate(?string $iso): string
    {
        if (!$iso) return '';
        try {
            return \Carbon\Carbon::parse($iso)->format('M Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private function dedupeKey(string $address, string $context): string
    {
        $a = mb_strtolower(preg_replace('/\s+/u', ' ', trim($address)));
        return $a . '|' . $context;
    }
}
