<?php

declare(strict_types=1);

namespace App\Http\Controllers\Map;

use App\Http\Controllers\Controller;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Models\MarketReports\SchemeOwner;
use App\Models\PresentationActiveListing;
use App\Models\PresentationSoldComp;
use App\Models\Property;
use App\Services\Map\MapBoundsRequest;
use App\Services\Map\MapPinService;
use App\Services\Map\MapProspectStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3g B4/B5 — Map module HTTP surface.
 *
 *   GET /corex/map                     — Leaflet page (Blade)
 *   GET /corex/map/pins                — JSON pins in bounds
 *   GET /corex/properties/{id}/map-card    — JSON detail card for HFC listing
 *   GET /corex/map/sold/{layerId}      — JSON detail card for sold comp
 *   GET /corex/map/active/{layerId}    — JSON detail card for active listing
 *   GET /corex/map/mic-subject/{id}    — JSON detail card for MIC subject
 *   GET /corex/map/scheme-owner/{id}   — JSON detail card for scheme owner
 *
 * Card payload (uniform): title, subtitle, address, lat, lng, facts[],
 * relationships[], sensitive_facts[] (Agent View only — Seller View strips
 * the sensitive_facts key entirely so the field never reaches the browser).
 */
final class MapController extends Controller
{
    public function __construct(private readonly MapPinService $svc = new MapPinService()) {}

    public function index(): \Illuminate\View\View
    {
        return view('corex.map.index');
    }

