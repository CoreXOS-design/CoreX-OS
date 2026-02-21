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
use App\Services\MarketAnalytics\CompetitiveStockService;
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
     * P2: Evidence-First source policy (threshold-based, not zero-fallback).
     *
     * Flow:
     *   1. Pure deterministic identifiers (no I/O)
     *   2. Build comparable sold set (InternalDealsAdapter)
     *   3. Evidence-First: prefer presentation uploads >= threshold
     *   4. Fetch active listings (Evidence-First same policy)
     *   5-13. Metric computation
     *   14. Persist (skipped when $persist = false)
     */
    public function run(MarketAnalyticsInput $input, bool $persist = true): MarketAnalyticsResult
    {
        // -- 1. Pure helpers --
        $suburbSlug = SuburbNormalizer::slug($input->suburb);
        $inputsHash = InputHasher::hash($input);

        // -- 2. Date window --
        $referenceDate = $input->referenceDate ?? Carbon::today()->toDateString();
        $dateFrom      = Carbon::parse($referenceDate)
            ->subMonths($input->periodMonths)
            ->toDateString();

        // -- 3. Sold comparable set -- Evidence-First policy --
        $soldFilter = new SoldTransactionsFilter(
            suburbSlug:   $suburbSlug,
            propertyType: $input->propertyType,
            dateFrom:     $dateFrom,
            dateTo:       $referenceDate,
            bedrooms:     $input->bedrooms,
            branchId:     $input->sourceBranchId,
        );

        $threshold     = (int) config('market_analytics.min_comps_threshold', 6);
        $internalComps = (new ComparableSetBuilder($this->soldSource))->build($soldFilter);

        $presentationSoldAdapter = null;
        $presentationComps       = null;
        $selectedSoldSource      = 'internal';

        if ($input->presentationId !== null) {
            $presentationSoldAdapter = new PresentationSoldCompsAdapter($input->presentationId);
            $presentationComps       = (new ComparableSetBuilder($presentationSoldAdapter))->build($soldFilter);

            if ($presentationComps->count >= $threshold) {
                $comps              = $presentationComps;
                $selectedSoldSource = 'presentation_uploads';
            } elseif ($internalComps->count >= $threshold) {
                $comps                   = $internalComps;
                $presentationSoldAdapter = null;
                $selectedSoldSource      = 'internal';
            } else {
                if ($presentationComps->count >= $internalComps->count) {
                    $comps              = $presentationComps;
                    $selectedSoldSource = 'presentation_uploads';
                } else {
                    $comps                   = $internalComps;
                    $presentationSoldAdapter = null;
                    $selectedSoldSource      = 'internal';
                }
            }
        } else {
            $comps = $internalComps;
        }

        // -- 4. Active listings snapshot -- Evidence-First policy --
        $listingsFilter = new ActiveListingsFilter(
            suburbSlug:   $suburbSlug,
            propertyType: $input->propertyType,
            asAtDate:     $referenceDate,
            bedrooms:     $input->bedrooms,
            branchId:     $input->sourceBranchId,
        );

        $importedListings = $this->listingsSource->getRecords($listingsFilter);

        $presentationListingsAdapter = null;
        $presentationListings        = null;
        $selectedListingsSource      = 'internal';

        if ($input->presentationId !== null) {
            $presentationListingsAdapter = new PresentationActiveListingsAdapter($input->presentationId);
            $presentationListings        = $presentationListingsAdapter->getRecords($listingsFilter);

            if ($presentationListings->count() >= $threshold) {
                $listings               = $presentationListings;
                $selectedListingsSource = 'presentation_uploads';
            } elseif ($importedListings->count() >= $threshold) {
                $listings                    = $importedListings;
                $presentationListingsAdapter = null;
                $selectedListingsSource      = 'internal';
            } else {
                if ($presentationListings->count() >= $importedListings->count()) {
                    $listings               = $presentationListings;
                    $selectedListingsSource = 'presentation_uploads';
                } else {
                    $listings                    = $importedListings;
                    $presentationListingsAdapter = null;
                    $selectedListingsSource      = 'internal';
                }
            }
        } else {
            $listings = $importedListings;
        }

        // Retrieve snapshot metadata from the active listings source record
        $activeSource      = $presentationListingsAdapter ?? $this->listingsSource;
        $listingsSR        = ($activeSource instanceof HasSourceRecord)
            ? $activeSource->getLastSourceRecord()
            : null;
        $snapshotRunId     = $listingsSR?->snapshotRunId;
        $snapshotCreatedAt = $listingsSR?->snapshotCreatedAt;

        // -- 5. Absorption rate metric --
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

        // -- 6. Data source metadata --
        $dataSources  = [];
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

        // -- 7. New listings count (for stock pressure) --
        $newListingsCount = null;
        if ($this->listingsSource instanceof ImportedListingsAdapter) {
            $newListings      = $this->listingsSource->queryNewInPeriod(
                $dateFrom, $referenceDate, $listingsFilter
            );
            $newListingsCount = $newListings->count();
        }

        // -- 8. Stock pressure metric --
        $stockPressure       = new StockPressureIndexMetric();
        $stockPressureResult = $stockPressure->compute(
            monthlySold:       $metricResult['breakdown']['monthly_sold'],
            newListingsCount:  $newListingsCount,
            periodMonths:      (float)$input->periodMonths,
            snapshotRunId:     $snapshotRunId,
            snapshotCreatedAt: $snapshotCreatedAt,
        );

        // -- 9. Deal-Listing match (Tier 2 DOM resolution) --
        $tier2FullMap = [];
        if ($input->sourceBranchId !== null) {
            $tier2FullMap = (new DealListingMatcher())->buildDomResolutionMap(
                compRows:   $comps->rows,
                branchId:   $input->sourceBranchId,
                periodFrom: $dateFrom,
                periodTo:   $referenceDate,
            );
        }

        $tier2DomSimple = array_map(fn (array $v): int => $v['dom_days'], $tier2FullMap);
        $tier2Available = !empty($tier2DomSimple);

        // -- 10. DOM curve metric --
        $domCurve       = new DomCurveMetric();
        $domCurveResult = $domCurve->compute(
            rows:           $comps->rows,
            tier2Available: $tier2Available,
            tier2DomMap:    $tier2DomSimple,
        );

        // -- 11. Price/m2 deviation metric --
        $pricePerSqm       = new PricePerSqmDeviationMetric();
        $pricePerSqmResult = $pricePerSqm->compute(
            subjectSizeM2:   $input->subjectSizeM2,
            subjectPriceInc: $input->subjectPriceInc,
            compRows:        $comps->rows,
            compsHash:       $comps->compsHash,
        );

        // -- 12. Elasticity proxy metric --
        $elasticity       = new ElasticityProxyMetric();
        $elasticityResult = $elasticity->compute(
            compRows:         $comps->rows,
            compsHash:        $comps->compsHash,
            domResolutionMap: $tier2DomSimple ?: null,
        );

        // -- 12.5. Competitive stock analysis --
        $annualAbsorption    = $input->periodMonths > 0
            ? $comps->count / $input->periodMonths * 12
            : 0.0;
        $competitiveStock    = (new CompetitiveStockService())->analyze(
            listingRows:       $listings->toArray(),
            subjectPrice:      $input->subjectPriceInc,
            annualAbsorption:  $annualAbsorption,
        );

        // -- 13. Assemble result --
        $result = MarketAnalyticsResult::empty();

        $result->monthsOfInventory       = $metricResult['value'];
        $result->demandSupplyRatio       = $stockPressureResult['value'];
        $result->domCurve                = $domCurveResult['value'];
        $result->pricePerSqmDeviationPct = $pricePerSqmResult['value'];
        $result->elasticityDaysPerPct    = $elasticityResult['value'];
        $result->elasticityRSquared      = $elasticityResult['breakdown']['r_squared'];
        $result->skipReason              = $metricResult['skip_reason'];

        $result->setBreakdown([
            'suburb_slug'          => $suburbSlug,
            'suburb_match_mode'    => 'like_normalized',
            'inputs_hash'          => $inputsHash,
            'sold_date_from'       => $dateFrom,
            'sold_date_to'         => $referenceDate,
            'comps_hash'           => $comps->compsHash,
            'comps_count'          => $comps->count,
            'active_listing_count' => $listings->count(),
            'dom_tier2_available'        => $tier2Available,
            'dom_tier2_matches'          => count($tier2FullMap),
            'deal_listing_match_version' => DealListingMatcher::MATCH_VERSION,
            'source_selection' => [
                'sold_comps' => [
                    'selected_source'    => $selectedSoldSource,
                    'internal_count'     => $internalComps->count,
                    'presentation_count' => $presentationComps?->count,
                    'threshold_used'     => $threshold,
                    'presentation_id'    => $input->presentationId,
                ],
                'active_listings' => [
                    'selected_source'    => $selectedListingsSource,
                    'internal_count'     => $importedListings->count(),
                    'presentation_count' => $presentationListings?->count(),
                    'threshold_used'     => $threshold,
                    'presentation_id'    => $input->presentationId,
                ],
            ],
            'absorption_rate'   => $metricResult['breakdown'],
            'stock_pressure'    => $stockPressureResult['breakdown'],
            'dom_curve'         => $domCurveResult['breakdown'],
            'price_per_sqm'     => $pricePerSqmResult['breakdown'],
            'elasticity'        => $elasticityResult['breakdown'],
            'competitive_stock' => $competitiveStock,
        ]);

        $result->setDataSources($dataSources);

        // -- 14. Persist --
        if ($persist) {
            MarketAnalyticsRun::create([
                'model_version'     => self::MODEL_VERSION,
                'inputs_hash'       => $inputsHash,
                'inputs_json'       => $input->toCanonicalArray(),
                'outputs_json'      => $result->toValuesArray(),
                'breakdown_json'    => $result->toBreakdownArray(),
                'data_sources_json' => $result->toDataSourcesArray(),
                'created_by'        => null,
            ]);
        }

        return $result;
    }
}