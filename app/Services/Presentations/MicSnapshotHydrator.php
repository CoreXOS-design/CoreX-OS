<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Agency;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Models\Presentation;
use App\Models\PresentationActiveListing;
use App\Models\PresentationField;
use App\Models\PresentationSoldComp;
use App\Models\Property;
use App\Models\PropertySettingItem;
use App\Support\MarketAnalytics\HaversineDistance;
use App\Support\MarketAnalytics\OutlierGuard;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3d — MIC → Presentation snapshot hydration.
 *
 * On generation we copy the agency-wide MIC evidence (market_report_comp_rows
 * + market_data_points) into the per-presentation tables that downstream
 * consumers (AnalysisDataService, PresentationPdfService, PricingSimulator)
 * already read from. No consumer needs modification — they keep reading
 * from presentation_sold_comps + presentation_active_listings + presentation_fields
 * exactly as before. The hydrator just makes sure those tables have the
 * right rows for the current property at compile time.
 *
 * Source-tag convention:
 *   parser_version = 'mic_snapshot_v1'  → MIC-sourced rows
 *   anything else                        → manual upload / portal / legacy
 *
 * On regeneration the previous 'mic_snapshot_v1' rows for the presentation
 * are deleted before re-insert, so the snapshot stays fresh. Manual upload
 * rows (other parser_version values) are never touched.
 *
 * Match strategy (call-out in Phase 3d §0 audit):
 *   1. Same subject — every row from market_reports.subject_address that
 *      LIKE-matches the presentation's property_address.
 *   2. Suburb-scope — suburb_normalised LIKE the presentation suburb.
 *   3. Geo-scope — Haversine when both sides have geo (only when scope=radius_all).
 * The union of these three is filtered by date window + property type, then
 * deduplicated by fingerprint.
 */
final class MicSnapshotHydrator
{
    public const SOURCE_TAG = 'mic_snapshot_v1';

    /**
     * @return array{
     *   sold_comps_inserted: int,
     *   active_listings_inserted: int,
     *   suburb_metrics_snapshotted: int,
     *   cma_metrics_snapshotted: int,
     *   source_reports: array<int>,
     *   scope_used: string,
     *   radius_m: int,
     *   period_months: int,
     * }
     */
    public function hydrateForPresentation(Presentation $presentation): array
    {
        $cfg = $this->resolveConfig($presentation);

        // Wipe previous MIC rows for this presentation. Manual evidence stays.
        PresentationSoldComp::where('presentation_id', $presentation->id)
            ->where('parser_version', self::SOURCE_TAG)
            ->delete();
        PresentationActiveListing::where('presentation_id', $presentation->id)
            ->where('parser_version', self::SOURCE_TAG)
            ->delete();

        // ── Sold comps ─────────────────────────────────────────────────────
        $compRows = $this->collectMatchedRows(
            $presentation, $cfg, MarketReportCompRow::ROW_COMP,
        );
        $deduped = $this->deduplicate($compRows, isSold: true);
        $soldInserted = 0;
        foreach ($deduped as $row) {
            // Phase 3e B — sanitise comp price/extent before persisting.
            $salePrice = OutlierGuard::price($row->sale_price);
            $sizeM2    = OutlierGuard::extentM2($row->extent_m2);
            if ($salePrice === null) {
                // Skip rows the guard couldn't validate — they'd corrupt averages.
                continue;
            }
            try {
                PresentationSoldComp::create([
                    'presentation_id' => $presentation->id,
                    'sold_date'       => $row->sale_date,
                    'sold_price_inc'  => $salePrice,
                    'suburb'          => $this->resolveSuburb($row, $presentation),
                    'property_type'   => $row->property_type,
                    'size_m2'         => $sizeM2,
                    'raw_row_json'    => $this->encodeRaw($row, $cfg['source_reports'] ?? [], $presentation),
                    'parser_version'  => self::SOURCE_TAG,
                ]);
                $soldInserted++;
            } catch (\Throwable) {
                // Skip; don't break the rest.
            }
        }

        // ── Active listings ────────────────────────────────────────────────
        $listingRows = $this->collectMatchedRows(
            $presentation, $cfg, MarketReportCompRow::ROW_LISTING,
        );
        $listingDedup = $this->deduplicate($listingRows, isSold: false);
        $listingInserted = 0;
        foreach ($listingDedup as $row) {
            $listPrice = OutlierGuard::price($row->list_price);
            $sizeM2    = OutlierGuard::extentM2($row->extent_m2);
            if ($listPrice === null) {
                continue;
            }
            try {
                PresentationActiveListing::create([
                    'presentation_id'   => $presentation->id,
                    'list_price_inc'    => $listPrice,
                    'suburb'            => $this->resolveSuburb($row, $presentation),
                    'property_type'     => $row->property_type,
                    'size_m2'           => $sizeM2,
                    'status'            => 'active',
                    'raw_row_json'      => $this->encodeRaw($row, $cfg['source_reports'] ?? [], $presentation),
                    'parser_version'    => self::SOURCE_TAG,
                    'extraction_method' => 'mic_snapshot_v1',
                    'is_active'         => true,
                    'first_seen_at'     => now(),
                    'last_seen_at'      => now(),
                ]);
                $listingInserted++;
            } catch (\Throwable) {
                // Skip
            }
        }

        // ── Suburb metrics ─────────────────────────────────────────────────
        $suburbMetrics = $this->hydrateSuburbMetrics($presentation);

        // ── CMA valuation ──────────────────────────────────────────────────
        $cmaMetrics = $this->hydrateCmaMetrics($presentation);

        return [
            'sold_comps_inserted'        => $soldInserted,
            'active_listings_inserted'   => $listingInserted,
            'suburb_metrics_snapshotted' => $suburbMetrics,
            'cma_metrics_snapshotted'    => $cmaMetrics,
            'source_reports'             => array_values(array_unique($cfg['source_reports'] ?? [])),
            'scope_used'                 => $cfg['scope'],
            'radius_m'                   => $cfg['radius_m'],
            'period_months'              => $cfg['period_months'],
        ];
    }

