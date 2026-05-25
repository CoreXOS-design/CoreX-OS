<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\Support\MarketAnalytics\OutlierGuard;
use Illuminate\Support\Facades\DB;

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
        // bounded. See MapBoundsRequest::zoomAwarePerLayerLimit().
        $perLayerLimit = $req->zoomAwarePerLayerLimit(count($req->layers));

        $layerCounts  = [];
        $totals       = [];
        $cappedLayers = [];
        $allRecords   = [];

        $sources = [
            'hfc_listings'    => fn () => $this->hfcListings($req, $perLayerLimit),
            'sold_comps'      => fn () => $this->soldComps($req, $perLayerLimit),
            'active_listings' => fn () => $this->activeListings($req, $perLayerLimit),
            'mic_subjects'    => fn () => $this->micSubjects($req, $perLayerLimit),
            // A.2.3 Item 3 — Sectional schemes now appear in Seller View too,
            // but with owner identity redacted at the toRecord boundary below.
            // (Pre-A.2.3 the whole layer was suppressed in Seller View — that
            // was over-cautious and hid useful scheme metadata.)
            'scheme_owners'   => fn () => $this->schemeOwners($req, $perLayerLimit),
        ];

        foreach ($sources as $key => $fetch) {
            if (!$req->wantsLayer($key)) continue;
            /** @var array{0: array, 1: int} $result */
            $result = $fetch();
            [$pins, $total] = $result;

            $layerCounts[$key] = count($pins);
            $totals[$key]      = $total;
            if ($total > count($pins)) {
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
        ];
    }

    /** @return array{0: array, 1: int} */
    private function hfcListings(MapBoundsRequest $req, int $limit): array
    {
        $q = DB::table('properties')
            ->whereNull('deleted_at')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereBetween('latitude',  [$req->south, $req->north])
            ->whereBetween('longitude', [$req->west,  $req->east]);

        // A.3.1 — scope (my / agency / all) + extended filters.
        $this->applyScopeFilter($q, $req, 'agency_id', 'agent_id');

        $this->applyDemoFilter($q, $req, 'is_demo');
        $this->applyPropertyTypeFilter($q, $req, 'property_type');
        $this->applyTypeFilter($q, $req, 'property_type');
        $this->applyBedroomsFilter($q, $req, 'beds');
        $this->applyPriceFilter($q, $req, 'price');

        // A.3.1 — extended filters. Columns are HFC properties-table specific.
        $this->applyRangeFilter($q, $req->bedroomsMin,  $req->bedroomsMax,  'beds');
        $this->applyRangeFilter($q, $req->bathroomsMin, $req->bathroomsMax, 'baths');
        $this->applyRangeFilter($q, $req->standMin,     $req->standMax,     'erf_size_m2');
        $this->applyRangeFilter($q, $req->buildingMin,  $req->buildingMax,  'size_m2');
        $this->applyStatusFilter($q, $req, 'status');
        $this->applySearchFilter($q, $req, ['address', 'title', 'complex_name', 'suburb']);

        $total = (clone $q)->count();

        // A.2.1 — fetch the columns the URL accessor needs so we can hand
        // the client a preferred_public_url + status without round-tripping.
        $rows = $q->select([
                'id', 'agency_id', 'address', 'property_type', 'price', 'status',
                'latitude', 'longitude', 'suburb', 'city', 'town', 'province',
                'pp_ref', 'p24_ref', 'pp_syndication_status', 'p24_syndication_status',
                'pp_suburb_id', 'listing_type',
            ])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $pins = $rows->map(function ($r) {
            // Hydrate just enough of a Property to call the accessor — avoids
            // an N+1 reload of every row through Eloquent.
            $p = new \App\Models\Property();
            // setRawAttributes lets isOnHfcWebsite() see the raw id (forceFill
            // doesn't set the primary key on an unsaved model).
            $p->setRawAttributes([
                'id'                       => $r->id,
                'agency_id'                => $r->agency_id,
                'status'                   => $r->status,
                'address'                  => $r->address,
                'suburb'                   => $r->suburb,
                'city'                     => $r->town ?? $r->city,
                'town'                     => $r->town,
                'province'                 => $r->province,
                'property_type'            => $r->property_type,
                'pp_ref'                   => $r->pp_ref,
                'p24_ref'                  => $r->p24_ref,
                'pp_syndication_status'    => $r->pp_syndication_status,
                'p24_syndication_status'   => $r->p24_syndication_status,
                'pp_suburb_id'             => $r->pp_suburb_id,
                'listing_type'             => $r->listing_type,
            ]);

            return [
                'id'                   => (int) $r->id,
                'layer'                => 'hfc_listings',
                'lat'                  => (float) $r->latitude,
                'lng'                  => (float) $r->longitude,
                'title'                => $r->address ?: 'Property #' . $r->id,
                'subtitle'             => $this->formatPropertySubtitle($r),
                'price'                => $r->price !== null ? (int) $r->price : null,
                'date'                 => null,
                'detail_url'           => route('corex.properties.map-card', ['property' => $r->id]),
                'sensitive'            => false,
                // A.2.1 — context the JS actionsForRecord() needs for the
                // smart Open-listing button.
                'status'               => (string) ($r->status ?? ''),
                'preferred_public_url' => $p->preferredPublicListingUrl(),
                'internal_url'         => route('corex.properties.show', $r->id),
                // A.2.3 Item 4 — full per-portal map so the JS can render a
                // portal strip (one icon per active portal).
                'public_listing_urls'  => $p->publicListingUrls(),
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
            ];
            if (count($combined) >= $limit * 3) break;
        }

        $pins = array_slice(array_values($combined), 0, $limit);
        $pins = $this->applyRadiusFilter($pins, $req);
        return [$pins, count($combined)];
    }

    /**
     * Active listings — same union pattern.
     *
     * @return array{0: array, 1: int}
     */
    private function activeListings(MapBoundsRequest $req, int $limit): array
    {
        $combined = [];

        // (a) market_report_comp_rows (row_type=listing). Same scheme-name
        // COALESCE pattern as soldComps — listings inherit subject GPS when
        // a matching scheme has been imported.
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
            ->where('mrcr.row_type', 'listing')
            ->whereNotNull('mrcr.list_price')
            ->whereRaw('COALESCE(mrcr.latitude, mr_scheme.subject_latitude) IS NOT NULL')
            ->whereRaw('COALESCE(mrcr.latitude, mr_scheme.subject_latitude) BETWEEN ? AND ?', [$req->south, $req->north])
            ->whereRaw('COALESCE(mrcr.longitude, mr_scheme.subject_longitude) BETWEEN ? AND ?', [$req->west, $req->east])
            ->select([
                'mrcr.id', 'mrcr.address', 'mrcr.list_price', 'mrcr.days_on_market',
                DB::raw('COALESCE(mrcr.latitude, mr_scheme.subject_latitude) as latitude'),
                DB::raw('COALESCE(mrcr.longitude, mr_scheme.subject_longitude) as longitude'),
                'mrcr.scheme_name', 'mrcr.section_number',
                // A.2.1 — parent report id surfaces "Open evaluation" if the
                // agent wants the source report instead of the prospect flow.
                'mrcr.market_report_id',
            ]);
        // A.3.1 — scope on parent market_reports.agency_id.
        $this->applyScopeFilter($mrcrQ, $req, 'mr.agency_id');
        $this->applyDemoFilter($mrcrQ, $req, 'mrcr.is_demo');
        $this->applyPriceFilter($mrcrQ, $req, 'mrcr.list_price');
        $this->applyTypeFilter($mrcrQ, $req, 'mrcr.property_type');
        $this->applyRangeFilter($mrcrQ, $req->standMin, $req->standMax, 'mrcr.extent_m2');
        $this->applyRangeFilter($mrcrQ, $req->domMin,   $req->domMax,   'mrcr.days_on_market');
        $this->applySearchFilter($mrcrQ, $req, ['mrcr.address', 'mrcr.scheme_name']);

        foreach ($mrcrQ->limit($limit)->get() as $r) {
            $key = $this->dedupeKey($r->address ?? $r->scheme_name ?? '', 'active');
            if (isset($combined[$key])) continue;
            $price = OutlierGuard::price($r->list_price);
            $title = $r->scheme_name
                ? trim($r->scheme_name . ($r->section_number ? ' § ' . $r->section_number : ''))
                : ($r->address ?? 'Listing #' . $r->id);
            $combined[$key] = [
                'id'               => 'mrcr:' . $r->id,
                'layer'            => 'active_listings',
                'lat'              => (float) $r->latitude,
                'lng'              => (float) $r->longitude,
                'title'            => $title,
                'subtitle'         => $this->formatActiveSubtitle($price, $r->days_on_market),
                'price'            => $price,
                'date'             => null,
                'detail_url'       => route('corex.map.active', ['layerId' => 'mrcr:' . $r->id]),
                'sensitive'        => false,
                'parent_report_id' => (int) $r->market_report_id,
            ];
        }

        // (b) presentation_active_listings via mic_comp_row_id join.
        $palQ = DB::table('presentation_active_listings as pal')
            ->join('presentations as p', 'p.id', '=', 'pal.presentation_id')
            ->whereNull('pal.deleted_at')
            ->whereNotNull('pal.raw_row_json')
            ->select([
                'pal.id', 'pal.list_price_inc as list_price',
                'pal.raw_row_json', 'pal.suburb',
            ]);
        $this->applyScopeFilter($palQ, $req, 'p.agency_id');
        $this->applyPriceFilter($palQ, $req, 'pal.list_price_inc');

        foreach ($palQ->limit($limit * 2)->get() as $r) {
            $raw = is_string($r->raw_row_json) ? (json_decode($r->raw_row_json, true) ?: []) : ((array) $r->raw_row_json ?: []);
            $compRowId = $raw['mic_comp_row_id'] ?? null;
            if (!$compRowId) continue;

            $gps = DB::table('market_report_comp_rows')
                ->where('id', $compRowId)
                ->whereNotNull('latitude')->whereNotNull('longitude')
                ->select(['latitude', 'longitude', 'address'])
                ->first();
            if (!$gps) continue;

            $lat = (float) $gps->latitude;
            $lng = (float) $gps->longitude;
            if ($lat < $req->south || $lat > $req->north) continue;
            if ($lng < $req->west  || $lng > $req->east)  continue;

            $key = $this->dedupeKey($raw['address'] ?? $gps->address ?? '', 'active');
            if (isset($combined[$key])) continue;
            $price = OutlierGuard::price($r->list_price);
            $combined[$key] = [
                'id'         => 'pal:' . $r->id,
                'layer'      => 'active_listings',
                'lat'        => $lat,
                'lng'        => $lng,
                'title'      => $raw['address'] ?? $gps->address ?? 'Listing #' . $r->id,
                'subtitle'   => $this->formatActiveSubtitle($price, $raw['days_on_market'] ?? null),
                'price'      => $price,
                'date'       => null,
                'detail_url' => route('corex.map.active', ['layerId' => 'pal:' . $r->id]),
                'sensitive'  => false,
            ];
            if (count($combined) >= $limit * 3) break;
        }

        $pins = array_slice(array_values($combined), 0, $limit);
        $pins = $this->applyRadiusFilter($pins, $req);
        return [$pins, count($combined)];
    }

    /** @return array{0: array, 1: int} */
    private function micSubjects(MapBoundsRequest $req, int $limit): array
    {
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
