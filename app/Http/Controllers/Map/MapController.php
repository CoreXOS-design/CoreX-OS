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
        ]);

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
        );

        $startedAt = microtime(true);
        $payload = $this->svc->getPinsInBounds($req);
        $payload['meta'] = [
            'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'view_mode'        => $req->viewMode,
        ];

        return response()->json($payload);
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

    /** GET /corex/map/mic-subject/{report} */
    public function micSubjectCard(Request $request, MarketReport $report): JsonResponse
    {
        $this->assertSameAgency($request, $report->agency_id);
        $viewMode = $request->query('viewMode', 'agent');

        $munRow = DB::table('market_data_points')
            ->whereNull('deleted_at')
            ->where('report_id', $report->id)
            ->where('metric_key', 'municipal_valuation')
            ->orderByDesc('id')
            ->first(['metric_value_numeric', 'metric_date']);

        $facts = array_filter([
            ['label' => 'Subject address', 'value' => $report->subject_address ?: '—'],
            $report->subject_scheme_name ? ['label' => 'Scheme', 'value' => $report->subject_scheme_name] : null,
            $report->subject_section_number ? ['label' => 'Section', 'value' => (string) $report->subject_section_number] : null,
            $report->subject_extent_m2 ? ['label' => 'Extent', 'value' => $report->subject_extent_m2 . ' m²'] : null,
            $munRow ? ['label' => 'Municipal valuation', 'value' => 'R ' . number_format((int) $munRow->metric_value_numeric, 0, '.', ' ') . ($munRow->metric_date ? ' (' . substr($munRow->metric_date, 0, 4) . ')' : '')] : null,
            ['label' => 'Imported', 'value' => optional($report->created_at)->format('M Y') ?: '—'],
            ['label' => 'Report type', 'value' => $report->reportType?->display_name ?? '—'],
        ]);

        $card = [
            'title'    => $report->subject_address ?: 'Report #' . $report->id,
            'subtitle' => trim(($report->reportType?->display_name ?? 'CMA Report') . ' · ' . (optional($report->created_at)->format('M Y') ?: '')),
            'address'  => $report->subject_address,
            'lat'      => $report->subject_latitude !== null ? (float) $report->subject_latitude : null,
            'lng'      => $report->subject_longitude !== null ? (float) $report->subject_longitude : null,
            'facts'    => array_values($facts),
            'relationships' => array_filter([
                ['label' => 'Open report', 'url' => route('market-intelligence.reports.show', $report)],
            ]),
        ];
        if ($viewMode === 'agent') {
            $card['sensitive_facts'] = [];
        }
        return response()->json($card);
    }

    /** GET /corex/map/scheme-owner/{owner} — Agent View only. */
    public function schemeOwnerCard(Request $request, SchemeOwner $owner): JsonResponse
    {
        $this->assertSameAgency($request, $owner->agency_id);
        if ($request->query('viewMode', 'agent') !== 'agent') {
            return response()->json(['error' => 'Forbidden in Seller View'], 403);
        }

        $matching = MarketReport::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $owner->agency_id)
            ->whereNotNull('subject_latitude')
            ->whereRaw('LOWER(subject_scheme_name) = ?', [mb_strtolower((string) $owner->scheme_name)])
            ->orderByDesc('id')
            ->first(['id', 'subject_latitude', 'subject_longitude', 'subject_address']);

        $card = [
            'title'    => trim(($owner->scheme_name ?? '') . ($owner->section_number ? ' § ' . $owner->section_number : '')),
            'subtitle' => 'Scheme owner',
            'address'  => $matching?->subject_address,
            'lat'      => $matching?->subject_latitude !== null ? (float) $matching->subject_latitude : null,
            'lng'      => $matching?->subject_longitude !== null ? (float) $matching->subject_longitude : null,
            'facts'    => array_filter([
                $owner->scheme_name   ? ['label' => 'Scheme',  'value' => $owner->scheme_name] : null,
                $owner->section_number ? ['label' => 'Section', 'value' => (string) $owner->section_number] : null,
                $owner->ss_number     ? ['label' => 'SS No',   'value' => $owner->ss_number] : null,
                $owner->ss_year       ? ['label' => 'SS Year', 'value' => (string) $owner->ss_year] : null,
            ]),
            'relationships' => [],
            'sensitive_facts' => array_filter([
                $owner->owner_name      ? ['label' => 'Owner',   'value' => $owner->owner_name] : null,
                $owner->owner_id_number ? ['label' => 'ID',      'value' => $owner->owner_id_number] : null,
                $owner->owner_phone     ? ['label' => 'Phone',   'value' => $owner->owner_phone] : null,
                $owner->owner_email     ? ['label' => 'Email',   'value' => $owner->owner_email] : null,
            ]),
        ];
        return response()->json($card);
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

        return [
            'title'    => $row->scheme_name
                ? trim($row->scheme_name . ($row->section_number ? ' § ' . $row->section_number : ''))
                : ($row->address ?: 'Comp #' . $row->id),
            'subtitle' => 'Comparable sale',
            'address'  => $row->address,
            'lat'      => $row->latitude !== null ? (float) $row->latitude : null,
            'lng'      => $row->longitude !== null ? (float) $row->longitude : null,
            'facts'    => array_filter([
                ['label' => 'Sale price', 'value' => $row->sale_price ? 'R ' . number_format((int) $row->sale_price, 0, '.', ' ') : '—'],
                ['label' => 'Sale date',  'value' => $row->sale_date ?: '—'],
                $row->extent_m2 ? ['label' => 'Extent', 'value' => $row->extent_m2 . ' m²'] : null,
                $row->r_per_m2  ? ['label' => 'R/m²',   'value' => 'R ' . number_format((int) $row->r_per_m2, 0, '.', ' ')] : null,
                $row->distance_to_subject_m !== null ? ['label' => 'From subject', 'value' => $row->distance_to_subject_m . ' m'] : null,
            ]),
            'relationships' => array_filter([
                ['label' => 'Open source report', 'url' => route('market-intelligence.reports.show', $row->market_report_id)],
            ]),
            'sensitive_facts' => [],
        ];
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
        return [
            'title'    => $raw['address'] ?? 'Comp #' . $row->id,
            'subtitle' => 'Comparable sale (presentation)',
            'address'  => $raw['address'] ?? null,
            'lat'      => $raw['latitude'] ?? null,
            'lng'      => $raw['longitude'] ?? null,
            'facts'    => array_filter([
                ['label' => 'Sale price', 'value' => $row->sold_price_inc ? 'R ' . number_format((int) $row->sold_price_inc, 0, '.', ' ') : '—'],
                ['label' => 'Sale date',  'value' => $row->sold_date ?: '—'],
                $row->size_m2 ? ['label' => 'Extent', 'value' => $row->size_m2 . ' m²'] : null,
            ]),
            'relationships' => array_filter([
                $row->presentation_id ? ['label' => 'Open presentation', 'url' => route('corex.presentations.show', $row->presentation_id)] : null,
            ]),
            'sensitive_facts' => [],
        ];
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

        return [
            'title'    => $row->scheme_name
                ? trim($row->scheme_name . ($row->section_number ? ' § ' . $row->section_number : ''))
                : ($row->address ?: 'Listing #' . $row->id),
            'subtitle' => 'Active listing',
            'address'  => $row->address,
            'lat'      => $row->latitude !== null ? (float) $row->latitude : null,
            'lng'      => $row->longitude !== null ? (float) $row->longitude : null,
            'facts'    => array_filter([
                ['label' => 'List price', 'value' => $row->list_price ? 'R ' . number_format((int) $row->list_price, 0, '.', ' ') : '—'],
                $row->days_on_market ? ['label' => 'Days on market', 'value' => (string) $row->days_on_market] : null,
                $row->extent_m2 ? ['label' => 'Extent', 'value' => $row->extent_m2 . ' m²'] : null,
            ]),
            'relationships' => array_filter([
                ['label' => 'Open source report', 'url' => route('market-intelligence.reports.show', $row->market_report_id)],
            ]),
            'sensitive_facts' => [],
        ];
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
        return [
            'title'    => $raw['address'] ?? 'Listing #' . $row->id,
            'subtitle' => 'Active listing (presentation)',
            'address'  => $raw['address'] ?? null,
            'lat'      => $raw['latitude'] ?? null,
            'lng'      => $raw['longitude'] ?? null,
            'facts'    => array_filter([
                ['label' => 'List price', 'value' => $row->list_price_inc ? 'R ' . number_format((int) $row->list_price_inc, 0, '.', ' ') : '—'],
                isset($raw['days_on_market']) ? ['label' => 'Days on market', 'value' => (string) $raw['days_on_market']] : null,
                $row->size_m2 ? ['label' => 'Extent', 'value' => $row->size_m2 . ' m²'] : null,
            ]),
            'relationships' => array_filter([
                $row->presentation_id ? ['label' => 'Open presentation', 'url' => route('corex.presentations.show', $row->presentation_id)] : null,
            ]),
            'sensitive_facts' => [],
        ];
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
