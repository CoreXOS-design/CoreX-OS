<?php

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyPresentationSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Captures market-state snapshots for properties.
 * Phase 1: captures at presentation generation time.
 * Phase 2: captures weekly for active listings + auto-regens.
 */
class MarketDataSnapshotService
{
    /**
     * Capture a complete market snapshot for a property.
     */
    public function capturePropertySnapshot(int $propertyId, ?int $presentationId = null, ?int $userId = null): PropertyPresentationSnapshot
    {
        $property = Property::withoutGlobalScopes()->findOrFail($propertyId);

        $comparableSales = $this->getComparableSales($propertyId);
        $comparableListings = $this->getComparableListings($propertyId);
        $areaAvg = $this->calculateAreaAverages($property->suburb);
        $recommendedPrice = $this->calculateRecommendedPrice($property, $comparableSales);
        $dom = $property->published_at ? (int) $property->published_at->diffInDays(now()) : null;

        return PropertyPresentationSnapshot::create([
            'property_id' => $propertyId,
            'presentation_id' => $presentationId,
            'generated_at' => now(),
            'generated_by_user_id' => $userId,
            'market_data_snapshot' => [
                'comparable_sales' => $comparableSales->toArray(),
                'comparable_listings' => $comparableListings->toArray(),
                'area_average_price' => $areaAvg['avg_price'],
                'area_days_on_market' => $areaAvg['avg_dom'],
                'source_data_pulled_at' => now()->toIso8601String(),
            ],
            'recommended_price_at_time' => $recommendedPrice,
            'days_on_market_at_time' => $dom,
            'is_dynamic' => false,
        ]);
    }

    /**
     * Get recent comparable sales — queries property_sold_records (M9) first,
     * falls back to presentation_sold_comps (legacy CMA data).
     */
    public function getComparableSales(int $propertyId, int $rangeMonths = 6): \Illuminate\Support\Collection
    {
        $property = Property::withoutGlobalScopes()->find($propertyId);
        if (!$property) return collect();

        // Primary source: property_sold_records (M9 Phase 1)
        $soldRecords = DB::table('property_sold_records')
            ->where('suburb', $property->suburb)
            ->where('sold_date', '>=', now()->subMonths($rangeMonths))
            ->where('id', '!=', $propertyId) // exclude self
            ->orderByDesc('sold_date')
            ->limit(10)
            ->get(['suburb', 'sold_price as sold_price_inc', 'sold_date', 'bedrooms as beds', 'sqm as size_m2']);

        if ($soldRecords->isNotEmpty()) return $soldRecords;

        // Fallback: presentation_sold_comps (legacy)
        return DB::table('presentation_sold_comps')
            ->join('presentations', 'presentations.id', '=', 'presentation_sold_comps.presentation_id')
            ->where('presentations.suburb', $property->suburb)
            ->where('presentations.agency_id', $property->agency_id)
            ->whereNull('presentation_sold_comps.deleted_at')
            ->where('presentation_sold_comps.sold_date', '>=', now()->subMonths($rangeMonths))
            ->orderByDesc('presentation_sold_comps.sold_date')
            ->limit(10)
            ->get(['presentation_sold_comps.suburb', 'presentation_sold_comps.sold_price_inc', 'presentation_sold_comps.sold_date', 'presentation_sold_comps.beds', 'presentation_sold_comps.size_m2']);
    }

    /**
     * Get comparable active listings in same area.
     */
    public function getComparableListings(int $propertyId): \Illuminate\Support\Collection
    {
        $property = Property::withoutGlobalScopes()->find($propertyId);
        if (!$property) return collect();

        return Property::withoutGlobalScopes()
            ->where('id', '!=', $propertyId)
            ->where('agency_id', $property->agency_id)
            ->where('suburb', $property->suburb)
            ->whereNull('deleted_at')
            ->whereNotNull('published_at')
            ->limit(10)
            ->get(['id', 'title', 'price', 'suburb', 'published_at'])
            ->map(fn($p) => [
                'id' => $p->id,
                'address' => $p->title,
                'price' => $p->price,
                'days_on_market' => $p->published_at ? (int) $p->published_at->diffInDays(now()) : null,
            ]);
    }

    /**
     * Calculate area averages from sold data.
     */
    public function calculateAreaAverages(?string $suburb): array
    {
        if (!$suburb) return ['avg_price' => null, 'avg_dom' => null];

        $avgPrice = DB::table('presentation_sold_comps')
            ->join('presentations', 'presentations.id', '=', 'presentation_sold_comps.presentation_id')
            ->where('presentation_sold_comps.suburb', $suburb)
            ->whereNull('presentation_sold_comps.deleted_at')
            ->where('presentation_sold_comps.sold_date', '>=', now()->subMonths(12))
            ->avg('presentation_sold_comps.sold_price_inc');

        // Area days on market from active listings
        $avgDom = Property::withoutGlobalScopes()
            ->where('suburb', $suburb)
            ->whereNotNull('published_at')
            ->whereNull('deleted_at')
            ->get()
            ->avg(fn($p) => $p->published_at->diffInDays(now()));

        return [
            'avg_price' => $avgPrice ? round($avgPrice) : null,
            'avg_dom' => $avgDom ? (int) round($avgDom) : null,
        ];
    }

    /**
     * Phase 1: simple recommended price = median of comparable sales.
     */
    public function calculateRecommendedPrice(Property $property, $comparableSales): ?float
    {
        if ($comparableSales->isEmpty()) return null;
        $prices = $comparableSales->pluck('sold_price_inc')->filter()->sort()->values();
        if ($prices->isEmpty()) return null;

        $mid = (int) floor($prices->count() / 2);
        return $prices->count() % 2 === 0
            ? ($prices[$mid - 1] + $prices[$mid]) / 2
            : $prices[$mid];
    }
}
