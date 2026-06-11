<?php

namespace App\Services\Presentations;

use App\Models\Agency;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Support\MarketAnalytics\HaversineDistance;
use App\Support\Presentations\CompFingerprint;
use App\Support\Presentations\SuburbMatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Presentations V2 — CMA coverage scorer.
 *
 * Phase 2: counted registered deals in the property's suburb.
 * Phase 3b: now counts the UNION of (deals + market_report_comp_rows + presentation_sold_comps),
 *   honouring the property's effective comp scope (radius vs suburb-only) +
 *   distinct-by-fingerprint (scheme + section if sectional, else address + sale_date)
 *   so the same sale present in multiple sources doesn't double-count.
 *
 * Pure read, deterministic, no DB writes. countComps is protected so tests
 * can stub it without hitting the DB.
 */
class CmaCoverageService
{
    public const STATE_RICH = 'rich';
    public const STATE_MODERATE = 'moderate';
    public const STATE_THIN = 'thin';
    public const STATE_NONE = 'none';

    public const DEFAULT_THRESHOLD_RICH     = 6;
    public const DEFAULT_THRESHOLD_MODERATE = 3;
    public const DEFAULT_THRESHOLD_THIN     = 1;
    public const DEFAULT_PERIOD_MONTHS      = 12;

    public const SCOPE_RADIUS_ALL  = 'radius_all';
    public const SCOPE_SUBURB_ONLY = 'suburb_only';

    public function scoreForProperty(Property $property, ?int $periodMonths = null): array
    {
        $lat = $property->latitude !== null && $property->latitude !== '' ? (float) $property->latitude : null;
        $lng = $property->longitude !== null && $property->longitude !== '' ? (float) $property->longitude : null;

        return $this->score(
            agencyId:     (int) $property->agency_id,
            suburb:       (string) ($property->suburb ?? ''),
            propertyType: (string) ($property->property_type ?? ''),
            subjectLat:   $lat,
            subjectLng:   $lng,
            periodMonths: $periodMonths,
            subjectIsDemo: (bool) ($property->is_demo ?? false),
        );
    }

    public function scoreForTrackedProperty(TrackedProperty $property, ?int $periodMonths = null): array
    {
        $lat = $property->latitude !== null && $property->latitude !== '' ? (float) $property->latitude : null;
        $lng = $property->longitude !== null && $property->longitude !== '' ? (float) $property->longitude : null;

        return $this->score(
            agencyId:     (int) $property->agency_id,
            suburb:       (string) ($property->suburb ?? ''),
            propertyType: (string) ($property->property_type ?? ''),
            subjectLat:   $lat,
            subjectLng:   $lng,
            periodMonths: $periodMonths,
            subjectIsDemo: (bool) ($property->is_demo ?? false),
        );
    }

    private function score(
        int $agencyId,
        string $suburb,
        string $propertyType,
        ?float $subjectLat,
        ?float $subjectLng,
        ?int $periodMonths,
        bool $subjectIsDemo = false,
    ): array {
        $thresholds = $this->thresholdsForAgency($agencyId);
        $window     = $periodMonths ?? $thresholds['period_months'];

        $compCount = $this->countComps($suburb, $window, $thresholds['scope'], $thresholds['radius_m'], $subjectLat, $subjectLng, $subjectIsDemo, $agencyId);

        $state = match (true) {
            $compCount === 0                       => self::STATE_NONE,
            $compCount >= $thresholds['rich']      => self::STATE_RICH,
            $compCount >= $thresholds['moderate']  => self::STATE_MODERATE,
            $compCount >= $thresholds['thin']      => self::STATE_THIN,
            default                                => self::STATE_NONE,
        };

        // AT-22 §1.5 — the robust market anchor for the Generate modal's
        // "Suggestion based on suburb data" field. This is the cleaned,
        // type/price/radius-gated median of the comp pool — NOT the asking
        // price (the modal used to echo asking, which was meaningless).
        // Computed only when comps exist; null otherwise. Spec §1.5.
        $marketAnchor = $compCount > 0
            ? $this->marketAnchor($suburb, $window, $thresholds['scope'], $thresholds['radius_m'], $subjectLat, $subjectLng, $subjectIsDemo, $agencyId, $propertyType)
            : null;

        return [
            'state'           => $state,
            'comp_count'      => $compCount,
            'market_anchor'   => $marketAnchor,
            'period_months'   => $window,
            'suburb'          => $suburb,
            'property_type'   => $propertyType,
            'comp_scope'      => $thresholds['scope'],
            'comp_radius_m'   => $thresholds['radius_m'],
            'subject_geo'     => $subjectLat !== null && $subjectLng !== null,
            'thresholds'      => [
                'rich'     => $thresholds['rich'],
                'moderate' => $thresholds['moderate'],
                'thin'     => $thresholds['thin'],
            ],
            'can_generate'    => $state !== self::STATE_NONE,
            'recommendation'  => $this->recommendation($state, $compCount, $thresholds),
        ];
    }