    // ── Config resolution ───────────────────────────────────────────────────

    /**
     * @return array{
     *   scope: string, radius_m: int, period_months: int,
     *   suburb_norm: string, suburb_like: string,
     *   subject_lat: ?float, subject_lng: ?float,
     *   subject_addr_needle: ?string,
     *   title_type: ?string,
     *   date_from: string, date_to: string,
     *   source_reports: array<int>,
     * }
     */
    private function resolveConfig(Presentation $presentation): array
    {
        $agency = $presentation->agency_id ? Agency::find($presentation->agency_id) : null;

        $scope = $presentation->comp_scope
            ?? $agency?->presentations_default_comp_scope
            ?? 'radius_all';
        $radius = (int) ($presentation->comp_radius_m
            ?? $agency?->presentations_default_radius_m
            ?? 1000);
        $period = (int) ($agency?->presentations_default_period_months ?? 12);

        $property = $presentation->property_id ? Property::find($presentation->property_id) : null;
        $lat = $property?->latitude !== null && $property?->latitude !== '' ? (float) $property->latitude : null;
        $lng = $property?->longitude !== null && $property?->longitude !== '' ? (float) $property->longitude : null;

        $suburb = (string) ($presentation->suburb ?? '');
        $suburbLike = '%' . mb_strtolower(trim($suburb)) . '%';
        $suburbNorm = mb_strtolower(trim($suburb));

        // Subject-address matching: presentation addresses can be verbose
        // ("4 Ss Madeira Gardens, 4 Tucker Avenue") while MIC subject
        // addresses tend to be the street-only fragment ("4 TUCKER AVENUE").
        // Extract street-shaped fragments from both sides and find market
        // reports whose subject_address contains ANY of the fragments OR
        // whose subject suburb contains the presentation suburb.
        $subjectAddr = (string) ($presentation->property_address ?? '');
        $needles = $this->extractAddressNeedles($subjectAddr);

        $dateFrom = Carbon::today()->subMonths($period)->toDateString();
        $dateTo   = Carbon::today()->toDateString();

        $subjectReportIds = [];
        $reportsQuery = MarketReport::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $presentation->agency_id);

