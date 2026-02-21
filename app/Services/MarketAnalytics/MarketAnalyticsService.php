<?php

namespace App\Services\MarketAnalytics;

use App\Models\MarketAnalyticsRun;
use App\Services\MarketAnalytics\Contracts\ActiveListingsSource;
use App\Services\MarketAnalytics\Contracts\HasSourceRecord;
use App\Services\MarketAnalytics\Contracts\SoldTransactionsSource;
use App\Services\MarketAnalytics\DTOs\ActiveListingsFilter;
use App\Services\MarketAnalytics\DTOs\MarketAnalyticsInput;
use App\Services\MarketAnalytics\DTOs\MarketAnalyticsResult;
use App\Services\MarketAnalytics\DTOs\SoldTransactionsFilter;
use App\Services\MarketAnalytics\Helpers\InputHasher;
use App\Services\MarketAnalytics\Helpers\SuburbNormalizer;
use App\Services\MarketAnalytics\Adapters\ImportedListingsAdapter;
use App\Services\MarketAnalytics\Adapters\PresentationActiveListingsAdapter;
use App\Services\MarketAnalytics\Adapters\PresentationSoldCompsAdapter;
use App\Services\MarketAnalytics\Metrics\AbsorptionRateMetric;
use App\Services\MarketAnalytics\Metrics\DomCurveMetric;
use App\Services\MarketAnalytics\Metrics\ElasticityProxyMetric;
use App\Services\MarketAnalytics\Metrics\PricePerSqmDeviationMetric;
use App\Services\MarketAnalytics\Metrics\StockPressureIndexMetric;
use App\Services\MarketAnalytics\Support\ComparableSetBuilder;
use App\Services\MarketAnalytics\Support\DealListingMatcher;
use Carbon\Carbon;

class MarketAnalyticsService
{
    public const MODEL_VERSION = 'v1.0.0';

    public function __construct(
        protected SoldTransactionsSource $soldSource,
        protected ActiveListingsSource   $listingsSource,
    ) {}