    /**
     * Count the union of comparable sales the engine would see for this
     * property. Three sources:
     *   1. `deals` (HFC's own registered transactions)
     *   2. `market_report_comp_rows` (MIC shared pool — CMA Info imports)
     *   3. `presentation_sold_comps` (legacy per-presentation manual uploads)
     *
     * Build 8d — deduped by a SOURCE-AGNOSTIC fingerprint via
     * App\Support\Presentations\CompFingerprint, so the same sale present
     * in more than one source counts ONCE (was 3× under the old prefixed
     * scheme). Same helper is used by MicSnapshotHydrator for CMA-wins
     * precedence when injecting deals into the engine pool.
     *
     * Build 8d — adds the deals.agency_id = subject filter that was
     * missing pre-8d (multi-tenancy gap).
     *
     * Protected so tests / coverage tools can stub.
     */
    protected function countComps(
        string $suburb,
        int $periodMonths,
        string $scope,
        int $radiusM,
        ?float $subjectLat,
        ?float $subjectLng,
        bool $subjectIsDemo = false,
        int $agencyId = 0,
    ): int {
        if ($suburb === '') {
            return 0;
        }

        $dateFrom = Carbon::today()->subMonths($periodMonths)->toDateString();
        $dateTo   = Carbon::today()->toDateString();
        // SuburbMatcher core — strips trailing locality suffix so SQL
        // pre-filter LIKE catches "uvongo" comps when subject is
        // "Uvongo Beach". PHP-side SuburbMatcher::matches() narrows the
        // hits to actual locality matches.
        $subjectCore = SuburbMatcher::normaliseSuburbToken($suburb);
        $coreLike    = $subjectCore !== '' ? '%' . $subjectCore . '%' : '%';

        $fingerprints = [];

        // 1. Deals — prefer FK suburb match (Phase 3i), fall back to legacy
        //    LOWER(property_address) LIKE for unlinked deals.
        // Phase 3h Step 9 — demo/real isolation.
        // Build 8d — agency_id filter (was missing — multi-tenancy gap).
        $dealsQuery = DB::table('deals')
            ->leftJoin('properties', 'properties.id', '=', 'deals.property_id')
            ->whereNotNull('deals.registration_date')
            ->where(function ($q) {
                $q->whereNull('deals.accepted_status')->orWhere('deals.accepted_status', '!=', 'D');
            })
            ->where('deals.is_demo', $subjectIsDemo)
            ->whereBetween('deals.registration_date', [$dateFrom, $dateTo])
            ->where(function ($q) use ($coreLike) {
                $q->whereRaw('LOWER(properties.suburb) LIKE ?', [$coreLike])
                  ->orWhere(function ($qq) use ($coreLike) {
                      $qq->whereNull('deals.property_id')
                         ->whereRaw('LOWER(deals.property_address) LIKE ?', [$coreLike]);
                  });
            });
        if ($agencyId > 0) {
            $dealsQuery->where('deals.agency_id', $agencyId);
        }
        $dealRows = $dealsQuery
            ->select([
                'deals.property_address',
                'deals.registration_date',
                'deals.property_value',
                'deals.sale_price',
                'deals.property_id',
                'properties.suburb as prop_suburb',
            ])
            ->get();
        foreach ($dealRows as $r) {
            // Narrow linked deals via SuburbMatcher; unlinked already
            // passed the SQL core-token LIKE on property_address.
            if (!empty($r->prop_suburb)
                && !SuburbMatcher::matches($r->prop_suburb, $suburb)) {
                continue;
            }
            $fingerprints[$this->fingerprintDeal($r)] = true;
        }

        // 2. MIC market_report_comp_rows — scope-branched read.
        // Phase 3h Step 9 — demo/real isolation.
        $micQuery = DB::table('market_report_comp_rows')
            ->whereNull('deleted_at')
            ->where('row_type', 'comp')
            ->whereNotNull('sale_date')
            ->whereNotNull('sale_price')
            ->where('is_demo', $subjectIsDemo)
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->select(['scheme_name', 'section_number', 'address', 'sale_date', 'sale_price', 'suburb_normalised', 'latitude', 'longitude']);

        $micRows = $micQuery->get();
        foreach ($micRows as $r) {
            if (!$this->compInScope($r, $scope, $suburb, $radiusM, $subjectLat, $subjectLng)) continue;
            $fingerprints[$this->fingerprintMic($r)] = true;
        }

        // 3. Legacy presentation_sold_comps fallback.
        // Phase 3h Step 9 — demo/real isolation.
        // SuburbMatcher: SQL pre-filter on core token, PHP narrow to
        // locality match. Mirrors the deal/MIC fix.
        $psRows = DB::table('presentation_sold_comps')
            ->whereNull('deleted_at')
            ->whereNotNull('sold_date')
            ->whereNotNull('sold_price_inc')
            ->where('is_demo', $subjectIsDemo)
            ->whereBetween('sold_date', [$dateFrom, $dateTo])
            ->where(function ($q) use ($coreLike) {
                $q->whereNull('suburb')->orWhereRaw('LOWER(suburb) LIKE ?', [$coreLike]);
            })
            ->select(['suburb', 'sold_date', 'sold_price_inc', 'raw_row_json'])
            ->get();
        foreach ($psRows as $r) {
            if (!empty($r->suburb) && !SuburbMatcher::matches($r->suburb, $suburb)) {
                continue;
            }
            $fingerprints[$this->fingerprintPs($r)] = true;
        }

        return count($fingerprints);
    }