    public function pins(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'north'        => 'required|numeric|between:-90,90',
            'south'        => 'required|numeric|between:-90,90|lte:north',
            'east'         => 'required|numeric|between:-180,180',
            'west'         => 'required|numeric|between:-180,180|lte:east',
            'layers'       => 'sometimes|array',
            'layers.*'     => 'string|in:hfc_listings,sold_comps,active_listings,mic_subjects,scheme_owners',
            'viewMode'     => 'sometimes|string|in:agent,seller',
            'dateFrom'     => 'sometimes|nullable|date',
            'dateTo'       => 'sometimes|nullable|date',
            'propertyTypes'=> 'sometimes|array',
            'propertyTypes.*' => 'string|max:50',
            'priceMin'     => 'sometimes|nullable|integer|min:0',
            'priceMax'     => 'sometimes|nullable|integer|min:0',
            'limit'        => 'sometimes|integer|min:1|max:5000',
            'include_demo' => 'sometimes|nullable',
            // Phase 3g V2 — filter panel.
            'dateFromYear' => 'sometimes|nullable|integer|min:2000|max:2099',
            'dateToYear'   => 'sometimes|nullable|integer|min:2000|max:2099',
            'bedrooms'     => 'sometimes|array',
            'bedrooms.*'   => 'integer|min:0|max:10',
            // Phase 3g V2 Part E — radius post-filter (embedded views).
            'radiusCenterLat' => 'sometimes|nullable|numeric|between:-90,90',
            'radiusCenterLng' => 'sometimes|nullable|numeric|between:-180,180',
            'radiusM'         => 'sometimes|nullable|integer|min:50|max:50000',
            // Phase A.3.1 — Search + filter overhaul.
            'scope'           => 'sometimes|nullable|string|in:my,agency,all',
            'search'          => 'sometimes|nullable|string|max:120',
            'bedroomsMin'     => 'sometimes|nullable|integer|min:0|max:20',
            'bedroomsMax'     => 'sometimes|nullable|integer|min:0|max:20',
            'bathroomsMin'    => 'sometimes|nullable|integer|min:0|max:20',
            'bathroomsMax'    => 'sometimes|nullable|integer|min:0|max:20',
            'standMin'        => 'sometimes|nullable|integer|min:0|max:1000000',
            'standMax'        => 'sometimes|nullable|integer|min:0|max:1000000',
            'buildingMin'     => 'sometimes|nullable|integer|min:0|max:100000',
            'buildingMax'     => 'sometimes|nullable|integer|min:0|max:100000',
            'listingStatus'   => 'sometimes|array',
            'listingStatus.*' => 'string|in:active,available,for_sale,to_let,draft,sold',
            'soldWindow'      => 'sometimes|nullable|string|in:3mo,6mo,12mo,24mo,all',
            'domMin'          => 'sometimes|nullable|integer|min:0|max:10000',
            'domMax'          => 'sometimes|nullable|integer|min:0|max:10000',
        ]);

        // Phase A.3.1 — 'all' scope is admin-only (owner role). Silently
        // downgrade for non-admins so the JSON contract stays clean (no 403
        // on a stale saved-search payload).
        $scope = $validated['scope'] ?? null;
        if ($scope === 'all' && !($request->user()?->isEffectiveOwner() ?? false)) {
            $scope = 'agency';
        }

        $user = $request->user();
        // Phase 3g hotfix #2 — System Owners have agency_id=NULL on the
        // users table; their active agency lives in session('active_agency_id'),
        // surfaced via effectiveAgencyId(). The agency.required middleware
        // already passes them through; this inline check just needs to read
        // the same source of truth.
        $effectiveAgencyId = $user?->effectiveAgencyId();
        if (!$user || !$effectiveAgencyId) {
            return response()->json(['error' => 'No agency context.'], 403);
        }

        $layers = $validated['layers'] ?? MapBoundsRequest::VALID_LAYERS;

        $req = new MapBoundsRequest(
            north:         (float) $validated['north'],
            south:         (float) $validated['south'],
            east:          (float) $validated['east'],
            west:          (float) $validated['west'],
            layers:        $layers,
            viewMode:      $validated['viewMode']  ?? 'agent',
            agencyId:      (int) $effectiveAgencyId,
            dateFrom:      $validated['dateFrom']  ?? null,
            dateTo:        $validated['dateTo']    ?? null,
            propertyTypes: $validated['propertyTypes'] ?? [],
            priceMin:      $validated['priceMin']  ?? null,
            priceMax:      $validated['priceMax']  ?? null,
            limit:         (int) ($validated['limit'] ?? 2000),
            dateFromYear:  isset($validated['dateFromYear']) ? (int) $validated['dateFromYear'] : null,
            dateToYear:    isset($validated['dateToYear'])   ? (int) $validated['dateToYear']   : null,
            bedrooms:      array_values(array_map('intval', $validated['bedrooms'] ?? [])),
            radiusCenterLat: isset($validated['radiusCenterLat']) ? (float) $validated['radiusCenterLat'] : null,
            radiusCenterLng: isset($validated['radiusCenterLng']) ? (float) $validated['radiusCenterLng'] : null,
            radiusM:         isset($validated['radiusM']) ? (int) $validated['radiusM'] : null,
            // Phase 3h Step 9.5 — query string accepts '0'/'false' to hide
            // demo pins. Anything else (or missing) keeps demo data visible.
            includeDemo:   filter_var(
                $validated['include_demo'] ?? true,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            ) ?? true,
            // Phase A.3.1 — Search + filter overhaul.
            scope:         $scope,
            actorUserId:   (int) $user->id,
            search:        $validated['search'] ?? null,
            bedroomsMin:   isset($validated['bedroomsMin'])  ? (int) $validated['bedroomsMin']  : null,
            bedroomsMax:   isset($validated['bedroomsMax'])  ? (int) $validated['bedroomsMax']  : null,
            bathroomsMin:  isset($validated['bathroomsMin']) ? (int) $validated['bathroomsMin'] : null,
            bathroomsMax:  isset($validated['bathroomsMax']) ? (int) $validated['bathroomsMax'] : null,
            standMin:      isset($validated['standMin'])     ? (int) $validated['standMin']     : null,
            standMax:      isset($validated['standMax'])     ? (int) $validated['standMax']     : null,
            buildingMin:   isset($validated['buildingMin'])  ? (int) $validated['buildingMin']  : null,
            buildingMax:   isset($validated['buildingMax'])  ? (int) $validated['buildingMax']  : null,
            listingStatus: $validated['listingStatus']       ?? [],
            soldWindow:    $validated['soldWindow']          ?? null,
            domMin:        isset($validated['domMin']) ? (int) $validated['domMin'] : null,
            domMax:        isset($validated['domMax']) ? (int) $validated['domMax'] : null,
        );

        $startedAt = microtime(true);
        $payload = $this->svc->getPinsInBounds($req);
        $payload['meta'] = [
            'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'view_mode'        => $req->viewMode,
        ];

        return response()->json($payload);
    }

    /**
     * Phase 3g V2 Part D — JSON pins for a specific presentation's spatial
     * view. Returns the presentation's subject + its hydrated sold comps and
     * active listings as map-ready pins (no bounds filter — the presentation
     * decides its own comp set via the hydrator).
     *
     * GET /corex/presentations/{presentation}/spatial-pins
     */
    public function presentationPins(Request $request, \App\Models\Presentation $presentation): JsonResponse
    {
        $this->assertSameAgency($request, $presentation->agency_id);

        // Subject GPS comes from the linked Property when available.
        $property = $presentation->property_id
            ? \App\Models\Property::withoutGlobalScopes()->find($presentation->property_id)
            : null;

        $subject = null;
        if ($property?->latitude !== null && $property->longitude !== null) {
            $subject = [
                'lat'      => (float) $property->latitude,
                'lng'      => (float) $property->longitude,
                'title'    => $property->address ?: ($presentation->property_address ?? 'Subject'),
                'subtitle' => $presentation->suburb,
                'role'     => 'subject',
            ];
        }

        $resolveGps = function (array $raw) {
            // 1. raw_row_json may carry explicit lat/lng (from manual upload extractors).
            if (isset($raw['latitude'], $raw['longitude'])
                && $raw['latitude'] !== null && $raw['longitude'] !== null) {
                return [(float) $raw['latitude'], (float) $raw['longitude']];
            }
            // 2. mic_comp_row_id → market_report_comp_rows.lat/lng (direct).
            $compRowId = $raw['mic_comp_row_id'] ?? null;
            $schemeName = $raw['scheme_name'] ?? null;
            if ($compRowId) {
                $r = \Illuminate\Support\Facades\DB::table('market_report_comp_rows')
                    ->where('id', $compRowId)
                    ->first(['latitude', 'longitude', 'scheme_name']);
                if ($r) {
                    if ($r->latitude !== null && $r->longitude !== null) {
                        return [(float) $r->latitude, (float) $r->longitude];
                    }
                    $schemeName = $schemeName ?: $r->scheme_name;
                }
            }
            // 3. Scheme-name inheritance: match scheme to any subject report.
            //    Same pattern MapPinService uses for the standalone map.
            if ($schemeName) {
                $mr = \Illuminate\Support\Facades\DB::table('market_reports')
                    ->whereRaw('LOWER(subject_scheme_name) = ?', [mb_strtolower($schemeName)])
                    ->whereNotNull('subject_latitude')->whereNotNull('subject_longitude')
                    ->orderByDesc('id')
                    ->first(['subject_latitude', 'subject_longitude']);
                if ($mr) {
                    return [(float) $mr->subject_latitude, (float) $mr->subject_longitude];
                }
            }
            return [null, null];
        };

        $soldPins = [];
        foreach ($presentation->soldComps as $sc) {
            $raw = is_string($sc->raw_row_json) ? (json_decode($sc->raw_row_json, true) ?: []) : ((array) $sc->raw_row_json ?: []);
            [$lat, $lng] = $resolveGps($raw);
            if ($lat === null || $lng === null) continue;

            $price = \App\Support\MarketAnalytics\OutlierGuard::price($sc->sold_price_inc);
            $soldPins[] = [
                'id'       => 'psc:' . $sc->id,
                'layer'    => 'sold_comps',
                'lat'      => (float) $lat,
                'lng'      => (float) $lng,
                'title'    => $raw['address'] ?? 'Comp #' . $sc->id,
                'subtitle' => 'Sold ' . ($price !== null ? 'R ' . number_format($price, 0, '.', ' ') : 'R —')
                    . ($sc->sold_date ? ' · ' . $sc->sold_date->format('M Y') : ''),
                'price'    => $price,
                'date'     => $sc->sold_date?->toDateString(),
                'detail_url' => route('corex.map.sold', ['layerId' => 'psc:' . $sc->id]),
            ];
        }

        $activePins = [];
        foreach ($presentation->activeListings as $al) {
            $raw = is_string($al->raw_row_json) ? (json_decode($al->raw_row_json, true) ?: []) : ((array) $al->raw_row_json ?: []);
            [$lat, $lng] = $resolveGps($raw);
            if ($lat === null || $lng === null) continue;

            $price = \App\Support\MarketAnalytics\OutlierGuard::price($al->list_price_inc);
            $activePins[] = [
                'id'       => 'pal:' . $al->id,
                'layer'    => 'active_listings',
                'lat'      => (float) $lat,
                'lng'      => (float) $lng,
                'title'    => $raw['address'] ?? 'Listing #' . $al->id,
                'subtitle' => ($price !== null ? 'R ' . number_format($price, 0, '.', ' ') : 'R —')
                    . (isset($raw['days_on_market']) ? ' · DOM ' . (int) $raw['days_on_market'] : ''),
                'price'    => $price,
                'detail_url' => route('corex.map.active', ['layerId' => 'pal:' . $al->id]),
            ];
        }

        return response()->json([
            'subject'         => $subject,
            'sold_comps'      => $soldPins,
            'active_listings' => $activePins,
            'counts' => [
                'sold'   => count($soldPins),
                'active' => count($activePins),
            ],
        ]);
    }

    /** GET /corex/properties/{property}/map-card */
    public function propertyCard(Request $request, Property $property): JsonResponse
    {
        $this->assertSameAgency($request, $property->agency_id);
        $viewMode = $request->query('viewMode', 'agent');

        $facts = array_filter([
            ['label' => 'Type',         'value' => $property->property_type ?: '—'],
            ['label' => 'Suburb',       'value' => $property->suburb ?: '—'],
            ['label' => 'Town',         'value' => $property->town   ?: '—'],
            ['label' => 'Bedrooms',     'value' => $property->beds   !== null ? (string) $property->beds  : '—'],
            ['label' => 'Bathrooms',    'value' => $property->baths  !== null ? (string) $property->baths : '—'],
            ['label' => 'Asking price', 'value' => $property->price ? 'R ' . number_format((int) $property->price, 0, '.', ' ') : '—'],
            ['label' => 'Status',       'value' => $property->status ?: '—'],
            ['label' => 'GPS source',   'value' => $property->geo_source ?: '—'],
        ]);

        $card = [
            'title'    => $property->address ?: 'Property #' . $property->id,
            'subtitle' => trim(($property->property_type ?? 'Property') . ' · ' . ($property->suburb ?? '')),
            'address'  => $property->address,
            'lat'      => $property->latitude !== null ? (float) $property->latitude : null,
            'lng'      => $property->longitude !== null ? (float) $property->longitude : null,
            'facts'    => array_values($facts),
            'relationships' => [
                ['label' => 'Open property', 'url' => route('corex.properties.show', $property)],
            ],
        ];
        if ($viewMode === 'agent') {
            $card['sensitive_facts'] = array_filter([
                $property->agent_id ? ['label' => 'Listing agent', 'value' => 'Agent #' . $property->agent_id] : null,
            ]);
        }
        return response()->json($card);
    }

    /** GET /corex/map/sold/{layerId} — layerId = mrcr:{id} | psc:{id} */
    public function soldCard(Request $request, string $layerId): JsonResponse
    {
        [$kind, $id] = $this->splitLayerId($layerId);
        $viewMode = $request->query('viewMode', 'agent');

        $card = match ($kind) {
            'mrcr' => $this->soldCardFromMrcr($request, (int) $id),
            'psc'  => $this->soldCardFromPsc($request, (int) $id),
            default => null,
        };
        if (!$card) return response()->json(['error' => 'Not found'], 404);

        if ($viewMode !== 'agent') {
            unset($card['sensitive_facts']);
        }
        return response()->json($card);
    }

    /** GET /corex/map/active/{layerId} — layerId = mrcr:{id} | pal:{id} */
    public function activeCard(Request $request, string $layerId): JsonResponse
    {
        [$kind, $id] = $this->splitLayerId($layerId);
        $viewMode = $request->query('viewMode', 'agent');

        $card = match ($kind) {
            'mrcr' => $this->activeCardFromMrcr($request, (int) $id),
            'pal'  => $this->activeCardFromPal($request, (int) $id),
            default => null,
        };
        if (!$card) return response()->json(['error' => 'Not found'], 404);
        if ($viewMode !== 'agent') unset($card['sensitive_facts']);
        return response()->json($card);
    }

    /** GET /corex/map/mic-subject/{report} — A.2.4 enrichment: full metadata
     *  (report type / period / GPS / data-points count / pulled-at). */
    public function micSubjectCard(Request $request, MarketReport $report): JsonResponse
    {
        $this->assertSameAgency($request, $report->agency_id);

        $munRow = DB::table('market_data_points')
            ->whereNull('deleted_at')
            ->where('report_id', $report->id)
            ->where('metric_key', 'municipal_valuation')
            ->orderByDesc('id')
            ->first(['metric_value_numeric', 'metric_date']);

        $gps = ($report->subject_latitude !== null && $report->subject_longitude !== null)
            ? sprintf('%.5f, %.5f', (float) $report->subject_latitude, (float) $report->subject_longitude)
            : null;

        $pulledAt = $report->parse_completed_at ?? $report->created_at;
        $period   = $report->radius_metres ? round($report->radius_metres / 1000, 1) . ' km radius' : null;

        $facts = array_values(array_filter([
            ['label' => 'Subject address', 'value' => $report->subject_address ?: '—'],
            $report->subject_scheme_name      ? ['label' => 'Scheme',     'value' => $report->subject_scheme_name] : null,
            $report->subject_section_number   ? ['label' => 'Section',    'value' => (string) $report->subject_section_number] : null,
            $report->subject_extent_m2        ? ['label' => 'Extent',     'value' => $report->subject_extent_m2 . ' m²'] : null,
            $munRow                            ? ['label' => 'Municipal evaluation', 'value' => 'R ' . number_format((int) $munRow->metric_value_numeric, 0, '.', ' ') . ($munRow->metric_date ? ' (' . substr($munRow->metric_date, 0, 4) . ')' : '')] : null,
            $report->source_suburb            ? ['label' => 'Source suburb', 'value' => $report->source_suburb] : null,
            $report->source_town              ? ['label' => 'Source town',   'value' => $report->source_town] : null,
            $gps                              ? ['label' => 'GPS',        'value' => $gps] : null,
            $report->report_date              ? ['label' => 'Report date', 'value' => \Carbon\Carbon::parse($report->report_date)->format('j M Y')] : null,
            $period                           ? ['label' => 'Search radius', 'value' => $period] : null,
            $report->data_points_count        ? ['label' => 'Data points', 'value' => number_format((int) $report->data_points_count)] : null,
            ['label' => 'Report type', 'value' => $report->reportType?->display_name ?? '—'],
            $report->parser_version           ? ['label' => 'Parser',     'value' => $report->parser_version] : null,
            $pulledAt                          ? ['label' => 'Pulled at',  'value' => \Carbon\Carbon::parse($pulledAt)->format('j M Y H:i')] : null,
        ]));

        $card = [
            'title'    => $report->subject_address ?: 'Report #' . $report->id,
            'subtitle' => trim(($report->reportType?->display_name ?? 'CMA Report') . ' · ' . (optional($report->created_at)->format('M Y') ?: '')),
            'address'  => $report->subject_address,
            'lat'      => $report->subject_latitude !== null ? (float) $report->subject_latitude : null,
            'lng'      => $report->subject_longitude !== null ? (float) $report->subject_longitude : null,
            'facts'    => $facts,
            'relationships' => array_filter([
                ['label' => 'Open report', 'url' => route('market-intelligence.reports.show', $report)],
            ]),
        ];
        // MIC subjects carry no PII — sensitive_facts stays empty regardless
        // of view mode (kept for client-side gating consistency).
        if ($request->query('viewMode', 'agent') === 'agent') {
            $card['sensitive_facts'] = [];
        }
        return response()->json($card);
    }

    /** GET /corex/map/scheme-owner/{owner} — A.2.4 enrichment.
     *  Building + section + extent + property type in facts; owner identity
     *  (name, ID, phone, email, bond, dates) in sensitive_facts (Agent View
     *  only). Fields that don't exist on the schema (phone/email/ID/bond)
     *  remain placeholders — they activate when those columns land. */
    public function schemeOwnerCard(Request $request, SchemeOwner $owner): JsonResponse
    {
        $this->assertSameAgency($request, $owner->agency_id);
        $isAgentView = $request->query('viewMode', 'agent') === 'agent';

        $matching = MarketReport::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $owner->agency_id)
            ->whereNotNull('subject_latitude')
            ->whereRaw('LOWER(subject_scheme_name) = ?', [mb_strtolower((string) $owner->scheme_name)])
            ->orderByDesc('id')
            ->first(['id', 'subject_latitude', 'subject_longitude', 'subject_address']);

        // Length of ownership — computed when purchase_date exists (column
        // not yet on schema; falls through gracefully).
        $lengthYears = null;
        if (isset($owner->purchase_date) && $owner->purchase_date) {
            try {
                $purchase = \Carbon\Carbon::parse($owner->purchase_date);
                $lengthYears = round(now()->diffInDays($purchase) / 365.25, 1);
            } catch (\Throwable) { /* ignore */ }
        }

        $card = [
            'title'    => trim(($owner->scheme_name ?? '') . ($owner->section_number ? ' § ' . $owner->section_number : '')),
            'subtitle' => $isAgentView ? 'Scheme owner' : 'Sectional Scheme unit',
            'address'  => $matching?->subject_address,
            'lat'      => $matching?->subject_latitude !== null ? (float) $matching->subject_latitude : null,
            'lng'      => $matching?->subject_longitude !== null ? (float) $matching->subject_longitude : null,
            'facts'    => array_values(array_filter([
                $owner->scheme_name        ? ['label' => 'Scheme',        'value' => $owner->scheme_name] : null,
                $owner->section_number     ? ['label' => 'Section',       'value' => (string) $owner->section_number] : null,
                $owner->flat_number        ? ['label' => 'Flat',          'value' => (string) $owner->flat_number] : null,
                $owner->scheme_ss_number   ? ['label' => 'SS number',     'value' => $owner->scheme_ss_number] : null,
                $owner->extent_m2          ? ['label' => 'Floor area',    'value' => $owner->extent_m2 . ' m²'] : null,
                $owner->property_type      ? ['label' => 'Property type', 'value' => $owner->property_type] : null,
                $matching?->subject_address ? ['label' => 'Building address', 'value' => $matching->subject_address] : null,
            ])),
            'relationships' => [],
        ];

        if ($isAgentView) {
            // Sensitive identity + financial facts. Each row is conditional
            // on the underlying column being populated — null rows hidden.
            // Phone / email / ID / bond fields read off the model
            // dynamically; they're null until those columns are added.
            $idNumber = $owner->owner_id_number ?? null;

            $card['sensitive_facts'] = array_values(array_filter([
                $owner->owner_name      ? ['label' => 'Owner', 'value' => $owner->owner_name] : null,
                $idNumber               ? [
                    'label'       => 'ID number',
                    'value'       => self::maskIdNumber((string) $idNumber),
                    'value_raw'   => (string) $idNumber,
                    'copyable'    => true,
                    'va_lookup'   => true,
                ] : null,
                $owner->owner_phone     ? ['label' => 'Phone', 'value' => $owner->owner_phone, 'copyable' => true] : null,
                $owner->owner_email     ? ['label' => 'Email', 'value' => $owner->owner_email, 'copyable' => true] : null,
                isset($owner->purchase_date) && $owner->purchase_date
                    ? ['label' => 'Date acquired', 'value' => \Carbon\Carbon::parse($owner->purchase_date)->format('j M Y')] : null,
                isset($owner->purchase_price) && $owner->purchase_price
                    ? ['label' => 'Purchase price', 'value' => 'R ' . number_format((int) $owner->purchase_price, 0, '.', ' ')] : null,
                $lengthYears                ? ['label' => 'Length of ownership', 'value' => $lengthYears . ' years'] : null,
                isset($owner->bond_holder) && $owner->bond_holder
                    ? ['label' => 'Bond holder', 'value' => $owner->bond_holder] : null,
                isset($owner->bond_amount) && $owner->bond_amount
                    ? ['label' => 'Bond amount', 'value' => 'R ' . number_format((int) $owner->bond_amount, 0, '.', ' ')] : null,
                isset($owner->bond_date) && $owner->bond_date
                    ? ['label' => 'Bond date',  'value' => \Carbon\Carbon::parse($owner->bond_date)->format('j M Y')] : null,
            ]));
        }

        return response()->json($card);
    }

    /**
     * Mask all but last 4 digits of an ID/passport number for visual display.
     * Example: "8901015009085" → "*********9085".
     *
     * Public + static so M54 can assert the contract without instantiating
     * the controller or hitting the DB.
     */
    public static function maskIdNumber(string $id): string
    {
        $len = strlen($id);
        if ($len <= 4) return $id;
        return str_repeat('*', $len - 4) . substr($id, -4);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function soldCardFromMrcr(Request $request, int $id): ?array
    {
        $row = DB::table('market_report_comp_rows as mrcr')
            ->join('market_reports as mr', 'mr.id', '=', 'mrcr.market_report_id')
            ->whereNull('mrcr.deleted_at')
            ->where('mrcr.id', $id)
            ->select([
                'mrcr.*', 'mr.agency_id', 'mr.subject_address',
            ])
            ->first();
        if (!$row) return null;
        $this->assertSameAgency($request, (int) $row->agency_id);

        // A.2.4 — enrich with every column the row carries.
        $isAgentView = $request->query('viewMode', 'agent') === 'agent';
        $rPerM2 = $row->r_per_m2;
        if (!$rPerM2 && $row->sale_price && $row->extent_m2) {
            $rPerM2 = (int) round(((int) $row->sale_price) / max(1, (int) $row->extent_m2));
        }

        $card = [
            'title'    => $row->scheme_name
                ? trim($row->scheme_name . ($row->section_number ? ' § ' . $row->section_number : ''))
                : ($row->address ?: 'Comp #' . $row->id),
            'subtitle' => 'Comparable sale',
            'address'  => $row->address,
            'lat'      => $row->latitude !== null ? (float) $row->latitude : null,
            'lng'      => $row->longitude !== null ? (float) $row->longitude : null,
            'facts'    => array_values(array_filter([
                $row->sale_price                  ? ['label' => 'Sale price', 'value' => 'R ' . number_format((int) $row->sale_price, 0, '.', ' ')] : null,
                $row->sale_date                   ? ['label' => 'Sale date',  'value' => \Carbon\Carbon::parse($row->sale_date)->format('j M Y')] : null,
                $row->property_type               ? ['label' => 'Property type', 'value' => $row->property_type] : null,
                $row->extent_m2                   ? ['label' => 'Extent', 'value' => $row->extent_m2 . ' m²'] : null,
                $rPerM2                            ? ['label' => 'R/m²',   'value' => 'R ' . number_format((int) $rPerM2, 0, '.', ' ')] : null,
                $row->estimated_value             ? ['label' => 'Estimated value', 'value' => 'R ' . number_format((int) $row->estimated_value, 0, '.', ' ')] : null,
                $row->municipal_valuation         ? ['label' => 'Municipal evaluation', 'value' => 'R ' . number_format((int) $row->municipal_valuation, 0, '.', ' ') . ($row->municipal_valuation_year ? ' (' . $row->municipal_valuation_year . ')' : '')] : null,
                $row->condition                   ? ['label' => 'Condition', 'value' => $row->condition] : null,
                $row->days_on_market              ? ['label' => 'Days on market', 'value' => (string) $row->days_on_market] : null,
                $row->distance_to_subject_m !== null ? ['label' => 'From subject', 'value' => $row->distance_to_subject_m . ' m'] : null,
                $row->suburb_normalised           ? ['label' => 'Suburb', 'value' => $row->suburb_normalised] : null,
                $row->ss_number                   ? ['label' => 'SS No', 'value' => $row->ss_number] : null,
                ['label' => 'Source', 'value' => 'CMA Info report'],
            ])),
            'relationships' => array_filter([
                ['label' => 'Open evaluation report', 'url' => route('market-intelligence.reports.show', $row->market_report_id)],
            ]),
        ];
        if ($isAgentView) {
            // Buyer / seller / bond details would land in sensitive_facts when
            // those columns ever populate. None are on the current MRCR schema.
            $card['sensitive_facts'] = [];
        }
        return $card;
    }

    private function soldCardFromPsc(Request $request, int $id): ?array
    {
        $row = DB::table('presentation_sold_comps as psc')
            ->join('presentations as p', 'p.id', '=', 'psc.presentation_id')
            ->whereNull('psc.deleted_at')
            ->where('psc.id', $id)
            ->select([
                'psc.*', 'p.agency_id', 'p.id as presentation_id', 'p.title as presentation_title',
            ])
            ->first();
        if (!$row) return null;
        $this->assertSameAgency($request, (int) $row->agency_id);

        $raw = is_string($row->raw_row_json) ? (json_decode($row->raw_row_json, true) ?: []) : ((array) $row->raw_row_json ?: []);
        $isAgentView = $request->query('viewMode', 'agent') === 'agent';

        // A.2.4 — compute R/m² and surface every available field from the
        // base columns + raw_row_json blob.
        $price = $row->sold_price_inc ? (int) $row->sold_price_inc : null;
        $size  = $row->size_m2 ? (float) $row->size_m2 : null;
        $rPerM2 = ($price && $size) ? (int) round($price / max(1, $size)) : null;

        $card = [
            'title'    => $raw['address'] ?? 'Comp #' . $row->id,
            'subtitle' => 'Comparable sale (presentation)',
            'address'  => $raw['address'] ?? null,
            'lat'      => $raw['latitude'] ?? null,
            'lng'      => $raw['longitude'] ?? null,
            'facts'    => array_values(array_filter([
                $price                            ? ['label' => 'Sale price', 'value' => 'R ' . number_format($price, 0, '.', ' ')] : null,
                $row->sold_date                   ? ['label' => 'Sale date',  'value' => \Carbon\Carbon::parse($row->sold_date)->format('j M Y')] : null,
                $row->property_type               ? ['label' => 'Property type', 'value' => $row->property_type] : null,
                $row->beds                        ? ['label' => 'Bedrooms',  'value' => (string) $row->beds] : null,
                $row->baths                       ? ['label' => 'Bathrooms', 'value' => (string) $row->baths] : null,
                $size                              ? ['label' => 'Extent', 'value' => $size . ' m²'] : null,
                $rPerM2                            ? ['label' => 'R/m²',   'value' => 'R ' . number_format($rPerM2, 0, '.', ' ')] : null,
                $row->suburb                      ? ['label' => 'Suburb', 'value' => $row->suburb] : null,
                $row->listed_date                 ? ['label' => 'Listed date', 'value' => \Carbon\Carbon::parse($row->listed_date)->format('j M Y')] : null,
                !empty($raw['days_on_market'])    ? ['label' => 'Days on market', 'value' => (string) $raw['days_on_market']] : null,
                !empty($raw['agency_name'])       ? ['label' => 'Listing agency', 'value' => $raw['agency_name']] : null,
                ['label' => 'Source', 'value' => 'Presentation comp upload'],
                $row->parser_version              ? ['label' => 'Parser', 'value' => $row->parser_version] : null,
                $row->created_at                  ? ['label' => 'Pulled at', 'value' => \Carbon\Carbon::parse($row->created_at)->format('j M Y')] : null,
            ])),
            'relationships' => array_filter([
                $row->presentation_id ? ['label' => 'Open presentation', 'url' => route('presentations.show', $row->presentation_id)] : null,
            ]),
        ];
        if ($isAgentView) {
            $card['sensitive_facts'] = array_values(array_filter([
                // Buyer / seller / agent name from raw JSON if present.
                !empty($raw['buyer_name'])  ? ['label' => 'Buyer',  'value' => $raw['buyer_name']]  : null,
                !empty($raw['seller_name']) ? ['label' => 'Seller', 'value' => $raw['seller_name']] : null,
                !empty($raw['agent_name'])  ? ['label' => 'Listing agent', 'value' => $raw['agent_name']] : null,
            ]));
        }
        return $card;
    }

    private function activeCardFromMrcr(Request $request, int $id): ?array
    {
        $row = DB::table('market_report_comp_rows as mrcr')
            ->join('market_reports as mr', 'mr.id', '=', 'mrcr.market_report_id')
            ->whereNull('mrcr.deleted_at')
            ->where('mrcr.id', $id)
            ->where('mrcr.row_type', 'listing')
            ->select(['mrcr.*', 'mr.agency_id'])
            ->first();
        if (!$row) return null;
        $this->assertSameAgency($request, (int) $row->agency_id);

        $isAgentView = $request->query('viewMode', 'agent') === 'agent';

        // A.2.4 — enrich Portal Stock detail (from MIC report row_type=listing).
        $card = [
            'title'    => $row->scheme_name
                ? trim($row->scheme_name . ($row->section_number ? ' § ' . $row->section_number : ''))
                : ($row->address ?: 'Listing #' . $row->id),
            'subtitle' => 'Portal Stock listing',
            'address'  => $row->address,
            'lat'      => $row->latitude !== null ? (float) $row->latitude : null,
            'lng'      => $row->longitude !== null ? (float) $row->longitude : null,
            'facts'    => array_values(array_filter([
                $row->list_price                  ? ['label' => 'List price',   'value' => 'R ' . number_format((int) $row->list_price, 0, '.', ' ')] : null,
                $row->days_on_market              ? ['label' => 'Days on market', 'value' => (string) $row->days_on_market] : null,
                $row->property_type               ? ['label' => 'Property type', 'value' => $row->property_type] : null,
                $row->extent_m2                   ? ['label' => 'Extent', 'value' => $row->extent_m2 . ' m²'] : null,
                $row->municipal_valuation         ? ['label' => 'Municipal evaluation', 'value' => 'R ' . number_format((int) $row->municipal_valuation, 0, '.', ' ') . ($row->municipal_valuation_year ? ' (' . $row->municipal_valuation_year . ')' : '')] : null,
                $row->condition                   ? ['label' => 'Condition',   'value' => $row->condition] : null,
                $row->ss_number                   ? ['label' => 'SS number',   'value' => $row->ss_number] : null,
                $row->distance_to_subject_m !== null ? ['label' => 'From subject', 'value' => $row->distance_to_subject_m . ' m'] : null,
                ['label' => 'Source', 'value' => 'CMA Info report'],
            ])),
            'relationships' => array_filter([
                ['label' => 'Open evaluation report', 'url' => route('market-intelligence.reports.show', $row->market_report_id)],
            ]),
        ];
        if ($isAgentView) {
            // Agent name / agency captured by MRCR rows — none on schema today.
            $card['sensitive_facts'] = [];
        }

        // A.2.5 — Portal Stock collision check: tell the client whether HFC
        // already has this address before "Prospect Now" is offered.
        $card['prospect_status'] = $this->resolveProspectStatus($request, [
            'address'   => $row->address,
            'latitude'  => $row->latitude !== null ? (float) $row->latitude : null,
            'longitude' => $row->longitude !== null ? (float) $row->longitude : null,
            'suburb'    => $row->suburb_normalised ?? null,
        ], (int) $row->agency_id);

        return $card;
    }

    private function activeCardFromPal(Request $request, int $id): ?array
    {
        $row = DB::table('presentation_active_listings as pal')
            ->join('presentations as p', 'p.id', '=', 'pal.presentation_id')
            ->whereNull('pal.deleted_at')
            ->where('pal.id', $id)
            ->select(['pal.*', 'p.agency_id', 'p.id as presentation_id'])
            ->first();
        if (!$row) return null;
        $this->assertSameAgency($request, (int) $row->agency_id);

        $raw = is_string($row->raw_row_json) ? (json_decode($row->raw_row_json, true) ?: []) : ((array) $row->raw_row_json ?: []);
        $isAgentView = $request->query('viewMode', 'agent') === 'agent';

        // Days on portal — prefer raw_row_json (most accurate) then computed
        // from first_seen_at/last_seen_at if those make it onto PAL.
        $daysOnPortal = $raw['days_on_market'] ?? null;
        if ($daysOnPortal === null && $row->first_seen_at) {
            $daysOnPortal = (int) \Carbon\Carbon::parse($row->first_seen_at)->diffInDays(now());
        }

        // Portal URL if captured — surfaces "Open on P24/PP" relationship link.
        $portalUrl = $raw['portal_url'] ?? $raw['source_url'] ?? null;
        $portalKey = $raw['portal_source'] ?? null;
        $portalLabel = match ($portalKey) {
            'p24'  => 'Open on Property24',
            'pp'   => 'Open on Private Property',
            default => 'Open portal listing',
        };

        $card = [
            'title'    => $raw['address'] ?? 'Listing #' . $row->id,
            'subtitle' => 'Portal Stock listing',
            'address'  => $raw['address'] ?? null,
            'lat'      => $raw['latitude'] ?? null,
            'lng'      => $raw['longitude'] ?? null,
            'facts'    => array_values(array_filter([
                $row->list_price_inc              ? ['label' => 'Asking price', 'value' => 'R ' . number_format((int) $row->list_price_inc, 0, '.', ' ')] : null,
                $daysOnPortal                      ? ['label' => 'Days on portal', 'value' => (string) $daysOnPortal] : null,
                $row->listing_date                ? ['label' => 'Listed date', 'value' => \Carbon\Carbon::parse($row->listing_date)->format('j M Y')] : null,
                $row->property_type               ? ['label' => 'Property type', 'value' => $row->property_type] : null,
                $row->beds                        ? ['label' => 'Bedrooms', 'value' => (string) $row->beds] : null,
                $row->baths                       ? ['label' => 'Bathrooms', 'value' => (string) $row->baths] : null,
                $row->size_m2                     ? ['label' => 'Extent', 'value' => $row->size_m2 . ' m²'] : null,
                $row->suburb                      ? ['label' => 'Suburb', 'value' => $row->suburb] : null,
                !empty($raw['portal_source'])     ? ['label' => 'Portal',     'value' => strtoupper($raw['portal_source'])] : null,
                !empty($raw['portal_ref'])        ? ['label' => 'Portal ref', 'value' => $raw['portal_ref']] : null,
                !empty($raw['agency_name'])       ? ['label' => 'Listing agency', 'value' => $raw['agency_name']] : null,
                $row->extraction_method           ? ['label' => 'Capture method', 'value' => $row->extraction_method] : null,
                $row->first_seen_at               ? ['label' => 'Captured at', 'value' => \Carbon\Carbon::parse($row->first_seen_at)->format('j M Y')] : null,
                $row->last_seen_at                ? ['label' => 'Last seen at', 'value' => \Carbon\Carbon::parse($row->last_seen_at)->format('j M Y')] : null,
                $row->data_quality_score          ? ['label' => 'Data quality', 'value' => $row->data_quality_score] : null,
            ])),
            'relationships' => array_values(array_filter([
                $portalUrl ? ['label' => $portalLabel, 'url' => $portalUrl] : null,
                $row->presentation_id ? ['label' => 'Open presentation', 'url' => route('presentations.show', $row->presentation_id)] : null,
            ])),
        ];
        if ($isAgentView) {
            $card['sensitive_facts'] = array_values(array_filter([
                !empty($raw['agent_name'])  ? ['label' => 'Listing agent', 'value' => $raw['agent_name']] : null,
                !empty($raw['agent_phone']) ? ['label' => 'Agent phone',  'value' => $raw['agent_phone'], 'copyable' => true] : null,
                !empty($raw['agent_email']) ? ['label' => 'Agent email',  'value' => $raw['agent_email'], 'copyable' => true] : null,
            ]));
        }

        // A.2.5 — Portal Stock collision check (same as MRCR branch).
        $card['prospect_status'] = $this->resolveProspectStatus($request, [
            'address'   => $raw['address'] ?? null,
            'latitude'  => isset($raw['latitude'])  ? (float) $raw['latitude']  : null,
            'longitude' => isset($raw['longitude']) ? (float) $raw['longitude'] : null,
            'suburb'    => $row->suburb ?? null,
        ], (int) $row->agency_id);

        return $card;
    }

    /**
     * Phase A.2.5 — collision detector wrapper. Returns the prospect_status
     * dict for the right-panel client to switch on. Failure-isolated: a
     * lookup hiccup never breaks the card.
     */
    private function resolveProspectStatus(Request $request, array $facts, int $agencyId): array
    {
        try {
            $user = $request->user();
            if (!$user) return ['status' => 'available'];
            return app(MapProspectStatusService::class)->resolve($facts, $agencyId, (int) $user->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('resolveProspectStatus failed', [
                'err' => $e->getMessage(),
            ]);
            return ['status' => 'available'];
        }
    }

    private function splitLayerId(string $layerId): array
    {
        $parts = explode(':', $layerId, 2);
        if (count($parts) !== 2) return ['', ''];
        return $parts;
    }

    private function assertSameAgency(Request $request, ?int $agencyId): void
    {
        $user = $request->user();
        // Phase 3g hotfix #2 — use effectiveAgencyId() so System Owners
        // (agency_id=NULL on the users row, session-selected agency) pass
        // when they're viewing data inside their currently selected agency.
        $effectiveAgencyId = $user?->effectiveAgencyId();
        if (!$user || $effectiveAgencyId === null || $effectiveAgencyId !== $agencyId) {
            abort(403, 'Cross-agency access denied.');
        }
    }
}
