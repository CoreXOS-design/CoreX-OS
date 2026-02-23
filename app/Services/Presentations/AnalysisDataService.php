<?php

namespace App\Services\Presentations;

use App\Models\PortalCapture;
use App\Models\Presentation;
use Illuminate\Support\Collection;

/**
 * Compiles all extracted presentation data into structured sections
 * for the analysis data-review display. All computations happen here,
 * NOT in Blade templates.
 */
class AnalysisDataService
{
    /**
     * Compile all extracted data into display-ready sections.
     *
     * Asking price is read from the presentation's asking_price_inc column.
     *
     * @param  Presentation  $presentation
     * @return array  Keyed by section name
     */
    public function compile(Presentation $presentation): array
    {
        $fields         = $presentation->fields->keyBy('field_key');
        $soldComps      = $presentation->soldComps()->with('sourceUpload')->get();
        $activeListings = $presentation->activeListings;
        $askingPrice    = $presentation->asking_price_inc;

        // Read agent selections from presentation record
        $cmaSelectedRange      = $presentation->cma_selected_range ?? 'middle';
        $vicinitySelectedRange = $presentation->vicinity_selected_range ?? 'middle';
        $excludedIndices       = $presentation->excluded_active_listing_indices ?? [];

        // Load portal captures for active competition (search captures contain listing data)
        $portalCaptures = PortalCapture::where('presentation_id', $presentation->id)
            ->where('parse_status', 'parsed')
            ->get();

        $activeCompetition = $this->compileActiveCompetition($activeListings, $portalCaptures);
        $activeCompetition = $this->applyExclusions($activeCompetition, $excludedIndices);

        $suburbOverview = $this->compileSuburbOverview($fields);
        $stockAbsorption = $this->compileStockAbsorption($portalCaptures, $activeCompetition, $suburbOverview);

        return [
            'subject_property'   => $this->compileSubjectProperty($presentation, $fields, $askingPrice),
            'suburb_overview'    => $suburbOverview,
            'comparable_sales'   => $this->compileComparableSales($soldComps),
            'cma_valuation'      => $this->compileCmaValuation($fields, $askingPrice, $cmaSelectedRange),
            'active_competition' => $activeCompetition,
            'stock_absorption'   => $stockAbsorption,
            'holding_cost'       => $this->compileHoldingCost($presentation),
            'key_insights'       => $this->compileKeyInsights($fields, $askingPrice, $cmaSelectedRange, $vicinitySelectedRange),
            'data_counts'        => [
                'fields'          => $fields->count(),
                'sold_comps'      => $soldComps->count(),
                'active_listings' => $activeCompetition['count'],
            ],
        ];
    }

    // ── 1. SUBJECT PROPERTY ──────────────────────────────────────────────

    private function compileSubjectProperty(Presentation $p, Collection $fields, ?int $askingPrice): array
    {
        return [
            'address'        => $fields->get('subject.address')?->final_value ?? $p->property_address,
            'suburb'         => $fields->get('subject.suburb')?->final_value ?? $p->suburb,
            'erf'            => $fields->get('subject.erf')?->final_value,
            'extent_m2'      => $this->intOrNull($fields->get('subject.extent_m2')?->final_value),
            'gps'            => $fields->get('subject.gps')?->final_value,
            'purchase_date'  => $fields->get('subject.purchase_date')?->final_value,
            'purchase_price' => $this->intOrNull($fields->get('subject.purchase_price')?->final_value),
            'indexed_value'  => $this->intOrNull($fields->get('subject.indexed_value')?->final_value),
            'cagr'           => $this->floatOrNull($fields->get('subject.cagr')?->final_value),
            'municipal_value' => $this->intOrNull($fields->get('municipal.total_value')?->final_value),
            'municipal_year' => $fields->get('municipal.valuation_year')?->final_value,
            'asking_price'   => $askingPrice,
            'bedrooms'       => $p->bedrooms,
            'property_type'  => $p->property_type,
            'monthly_holding_total' => $this->calcMonthlyHolding($p),
        ];
    }

    // ── 2. SUBURB OVERVIEW ───────────────────────────────────────────────

    private function compileSuburbOverview(Collection $fields): array
    {
        return [
            'latest_year'  => $fields->get('suburb.latest_year')?->final_value,
            'sales_count'  => $this->intOrNull($fields->get('suburb.latest_sales_count')?->final_value),
            'median_price' => $this->intOrNull($fields->get('suburb.latest_median_price')?->final_value),
            'low_range'    => $this->intOrNull($fields->get('suburb.latest_low')?->final_value),
            'high_range'   => $this->intOrNull($fields->get('suburb.latest_high')?->final_value),
            'max_price'    => $this->intOrNull($fields->get('suburb.latest_max')?->final_value),
        ];
    }

