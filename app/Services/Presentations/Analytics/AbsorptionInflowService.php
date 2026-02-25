<?php

namespace App\Services\Presentations\Analytics;

use App\Models\P24Listing;
use App\Models\P24Suburb;
use App\Models\Presentation;
use App\Support\SuburbMapper;
use Carbon\Carbon;

/**
 * Calculates new listing inflow and adjusted absorption metrics
 * using P24 alert email import data.
 *
 * All math happens here — Blade only renders the result array.
 */
class AbsorptionInflowService
{
    /**
     * Compute inflow and absorption data for a presentation.
     *
     * @param  Presentation  $presentation
     * @param  array         $stockAbsorption  The existing stock_absorption data from AnalysisDataService
     * @return array
     */
    public function compute(Presentation $presentation, array $stockAbsorption): array
    {
        $suburb       = $presentation->suburb;
        $propertyType = $presentation->property_type;
        $askingPrice  = $presentation->asking_price_inc;

        // If no suburb or asking price, return empty state
        if (empty($suburb) || empty($askingPrice)) {
            return $this->emptyResult('Missing suburb or asking price on presentation.');
        }

        // Build list of target suburb names (presentation suburb + nearby suburbs via town mapping)
        $targetSuburbs = $this->resolveTargetSuburbs($suburb);
        $townLabel = SuburbMapper::townLabel($suburb);

        // Map presentation property_type to P24 property_type values
        $targetTypes = $this->mapPropertyTypes($propertyType);

        // Price range: ±25% of asking price
        $priceLow  = (int) round($askingPrice * 0.75);
        $priceHigh = (int) round($askingPrice * 1.25);

        // Count matching P24 listings by period
        $now = Carbon::now();
        $count7d  = $this->countMatching($targetSuburbs, $targetTypes, $priceLow, $priceHigh, $now->copy()->subDays(7));
        $count30d = $this->countMatching($targetSuburbs, $targetTypes, $priceLow, $priceHigh, $now->copy()->subDays(30));
        $count90d = $this->countMatching($targetSuburbs, $targetTypes, $priceLow, $priceHigh, $now->copy()->subDays(90));

        // Total P24 alert listings (unfiltered, for context)
        $totalP24Listings = P24Listing::count();

        // Pull existing absorption data
        $activeListings = $stockAbsorption['total_active_stock'] ?? 0;
        $monthlySales   = $stockAbsorption['monthly_sales'] ?? null;
        $annualSales    = $stockAbsorption['annual_sales'] ?? null;

        // Calculate new listing rate (monthly average from 90-day window)
        $newListingRate = $count90d > 0 ? round($count90d / 3, 1) : 0;

        // Net absorption
        $netAbsorption      = null;
        $stockTrend         = null;
        $adjustedMonths     = null;
        $monthlyProbability = null;
        $prob3Months        = null;
        $adjustedProb3      = null;
        $poolAfter3Months   = null;
        $narrative          = null;

        if ($monthlySales !== null && $monthlySales > 0) {
            $netAbsorption = round($monthlySales - $newListingRate, 1);

            if ($netAbsorption > 0.1) {
                $stockTrend = 'depleting';
            } elseif ($netAbsorption < -0.1) {
                $stockTrend = 'growing';
            } else {
                $stockTrend = 'stable';
            }

            // Adjusted months of supply (only meaningful if stock is depleting)
            if ($netAbsorption > 0 && $activeListings > 0) {
                $adjustedMonths = round($activeListings / $netAbsorption, 1);
            }

            // Selling probability
            if ($activeListings > 0) {
                $monthlyRate        = $monthlySales / $activeListings;
                $monthlyProbability = round($monthlyRate * 100, 1);
                $prob3Months        = round((1 - pow(1 - $monthlyRate, 3)) * 100, 1);

                // Adjusted 3-month probability (accounting for inflow growing the pool)
                if ($newListingRate > 0) {
                    // Average pool size over 3 months = active + (net_change * 1.5)
                    // net_change per month = new_listing_rate - sales_per_month
                    $netChangePerMonth = $newListingRate - $monthlySales;
                    $avgPool = $activeListings + ($netChangePerMonth * 1.5);
                    if ($avgPool > 0) {
                        $adjustedRate  = $monthlySales / $avgPool;
                        $adjustedProb3 = round((1 - pow(1 - $adjustedRate, 3)) * 100, 1);
                        // Clamp to 0-100
                        $adjustedProb3 = max(0, min(100, $adjustedProb3));
                    }
                }
            }

            // Pool projection after 3 months
            if ($activeListings > 0) {
                $poolAfter3Months = (int) round($activeListings + ($newListingRate * 3) - ($monthlySales * 3));
                if ($poolAfter3Months < 0) {
                    $poolAfter3Months = 0;
                }
            }

            // Generate narrative
            $narrative = $this->generateNarrative(
                $stockTrend, $activeListings, $monthlySales, $newListingRate,
                $netAbsorption, $poolAfter3Months, $adjustedMonths
            );
        }

        return [
            'has_data'               => true,
            'total_p24_listings'     => $totalP24Listings,
            'target_suburbs'         => $targetSuburbs,
            'town_label'             => $townLabel,
            'target_types'           => $targetTypes,
            'price_range'            => ['low' => $priceLow, 'high' => $priceHigh],
            'count_7d'               => $count7d,
            'count_30d'              => $count30d,
            'count_90d'              => $count90d,
            'new_listing_rate'       => $newListingRate,
            'active_listings'        => $activeListings,
            'monthly_sales'          => $monthlySales,
            'annual_sales'           => $annualSales,
            'net_absorption'         => $netAbsorption,
            'stock_trend'            => $stockTrend,
            'adjusted_months_supply' => $adjustedMonths,
            'monthly_probability'    => $monthlyProbability,
            'prob_3_months'          => $prob3Months,
            'adjusted_prob_3_months' => $adjustedProb3,
            'pool_after_3_months'    => $poolAfter3Months,
            'narrative'              => $narrative,
        ];
    }

