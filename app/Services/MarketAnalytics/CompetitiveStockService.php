<?php

namespace App\Services\MarketAnalytics;

use App\Services\Presentations\Evidence\ListingLifecycleService;
use App\Services\Presentations\PresentationDataQualityService;

/**
 * Computes competitive market position statistics from active listing rows.
 *
 * Deterministic only. No AI. No external I/O.
 * Called from MarketAnalyticsService after the listings set is resolved.
 */
class CompetitiveStockService
{
    private ?ListingLifecycleService $lifecycleService;
    private ?PresentationDataQualityService $dataQualityService;

    public function __construct(
        ?ListingLifecycleService $lifecycleService = null,
        ?PresentationDataQualityService $dataQualityService = null,
    ) {
        $this->lifecycleService   = $lifecycleService;
        $this->dataQualityService = $dataQualityService;
    }

    /**
     * Analyse the active listings and compute competitive statistics.
     *
     * @param  array<int, array<string, mixed>>  $listingRows    From the active listings adapter
     * @param  float|null                        $subjectPrice   Subject property asking price (ZAR), or null if not set
     * @param  float                             $annualAbsorption  Annual number of properties sold
     *
     * @return array{
     *   total_active_stock:   int,
     *   median_price:         float|null,
     *   mean_price:           float|null,
     *   min_price:            float|null,
     *   max_price:            float|null,
     *   below_subject_count:  int|null,
     *   above_subject_count:  int|null,
     *   stock_months_available: float|null
     * }
     */
    public function analyze(array $listingRows, ?float $subjectPrice, float $annualAbsorption): array
    {
        // Extract valid prices from listing rows
        $prices = [];
        foreach ($listingRows as $row) {
            $price = isset($row['list_price_inc']) ? (float)$row['list_price_inc'] : null;
            if ($price !== null && $price > 0) {
                $prices[] = $price;
            }
        }

        $total = count($listingRows);
        $count = count($prices);

        // ── Median ───────────────────────────────────────────────────────────
        $medianPrice = null;
        if ($count > 0) {
            sort($prices);
            $mid         = (int)floor($count / 2);
            $medianPrice = ($count % 2 === 0)
                ? ($prices[$mid - 1] + $prices[$mid]) / 2.0
                : (float)$prices[$mid];
        }

        // ── Mean / Min / Max ─────────────────────────────────────────────────
        $meanPrice = $count > 0 ? array_sum($prices) / $count : null;
        $minPrice  = $count > 0 ? (float)min($prices) : null;
        $maxPrice  = $count > 0 ? (float)max($prices) : null;

        // ── Competitive position vs subject price ─────────────────────────────
        $belowCount = null;
        $aboveCount = null;

        if ($subjectPrice !== null && $subjectPrice > 0 && $count > 0) {
            $belowCount = count(array_filter($prices, fn (float $p) => $p < $subjectPrice));
            $aboveCount = count(array_filter($prices, fn (float $p) => $p > $subjectPrice));
        }

        // ── Stock months = active / (annual / 12) ────────────────────────────
        $stockMonths = null;
        if ($annualAbsorption > 0 && $total > 0) {
            $stockMonths = round($total * 12 / $annualAbsorption, 2);
        }

        return [
            'total_active_stock'    => $total,
            'median_price'          => $medianPrice !== null ? round($medianPrice, 2) : null,
            'mean_price'            => $meanPrice  !== null ? round($meanPrice,  2) : null,
            'min_price'             => $minPrice,
            'max_price'             => $maxPrice,
            'below_subject_count'   => $belowCount,
            'above_subject_count'   => $aboveCount,
            'stock_months_available'=> $stockMonths,
        ];
    }

    /**
     * Analyse with optional lifecycle enrichment.
     *
     * Returns the same result as analyze() but with an additional
     * 'lifecycle' key when the feature flag is enabled.
     *
     * @param  array<int, array<string, mixed>>  $listingRows
     * @param  float|null                        $subjectPrice
     * @param  float                             $annualAbsorption
     * @param  int|null                          $presentationId  Required for lifecycle enrichment
     *
     * @return array The standard analyze output, plus optional 'lifecycle' block
     */
    public function analyzeWithLifecycle(
        array $listingRows,
        ?float $subjectPrice,
        float $annualAbsorption,
        ?int $presentationId = null,
    ): array {
        $result = $this->analyze($listingRows, $subjectPrice, $annualAbsorption);

        if (
            $presentationId !== null
            && $this->lifecycleService !== null
            && config('features.listing_lifecycle_v1')
        ) {
            $churn = $this->lifecycleService->calculateChurnMetrics($presentationId);

            $result['lifecycle'] = [
                'dom_median'           => $churn['median_dom'],
                'stale_percentage'     => $churn['stale_percentage'],
                'avg_price_drop_percent' => $churn['avg_price_drop_percent'],
            ];
        }

        // ── Data quality enrichment (feature-flagged) ──────────────────
        if (
            $presentationId !== null
            && $this->dataQualityService !== null
            && config('features.listing_data_quality_v1')
        ) {
            $quality = $this->dataQualityService->evaluate($presentationId);

            $result['data_quality'] = [
                'avg_score'              => $quality['avg_data_quality_score'],
                'avg_merge_confidence'   => $quality['avg_merge_confidence'],
                'conflict_listing_count' => $quality['conflict_listing_count'],
            ];
        }

        return $result;
    }
}