    /**
     * AT-22 §1.5 — robust market anchor for the Generate modal.
     *
     * Gathers suburb-broad MIC comp candidates and runs them through
     * CompPoolBuilder (type hard-gate → price band → radius ladder →
     * divergence → rank). Returns the gated pool's median — the same
     * defensible anchor the hydrator persists against, so the modal
     * "Suggestion" and the generated CMA agree. Returns null when no
     * usable comps resolve. Lightweight: one suburb-scoped query.
     */
    protected function marketAnchor(
        string $suburb,
        int $periodMonths,
        string $scope,
        int $radiusM,
        ?float $subjectLat,
        ?float $subjectLng,
        bool $subjectIsDemo,
        int $agencyId,
        ?string $propertyType,
    ): ?int {
        if ($suburb === '') {
            return null;
        }
        $dateFrom = Carbon::today()->subMonths($periodMonths)->toDateString();
        $dateTo   = Carbon::today()->toDateString();

        $rows = DB::table('market_report_comp_rows')
            ->whereNull('deleted_at')
            ->where('row_type', 'comp')
            ->whereNotNull('sale_date')
            ->whereNotNull('sale_price')
            ->where('is_demo', $subjectIsDemo)
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->select(['sale_price', 'property_type', 'extent_m2', 'latitude', 'longitude', 'suburb_normalised'])
            ->get();

        // Collect suburb-broad candidates; CompPoolBuilder narrows by its
        // own radius ladder (mirrors the hydrator's collect-broad-gate-down).
        $candidates = [];
        foreach ($rows as $i => $r) {
            if (empty($r->suburb_normalised) || !SuburbMatcher::matches($r->suburb_normalised, $suburb)) {
                continue;
            }
            $candidates[] = [
                'key'           => $i,
                'price'         => (int) $r->sale_price,
                'size_m2'       => $r->extent_m2 !== null ? (int) $r->extent_m2 : null,
                'property_type' => $r->property_type,
                'lat'           => $r->latitude,
                'lng'           => $r->longitude,
                'exempt'        => false,
            ];
        }
        if (empty($candidates)) {
            return null;
        }

        $config  = CompPoolBuilder::configForAgency(\App\Models\Agency::find($agencyId));
        $subject = [
            'title_type'    => null,
            'property_type' => $propertyType,
            'lat'           => $subjectLat,
            'lng'           => $subjectLng,
            'erf_m2'        => null,
        ];
        return (new CompPoolBuilder())->select($subject, $candidates, $config)['anchor'];
    }

    /**
     * Does a market_report_comp_rows row satisfy the configured scope?
     *
     * Suburb signature now goes through SuburbMatcher so subject
     * "Uvongo Beach" matches comp suburb_normalised "uvongo". Pre-fix
     * the directional substring check dropped every cross-naming pair.
     */
    private function compInScope(object $row, string $scope, string $subjectSuburb, int $radiusM, ?float $subjectLat, ?float $subjectLng): bool
    {
        if ($scope === self::SCOPE_SUBURB_ONLY) {
            return SuburbMatcher::matches($row->suburb_normalised, $subjectSuburb);
        }
        // radius_all — Haversine when both sides have geo, else fall back to suburb.
        if ($subjectLat !== null && $subjectLng !== null && $row->latitude !== null && $row->longitude !== null) {
            $d = HaversineDistance::distanceMetres($subjectLat, $subjectLng, (float) $row->latitude, (float) $row->longitude);
            return $d <= max(1, $radiusM);
        }
        return SuburbMatcher::matches($row->suburb_normalised, $subjectSuburb);
    }

