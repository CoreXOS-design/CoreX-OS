<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\Property;
use App\Models\PropertyPresentationSnapshot;
use App\Services\Presentations\CompPoolBuilder;
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
            // Same-listing-type gate — a rental is never a comparable for a
            // sale (mirrors PropertyIntelligenceService::getComparableListings).
            // Legacy rows with a NULL listing_type default to sale.
            ->where(function ($q) use ($property) {
                $subjectType = $property->listing_type ?? 'sale';
                $q->where('listing_type', $subjectType);
                if ($subjectType === 'sale') {
                    $q->orWhereNull('listing_type');
                }
            })
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
     *
     * Unified with the recommended-price pool: the canonical M9 sold-records
     * table (property_sold_records) is the primary source, legacy
     * presentation_sold_comps the fallback. Before this, Area Average read
     * presentation_sold_comps (a MEAN) while Recommended Price read
     * property_sold_records (a MEDIAN) — two different tables, structurally
     * irreconcilable on the same card. Both now cascade the same sources.
     */
    public function calculateAreaAverages(?string $suburb): array
    {
        if (!$suburb) return ['avg_price' => null, 'avg_dom' => null];

        // Primary: canonical M9 sold records (all types — this is a true
        // "area" figure, deliberately broader than the gated comp pool).
        $avgPrice = DB::table('property_sold_records')
            ->where('suburb', $suburb)
            ->where('sold_date', '>=', now()->subMonths(12))
            ->whereNotNull('sold_price')
            ->avg('sold_price');

        // Fallback: legacy per-presentation manual comps.
        if (!$avgPrice) {
            $avgPrice = DB::table('presentation_sold_comps')
                ->join('presentations', 'presentations.id', '=', 'presentation_sold_comps.presentation_id')
                ->where('presentation_sold_comps.suburb', $suburb)
                ->whereNull('presentation_sold_comps.deleted_at')
                ->where('presentation_sold_comps.sold_date', '>=', now()->subMonths(12))
                ->avg('presentation_sold_comps.sold_price_inc');
        }

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
     * Recommended price = the profile-gated market anchor for the subject, not
     * a raw suburb median.
     *
     * Before this, the recommendation was the plain median of up to 10
     * unfiltered same-suburb sold records — so a 7-bed R1.9M house was valued
     * off a pool of 1-bed flats and even a commercial shop, collapsing the
     * figure to ~R804k (the pre-AT-22 "R1.1M trap"). It now runs the sold
     * records through the canonical CompPoolBuilder (title-type hard-gate →
     * subject-anchored price band → radius ladder → divergence → rank),
     * anchored on the subject's asking price, and returns the gated pool's
     * robust median. Returns null when no genuine comparable resolves — an
     * honest "insufficient comparable sales" beats a misleading number.
     *
     * @param  mixed  $comparableSales  legacy 2nd arg (the display pool);
     *         retained for signature compatibility. The recommendation now
     *         derives from a broader candidate set built from $property and
     *         freshly gated, not from this (limited, ungated) collection.
     */
    public function calculateRecommendedPrice(Property $property, $comparableSales = null): ?float
    {
        $candidates = $this->gatedComparableCandidates($property);
        if (empty($candidates)) {
            return null;
        }

        $config  = CompPoolBuilder::configForAgency(Agency::find($property->agency_id));
        $subject = [
            'title_type'    => $property->title_type,
            'property_type' => $property->property_type,
            'lat'           => $property->latitude,
            'lng'           => $property->longitude,
            'erf_m2'        => $property->erf_size ?? null,
            // Anchor the price band on the subject's asking so an off-profile
            // pool cannot drag the recommendation down (AT-22 §1.5).
            'anchor_price'  => $property->price ? (int) $property->price : null,
            'address'       => $property->address,
        ];

        $anchor = (new CompPoolBuilder())->select($subject, $candidates, $config)['anchor'];

        return $anchor !== null ? (float) $anchor : null;
    }

    /**
     * Broad sold-comp candidate set for the gated recommendation. Primary
     * source property_sold_records (M9), 12-month window, ALL types — the
     * CompPoolBuilder gates by type/band, so we feed it broad and let it
     * narrow. Falls back to presentation_sold_comps when the M9 table has
     * nothing for the suburb (mirrors getComparableSales' source precedence).
     *
     * @return list<array>
     */
    private function gatedComparableCandidates(Property $property, int $rangeMonths = 12): array
    {
        $suburb = $property->suburb;
        if (!$suburb) {
            return [];
        }
        $since = now()->subMonths($rangeMonths);

        $rows = DB::table('property_sold_records')
            ->where('suburb', $suburb)
            ->where('sold_date', '>=', $since)
            ->where('id', '!=', $property->id)
            ->whereNotNull('sold_price')
            ->get(['sold_price', 'property_type', 'sqm', 'address']);

        if ($rows->isNotEmpty()) {
            return $rows->values()->map(fn ($r, $i) => [
                'key'           => $i,
                'price'         => (int) $r->sold_price,
                'size_m2'       => $r->sqm !== null ? (int) $r->sqm : null,
                'property_type' => $r->property_type,
                'title_type'    => null,
                'lat'           => null,
                'lng'           => null,
                'address'       => $r->address ?? null,
                'exempt'        => false,
            ])->all();
        }

        // Fallback: legacy per-presentation manual comps.
        $legacy = DB::table('presentation_sold_comps')
            ->join('presentations', 'presentations.id', '=', 'presentation_sold_comps.presentation_id')
            ->where('presentations.suburb', $suburb)
            ->where('presentations.agency_id', $property->agency_id)
            ->whereNull('presentation_sold_comps.deleted_at')
            ->where('presentation_sold_comps.sold_date', '>=', $since)
            ->whereNotNull('presentation_sold_comps.sold_price_inc')
            ->get([
                'presentation_sold_comps.sold_price_inc as sold_price',
                'presentation_sold_comps.size_m2 as sqm',
            ]);

        return $legacy->values()->map(fn ($r, $i) => [
            'key'           => $i,
            'price'         => (int) $r->sold_price,
            'size_m2'       => $r->sqm !== null ? (int) $r->sqm : null,
            'property_type' => null,
            'title_type'    => null,
            'lat'           => null,
            'lng'           => null,
            'address'       => null,
            'exempt'        => false,
        ])->all();
    }
}
