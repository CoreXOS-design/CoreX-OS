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
use App\Services\MarketAnalytics\Metrics\AbsorptionRateMetric;
use App\Services\MarketAnalytics\Metrics\DomCurveMetric;
use App\Services\MarketAnalytics\Metrics\StockPressureIndexMetric;
use App\Services\MarketAnalytics\Support\ComparableSetBuilder;
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

        // ── 4. Active listings snapshot ──────────────────────────────────────
        $listingsFilter = new ActiveListingsFilter(
            suburbSlug:   $suburbSlug,
            propertyType: $input->propertyType,
            asAtDate:     $referenceDate,
            bedrooms:     $input->bedrooms,
            branchId:     $input->sourceBranchId,
        );

        $listings = $this->listingsSource->getRecords($listingsFilter);

        // Retrieve snapshot metadata from the listings source record
        $listingsSR        = ($this->listingsSource instanceof HasSourceRecord)
            ? $this->listingsSource->getLastSourceRecord()
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
        );

        // ── 6. Data source metadata ──────────────────────────────────────────
        $dataSources = [];
        foreach ([$this->soldSource, $this->listingsSource] as $src) {
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

        // ── 9. DOM curve metric ───────────────────────────────────────────────
        // Tier 2 (proxy from imported listings) has no safe match key yet;
        // tier2Available=false until a deterministic match strategy is added.
        $domCurve       = new DomCurveMetric();
        $domCurveResult = $domCurve->compute(
            rows:          $comps->rows,
            tier2Available: false,
            tier2DomMap:   [],
        );

        // ── 10. Assemble result ──────────────────────────────────────────────
        $result = MarketAnalyticsResult::empty();

        $result->monthsOfInventory = $metricResult['value'];
        $result->demandSupplyRatio = $stockPressureResult['value'];
        $result->domCurve          = $domCurveResult['value'];
        $result->skipReason        = $metricResult['skip_reason'];

        $result->setBreakdown([
            // Context
            'suburb_slug'          => $suburbSlug,
            'inputs_hash'          => $inputsHash,
            'sold_date_from'       => $dateFrom,
            'sold_date_to'         => $referenceDate,
            'comps_hash'           => $comps->compsHash,
            'comps_count'          => $comps->count,
            'active_listing_count' => $listings->count(),
            // Metric detail (nested per metric)
            'absorption_rate'      => $metricResult['breakdown'],
            'stock_pressure'       => $stockPressureResult['breakdown'],
            'dom_curve'            => $domCurveResult['breakdown'],
        ]);

        $result->setDataSources($dataSources);

        // ── 11. Persist ──────────────────────────────────────────────────────
        MarketAnalyticsRun::create([
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