    /**
     * Count P24 listings matching the criteria since a given date.
     */
    private function countMatching(array $suburbs, array $types, int $priceLow, int $priceHigh, Carbon $since): int
    {
        $query = P24Listing::where('first_seen_date', '>=', $since)
            ->whereBetween('asking_price', [$priceLow, $priceHigh]);

        // Suburb filter: match where suburb is populated and in target list
        if (!empty($suburbs)) {
            $query->where(function ($q) use ($suburbs) {
                foreach ($suburbs as $s) {
                    $q->orWhereRaw('LOWER(suburb) = ?', [strtolower($s)]);
                }
            });
        }

        // Property type filter
        if (!empty($types)) {
            $query->where(function ($q) use ($types) {
                foreach ($types as $t) {
                    $q->orWhereRaw('LOWER(property_type) = ?', [strtolower($t)]);
                }
            });
        }

        return $query->count();
    }

    /**
     * Resolve target suburbs using town-level expansion (SuburbMapper first, P24Suburb fallback).
     *
     * SuburbMapper gives us all suburbs in the same town (e.g., Manaba Beach → all Margate-area suburbs).
     * P24Suburb provides surrounding suburbs from portal data as a secondary source.
     */
    private function resolveTargetSuburbs(string $suburb): array
    {
        // Primary: town-level expansion via SuburbMapper
        $targets = SuburbMapper::expandToTownArea($suburb);

        // Secondary: P24 surrounding suburbs (may include areas the mapper doesn't have)
        $record = P24Suburb::lookup($suburb);
        if ($record) {
            $surroundingIds = $record->surrounding_ids;
            if (!empty($surroundingIds) && is_array($surroundingIds)) {
                $p24Surrounding = P24Suburb::whereIn('p24_id', $surroundingIds)->pluck('name')->toArray();
                $targets = array_merge($targets, $p24Surrounding);
            }
        }

        return array_unique($targets);
    }

    /**
     * Map presentation property_type to P24 property_type values.
     *
     * Presentation uses: house, townhouse, apartment, duplex, vacant_land, farm, other
     * P24 uses: House, Townhouse, Apartment, Farm, Vacant Land
     */
    private function mapPropertyTypes(?string $presentationType): array
    {
        if (empty($presentationType)) {
            return [];
        }

        return match (strtolower($presentationType)) {
            'house'       => ['House'],
            'townhouse'   => ['Townhouse'],
            'apartment'   => ['Apartment'],
            'duplex'      => ['Townhouse', 'Apartment'], // duplex often listed as either
            'vacant_land' => ['Vacant Land'],
            'farm'        => ['Farm'],
            default       => [], // 'other' — don't filter by type
        };
    }

    /**
     * Generate a narrative insight based on the absorption data.
     */
    private function generateNarrative(
        string $stockTrend,
        int $activeListings,
        float $monthlySales,
        float $newListingRate,
        float $netAbsorption,
        ?int $poolAfter3Months,
        ?float $adjustedMonths,
    ): string {
        if ($stockTrend === 'growing') {
            $msg = "Competition is increasing — {$newListingRate} new similar listings per month vs {$monthlySales} sales.";
            if ($poolAfter3Months !== null) {
                $msg .= " After 3 months, you'll be competing against ~{$poolAfter3Months} properties.";
            }
            $msg .= ' Pricing competitively now gives you the best chance of selling before the pool grows further.';
            return $msg;
        }

        if ($stockTrend === 'depleting') {
            $msg = "Market is absorbing stock faster than new listings arrive.";
            $msg .= " Net depletion rate: {$netAbsorption} properties per month.";
            if ($adjustedMonths !== null) {
                $msg .= " At this rate, current stock will clear in ~{$adjustedMonths} months.";
            }
            return $msg;
        }

        // stable
        return "New listing inflow roughly matches the sales rate — stock levels are stable at ~{$activeListings} properties. "
             . "Pricing within the market range remains important to stand out.";
    }

    /**
     * Return an empty result when data is insufficient.
     */
    private function emptyResult(string $reason): array
    {
        return [
            'has_data'               => false,
            'reason'                 => $reason,
            'total_p24_listings'     => P24Listing::count(),
            'target_suburbs'         => [],
            'target_types'           => [],
            'price_range'            => null,
            'count_7d'               => 0,
            'count_30d'              => 0,
            'count_90d'              => 0,
            'new_listing_rate'       => 0,
            'active_listings'        => 0,
            'monthly_sales'          => null,
            'annual_sales'           => null,
            'net_absorption'         => null,
            'stock_trend'            => null,
            'adjusted_months_supply' => null,
            'monthly_probability'    => null,
            'prob_3_months'          => null,
            'adjusted_prob_3_months' => null,
            'pool_after_3_months'    => null,
            'narrative'              => null,
        ];
    }
}
