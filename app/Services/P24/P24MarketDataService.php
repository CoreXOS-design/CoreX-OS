<?php

namespace App\Services\P24;

use App\Models\P24Listing;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class P24MarketDataService
{
    /**
     * Get market stats for a specific suburb.
     */
    public function getSuburbStats(string $suburb, int $months = 6): array
    {
        $since = Carbon::now()->subMonths($months)->toDateString();

        $query = P24Listing::active()->inSuburb($suburb)->where('first_seen_date', '>=', $since);

        $listings = $query->get();

        if ($listings->isEmpty()) {
            return [
                'total_listings' => 0,
                'avg_price' => 0,
                'median_price' => 0,
                'min_price' => 0,
                'max_price' => 0,
                'new_listings_per_month' => [],
                'avg_days_on_market' => 0,
                'price_trend' => 'stable',
                'common_property_types' => [],
            ];
        }

        $prices = $listings->pluck('asking_price')->map(fn($p) => (float) $p)->sort()->values();

        // New listings per month
        $perMonth = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthDate = Carbon::now()->subMonths($i);
            $key = $monthDate->format('Y-m');
            $start = $monthDate->copy()->startOfMonth()->toDateString();
            $end = $monthDate->copy()->endOfMonth()->toDateString();

            $perMonth[$key] = $listings->filter(function ($l) use ($start, $end) {
                $d = $l->first_seen_date->toDateString();
                return $d >= $start && $d <= $end;
            })->count();
        }

        // Price trend: compare last 3 months avg vs previous 3 months avg
        $midpoint = Carbon::now()->subMonths(3)->toDateString();
        $recentPrices = $listings->filter(fn($l) => $l->first_seen_date->toDateString() >= $midpoint)
            ->pluck('asking_price')->map(fn($p) => (float) $p);
        $olderPrices = $listings->filter(fn($l) => $l->first_seen_date->toDateString() < $midpoint)
            ->pluck('asking_price')->map(fn($p) => (float) $p);

        $trend = 'stable';
        if ($recentPrices->count() >= 3 && $olderPrices->count() >= 3) {
            $recentAvg = $recentPrices->avg();
            $olderAvg = $olderPrices->avg();
            $changePct = (($recentAvg - $olderAvg) / $olderAvg) * 100;

            if ($changePct > 3) {
                $trend = 'up';
            } elseif ($changePct < -3) {
                $trend = 'down';
            }
        }

        return [
            'total_listings' => $listings->count(),
            'avg_price' => round($prices->avg(), 2),
            'median_price' => round($this->median($prices), 2),
            'min_price' => round($prices->min(), 2),
            'max_price' => round($prices->max(), 2),
            'new_listings_per_month' => $perMonth,
            'avg_days_on_market' => round($listings->avg('days_on_market') ?? 0),
            'price_trend' => $trend,
            'common_property_types' => $listings->groupBy('property_type')
                ->map(fn($group) => $group->count())
                ->sortDesc()
                ->toArray(),
        ];
    }

    /**
     * Get comparable listings in the same suburb within a price range.
     */
    public function getAreaComparables(string $suburb, float $price, float $range = 0.2): array
    {
        $min = $price * (1 - $range);
        $max = $price * (1 + $range);

        return P24Listing::active()
            ->inSuburb($suburb)
            ->inPriceRange($min, $max)
            ->orderBy('asking_price')
            ->get()
            ->toArray();
    }

    /**
     * Get price distribution by brackets for a suburb.
     */
    public function getPriceDistribution(string $suburb): array
    {
        $brackets = [
            'Under R1M' => [0, 999999],
            'R1M - R1.5M' => [1000000, 1499999],
            'R1.5M - R2M' => [1500000, 1999999],
            'R2M - R3M' => [2000000, 2999999],
            'R3M - R5M' => [3000000, 4999999],
            'R5M+' => [5000000, 999999999],
        ];

        $result = [];
        foreach ($brackets as $label => [$min, $max]) {
            $result[$label] = P24Listing::active()
                ->inSuburb($suburb)
                ->inPriceRange($min, $max)
                ->count();
        }

        return $result;
    }

    /**
     * Get overall market summary across all suburbs.
     */
    public function getMarketSummary(): array
    {
        $active = P24Listing::active();

        return [
            'total_active' => (clone $active)->count(),
            'total_tracked' => P24Listing::count(),
            'avg_price' => round((float) (clone $active)->avg('asking_price'), 2),
            'suburbs' => (clone $active)
                ->select('suburb', DB::raw('COUNT(*) as count'), DB::raw('AVG(asking_price) as avg_price'))
                ->groupBy('suburb')
                ->orderByDesc('count')
                ->get()
                ->toArray(),
            'by_type' => (clone $active)
                ->select('property_type', DB::raw('COUNT(*) as count'))
                ->groupBy('property_type')
                ->orderByDesc('count')
                ->get()
                ->toArray(),
        ];
    }

    private function median($sorted): float
    {
        $count = $sorted->count();
        if ($count === 0) {
            return 0;
        }

        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($sorted[$mid - 1] + $sorted[$mid]) / 2;
        }

        return $sorted[$mid];
    }
}