    /**
     * Build 8d — fingerprints now source-agnostic via CompFingerprint.
     * The latent badge double-count between CMA and deal sources for the
     * same sale is fixed: identical sale ⇒ identical key ⇒ counted once.
     *
     * Deal uses sale_price (Phase 3i canonical bigint) when present and
     * falls back to property_value (the legacy decimal mirror). MIC keeps
     * its scheme/section sectional branch via the helper.
     * Legacy presentation_sold_comps still keys on address/date/price —
     * the suburb-only legacy key from pre-8d is replaced by an address-
     * keyed one (raw_row_json carries the address); when address is
     * missing it falls back to suburb so the keying behaviour for legacy
     * rows stays stable.
     */
    private function fingerprintDeal(object $r): string
    {
        $price = isset($r->sale_price) && $r->sale_price !== null
            ? (int) $r->sale_price
            : (int) ($r->property_value ?? 0);
        return CompFingerprint::sourceAgnosticKey(
            address: (string) ($r->property_address ?? ''),
            schemeName: null,
            sectionNumber: null,
            saleDate: (string) ($r->registration_date ?? ''),
            salePrice: $price,
        );
    }

    private function fingerprintMic(object $r): string
    {
        return CompFingerprint::sourceAgnosticKey(
            address: (string) ($r->address ?? ''),
            schemeName: $r->scheme_name ?? null,
            sectionNumber: $r->section_number ?? null,
            saleDate: (string) ($r->sale_date ?? ''),
            salePrice: (int) ($r->sale_price ?? 0),
        );
    }

    private function fingerprintPs(object $r): string
    {
        $address = null;
        if (!empty($r->raw_row_json)) {
            $decoded = json_decode((string) $r->raw_row_json, true);
            if (is_array($decoded)) {
                $address = $decoded['address'] ?? null;
            }
        }
        return CompFingerprint::sourceAgnosticKey(
            address: $address !== null ? (string) $address : (string) ($r->suburb ?? ''),
            schemeName: null,
            sectionNumber: null,
            saleDate: (string) ($r->sold_date ?? ''),
            salePrice: (int) ($r->sold_price_inc ?? 0),
        );
    }

    /**
     * @return array{rich:int,moderate:int,thin:int,period_months:int,scope:string,radius_m:int}
     */
    private function thresholdsForAgency(int $agencyId): array
    {
        $agency = $agencyId > 0 ? Agency::find($agencyId) : null;

        return [
            'rich'          => (int) ($agency->presentations_coverage_rich_threshold ?? self::DEFAULT_THRESHOLD_RICH),
            'moderate'      => (int) ($agency->presentations_coverage_moderate_threshold ?? self::DEFAULT_THRESHOLD_MODERATE),
            'thin'          => (int) ($agency->presentations_coverage_thin_threshold ?? self::DEFAULT_THRESHOLD_THIN),
            'period_months' => (int) ($agency->presentations_default_period_months ?? self::DEFAULT_PERIOD_MONTHS),
            'scope'         => (string) ($agency->presentations_default_comp_scope ?? self::SCOPE_RADIUS_ALL),
            'radius_m'      => (int) ($agency->presentations_default_radius_m ?? 1000),
        ];
    }

    private function recommendation(string $state, int $compCount, array $thresholds): string
    {
        $scopeLabel = $thresholds['scope'] === self::SCOPE_RADIUS_ALL
            ? sprintf('within %dm', $thresholds['radius_m'])
            : 'suburb-only';

        return match ($state) {
            self::STATE_RICH     => sprintf('Strong data — %d recent comparable sales (%s).', $compCount, $scopeLabel),
            self::STATE_MODERATE => sprintf('Moderate data — %d recent comparable sales (%s). Stronger comps available with more uploads.', $compCount, $scopeLabel),
            self::STATE_THIN     => sprintf('Thin data — %d recent comparable sale%s (%s). Upload more CMAs to strengthen.', $compCount, $compCount === 1 ? '' : 's', $scopeLabel),
            default              => sprintf('No comparable sales found (%s). Upload CMAs first?', $scopeLabel),
        };
    }
}
