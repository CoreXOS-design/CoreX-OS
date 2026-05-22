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
    /** @return array{bounds: array, layers: array, totals: array} */
    public function getPinsInBounds(MapBoundsRequest $req): array
    {
        $perLayerLimit = max(50, (int) floor($req->effectiveLimit() / max(1, count($req->layers))));

        $layers = [];
        $totals = [];

        if ($req->wantsLayer('hfc_listings')) {
            [$pins, $total] = $this->hfcListings($req, $perLayerLimit);
            $layers[] = ['key' => 'hfc_listings', 'count' => count($pins), 'total' => $total, 'capped' => $total > count($pins), 'pins' => $pins];
            $totals['hfc_listings'] = $total;
        }

        if ($req->wantsLayer('sold_comps')) {
            [$pins, $total] = $this->soldComps($req, $perLayerLimit);
            $layers[] = ['key' => 'sold_comps', 'count' => count($pins), 'total' => $total, 'capped' => $total > count($pins), 'pins' => $pins];
            $totals['sold_comps'] = $total;
        }

        if ($req->wantsLayer('active_listings')) {
            [$pins, $total] = $this->activeListings($req, $perLayerLimit);
            $layers[] = ['key' => 'active_listings', 'count' => count($pins), 'total' => $total, 'capped' => $total > count($pins), 'pins' => $pins];
            $totals['active_listings'] = $total;
        }

        if ($req->wantsLayer('mic_subjects')) {
            [$pins, $total] = $this->micSubjects($req, $perLayerLimit);
            $layers[] = ['key' => 'mic_subjects', 'count' => count($pins), 'total' => $total, 'capped' => $total > count($pins), 'pins' => $pins];
            $totals['mic_subjects'] = $total;
        }

        // Scheme Owners — Agent View only.
        if ($req->wantsLayer('scheme_owners') && !$req->isSellerView()) {
            [$pins, $total] = $this->schemeOwners($req, $perLayerLimit);
            $layers[] = ['key' => 'scheme_owners', 'count' => count($pins), 'total' => $total, 'capped' => $total > count($pins), 'pins' => $pins];
            $totals['scheme_owners'] = $total;
        }

        return [
            'bounds'  => [
                'north' => $req->north, 'south' => $req->south,
                'east'  => $req->east,  'west'  => $req->west,
            ],
            'layers'  => $layers,
            'totals'  => $totals,
        ];
    }

    /** @return array{0: array, 1: int} */
    private function hfcListings(MapBoundsRequest $req, int $limit): array
    {
        $q = DB::table('properties')
            ->whereNull('deleted_at')
            ->where('agency_id', $req->agencyId)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereBetween('latitude',  [$req->south, $req->north])
            ->whereBetween('longitude', [$req->west,  $req->east]);

        $this->applyPropertyTypeFilter($q, $req, 'property_type');
        $this->applyPriceFilter($q, $req, 'price');

        $total = (clone $q)->count();

        $rows = $q->select(['id', 'address', 'property_type', 'price', 'latitude', 'longitude'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $pins = $rows->map(fn ($r) => [
            'id'         => (int) $r->id,
            'layer'      => 'hfc_listings',
            'lat'        => (float) $r->latitude,
            'lng'        => (float) $r->longitude,
            'title'      => $r->address ?: 'Property #' . $r->id,
            'subtitle'   => $this->formatPropertySubtitle($r),
            'price'      => $r->price !== null ? (int) $r->price : null,
            'date'       => null,
            'detail_url' => route('corex.properties.map-card', ['property' => $r->id]),
            'sensitive'  => false,
        ])->all();

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
            ->leftJoin('market_reports as mr_scheme', function ($j) {
                $j->on(DB::raw('LOWER(mr_scheme.subject_scheme_name)'), '=', DB::raw('LOWER(mrcr.scheme_name)'))
                  ->whereNotNull('mr_scheme.subject_latitude');
            })
            ->whereNull('mrcr.deleted_at')
            ->where('mr.agency_id', $req->agencyId)
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
            ]);
        $this->applyDateFilter($mrcrQ, $req, 'mrcr.sale_date');
        $this->applyPriceFilter($mrcrQ, $req, 'mrcr.sale_price');

        foreach ($mrcrQ->limit($limit)->get() as $r) {
            $key = $this->dedupeKey($r->address ?? $r->scheme_name ?? '', $r->sale_date ?? '');
            if (isset($combined[$key])) continue;
            $price = OutlierGuard::price($r->sale_price);
            $title = $r->scheme_name
                ? trim($r->scheme_name . ($r->section_number ? ' § ' . $r->section_number : ''))
                : ($r->address ?? 'Comp #' . $r->id);
            $combined[$key] = [
                'id'         => 'mrcr:' . $r->id,
                'layer'      => 'sold_comps',
                'lat'        => (float) $r->latitude,
                'lng'        => (float) $r->longitude,
                'title'      => $title,
                'subtitle'   => $this->formatSoldSubtitle($price, $r->sale_date),
                'price'      => $price,
                'date'       => $r->sale_date,
                'detail_url' => route('corex.map.sold', ['layerId' => 'mrcr:' . $r->id]),
                'sensitive'  => false,
            ];
        }

        // (b) presentation_sold_comps NOT covered by (a). Read GPS via JSON
        // join to mrcr by mic_comp_row_id. Skip when mic_comp_row_id is null.
        $pscQ = DB::table('presentation_sold_comps as psc')
            ->join('presentations as p', 'p.id', '=', 'psc.presentation_id')
            ->whereNull('psc.deleted_at')
            ->where('p.agency_id', $req->agencyId)
            ->whereNotNull('psc.raw_row_json')
            ->select([
                'psc.id', 'psc.sold_date as sale_date', 'psc.sold_price_inc as sale_price',
                'psc.raw_row_json', 'psc.suburb',
            ]);
        $this->applyDateFilter($pscQ, $req, 'psc.sold_date');
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

        $pins = array_slice(array_values($combined), 0, $limit);
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
            ->leftJoin('market_reports as mr_scheme', function ($j) {
                $j->on(DB::raw('LOWER(mr_scheme.subject_scheme_name)'), '=', DB::raw('LOWER(mrcr.scheme_name)'))
                  ->whereNotNull('mr_scheme.subject_latitude');
            })
            ->whereNull('mrcr.deleted_at')
            ->where('mr.agency_id', $req->agencyId)
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
            ]);
        $this->applyPriceFilter($mrcrQ, $req, 'mrcr.list_price');

        foreach ($mrcrQ->limit($limit)->get() as $r) {
            $key = $this->dedupeKey($r->address ?? $r->scheme_name ?? '', 'active');
            if (isset($combined[$key])) continue;
            $price = OutlierGuard::price($r->list_price);
            $title = $r->scheme_name
                ? trim($r->scheme_name . ($r->section_number ? ' § ' . $r->section_number : ''))
                : ($r->address ?? 'Listing #' . $r->id);
            $combined[$key] = [
                'id'         => 'mrcr:' . $r->id,
                'layer'      => 'active_listings',
                'lat'        => (float) $r->latitude,
                'lng'        => (float) $r->longitude,
                'title'      => $title,
                'subtitle'   => $this->formatActiveSubtitle($price, $r->days_on_market),
                'price'      => $price,
                'date'       => null,
                'detail_url' => route('corex.map.active', ['layerId' => 'mrcr:' . $r->id]),
                'sensitive'  => false,
            ];
        }

        // (b) presentation_active_listings via mic_comp_row_id join.
        $palQ = DB::table('presentation_active_listings as pal')
            ->join('presentations as p', 'p.id', '=', 'pal.presentation_id')
            ->whereNull('pal.deleted_at')
            ->where('p.agency_id', $req->agencyId)
            ->whereNotNull('pal.raw_row_json')
            ->select([
                'pal.id', 'pal.list_price_inc as list_price',
                'pal.raw_row_json', 'pal.suburb',
            ]);
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
        return [$pins, count($combined)];
    }

    /** @return array{0: array, 1: int} */
    private function micSubjects(MapBoundsRequest $req, int $limit): array
    {
        $q = DB::table('market_reports')
            ->whereNull('deleted_at')
            ->where('agency_id', $req->agencyId)
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
            ->where('so.agency_id', $req->agencyId)
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

        $total = DB::table('scheme_owners as so')
            ->join('market_reports as mr', function ($j) {
                $j->on(DB::raw('LOWER(mr.subject_scheme_name)'), '=', DB::raw('LOWER(so.scheme_name)'));
            })
            ->whereNull('so.deleted_at')
            ->whereNull('mr.deleted_at')
            ->where('so.agency_id', $req->agencyId)
            ->whereNotNull('mr.subject_latitude')
            ->whereBetween('mr.subject_latitude',  [$req->south, $req->north])
            ->whereBetween('mr.subject_longitude', [$req->west,  $req->east])
            ->distinct('so.id')
            ->count('so.id');

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

        return [$pins, $total];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function applyPropertyTypeFilter($q, MapBoundsRequest $req, string $column): void
    {
        if (empty($req->propertyTypes)) return;
        $q->whereIn($column, $req->propertyTypes);
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