    /**
     * Run market analytics for the given inputs.
     *
     * Phase 2, step 2.3: AbsorptionRateMetric wired + persistence added.
     *
     * Flow:
     *   1. Pure deterministic identifiers (no I/O)
     *   2. Build comparable sold set (InternalDealsAdapter)
     *   3. Fetch active listings snapshot (ImportedListingsAdapter)
     *   4. Compute absorption rate metric
     *   5. Assemble result + collect data source metadata
     *   6. Persist to market_analytics_runs
     */
    public function run(MarketAnalyticsInput $input): MarketAnalyticsResult
    {
        // ── 1. Pure helpers ──────────────────────────────────────────────────
        $suburbSlug = SuburbNormalizer::slug($input->suburb);
        $inputsHash = InputHasher::hash($input);

        // ── 2. Date window ───────────────────────────────────────────────────
        $referenceDate = $input->referenceDate ?? Carbon::today()->toDateString();
        $dateFrom      = Carbon::parse($referenceDate)
            ->subMonths($input->periodMonths)
            ->toDateString();

        // ── 3. Sold comparable set ───────────────────────────────────────────
        $soldFilter = new SoldTransactionsFilter(
            suburbSlug:   $suburbSlug,
            propertyType: $input->propertyType,
            dateFrom:     $dateFrom,
            dateTo:       $referenceDate,
            bedrooms:     $input->bedrooms,
            branchId:     $input->sourceBranchId,
        );

        $comps = (new ComparableSetBuilder($this->soldSource))->build($soldFilter);

        // ── 3b. Fallback: presentation evidence (if internal returned 0) ──────
        $presentationSoldAdapter = null;
        if ($comps->count === 0 && $input->presentationId !== null) {
            $presentationSoldAdapter = new PresentationSoldCompsAdapter($input->presentationId);
            $comps = (new ComparableSetBuilder($presentationSoldAdapter))->build($soldFilter);
        }

        // ── 4. Active listings snapshot ──────────────────────────────────────
        $listingsFilter = new ActiveListingsFilter(
            suburbSlug:   $suburbSlug,
            propertyType: $input->propertyType,
            asAtDate:     $referenceDate,
            bedrooms:     $input->bedrooms,
            branchId:     $input->sourceBranchId,
        );

        $listings = $this->listingsSource->getRecords($listingsFilter);

        // ── 4b. Fallback: presentation evidence (if imported returned 0) ──────
        $presentationListingsAdapter = null;
        if ($listings->count() === 0 && $input->presentationId !== null) {
            $presentationListingsAdapter = new PresentationActiveListingsAdapter($input->presentationId);
            $listings = $presentationListingsAdapter->getRecords($listingsFilter);
        }

        // Retrieve snapshot metadata from the listings source record
        $activeSource      = $presentationListingsAdapter ?? $this->listingsSource;
        $listingsSR        = ($activeSource instanceof HasSourceRecord)
            ? $activeSource->getLastSourceRecord()
            : null;
        $snapshotRunId     = $listingsSR?->snapshotRunId;
        $snapshotCreatedAt = $listingsSR?->snapshotCreatedAt;

        // ── 5. Absorption rate metric ────────────────────────────────────────
        $metric       = new AbsorptionRateMetric();
        $metricResult = $metric->compute(
            soldCount:         $comps->count,
            activeStock:       $listings->count(),
            periodMonths:      (float)$input->periodMonths,
            compsHash:         $comps->compsHash,
            snapshotRunId:     $snapshotRunId,
            snapshotCreatedAt: $snapshotCreatedAt,
            suburbMatchMode:   'like_normalized',
        );

        // ── 6. Data source metadata ──────────────────────────────────────────
        $dataSources = [];
        $sourcesToLog = array_filter([
            $this->soldSource,
            $presentationSoldAdapter,
            $this->listingsSource,
            $presentationListingsAdapter,
        ]);
        foreach ($sourcesToLog as $src) {
            if ($src instanceof HasSourceRecord) {
                $sr = $src->getLastSourceRecord();
                if ($sr !== null) {
                    $dataSources[] = $sr->toArray();
                }
            }
        }

        // ── 7. New listings count (for stock pressure) ───────────────────────
        // Must be fetched AFTER step 6 so data sources captures the active-as-of
        // record; queryNewInPeriod overwrites lastSourceRecord on the adapter.
        $newListingsCount = null;
        if ($this->listingsSource instanceof ImportedListingsAdapter) {
            $newListings      = $this->listingsSource->queryNewInPeriod(
                $dateFrom, $referenceDate, $listingsFilter
            );
            $newListingsCount = $newListings->count();
        }

        // ── 8. Stock pressure metric ─────────────────────────────────────────
        $stockPressure       = new StockPressureIndexMetric();
        $stockPressureResult = $stockPressure->compute(
            monthlySold:       $metricResult['breakdown']['monthly_sold'],
            newListingsCount:  $newListingsCount,
            periodMonths:      (float)$input->periodMonths,
            snapshotRunId:     $snapshotRunId,
            snapshotCreatedAt: $snapshotCreatedAt,
        );

        // ── 9. Deal↔Listing match (Tier 2 DOM resolution) ───────────────────
        // Matches comp rows that have no listed_date against listing_stocks using
        // address similarity + price proximity. Skipped when no sourceBranchId.
        $tier2FullMap = [];
        if ($input->sourceBranchId !== null) {
            $tier2FullMap = (new DealListingMatcher())->buildDomResolutionMap(
                compRows:   $comps->rows,
                branchId:   $input->sourceBranchId,
                periodFrom: $dateFrom,
                periodTo:   $referenceDate,
            );
        }

        // Simplified map consumed by metrics: row_hash → dom_days (int)
        $tier2DomSimple = array_map(fn (array $v): int => $v['dom_days'], $tier2FullMap);
        $tier2Available  = !empty($tier2DomSimple);

        // ── 10. DOM curve metric ─────────────────────────────────────────────
        $domCurve       = new DomCurveMetric();
        $domCurveResult = $domCurve->compute(
            rows:           $comps->rows,
            tier2Available: $tier2Available,
            tier2DomMap:    $tier2DomSimple,
        );

        // ── 11. Price/m² deviation metric ───────────────────────────────────
        $pricePerSqm       = new PricePerSqmDeviationMetric();
        $pricePerSqmResult = $pricePerSqm->compute(
            subjectSizeM2:   $input->subjectSizeM2,
            subjectPriceInc: $input->subjectPriceInc,
            compRows:        $comps->rows,
            compsHash:       $comps->compsHash,
        );

        // ── 12. Elasticity proxy metric ──────────────────────────────────────
        $elasticity       = new ElasticityProxyMetric();
        $elasticityResult = $elasticity->compute(
            compRows:         $comps->rows,
            compsHash:        $comps->compsHash,
            domResolutionMap: $tier2DomSimple ?: null,
        );

        // ── 13. Assemble result ──────────────────────────────────────────────
        $result = MarketAnalyticsResult::empty();

        $result->monthsOfInventory       = $metricResult['value'];
        $result->demandSupplyRatio       = $stockPressureResult['value'];
        $result->domCurve                = $domCurveResult['value'];
        $result->pricePerSqmDeviationPct = $pricePerSqmResult['value'];
        $result->elasticityDaysPerPct    = $elasticityResult['value'];
        $result->elasticityRSquared      = $elasticityResult['breakdown']['r_squared'];
        $result->skipReason              = $metricResult['skip_reason'];

        $result->setBreakdown([
            // Context
            'suburb_slug'          => $suburbSlug,
            'suburb_match_mode'    => 'like_normalized',
            'inputs_hash'          => $inputsHash,
            'sold_date_from'       => $dateFrom,
            'sold_date_to'         => $referenceDate,
            'comps_hash'           => $comps->compsHash,
            'comps_count'          => $comps->count,
            'active_listing_count' => $listings->count(),
            // Tier 2 match metadata
            'dom_tier2_available'        => $tier2Available,
            'dom_tier2_matches'          => count($tier2FullMap),
            'deal_listing_match_version' => DealListingMatcher::MATCH_VERSION,
            // Metric detail (nested per metric)
            'absorption_rate'      => $metricResult['breakdown'],
            'stock_pressure'       => $stockPressureResult['breakdown'],
            'dom_curve'            => $domCurveResult['breakdown'],
            'price_per_sqm'        => $pricePerSqmResult['breakdown'],
            'elasticity'           => $elasticityResult['breakdown'],
        ]);

        $result->setDataSources($dataSources);

        // ── 14. Persist ──────────────────────────────────────────────────────
        $maRun = MarketAnalyticsRun::create([
            'model_version'     => self::MODEL_VERSION,
            'inputs_hash'       => $inputsHash,
            'inputs_json'       => $input->toCanonicalArray(),
            'outputs_json'      => $result->toValuesArray(),
            'breakdown_json'    => $result->toBreakdownArray(),
            'data_sources_json' => $result->toDataSourcesArray(),
            'created_by'        => null,
        ]);

        return $result;
    }
}