    // ── 3. COMPARABLE SALES ──────────────────────────────────────────────

    private function compileComparableSales(Collection $soldComps): array
    {
        $groups = [
            'vicinity'     => [],
            'cma_comps'    => [],
            'street_sales' => [],
        ];

        foreach ($soldComps as $comp) {
            $raw    = is_string($comp->raw_row_json) ? json_decode($comp->raw_row_json, true) : ($comp->raw_row_json ?? []);
            $source = $raw['source'] ?? 'unknown';
            $sizeM2 = $comp->size_m2 ?: ($raw['extent_m2'] ?? null);

            $row = [
                'address'      => $raw['address'] ?? null,
                'distance_m'   => $raw['distance_m'] ?? null,
                'erf_no'       => $raw['erf_no'] ?? null,
                'extent_m2'    => $sizeM2 ? (int) $sizeM2 : null,
                'sale_date'    => $comp->sold_date ? $comp->sold_date->format('Y/m/d') : null,
                'sale_price'   => $comp->sold_price_inc,
                'price_per_m2' => $raw['price_per_m2']
                    ?? ($sizeM2 > 0 && $comp->sold_price_inc > 0
                        ? (int) round($comp->sold_price_inc / $sizeM2)
                        : null),
            ];

            $key = match ($source) {
                'vicinity_sales' => 'vicinity',
                'cma_comps'      => 'cma_comps',
                'street_sales'   => 'street_sales',
                default          => 'vicinity',
            };

            $groups[$key][] = $row;
        }

        // Compute summary stats per group
        $result = [];
        foreach ($groups as $key => $rows) {
            $prices = array_filter(array_column($rows, 'sale_price'), fn($v) => $v > 0);
            $ppm2   = array_filter(array_column($rows, 'price_per_m2'), fn($v) => $v > 0);

            $result[$key] = [
                'rows'             => $rows,
                'count'            => count($rows),
                'avg_price'        => count($prices) > 0 ? (int) round(array_sum($prices) / count($prices)) : null,
                'avg_price_per_m2' => count($ppm2) > 0 ? (int) round(array_sum($ppm2) / count($ppm2)) : null,
            ];
        }

        return $result;
    }

    // ── 4. CMA VALUATION ─────────────────────────────────────────────────

    private function compileCmaValuation(Collection $fields, ?int $askingPrice, string $cmaSelectedRange = 'middle'): array
    {
        $lower  = $this->intOrNull($fields->get('cma.lower_range')?->final_value);
        $middle = $this->intOrNull($fields->get('cma.middle_range')?->final_value);
        $upper  = $this->intOrNull($fields->get('cma.upper_range')?->final_value);

        $vicinityLower  = $this->intOrNull($fields->get('vicinity.lower_range')?->final_value);
        $vicinityMiddle = $this->intOrNull($fields->get('vicinity.middle_range')?->final_value);
        $vicinityUpper  = $this->intOrNull($fields->get('vicinity.upper_range')?->final_value);
        $vicinityPpm2   = $this->intOrNull($fields->get('vicinity.avg_price_per_m2')?->final_value);

        $selectedValue = match($cmaSelectedRange) {
            'lower' => $lower,
            'upper' => $upper,
            default => $middle,
        };

        $askingVsCmaPct = null;
        if ($askingPrice && $selectedValue && $selectedValue > 0) {
            $askingVsCmaPct = round(($askingPrice - $selectedValue) / $selectedValue * 100, 1);
        }

        return [
            'cma_lower'          => $lower,
            'cma_middle'         => $middle,
            'cma_upper'          => $upper,
            'selected_range'     => $cmaSelectedRange,
            'selected_value'     => $selectedValue,
            'vicinity_lower'     => $vicinityLower,
            'vicinity_middle'    => $vicinityMiddle,
            'vicinity_upper'     => $vicinityUpper,
            'vicinity_ppm2'      => $vicinityPpm2,
            'asking_price'       => $askingPrice,
            'asking_vs_cma_pct'  => $askingVsCmaPct,
            'is_overpriced'      => $askingVsCmaPct !== null && $askingVsCmaPct > 10,
        ];
    }

    // ── 5. ACTIVE COMPETITION ────────────────────────────────────────────