        if (!empty($needles) || $suburb !== '') {
            $subjectReportIds = $reportsQuery
                ->where(function ($q) use ($needles, $suburb) {
                    foreach ($needles as $n) {
                        $q->orWhereRaw('LOWER(subject_address) LIKE ?', ['%' . $n . '%']);
                    }
                    if ($suburb !== '') {
                        // Match by subject suburb OR by the suburb appearing inside subject_address.
                        $q->orWhereRaw('LOWER(source_suburb) = ?', [mb_strtolower($suburb)]);
                        $q->orWhereRaw('LOWER(subject_address) LIKE ?', ['%' . mb_strtolower($suburb) . '%']);
                    }
                })
                ->pluck('id')
                ->all();
        }

        // Build 1 — title_type discipline. The subject's category (the name
        // stored on the property record) maps to a PropertySettingItem row
        // whose title_type drives comp-selection. Houses (full_title) must
        // not compare against apartments (sectional_title) or vacant land.
        // Spec: .ai/specs/presentation-data-lineage.md §3-A.
        // Hotfix — original Build 1 line referenced an undefined $agencyId
        // variable (only $agency object exists in this scope). Pull the int
        // from the presentation, which is the multi-tenancy anchor anyway.
        $titleType = $this->resolveSubjectTitleType($presentation, (int) $presentation->agency_id);

