<?php

namespace App\Services\Presentations\Evidence;

use App\Models\PresentationActiveListing;
use App\Models\PresentationListingPriceHistory;
use Carbon\Carbon;

/**
 * Computes listing lifecycle metrics: DOM, DOM buckets, and churn statistics.
 *
 * Deterministic only. No AI. No external I/O.
 */
class ListingLifecycleService
{
    /**
     * Calculate Days on Market for a single listing.
     *
     * DOM = days between first_seen_at and (is_active ? now() : last_seen_at)
     */
    public function calculateDom(PresentationActiveListing $listing): int
    {
        if ($listing->first_seen_at === null) {
            return 0;
        }

        $start = Carbon::parse($listing->first_seen_at)->startOfDay();
        $end   = $listing->is_active
            ? Carbon::now()->startOfDay()
            : ($listing->last_seen_at !== null
                ? Carbon::parse($listing->last_seen_at)->startOfDay()
                : $start);

        return max(0, $start->diffInDays($end));
    }

    /**
     * Map a DOM value to a human-readable bucket.
     */
    public function getDomBucket(int $dom): string
    {
        return match (true) {
            $dom <= 30  => 'fresh',
            $dom <= 60  => 'normal',
            $dom <= 120 => 'aging',
            default     => 'stale',
        };
    }

    /**
     * Calculate churn metrics for all active listings in a presentation.
     *
     * @return array{
     *   average_dom: float|null,
     *   median_dom: float|null,
     *   stale_percentage: float|null,
     *   price_reduction_percentage: float|null,
     *   avg_price_drop_percent: float|null
     * }
     */
    public function calculateChurnMetrics(int $presentationId): array
    {
        $listings = PresentationActiveListing::where('presentation_id', $presentationId)
            ->where('is_active', true)
            ->get();

        if ($listings->isEmpty()) {
            return [
                'average_dom'                => null,
                'median_dom'                 => null,
                'stale_percentage'           => null,
                'price_reduction_percentage' => null,
                'avg_price_drop_percent'     => null,
            ];
        }

        // ── DOM values ──────────────────────────────────────────────────
        $domValues = $listings->map(fn ($l) => $this->calculateDom($l))->values()->toArray();
        sort($domValues);

        $count      = count($domValues);
        $averageDom = array_sum($domValues) / $count;

        // Median
        $mid       = (int) floor($count / 2);
        $medianDom = ($count % 2 === 0)
            ? ($domValues[$mid - 1] + $domValues[$mid]) / 2.0
            : (float) $domValues[$mid];

        // Stale percentage (DOM > 120)
        $staleCount      = count(array_filter($domValues, fn (int $d) => $d > 120));
        $stalePercentage = round($staleCount / $count * 100, 2);

        // ── Price reduction metrics ─────────────────────────────────────
        $listingIds = $listings->pluck('id')->toArray();

        $priceDrops = [];
        foreach ($listingIds as $listingId) {
            $history = PresentationListingPriceHistory::where('active_listing_id', $listingId)
                ->orderBy('captured_at')
                ->get();

            if ($history->count() < 2) {
                continue;
            }

            $firstPrice = (int) $history->first()->price_inc;
            $lastPrice  = (int) $history->last()->price_inc;

            if ($lastPrice < $firstPrice && $firstPrice > 0) {
                $dropPercent  = round(($firstPrice - $lastPrice) / $firstPrice * 100, 2);
                $priceDrops[] = $dropPercent;
            }
        }

        $totalListings             = count($listingIds);
        $priceReductionPercentage  = $totalListings > 0
            ? round(count($priceDrops) / $totalListings * 100, 2)
            : null;
        $avgPriceDropPercent       = count($priceDrops) > 0
            ? round(array_sum($priceDrops) / count($priceDrops), 2)
            : null;

        return [
            'average_dom'                => round($averageDom, 2),
            'median_dom'                 => round($medianDom, 2),
            'stale_percentage'           => $stalePercentage,
            'price_reduction_percentage' => $priceReductionPercentage,
            'avg_price_drop_percent'     => $avgPriceDropPercent,
        ];
    }
}