    private function compileActiveCompetition(Collection $activeListings, Collection $portalCaptures): array
    {
        $rows = [];
        $seenKeys = []; // Dedup by external_key (P24 listing ID)

        // Pre-build a lookup of rich property data from individual property captures.
        // This lets search items be enriched with detail from property page captures.
        $propertyDetailLookup = [];
        foreach ($portalCaptures as $capture) {
            $fields = $capture->extracted_fields_json;
            if (empty($fields) || $capture->page_type !== 'property') continue;
            $listingId = $fields['listing_id'] ?? null;
            if ($listingId) {
                $propertyDetailLookup[$listingId] = $fields;
            }
        }

        // 1. Rows from presentation_active_listings (CMA/upload extraction)
        foreach ($activeListings as $al) {
            $raw = is_string($al->raw_row_json) ? json_decode($al->raw_row_json, true) : ($al->raw_row_json ?? []);
            $key = $al->external_key;
            if ($key) $seenKeys[$key] = true;

            $rows[] = [
                'address'        => $raw['address'] ?? null,
                'property_type'  => $raw['property_type'] ?? $al->property_type,
                'beds'           => $al->beds ?: ($raw['beds'] ?? null),
                'baths'          => $al->baths ?: ($raw['baths'] ?? null),
                'extent_m2'      => $al->size_m2 ?: ($raw['extent_m2'] ?? null),
                'list_date'      => $raw['list_date'] ?? ($al->listing_date ? $al->listing_date->format('Y/m/d') : null),
                'list_price'     => $al->list_price_inc,
                'days_on_market' => $raw['days_on_market'] ?? null,
                'url'            => $raw['url'] ?? null,
                'source'         => 'upload',
            ];
        }

        // 2. Property page captures first (rich data — bedrooms, bathrooms, suburb, etc.)
        foreach ($portalCaptures as $capture) {
            $fields = $capture->extracted_fields_json;
            if (empty($fields) || $capture->page_type !== 'property') continue;

            $listingId = $fields['listing_id'] ?? null;
            if ($listingId && isset($seenKeys[$listingId])) continue;
            if ($listingId) $seenKeys[$listingId] = true;

            $rows[] = [
                'address'        => $fields['title'] ?? $fields['suburb'] ?? null,
                'property_type'  => $fields['property_type'] ?? null,
                'beds'           => $fields['bedrooms'] ?? $fields['beds'] ?? null,
                'baths'          => $fields['bathrooms'] ?? $fields['baths'] ?? null,
                'extent_m2'      => $fields['erf_m2'] ?? $fields['floor_m2'] ?? null,
                'list_date'      => null,
                'list_price'     => $fields['price'] ?? $fields['asking_price'] ?? null,
                'days_on_market' => null,
                'url'            => $fields['url'] ?? $capture->source_url,
                'source'         => 'portal_listing',
            ];
        }

        // 3. Search page captures (listing arrays — enriched from property lookup when available)
        foreach ($portalCaptures as $capture) {
            $fields = $capture->extracted_fields_json;
            if (empty($fields) || $capture->page_type !== 'search') continue;
            if (empty($fields['search']['items'])) continue;

            foreach ($fields['search']['items'] as $item) {
                $listingId = $item['portal_listing_id'] ?? null;
                if ($listingId && isset($seenKeys[$listingId])) continue;
                if ($listingId) $seenKeys[$listingId] = true;

                // Enrich sparse search items from property detail lookup
                $detail = ($listingId && isset($propertyDetailLookup[$listingId]))
                    ? $propertyDetailLookup[$listingId]
                    : null;

                $rows[] = [
                    'address'        => $detail['title'] ?? $detail['suburb'] ?? $item['title'] ?? null,
                    'property_type'  => $detail['property_type'] ?? $item['property_type'] ?? null,
                    'beds'           => $detail['bedrooms'] ?? $item['beds'] ?? null,
                    'baths'          => $detail['bathrooms'] ?? $item['baths'] ?? null,
                    'extent_m2'      => $detail['erf_m2'] ?? $item['erf_m2'] ?? $item['size_m2'] ?? null,
                    'list_date'      => null,
                    'list_price'     => $item['price'] ?? $detail['price'] ?? null,
                    'days_on_market' => null,
                    'url'            => $item['url'] ?? $detail['url'] ?? null,
                    'source'         => 'portal_search',
                ];
            }
        }

        $prices = array_filter(array_column($rows, 'list_price'), fn($v) => $v > 0);

        return [
            'rows'             => $rows,
            'count'            => count($rows),
            'avg_asking_price' => count($prices) > 0 ? (int) round(array_sum($prices) / count($prices)) : null,
        ];
    }

