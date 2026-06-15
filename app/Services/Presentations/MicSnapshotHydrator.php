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
use App\Support\Presentations\CompFingerprint;
use App\Support\Presentations\SuburbMatcher;
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
    public const SOURCE_TAG       = 'mic_snapshot_v1';
    public const SOURCE_TAG_DEAL  = 'deal_register_v1';

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
     *   n_deals_added: int,
     *   n_deals_dedup_skipped: int,
     * }
     */
    public function hydrateForPresentation(Presentation $presentation): array
    {
        $cfg = $this->resolveConfig($presentation);

        // Wipe previous MIC + deal-source rows for this presentation.
        // Manual evidence (any other parser_version) stays untouched.
        PresentationSoldComp::where('presentation_id', $presentation->id)
            ->whereIn('parser_version', [self::SOURCE_TAG, self::SOURCE_TAG_DEAL])
            ->delete();
        PresentationActiveListing::where('presentation_id', $presentation->id)
            ->where('parser_version', self::SOURCE_TAG)
            ->delete();

        // ── Sold comps ─────────────────────────────────────────────────────
        // AT-22 §1 — collect candidates out to the radius ceiling so the
        // CompPoolBuilder widen-if-thin ladder has room to work, then gate
        // (type → price band → radius ladder → divergence → rank) before
        // persisting. The prior path persisted every suburb match at a
        // 1000m radius with no price/erf gate, pulling sub-R1M tiny-erf
        // sales. Spec .ai/specs/at22-presentation-quality.md §1 / §1.5.
        $poolConfig = CompPoolBuilder::configForAgency($presentation->agency);
        $compCfg = array_merge($cfg, [
            'radius_m' => max((int) $cfg['radius_m'], (int) $poolConfig['radius_max_m']),
        ]);
        $compRows = $this->collectMatchedRows(
            $presentation, $compCfg, MarketReportCompRow::ROW_COMP,
        );
        $deduped = $this->deduplicate($compRows, isSold: true);

        $candidates = [];
        foreach ($deduped as $i => $row) {
            $salePrice = OutlierGuard::price($row->sale_price);
            if ($salePrice === null) {
                continue; // guard couldn't validate — would corrupt averages
            }
            // Exempt = analyst-vetted same-subject report OR trusted-internal
            // deal: exempt from the price gate, never shortlist-dropped (§1).
            $exempt = !empty($cfg['source_reports'])
                && in_array((int) $row->market_report_id, $cfg['source_reports'], true);
            if (!$exempt && !empty($row->raw_row_json) && is_string($row->raw_row_json)) {
                $decoded = json_decode($row->raw_row_json, true);
                $exempt = is_array($decoded) && !empty($decoded['trusted_internal_source']);
            }
            $candidates[] = [
                'key'           => $i,
                'price'         => $salePrice,
                'size_m2'       => OutlierGuard::extentM2($row->extent_m2),
                'property_type' => $row->property_type,
                // AT-22 item 6 — derive the title category from the sectional
                // signal (scheme_name / section_number) so the type hard-gate
                // can tell sectional units from freehold even when the source
                // property_type is the generic "Residence"/"Residential".
                'title_type'    => $this->deriveCompTitleType($row),
                'lat'           => $row->latitude,
                'lng'           => $row->longitude,
                // PRES-CMA-FIX — address feeds CompPoolBuilder's subject
                // self-exclusion guard (a comp at the subject's own address
                // is the subject, never its own comparable).
                'address'       => $row->address ?? null,
                'exempt'        => $exempt,
                'row'           => $row,
            ];
        }

        $isSectional   = ($cfg['title_type'] === \App\Services\TitleTypeClassifier::TITLE_SECTIONAL);
        $subjectProfile = [
            'title_type'    => $cfg['title_type'],
            'property_type' => $presentation->property?->property_type,
            'lat'           => $cfg['subject_lat'],
            'lng'           => $cfg['subject_lng'],
            'erf_m2'        => $isSectional
                ? ($presentation->property?->size_m2 ?? $presentation->floor_area_m2)
                : ($presentation->property?->erf_size_m2
                    ?? $presentation->erf_size_m2
                    ?? $presentation->property?->size_m2),
            // AT-22 §1.5 — subject-derived anchor for the PRICE band gate so a
            // polluted pool can't drag the band down (the R927k/R1.1M trap).
            // The displayed CMA range still derives from the cleaned pool's
            // P25–P75 (CmaComputeService); this only governs which comps the
            // gate admits. Asking is the expected-value input the agent gave.
            'anchor_price'  => $this->resolveSubjectAnchorPrice($presentation),
            // PRES-CMA-FIX — subject address for the self-exclusion guard.
            'address'       => $presentation->property_address ?? $presentation->property?->property_address ?? null,
        ];

        $poolResult  = (new CompPoolBuilder())->select($subjectProfile, $candidates, $poolConfig);
        $radiusUsed  = $poolResult['radius_used'];
        $poolAnchor  = $poolResult['anchor'];

        \Illuminate\Support\Facades\Log::info('[AT-22] comp pool gated', [
            'presentation_id' => $presentation->id,
            'radius_used_m'   => $radiusUsed,
            'widened'         => $poolResult['widened'],
            'anchor'          => $poolAnchor,
            'diagnostics'     => $poolResult['diagnostics'],
        ]);

        $soldInserted = 0;
        foreach ($poolResult['selected'] as $cand) {
            $row       = $cand['row'];
            $salePrice = OutlierGuard::price($row->sale_price);
            $sizeM2    = OutlierGuard::extentM2($row->extent_m2);
            if ($salePrice === null) {
                continue;
            }
            try {
                PresentationSoldComp::create([
                    'agency_id'       => $presentation->agency_id,
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

        // ── Deal-register comps (Build 8d) ────────────────────────────────
        //
        // Feed registered deals (HFC's own closed transactions) into the
        // engine pool with EQUAL WEIGHT to CMA-sourced comps. CMA-wins
        // precedence on dedup: a deal whose source-agnostic fingerprint
        // matches a just-materialised MIC row is dropped. A deal NOT in
        // CMA data is a full equal comp.
        //
        // Selection criteria mirror CmaCoverageService::countComps's deals
        // query exactly so the badge count and engine pool agree.
        [$dealsAdded, $dealsDeduped] = $this->collectAndInsertDealComps($presentation, $cfg);

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
            'n_deals_added'              => $dealsAdded,
            'n_deals_dedup_skipped'      => $dealsDeduped,
        ];
    }

    // ── Deal-register comps (Build 8d) ──────────────────────────────────

    /**
     * Query the deals table for closed transactions in the subject
     * suburb + date window, then materialise each one into
     * presentation_sold_comps unless a just-materialised MIC row already
     * carries the same source-agnostic fingerprint (CMA-wins precedence).
     *
     * Selection criteria mirror CmaCoverageService::countComps L147-163
     * 1:1 (registration_date NOT NULL, accepted_status != 'D', is_demo
     * matches subject, registration_date in date window, suburb match
     * with unlinked-address fallback). Adds agency_id filter.
     *
     * Deal rows widen the select to pull joined property attributes:
     * suburb, property_type, size_m2, erf_size_m2, title_type.
     *
     * Trust posture: raw_row_json carries `trusted_internal_source: true`
     * so any title_type filter downstream exempts these the same way it
     * exempts analyst-vetted same-subject CMA comps (collectMatchedRows
     * subjectReportHit path).
     *
     * @return array{0:int, 1:int}  [deals_added, deals_dedup_skipped]
     */
    private function collectAndInsertDealComps(Presentation $presentation, array $cfg): array
    {
        $agencyId = (int) $presentation->agency_id;
        $suburb   = (string) ($presentation->suburb ?? '');
        if ($agencyId <= 0 || trim($suburb) === '') {
            return [0, 0];
        }

        $subjectIsDemo = (bool) ($presentation->property?->is_demo ?? false);
        // PRES-CMA-FIX — the subject's own closed deal must not enter its
        // comparable-sales pool. property_id is the exact, zero-false-positive
        // subject match (same Property pillar record).
        $subjectPropertyId = $presentation->property_id !== null ? (int) $presentation->property_id : null;
        $suburbNorm    = mb_strtolower(trim($suburb));
        // SuburbMatcher core token — strips "Beach"/"Bay"/etc so SQL pre-
        // filter matches "uvongo" comps when subject is "Uvongo Beach".
        // PHP-side SuburbMatcher::matches() narrows the SQL set to actual
        // locality matches, keeping the helper as the single source of
        // truth for matching semantics.
        $subjectCore   = SuburbMatcher::normaliseSuburbToken($suburb);
        $coreLike      = $subjectCore !== '' ? '%' . $subjectCore . '%' : '%';

        // Pre-load fingerprints for the just-materialised MIC rows on
        // this presentation. CMA precedence: a deal that matches one of
        // these is skipped.
        $micFingerprints = [];
        $existing = PresentationSoldComp::where('presentation_id', $presentation->id)
            ->where('parser_version', self::SOURCE_TAG)
            ->get(['sold_date', 'sold_price_inc', 'raw_row_json']);
        foreach ($existing as $row) {
            $decoded = is_string($row->raw_row_json) ? json_decode($row->raw_row_json, true) : [];
            $address = is_array($decoded) ? ($decoded['address'] ?? null) : null;
            $scheme  = is_array($decoded) ? ($decoded['scheme_name'] ?? null) : null;
            $section = is_array($decoded) ? ($decoded['section_number'] ?? null) : null;
            $dateStr = $row->sold_date instanceof \DateTimeInterface
                ? $row->sold_date->format('Y-m-d')
                : (string) $row->sold_date;
            $key = CompFingerprint::sourceAgnosticKey(
                address: $address !== null ? (string) $address : null,
                schemeName: $scheme !== null ? (string) $scheme : null,
                sectionNumber: $section !== null ? (string) $section : null,
                saleDate: $dateStr,
                salePrice: (int) $row->sold_price_inc,
            );
            $micFingerprints[$key] = true;
        }

        $dealRows = DB::table('deals')
            ->leftJoin('properties', 'properties.id', '=', 'deals.property_id')
            ->where('deals.agency_id', $agencyId)
            ->whereNotNull('deals.registration_date')
            ->where(function ($q) {
                $q->whereNull('deals.accepted_status')->orWhere('deals.accepted_status', '!=', 'D');
            })
            ->where('deals.is_demo', $subjectIsDemo)
            ->whereBetween('deals.registration_date', [$cfg['date_from'], $cfg['date_to']])
            ->where(function ($q) use ($coreLike) {
                $q->whereRaw('LOWER(properties.suburb) LIKE ?', [$coreLike])
                  ->orWhere(function ($qq) use ($coreLike) {
                      $qq->whereNull('deals.property_id')
                         ->whereRaw('LOWER(deals.property_address) LIKE ?', [$coreLike]);
                  });
            })
            ->select([
                'deals.id              as deal_id',
                'deals.property_id     as deal_property_id',
                'deals.property_address',
                'deals.registration_date',
                'deals.sale_date',
                'deals.sale_price',
                'deals.property_value',
                'deals.is_demo         as deal_is_demo',
                'properties.suburb     as prop_suburb',
                'properties.property_type as prop_property_type',
                'properties.size_m2    as prop_size_m2',
                'properties.erf_size_m2 as prop_erf_size_m2',
                'properties.title_type as prop_title_type',
                'properties.latitude   as prop_lat',
                'properties.longitude  as prop_lng',
            ])
            ->get();

        $added = 0;
        $skipped = 0;
        foreach ($dealRows as $r) {
            // SuburbMatcher narrow: SQL pre-filter used the core token
            // (permissive LIKE), now confirm full match semantics. Linked
            // deals: compare joined property suburb against subject.
            // Unlinked deals (no property_id): the SQL LIKE on
            // deal.property_address already required the core token to
            // appear in the free-text address — accept those as-is.
            if (!empty($r->prop_suburb)
                && !SuburbMatcher::matches($r->prop_suburb, $suburb)) {
                continue;
            }

            // PRES-CMA-FIX — skip the subject's own deal (same Property
            // pillar record); it can never be its own comparable.
            if ($subjectPropertyId !== null
                && $r->deal_property_id !== null
                && (int) $r->deal_property_id === $subjectPropertyId) {
                $skipped++;
                continue;
            }

            // Price: prefer canonical bigint sale_price, fall back to
            // property_value (legacy decimal mirror). OutlierGuard for
            // floor/ceiling sanity — same gate as MIC comps.
            $rawPrice = $r->sale_price ?? $r->property_value;
            $salePrice = OutlierGuard::price($rawPrice);
            if ($salePrice === null) {
                continue; // out-of-band — drop quietly, same posture as MIC
            }

            $saleDate = (string) ($r->registration_date ?? $r->sale_date ?? '');

            $fingerprint = CompFingerprint::sourceAgnosticKey(
                address: (string) ($r->property_address ?? ''),
                schemeName: null,
                sectionNumber: null,
                saleDate: $saleDate,
                salePrice: $salePrice,
            );
            if (isset($micFingerprints[$fingerprint])) {
                $skipped++;
                continue; // CMA-wins precedence
            }

            $titleType   = $r->prop_title_type !== null && $r->prop_title_type !== ''
                ? (string) $r->prop_title_type
                : null;
            $isSectional = $titleType === \App\Services\TitleTypeClassifier::TITLE_SECTIONAL;

            $sizeM2 = $isSectional
                ? OutlierGuard::extentM2($r->prop_size_m2)
                : OutlierGuard::extentM2($r->prop_erf_size_m2);

            $suburbValue = $r->prop_suburb
                ?: $this->resolveSuburb((object) [
                    'suburb_normalised' => null,
                ], $presentation);

            $raw = [
                'source'                  => 'deal_register',
                'deal_id'                 => (int) $r->deal_id,
                'address'                 => (string) ($r->property_address ?? ''),
                'suburb'                  => (string) ($suburbValue ?? ''),
                'sale_date'               => $saleDate,
                'sale_price'              => $salePrice,
                'source_property_id'      => $r->deal_property_id !== null ? (int) $r->deal_property_id : null,
                'property_type'           => $r->prop_property_type !== null ? (string) $r->prop_property_type : null,
                'size_m2'                 => $sizeM2,
                'title_type'              => $titleType,
                'latitude'                => $r->prop_lat,
                'longitude'               => $r->prop_lng,
                'trusted_internal_source' => true,
                'subject_match_used'      => false,
            ];

            try {
                PresentationSoldComp::create([
                    'agency_id'       => $agencyId,
                    'presentation_id' => $presentation->id,
                    'sold_date'       => $saleDate,
                    'sold_price_inc'  => $salePrice,
                    'suburb'          => $suburbValue,
                    'property_type'   => $r->prop_property_type,
                    'size_m2'         => $sizeM2,
                    'raw_row_json'    => json_encode($raw, JSON_THROW_ON_ERROR),
                    'parser_version'  => self::SOURCE_TAG_DEAL,
                ]);
                $added++;
            } catch (\Throwable) {
                // Skip; don't break the rest.
            }
        }

        return [$added, $skipped];
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

        // Keystone — title_type now lives on properties.title_type,
        // derived from property_type by TitleTypeClassifier on every save.
        // Read the column first; fall back to the classifier (which
        // re-derives + tries category) only when the column is NULL,
        // covering rows pre-dating the backfill. Spec:
        // .ai/specs/presentation-data-lineage.md §3-A.
        $titleType = $presentation->property?->title_type
            ?? ($presentation->property
                ? app(\App\Services\TitleTypeClassifier::class)->forProperty($presentation->property)
                : null);

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

    // Keystone — resolveSubjectTitleType + classifyCompTitleType were
    // duplicate bodies (mirror of PresentationReviewController's pair).
    // Their logic now lives in App\Services\TitleTypeClassifier. Subject
    // classification reads properties.title_type directly above; comp
    // classification calls the service inline at the filter site.

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

                // Build 8d — trusted-internal-source exemption. Deal-
                // sourced rows (HFC's own registered transactions) get
                // the same trust posture as analyst-vetted same-subject
                // comps, so they aren't dropped on NULL property_type.
                // Today deals bypass this filter entirely (they skip
                // collectMatchedRows and write directly to
                // PresentationSoldComp); the exemption is defensive
                // against future refactors that unify the filter over
                // a merged pool.
                $trustedInternal = false;
                if (isset($row->raw_row_json) && is_string($row->raw_row_json) && $row->raw_row_json !== '') {
                    $decoded = json_decode($row->raw_row_json, true);
                    $trustedInternal = is_array($decoded) && !empty($decoded['trusted_internal_source']);
                }

                if (!$subjectReportHit && !$trustedInternal) {
                    // Preserve Build 1's strict-drop semantic: a comp
                    // with no usable property_type was classified as
                    // TITLE_OTHER and dropped (OTHER never matches the
                    // subject's actual type). The new service returns
                    // null on blank to keep forProperty's fallback chain
                    // clean — coerce at the call site.
                    $compTitleType = app(\App\Services\TitleTypeClassifier::class)
                            ->fromPropertyType($row->property_type ?? null)
                        ?? \App\Services\TitleTypeClassifier::TITLE_OTHER;
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
            // Uses SuburbMatcher to bridge subject "Uvongo Beach" ⇄ comp
            // "uvongo" naming. Pre-helper the directional str_contains
            // dropped 59-of-59 in-window candidates on the local Uvongo
            // Beach probe.
            if ($suburbNorm !== '' && !empty($row->suburb_normalised)
                && SuburbMatcher::matches($row->suburb_normalised, $suburbNorm)) {
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

    /**
     * AT-22 item 6 — title category for a comp row. A populated scheme_name
     * or section_number is an unambiguous sectional-title signal (a unit in a
     * sectional scheme); it overrides the generic source property_type, which
     * for portal/MIC data is almost always the catch-all "Residence" /
     * "Residential" and cannot distinguish freehold from sectional. Without
     * this, sectional units classify as freehold and leak into a freehold
     * subject's type-gated pool (PRES 87: 57 sectional comps, 49 of them
     * sub-R1M, on a R2.9M full-title subject).
     */
    private function deriveCompTitleType(object $row): ?string
    {
        $scheme  = trim((string) ($row->scheme_name ?? ''));
        $section = trim((string) ($row->section_number ?? ''));
        if ($scheme !== '' || $section !== '') {
            return \App\Services\TitleTypeClassifier::TITLE_SECTIONAL;
        }
        // No sectional signal — defer to the property-type heuristic (may be
        // null/full when the source type is generic; the gate fails open on
        // null, which is the intended posture).
        return app(\App\Services\TitleTypeClassifier::class)->fromPropertyType($row->property_type ?? null);
    }

    /**
     * AT-22 §1.5 — the subject's expected value, used to anchor the comp
     * price-band gate. Asking price is the figure the agent entered for THIS
     * subject; it is the robust gate anchor (the displayed CMA range still
     * comes from the cleaned pool, never from asking — spec §5). Returns null
     * when no asking is set, in which case CompPoolBuilder falls back to the
     * cleaned-pool median.
     */
    private function resolveSubjectAnchorPrice(Presentation $presentation): ?int
    {
        $asking = (int) ($presentation->asking_price_inc ?? 0);
        return $asking > 0 ? $asking : null;
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
        // Eager geocode hook — when the upstream comp row has an
        // address but no GPS, resolve once via AddressResolverService
        // and persist back to market_report_comp_rows so future
        // presentations get the result for free. The 34% of comp rows
        // that lack GPS at parse time become plottable on the next
        // hydration without an explicit backfill pass.
        //
        // Silent on failure — the resolver's own cache prevents
        // hammering Google for permanently-unresolvable addresses
        // (cache-as-failed branch). The map's plotted/unplotted
        // caption surfaces the residual to the agent.
        $lat = $row->latitude  ?? null;
        $lng = $row->longitude ?? null;
        if (($lat === null || $lng === null) && !empty($row->address)) {
            try {
                $result = (new \App\Services\Geocoding\AddressResolverService())->resolve(
                    (string) $row->address,
                    null, // suburb already in $row->address typically
                    null,
                    context: 'mic_comp_row:' . (int) $row->id,
                );
                if ($result->hasGps()) {
                    $lat = $result->latitude;
                    $lng = $result->longitude;
                    // Persist back so the next hydration reads from
                    // the column directly. saveQuietly equivalent via
                    // raw update (no model in scope; cheap).
                    \Illuminate\Support\Facades\DB::table('market_report_comp_rows')
                        ->where('id', (int) $row->id)
                        ->update([
                            'latitude'  => $lat,
                            'longitude' => $lng,
                            'updated_at'=> now(),
                        ]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::debug('comp_row geocode failed', [
                    'comp_row_id' => $row->id ?? null,
                    'err'         => $e->getMessage(),
                ]);
            }
        }

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
            // CMA-map fix — expose GPS so the review-screen marker
            // placement (PresentationReviewController:117) reads real
            // values instead of always-null. Pre-fix the columns were
            // SELECTed but never encoded into the snapshot JSON, which
            // silently broke comp plotting on the review map.
            'latitude'            => $lat,
            'longitude'           => $lng,
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

        $pull = function (string $key, ?int $year = null) use ($suburb) {
            $q = DB::table('market_data_points')
                ->whereNull('deleted_at')
                ->where('metric_key', $key)
                ->whereRaw('LOWER(suburb_normalised) = ?', [$suburb]);
            if ($year !== null) {
                $q->whereYear('metric_date', $year);
            }
            return $q->orderByDesc('metric_date')->first(['metric_value_numeric', 'metric_date']);
        };

        // AT-22 R3 — robustness: anchor on the most recent YEAR that has ANY
        // suburb-level datapoint (median OR sales-count OR price ranges), not
        // just the median. The median is no longer mandatory — we surface
        // latest_year + whatever metrics exist, so a suburb with (say)
        // ranges-only data still populates the Market Overview instead of
        // going entirely blank (the old code returned 0 the moment no median
        // existed, discarding the ranges it had).
        $anchor = DB::table('market_data_points')
            ->whereNull('deleted_at')
            ->whereIn('metric_key', [
                'suburb_median_price_year', 'suburb_sales_count_year',
                'suburb_low_year', 'suburb_high_year', 'suburb_max_year',
            ])
            ->whereRaw('LOWER(suburb_normalised) = ?', [$suburb])
            ->orderByDesc('metric_date')
            ->first(['metric_date']);

        if ($anchor && $anchor->metric_date) {
            $year   = Carbon::parse($anchor->metric_date)->year;
            $median = $pull('suburb_median_price_year', $year) ?: $pull('suburb_median_price_12m');
            $count  = $pull('suburb_sales_count_year', $year)  ?: $pull('suburb_total_sales_12m');
            $low    = $pull('suburb_low_year', $year);
            $high   = $pull('suburb_high_year', $year);
            $max    = $pull('suburb_max_year', $year);

            $writes = array_filter([
                'suburb.latest_year'         => (string) $year,
                'suburb.latest_median_price' => $median ? (string) (int) $median->metric_value_numeric : null,
                'suburb.latest_sales_count'  => $count  ? (string) (int) $count->metric_value_numeric  : null,
                'suburb.latest_low'          => $low  ? (string) (int) $low->metric_value_numeric  : null,
                'suburb.latest_high'         => $high ? (string) (int) $high->metric_value_numeric : null,
                'suburb.latest_max'          => $max  ? (string) (int) $max->metric_value_numeric  : null,
            ], fn ($v) => $v !== null);

            return $this->upsertFields($presentation->id, $writes);
        }

        // No per-year series at all — try the 12-month aggregates before
        // giving up entirely.
        $median12 = $pull('suburb_median_price_12m');
        $count12  = $pull('suburb_total_sales_12m');
        if (!$median12 && !$count12) {
            return 0;
        }
        $writes = array_filter([
            'suburb.latest_year'         => (string) (($median12 ?? $count12)->metric_date ? Carbon::parse(($median12 ?? $count12)->metric_date)->year : (int) date('Y')),
            'suburb.latest_median_price' => $median12 ? (string) (int) $median12->metric_value_numeric : null,
            'suburb.latest_sales_count'  => $count12  ? (string) (int) $count12->metric_value_numeric  : null,
        ], fn ($v) => $v !== null);

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