        return [
            'scope'                => $scope,
            'radius_m'             => $radius,
            'period_months'        => $period,
            'suburb_norm'          => $suburbNorm,
            'suburb_like'          => $suburbLike,
            'subject_lat'          => $lat,
            'subject_lng'          => $lng,
            // Build 1 — replaced the dead property_type_kind that was
            // computed but never reached the comp query. title_type is the
            // real discipline drive.
            'title_type'           => $titleType,
            'date_from'            => $dateFrom,
            'date_to'              => $dateTo,
            'source_reports'       => $subjectReportIds,
        ];
    }

    /**
     * Resolve the subject's title_type from its PropertySettingItem
     * (matched by agency + group=category + name = property.category).
     * Returns null when:
     *   - the property has no `category` value;
     *   - or the category name doesn't match any agency setting;
     *   - or the matching row exists but title_type is null.
     * A null return means "skip the comp filter and log a warning" — the
     * comp query is left wide open rather than emitting an empty set.
     */
    private function resolveSubjectTitleType(Presentation $presentation, int $agencyId): ?string
    {
        $categoryName = $presentation->property?->category ?? null;
        if (!is_string($categoryName) || trim($categoryName) === '') {
            \Illuminate\Support\Facades\Log::info('[PRES-WARN] subject category missing — comp title_type filter SKIPPED', [
                'presentation_id' => $presentation->id,
                'property_id'     => $presentation->property?->id,
            ]);
            return null;
        }

        $row = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('group', PropertySettingItem::GROUP_CATEGORY)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($categoryName))])
            ->first(['id', 'name', 'title_type']);

        if (!$row) {
            // Fall back to the system defaults (no agency_id) — covers
            // agencies that haven't customised the category list yet.
            $row = PropertySettingItem::withoutGlobalScopes()
                ->whereNull('agency_id')
                ->where('group', PropertySettingItem::GROUP_CATEGORY)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($categoryName))])
                ->first(['id', 'name', 'title_type']);
        }

        if (!$row || empty($row->title_type)) {
            \Illuminate\Support\Facades\Log::info('[PRES-WARN] subject category has no title_type — comp filter SKIPPED', [
                'presentation_id' => $presentation->id,
                'category'        => $categoryName,
            ]);
            return null;
        }

        return $row->title_type;
    }

    /**
     * Classify a comp's free-text property_type into a title_type bucket.
     * Used by the comp-selection filter — comps whose classified
     * title_type doesn't match the subject's are dropped.
     *
     * Sectional title language: sectional, apartment, flat, unit,
     *   townhouse, duplex.
     * Vacant land: vacant_land, plot, stand, erf, vacant.
     * Everything else: full_title.
     */
    private function classifyCompTitleType(?string $compType): string
    {
        $t = strtolower((string) $compType);
        if ($t === '') return PropertySettingItem::TITLE_OTHER;
        if (str_contains($t, 'sectional') || str_contains($t, 'apartment') || str_contains($t, 'flat')
            || str_contains($t, 'unit') || str_contains($t, 'townhouse') || str_contains($t, 'duplex')) {
            return PropertySettingItem::TITLE_SECTIONAL;
        }
        if (str_contains($t, 'vacant') || str_contains($t, 'plot') || str_contains($t, 'stand')
            || str_contains($t, 'erf')) {
            return PropertySettingItem::TITLE_VACANT;
        }
        return PropertySettingItem::TITLE_FULL;
    }

    /**
     * Extract street-shaped fragments from an address.
     *
     * "4 Ss Madeira Gardens, 4 Tucker Avenue" →
     *   ["4 ss madeira gardens", "4 tucker avenue", "madeira gardens", "tucker avenue"]
     *
     * We strip down to lowercased street fragments and drop anything shorter
     * than 8 chars so we don't match noise.
     */
    private function extractAddressNeedles(string $address): array
    {
        $address = trim($address);
        if ($address === '') return [];

        $needles = [];

        // Split on commas → each comma-separated piece is a candidate fragment.
        foreach (explode(',', $address) as $piece) {
            $piece = mb_strtolower(trim($piece));
            if (mb_strlen($piece) >= 8) {
                $needles[] = $piece;
            }
            // Strip the leading number (e.g. "4 Tucker Avenue" → "tucker avenue").
            $stripped = preg_replace('/^\d+\s+/', '', $piece);
            if ($stripped && $stripped !== $piece && mb_strlen($stripped) >= 8) {
                $needles[] = $stripped;
            }
        }

        return array_values(array_unique($needles));
    }

    // Build 1 — classifyType(string) RETIRED. It returned 'sectional' /
    // 'full' / 'unknown' but was never consumed downstream (the only
    // caller stored the result in $cfg['property_type_kind'] which never
    // reached the comp WHERE clause). classifyCompTitleType() above is
    // the replacement and IS consumed by the row filter — verified by
    // tests M81–M84.

    // ── Row collection + filtering ──────────────────────────────────────────

    /**
     * Gather candidate market_report_comp_rows for the presentation:
     *   - rows from any market_report whose subject matches the presentation address (same-subject branch)
     *   - rows whose suburb_normalised matches the presentation suburb
     *   - rows within Haversine distance when both sides have geo (radius scope only)
     * Filtered to row_type, sale_date window, sale_price NOT NULL (for sold),
     * list_price NOT NULL (for listings).
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function collectMatchedRows(Presentation $presentation, array $cfg, string $rowType): \Illuminate\Support\Collection
    {
        // Phase 3h Step 9 — demo/real isolation. Read the subject property's
        // is_demo flag once; comp rows must match.
        $subjectIsDemo = (bool) ($presentation->property?->is_demo ?? false);

        $query = DB::table('market_report_comp_rows')
            ->whereNull('deleted_at')
            ->where('row_type', $rowType)
            ->where('is_demo', $subjectIsDemo);

        if ($rowType === MarketReportCompRow::ROW_COMP) {
            // Same-subject reports skip the strict date window — the analyst
            // who built the report already vetted these as relevant to the
            // subject, so freshness is a weaker signal than relevance. For
            // pure suburb / Haversine matches the date window still applies.
            $subjectReportIds = $cfg['source_reports'];
            $query->whereNotNull('sale_date')->whereNotNull('sale_price');
            $query->where(function ($q) use ($subjectReportIds, $cfg) {
                if (!empty($subjectReportIds)) {
                    $q->whereIn('market_report_id', $subjectReportIds);
                }
                $q->orWhere(function ($q2) use ($cfg) {
                    $q2->whereBetween('sale_date', [$cfg['date_from'], $cfg['date_to']]);
                });
            });
        } else { // listings
            $query->whereNotNull('list_price');
        }

        $rows = $query->select([
            'id', 'market_report_id', 'row_index',
            'scheme_name', 'section_number', 'flat_number', 'ss_number', 'ss_year',
            'address', 'suburb_normalised', 'property_type', 'extent_m2',
            'sale_date', 'sale_price', 'list_price', 'days_on_market',
            'distance_to_subject_m', 'latitude', 'longitude',
            'raw_row_json',
        ])->get();

        $subjectReportIds = $cfg['source_reports'];
        $suburbNorm       = $cfg['suburb_norm'];
        $lat              = $cfg['subject_lat'];
        $lng              = $cfg['subject_lng'];
        $radius           = max(1, $cfg['radius_m']);
        $scope            = $cfg['scope'];

        $titleType = $cfg['title_type'] ?? null;

        return $rows->filter(function ($row) use ($subjectReportIds, $suburbNorm, $lat, $lng, $radius, $scope, $titleType) {
            // Build 1 — title_type discipline. When the subject's category
            // resolves to a title_type, drop comps whose property_type
            // classifies into a different title_type. A null title_type
            // (subject category missing or unconfigured) skips the filter
            // — already logged upstream as [PRES-WARN]. Same-subject reports
            // are exempt because they were already vetted by the analyst.
            if ($titleType !== null) {
                $subjectReportHit = !empty($subjectReportIds)
                    && in_array((int) $row->market_report_id, $subjectReportIds, true);
                if (!$subjectReportHit) {
                    $compTitleType = $this->classifyCompTitleType($row->property_type ?? null);
                    if ($compTitleType !== $titleType) {
                        return false;
                    }
                }
            }

            // Branch 1: same-subject — every comp from a market report that
            // analysed this exact property is in scope by definition.
            if (!empty($subjectReportIds) && in_array((int) $row->market_report_id, $subjectReportIds, true)) {
                return true;
            }

            // Branch 2: suburb match (when row has a suburb).
            if ($suburbNorm !== '' && !empty($row->suburb_normalised)
                && str_contains(mb_strtolower((string) $row->suburb_normalised), $suburbNorm)) {
                return true;
            }

            // Branch 3: Haversine — only meaningful when scope is radius_all AND both sides have geo.
            if ($scope === 'radius_all'
                && $lat !== null && $lng !== null
                && $row->latitude !== null && $row->longitude !== null) {
                $d = HaversineDistance::distanceMetres($lat, $lng, (float) $row->latitude, (float) $row->longitude);
                if ($d <= $radius) return true;
            }

            return false;
        })->values();
    }

    /**
     * Deduplicate rows by fingerprint. Within a fingerprint, prefer the
     * row from the most-recent market_report (highest id).
     *
     * @param \Illuminate\Support\Collection<int, object> $rows
     * @return array<int, object>
     */
    private function deduplicate(\Illuminate\Support\Collection $rows, bool $isSold): array
    {
        $byFingerprint = [];
        foreach ($rows as $row) {
            $fp = $this->fingerprint($row, $isSold);
            if (!isset($byFingerprint[$fp])) {
                $byFingerprint[$fp] = $row;
                continue;
            }
            // Prefer the row from the higher market_report_id (more recent).
            if ((int) $row->market_report_id > (int) $byFingerprint[$fp]->market_report_id) {
                $byFingerprint[$fp] = $row;
            }
        }
        return array_values($byFingerprint);
    }

    private function fingerprint(object $row, bool $isSold): string
    {
        $scheme = trim((string) ($row->scheme_name ?? ''));
        $section = trim((string) ($row->section_number ?? ''));
        $addr   = trim((string) ($row->address ?? ''));
        $date   = (string) ($isSold ? ($row->sale_date ?? '') : '');
        $price  = (int) ($isSold ? ($row->sale_price ?? 0) : ($row->list_price ?? 0));

        if ($scheme !== '' && $section !== '') {
            return 'S|' . strtoupper($scheme) . '|' . $section . '|' . $date . '|' . $price;
        }
        return 'A|' . mb_strtolower($addr) . '|' . $date . '|' . $price;
    }

    private function resolveSuburb(object $row, Presentation $presentation): ?string
    {
        if (!empty($row->suburb_normalised)) return $row->suburb_normalised;
        return $presentation->suburb ?: null;
    }

    private function encodeRaw(object $row, array $sourceReportIds, Presentation $presentation): string
    {
        $payload = [
            'source'              => 'mic_snapshot',
            'source_report_id'    => (int) $row->market_report_id,
            'mic_comp_row_id'     => (int) $row->id,
            'address'             => $row->address,
            'scheme_name'         => $row->scheme_name,
            'section_number'      => $row->section_number,
            'distance_m'          => $row->distance_to_subject_m,
            'extent_m2'           => $row->extent_m2,
            'sale_date'           => $row->sale_date,
            'sale_price'          => $row->sale_price,
            'list_price'          => $row->list_price,
            'days_on_market'      => $row->days_on_market,
            'price_per_m2'        => ($row->extent_m2 && $row->sale_price)
                                    ? (int) round($row->sale_price / $row->extent_m2)
                                    : (($row->extent_m2 && $row->list_price)
                                        ? (int) round($row->list_price / $row->extent_m2)
                                        : null),
            'subject_match_used'  => in_array((int) $row->market_report_id, $sourceReportIds, true),
        ];
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    // ── Suburb metrics ─────────────────────────────────────────────────────

    /**
     * Pull suburb-level annual median/sales-count metrics from
     * market_data_points (the MIC warehouse) and write them into
     * presentation_fields with the keys AnalysisDataService expects:
     *   suburb.latest_year, suburb.latest_sales_count, suburb.latest_median_price.
     * The low/high/max keys are best-effort (CMA Info Median Sales Analysis
     * doesn't carry them per year — we leave them null when not derivable
     * so AnalysisDataService renders dashes for those columns).
     */
    private function hydrateSuburbMetrics(Presentation $presentation): int
    {
        $suburb = mb_strtolower(trim((string) ($presentation->suburb ?? '')));
        if ($suburb === '') return 0;

        // Find the most recent year of data for this suburb.
        $latest = DB::table('market_data_points')
            ->whereNull('deleted_at')
            ->where('metric_key', 'suburb_median_price_year')
            ->where(function ($q) use ($suburb) {
                $q->whereRaw('LOWER(suburb_normalised) = ?', [$suburb])
                  ->orWhereRaw('LOWER(suburb_normalised) LIKE ?', ['%' . $suburb . '%']);
            })
            ->orderByDesc('metric_date')
            ->first(['metric_value_numeric', 'metric_date']);

        // Fall back to the 12-month median if no per-year series.
        if (!$latest) {
            $latest = DB::table('market_data_points')
                ->whereNull('deleted_at')
                ->where('metric_key', 'suburb_median_price_12m')
                ->whereRaw('LOWER(suburb_normalised) = ?', [$suburb])
                ->orderByDesc('metric_date')
                ->first(['metric_value_numeric', 'metric_date']);
        }

        if (!$latest) return 0;

        $year = $latest->metric_date ? Carbon::parse($latest->metric_date)->year : (int) date('Y');

        // Companion: sales count for the same year.
        $countRow = DB::table('market_data_points')
            ->whereNull('deleted_at')
            ->where('metric_key', 'suburb_sales_count_year')
            ->whereRaw('LOWER(suburb_normalised) = ?', [$suburb])
            ->whereYear('metric_date', $year)
            ->orderByDesc('metric_date')
            ->first(['metric_value_numeric']);

        $countFallback = DB::table('market_data_points')
            ->whereNull('deleted_at')
            ->where('metric_key', 'suburb_total_sales_12m')
            ->whereRaw('LOWER(suburb_normalised) = ?', [$suburb])
            ->orderByDesc('metric_date')
            ->first(['metric_value_numeric']);

        $writes = [
            'suburb.latest_year'         => (string) $year,
            'suburb.latest_median_price' => (string) (int) $latest->metric_value_numeric,
            'suburb.latest_sales_count'  => $countRow
                ? (string) (int) $countRow->metric_value_numeric
                : ($countFallback ? (string) (int) $countFallback->metric_value_numeric : null),
        ];

        // Phase 3e A3 — low/high/max from the per-year Residential Price
        // Ranges table (MSA parser writes suburb_low_year / suburb_high_year
        // / suburb_max_year keys). Pull the same year as the median above so
        // all four columns line up.
        $rangeKeys = [
            'suburb_low_year'  => 'suburb.latest_low',
            'suburb_high_year' => 'suburb.latest_high',
            'suburb_max_year'  => 'suburb.latest_max',
        ];
        foreach ($rangeKeys as $srcKey => $dstKey) {
            $rangeRow = DB::table('market_data_points')
                ->whereNull('deleted_at')
                ->where('metric_key', $srcKey)
                ->whereRaw('LOWER(suburb_normalised) = ?', [$suburb])
                ->whereYear('metric_date', $year)
                ->orderByDesc('metric_date')
                ->first(['metric_value_numeric']);
            if ($rangeRow) {
                $writes[$dstKey] = (string) (int) $rangeRow->metric_value_numeric;
            }
        }

        return $this->upsertFields($presentation->id, $writes);
    }

    // ── CMA valuation ───────────────────────────────────────────────────────

    private function hydrateCmaMetrics(Presentation $presentation): int
    {
        $suburb = mb_strtolower(trim((string) ($presentation->suburb ?? '')));
        if ($suburb === '') return 0;

        // Prefer the most recent Property Valuation report whose subject matches
        // the presentation address.
        $subjectAddr = mb_strtolower(trim((string) $presentation->property_address));
        $sourceReport = null;
        if ($subjectAddr !== '') {
            $sourceReport = DB::table('market_reports')
                ->whereNull('deleted_at')
                ->where('agency_id', $presentation->agency_id)
                ->whereRaw('LOWER(subject_address) LIKE ?', ['%' . $subjectAddr . '%'])
                ->orderByDesc('id')
                ->first(['id']);
        }

        $base = DB::table('market_data_points')
            ->whereNull('deleted_at')
            ->whereIn('metric_key', ['cma_value_lower', 'cma_value_middle', 'cma_value_upper']);

        if ($sourceReport) {
            $rows = (clone $base)->where('report_id', $sourceReport->id)
                ->orderByDesc('id')
                ->get(['metric_key', 'metric_value_numeric']);
        } else {
            $rows = $base
                ->whereRaw('LOWER(suburb_normalised) = ?', [$suburb])
                ->orderByDesc('metric_date')
                ->get(['metric_key', 'metric_value_numeric']);
        }

        if ($rows->isEmpty()) return 0;

        // Prefer the most-recent value per key.
        $byKey = [];
        foreach ($rows as $r) {
            if (!isset($byKey[$r->metric_key])) {
                $byKey[$r->metric_key] = (int) $r->metric_value_numeric;
            }
        }

        $writes = array_filter([
            'cma.lower_range'  => isset($byKey['cma_value_lower'])  ? (string) $byKey['cma_value_lower']  : null,
            'cma.middle_range' => isset($byKey['cma_value_middle']) ? (string) $byKey['cma_value_middle'] : null,
            'cma.upper_range'  => isset($byKey['cma_value_upper'])  ? (string) $byKey['cma_value_upper']  : null,
        ], fn ($v) => $v !== null);

        return $this->upsertFields($presentation->id, $writes);
    }

    /**
     * Upsert (presentation_id, field_key). Honours agent overrides — if a
     * non-empty override_value exists for the same key, we leave final_value
     * = override_value and only refresh extracted_value.
     *
     * @param array<string, ?string> $writes  key => value (null skipped)
     */
    private function upsertFields(int $presentationId, array $writes): int
    {
        $written = 0;
        foreach ($writes as $key => $value) {
            if ($value === null || $value === '') continue;
            $existing = PresentationField::where('presentation_id', $presentationId)
                ->where('field_key', $key)
                ->first();
            if ($existing) {
                $existing->update([
                    'extracted_value' => $value,
                    'confidence'      => 0.85,
                    'final_value'     => $existing->override_value ?: $value,
                ]);
            } else {
                PresentationField::create([
                    'presentation_id' => $presentationId,
                    'field_key'       => $key,
                    'extracted_value' => $value,
                    'override_value'  => null,
                    'final_value'     => $value,
                    'confidence'      => 0.85,
                ]);
            }
            $written++;
        }
        return $written;
    }
}