    // ── 6. HOLDING COST ──────────────────────────────────────────────────

    private function compileHoldingCost(Presentation $p): array
    {
        $breakdown = [
            'Bond payment'    => (float) ($p->monthly_bond ?? 0),
            'Rates'           => (float) ($p->monthly_rates ?? 0),
            'Levies'          => (float) ($p->monthly_levies ?? 0),
            'Insurance'       => (float) ($p->monthly_insurance ?? 0),
            'Utilities'       => (float) ($p->monthly_utilities ?? 0),
            'Opportunity cost' => (float) ($p->monthly_opportunity_cost ?? 0),
        ];

        $monthly = array_sum($breakdown);

        return [
            'breakdown'      => $breakdown,
            'monthly_total'  => $monthly,
            'projected_3m'   => $monthly * 3,
            'projected_6m'   => $monthly * 6,
            'projected_12m'  => $monthly * 12,
        ];
    }

    // ── 7. KEY INSIGHTS ──────────────────────────────────────────────────

    private function compileKeyInsights(Collection $fields, ?int $askingPrice, string $cmaSelectedRange = 'middle', string $vicinitySelectedRange = 'middle'): array
    {
        if (!$askingPrice) {
            return ['asking_price_set' => false, 'comparisons' => []];
        }

        $cmaValue = match($cmaSelectedRange) {
            'lower' => $this->intOrNull($fields->get('cma.lower_range')?->final_value),
            'upper' => $this->intOrNull($fields->get('cma.upper_range')?->final_value),
            default => $this->intOrNull($fields->get('cma.middle_range')?->final_value),
        };

        $vicinityValue = match($vicinitySelectedRange) {
            'lower'  => $this->intOrNull($fields->get('vicinity.lower_range')?->final_value),
            'upper'  => $this->intOrNull($fields->get('vicinity.upper_range')?->final_value),
            default  => $this->intOrNull($fields->get('vicinity.middle_range')?->final_value)
                        ?? $this->intOrNull($fields->get('vicinity.average_price')?->final_value),
        };

        $benchmarks = [
            [
                'label'     => 'vs CMA Valuation (' . $cmaSelectedRange . ')',
                'benchmark' => $cmaValue,
                'thresholds' => ['warning' => 5, 'danger' => 15],
            ],
            [
                'label'     => 'vs Suburb Median',
                'benchmark' => $this->intOrNull($fields->get('suburb.latest_median_price')?->final_value),
                'thresholds' => ['warning' => 20, 'danger' => 50],
            ],
            [
                'label'     => 'vs Vicinity Range (' . $vicinitySelectedRange . ')',
                'benchmark' => $vicinityValue,
                'thresholds' => ['warning' => 10, 'danger' => 30],
            ],
        ];

        $comparisons = [];
        foreach ($benchmarks as $b) {
            if ($b['benchmark'] && $b['benchmark'] > 0) {
                $pct = round(($askingPrice - $b['benchmark']) / $b['benchmark'] * 100, 1);
                $comparisons[] = [
                    'label'          => $b['label'],
                    'asking'         => $askingPrice,
                    'benchmark'      => $b['benchmark'],
                    'pct_difference' => $pct,
                    'status'         => $pct > $b['thresholds']['danger'] ? 'danger'
                                     : ($pct > $b['thresholds']['warning'] ? 'warning' : 'ok'),
                ];
            }
        }

        return [
            'asking_price_set' => true,
            'asking_price'     => $askingPrice,
            'comparisons'      => $comparisons,
        ];
    }

    // ── EXCLUSIONS ─────────────────────────────────────────────────────────

    /**
     * Apply agent-selected exclusions to active competition rows.
     * Adds row_index + is_excluded flag to each row, recalculates count/avg from included rows only.
     */
    private function applyExclusions(array $competition, array $excludedIndices): array
    {
        $rows = $competition['rows'] ?? [];
        $taggedRows = [];
        $includedPrices = [];

        foreach ($rows as $i => $row) {
            $excluded = in_array($i, $excludedIndices, true);
            $row['row_index']   = $i;
            $row['is_excluded'] = $excluded;
            $taggedRows[] = $row;

            if (!$excluded && isset($row['list_price']) && $row['list_price'] > 0) {
                $includedPrices[] = $row['list_price'];
            }
        }

        return [
            'rows'             => $taggedRows,
            'total_count'      => count($taggedRows),
            'count'            => count($taggedRows) - count(array_filter($taggedRows, fn($r) => $r['is_excluded'])),
            'avg_asking_price' => count($includedPrices) > 0
                ? (int) round(array_sum($includedPrices) / count($includedPrices))
                : null,
        ];
    }

