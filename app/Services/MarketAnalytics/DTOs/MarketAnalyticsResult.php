<?php

namespace App\Services\MarketAnalytics\DTOs;

class MarketAnalyticsResult
{
    // All metric fields start null; computed in later phases.
    public ?float  $medianSalePrice     = null;
    public ?float  $avgDaysOnMarket     = null;
    public ?int    $soldCount           = null;
    public ?int    $activeListingCount  = null;
    public ?float  $clearanceRate       = null;
    public ?float  $medianListPrice     = null;
    public ?float  $monthsOfInventory   = null;  // absorption rate (step 2.3)
    public ?float  $demandSupplyRatio   = null;  // stock pressure index (step 2.4)
    public ?array  $domCurve            = null;  // DOM p25/p50/p75 (step 2.5)
    public ?string $skipReason          = null;

    // Breakdown and source detail arrays (empty until metric phase)
    private array $breakdown    = [];
    private array $dataSources  = [];

    private function __construct() {}

    /**
     * Assemble an empty result object.
     * Additional static factory methods will be added in later phases.
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Flat key→value map of top-level metrics for storage in outputs_json.
     */
    public function toValuesArray(): array
    {
        return [
            'median_sale_price'    => $this->medianSalePrice,
            'avg_days_on_market'   => $this->avgDaysOnMarket,
            'sold_count'           => $this->soldCount,
            'active_listing_count' => $this->activeListingCount,
            'clearance_rate'       => $this->clearanceRate,
            'median_list_price'    => $this->medianListPrice,
            'months_of_inventory'  => $this->monthsOfInventory,
            'demand_supply_ratio'  => $this->demandSupplyRatio,
            'dom_curve'            => $this->domCurve,
            'skip_reason'          => $this->skipReason,
        ];
    }

    /**
     * Per-metric breakdown detail for storage in breakdown_json.
     */
    public function toBreakdownArray(): array
    {
        return $this->breakdown;
    }

    /**
     * Data source records consulted during computation.
     */
    public function toDataSourcesArray(): array
    {
        return $this->dataSources;
    }

    public function setBreakdown(array $breakdown): void
    {
        $this->breakdown = $breakdown;
    }

    public function setDataSources(array $dataSources): void
    {
        $this->dataSources = $dataSources;
    }
}