    // ── STOCK ABSORPTION ──────────────────────────────────────────────────

    /**
     * Compile stock absorption data from portal search captures + suburb sales.
     *
     * total_active_stock: highest search.total_count from portal captures (agent may have
     *   done a broader search), falling back to the count of extracted active listing rows.
     * annual_sales: from suburb.latest_sales_count (presentation_fields).
     *
     * Absorption labels:
     *   < 3 months:  "Seller's Market — Low stock, high demand" (green)
     *   3-6 months:  "Balanced Market" (amber)
     *   6-12 months: "Buyer's Market — High stock, price pressure" (orange)
     *   > 12 months: "Oversupplied — Significant price pressure" (red)
     */
    private function compileStockAbsorption(Collection $portalCaptures, array $activeCompetition, array $suburbOverview): array
    {
        // 1. Get total_active_stock from search capture total_count (use highest)
        $searchTotalCount = null;
        foreach ($portalCaptures as $capture) {
            $fields = $capture->extracted_fields_json;
            if (empty($fields) || ($capture->page_type !== 'search')) continue;
            $tc = $fields['search']['total_count'] ?? null;
            if ($tc !== null && (int) $tc > 0) {
                $tc = (int) $tc;
                if ($searchTotalCount === null || $tc > $searchTotalCount) {
                    $searchTotalCount = $tc;
                }
            }
        }

        // Fallback: count of extracted active listing rows (non-excluded)
        $extractedListingCount = $activeCompetition['count'] ?? 0;
        $totalActiveStock = $searchTotalCount ?? $extractedListingCount;
        $stockSource = $searchTotalCount !== null ? 'portal_search' : 'extracted_listings';

        // Count of listings with price data (from the extracted rows)
        $listingsWithPrice = 0;
        foreach (($activeCompetition['rows'] ?? []) as $row) {
            if (!empty($row['is_excluded'])) continue;
            if (isset($row['list_price']) && (int) $row['list_price'] > 0) {
                $listingsWithPrice++;
            }
        }

        // 2. Get annual sales from suburb overview
        $annualSales = $suburbOverview['sales_count'] ?? null;

        // 3. Calculate absorption metrics
        $monthlySales = null;
        $monthsOfSupply = null;
        $yearsOfSupply = null;
        $absorptionLabel = null;
        $absorptionColor = null;

        if ($annualSales !== null && $annualSales > 0 && $totalActiveStock > 0) {
            $monthlySales = round($annualSales / 12, 1);
            $monthsOfSupply = round($totalActiveStock / $monthlySales, 1);
            $yearsOfSupply = round($monthsOfSupply / 12, 1);

            if ($monthsOfSupply < 3) {
                $absorptionLabel = "Seller's Market — Low stock, high demand";
                $absorptionColor = 'green';
            } elseif ($monthsOfSupply <= 6) {
                $absorptionLabel = 'Balanced Market';
                $absorptionColor = 'amber';
            } elseif ($monthsOfSupply <= 12) {
                $absorptionLabel = "Buyer's Market — High stock, price pressure";
                $absorptionColor = 'orange';
            } else {
                $absorptionLabel = 'Oversupplied — Significant price pressure';
                $absorptionColor = 'red';
            }
        }

        return [
            'total_active_stock'    => $totalActiveStock,
            'search_total_count'    => $searchTotalCount,
            'extracted_listing_count' => $extractedListingCount,
            'listings_with_price'   => $listingsWithPrice,
            'stock_source'          => $stockSource,
            'annual_sales'          => $annualSales,
            'monthly_sales'         => $monthlySales,
            'months_of_supply'      => $monthsOfSupply,
            'years_of_supply'       => $yearsOfSupply,
            'absorption_label'      => $absorptionLabel,
            'absorption_color'      => $absorptionColor,
        ];
    }

    // ── HELPERS ───────────────────────────────────────────────────────────

    private function calcMonthlyHolding(Presentation $p): float
    {
        return (float) ($p->monthly_bond ?? 0)
             + (float) ($p->monthly_rates ?? 0)
             + (float) ($p->monthly_levies ?? 0)
             + (float) ($p->monthly_insurance ?? 0)
             + (float) ($p->monthly_utilities ?? 0)
             + (float) ($p->monthly_opportunity_cost ?? 0);
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value !== null && $value !== '' ? (float) $value : null;
    }
}
