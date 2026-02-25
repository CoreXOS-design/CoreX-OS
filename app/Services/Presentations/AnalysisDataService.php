<?php

namespace App\Services\Presentations;

use App\Models\PortalCapture;
use App\Models\Presentation;
use App\Services\Presentations\Analytics\AbsorptionInflowService;
use App\Services\Presentations\Analytics\PropConInsightsService;
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

        // P24 alert email inflow analysis
        $inflowAbsorption = (new AbsorptionInflowService())->compute($presentation, $stockAbsorption);

        // PropCon listing performance insights
        $propconInsights = (new PropConInsightsService())->compute($presentation);

        // Detect sectional title from extracted fields or presentation property_type
        $isSectional = ($fields->get('vicinity.property_type')?->final_value === 'sectional')
            || stripos($presentation->property_type ?? '', 'sectional') !== false;

        return [
            'subject_property'   => $this->compileSubjectProperty($presentation, $fields, $askingPrice),
            'suburb_overview'    => $suburbOverview,
            'comparable_sales'   => $this->compileComparableSales($soldComps, $presentation->property_address),
            'cma_valuation'      => $this->compileCmaValuation($fields, $askingPrice, $cmaSelectedRange),
            'active_competition' => $activeCompetition,
            'stock_absorption'   => $stockAbsorption,
            'inflow_absorption'  => $inflowAbsorption,
            'propcon_insights'   => $propconInsights,
            'price_position'     => $this->compilePricePosition($activeCompetition, $askingPrice),
            'price_brackets'     => $this->compilePriceBrackets($activeCompetition, $askingPrice),
            'holding_cost'       => $this->compileHoldingCost($presentation),
            'key_insights'       => $this->compileKeyInsights($fields, $askingPrice, $cmaSelectedRange, $vicinitySelectedRange),
            'is_sectional'       => $isSectional,
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

    private function compileComparableSales(Collection $soldComps, ?string $subjectAddress = null): array
    {
        $groups = [
            'vicinity'     => [],
            'cma_comps'    => [],
            'street_sales' => [],
        ];

        // Normalise subject address for exclusion matching
        $normalSubject = $subjectAddress ? strtolower(trim($subjectAddress)) : null;

        foreach ($soldComps as $comp) {
            $raw    = is_string($comp->raw_row_json) ? json_decode($comp->raw_row_json, true) : ($comp->raw_row_json ?? []);
            $source = $raw['source'] ?? 'unknown';
            $sizeM2 = $comp->size_m2 ?: ($raw['extent_m2'] ?? null);

            $rowAddress = $raw['address'] ?? null;

            // Exclude subject property from comps
            if ($normalSubject && $rowAddress && str_contains(strtolower(trim($rowAddress)), $normalSubject)) {
                continue;
            }

            $row = [
                'address'      => $rowAddress,
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
                'vicinity_sales', 'vicinity_sales_sectional' => 'vicinity',
                'cma_comps'      => 'cma_comps',
                'street_sales'   => 'street_sales',
                default          => 'vicinity',
            };

            $groups[$key][] = $row;
        }

        // Dedup across source groups: same address + sale_date + sale_price = same row
        $allRows = [];
        foreach ($groups as $key => $rows) {
            foreach ($rows as $row) {
                $allRows[] = array_merge($row, ['_source' => $key]);
            }
        }
        $allRows = $this->deduplicateComps($allRows);

        // Re-split into groups after dedup
        $groups = ['vicinity' => [], 'cma_comps' => [], 'street_sales' => []];
        foreach ($allRows as $row) {
            $src = $row['_source'];
            unset($row['_source']);
            $groups[$src][] = $row;
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

    /**
     * Dedup comparable sale rows: if two rows have the same address + sale_date + sale_price,
     * keep only the one with more data fields populated.
     */
    private function deduplicateComps(array $rows): array
    {
        $seen = [];   // dedupKey => index in $result
        $result = [];

        foreach ($rows as $row) {
            $addr  = strtolower(trim($row['address'] ?? ''));
            $date  = $row['sale_date'] ?? '';
            $price = (int) ($row['sale_price'] ?? 0);

            // Can't dedup without address
            if ($addr === '') {
                $result[] = $row;
                continue;
            }

            $dedupKey = $addr . '|' . $date . '|' . $price;

            if (isset($seen[$dedupKey])) {
                // Keep the one with more populated fields
                $existingIdx = $seen[$dedupKey];
                if ($this->compRowDataScore($row) > $this->compRowDataScore($result[$existingIdx])) {
                    $result[$existingIdx] = $row;
                }
                continue;
            }

            $seen[$dedupKey] = count($result);
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Score comp row data richness for dedup preference.
     */
    private function compRowDataScore(array $row): int
    {
        $score = 0;
        if (!empty($row['sale_price']))   $score += 3;
        if (!empty($row['address']))      $score += 2;
        if (!empty($row['distance_m']))   $score++;
        if (!empty($row['extent_m2']))    $score++;
        if (!empty($row['price_per_m2'])) $score++;
        if (!empty($row['erf_no']))       $score++;
        return $score;
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

        // Check if we have portal captures that provide richer data
        $hasPortalData = $portalCaptures->where('parse_status', 'parsed')->count() > 0;

        // 1. Rows from presentation_active_listings (CMA/upload extraction)
        // If portal captures exist, skip upload rows that have no address (they'd show as blanks)
        foreach ($activeListings as $al) {
            $raw = is_string($al->raw_row_json) ? json_decode($al->raw_row_json, true) : ($al->raw_row_json ?? []);
            $key = $al->external_key;
            $address = $raw['address'] ?? null;

            // Skip rows without address if portal data is available (prefer richer data)
            if ($hasPortalData && empty($address) && empty($raw['property_type'])) {
                continue;
            }

            if ($key) $seenKeys[$key] = true;

            $rows[] = [
                'address'        => $address,
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

                // Build address: prefer p24_address ("20 Broadway St") over title ("4 Bed House")
                $itemAddress = $item['address'] ?? null;
                $itemLocation = $item['location'] ?? null;
                $displayAddress = $detail['title'] ?? $detail['suburb'] ?? null;
                if ($itemAddress && $itemLocation) {
                    $displayAddress = $itemAddress . ', ' . $itemLocation;
                } elseif ($itemAddress) {
                    $displayAddress = $itemAddress;
                } elseif (!$displayAddress) {
                    $displayAddress = $item['title'] ?? $itemLocation ?? null;
                }

                $rows[] = [
                    'address'        => $displayAddress,
                    'property_type'  => $detail['property_type'] ?? $item['title'] ?? null,
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

        $rawListingCount = count($rows);

        // Deduplicate by physical property: P24 shows the same property listed by
        // multiple agencies as separate search results with different listing IDs.
        // Group by price + erf_m2 (with 10% erf tolerance) to collapse these.
        $rows = $this->deduplicateByProperty($rows);

        $prices = array_filter(array_column($rows, 'list_price'), fn($v) => $v > 0);

        return [
            'rows'              => $rows,
            'count'             => count($rows),
            'raw_listing_count' => $rawListingCount,
            'avg_asking_price'  => count($prices) > 0 ? (int) round(array_sum($prices) / count($prices)) : null,
        ];
    }

    // ── PROPERTY DEDUPLICATION ──────────────────────────────────────────

    /**
     * Deduplicate active competition rows by physical property.
     *
     * P24 groups the same physical property listed by multiple agencies as
     * separate search results, each with a unique P24 listing ID. This inflates
     * the listing count vs P24's stated total_count (which counts unique properties).
     *
     * Strategy: group rows where price matches exactly AND erf_m2 is within 10%
     * tolerance. Keep the most data-rich representative per group.
     */
    private function deduplicateByProperty(array $rows): array
    {
        if (count($rows) <= 1) {
            return $rows;
        }

        $groups = [];    // group_index => [row_indices]
        $groupReps = []; // group_index => representative row

        foreach ($rows as $idx => $row) {
            $price = (int) ($row['list_price'] ?? 0);
            $erf = (int) ($row['extent_m2'] ?? 0);

            // Rows without a price can't be reliably grouped — keep as-is
            if ($price <= 0) {
                $gIdx = count($groups);
                $groups[$gIdx] = [$idx];
                $groupReps[$gIdx] = $row;
                continue;
            }

            $foundGroup = null;
            foreach ($groupReps as $gIdx => $rep) {
                $repPrice = (int) ($rep['list_price'] ?? 0);
                $repErf = (int) ($rep['extent_m2'] ?? 0);

                if ($price === $repPrice && $this->erfsMatch($erf, $repErf)) {
                    $foundGroup = $gIdx;
                    break;
                }
            }

            if ($foundGroup !== null) {
                $groups[$foundGroup][] = $idx;
                // Keep the row with the most populated fields as representative
                if ($this->rowDataScore($row) > $this->rowDataScore($groupReps[$foundGroup])) {
                    $groupReps[$foundGroup] = $row;
                }
            } else {
                $gIdx = count($groups);
                $groups[$gIdx] = [$idx];
                $groupReps[$gIdx] = $row;
            }
        }

        // Build deduped output — one row per physical property
        $deduped = [];
        foreach ($groups as $gIdx => $indices) {
            $rep = $groupReps[$gIdx];
            $rep['property_group_id'] = $gIdx;
            $rep['listing_ids_in_group'] = count($indices);
            $rep['is_multi_agency'] = count($indices) > 1;
            $deduped[] = $rep;
        }

        return $deduped;
    }

    /**
     * Check if two erf_m2 values match within 10% tolerance.
     * Both must be non-zero — if either is missing, we can't confirm same property.
     */
    private function erfsMatch(int $a, int $b): bool
    {
        if ($a <= 0 || $b <= 0) return false;
        $max = max($a, $b);
        return abs($a - $b) / $max <= 0.10;
    }

    /**
     * Score how much useful data a row has (for choosing best representative).
     */
    private function rowDataScore(array $row): int
    {
        $score = 0;
        if (!empty($row['list_price'])) $score += 3;
        if (!empty($row['address']))    $score += 2;
        if (!empty($row['beds']))       $score++;
        if (!empty($row['baths']))      $score++;
        if (!empty($row['extent_m2']))  $score++;
        if (!empty($row['url']))        $score++;
        if (!empty($row['property_type'])) $score++;
        return $score;
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
            'rows'              => $taggedRows,
            'total_count'       => count($taggedRows),
            'count'             => count($taggedRows) - count(array_filter($taggedRows, fn($r) => $r['is_excluded'])),
            'raw_listing_count' => $competition['raw_listing_count'] ?? count($taggedRows),
            'avg_asking_price'  => count($includedPrices) > 0
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

    // ── PRICE POSITION ─────────────────────────────────────────────────

    /**
     * Rank the asking price among active competition listings.
     *
     * Returns price_rank (1 = most expensive), total_listings, counts cheaper/more expensive,
     * and price_percentile (100 = most expensive).
     */
    private function compilePricePosition(array $activeCompetition, ?int $askingPrice): array
    {
        if (!$askingPrice || $askingPrice <= 0) {
            return ['has_data' => false, 'reason' => 'no_asking_price'];
        }

        // Collect non-excluded listing prices
        $prices = [];
        foreach (($activeCompetition['rows'] ?? []) as $row) {
            if (!empty($row['is_excluded'])) continue;
            if (isset($row['list_price']) && (int) $row['list_price'] > 0) {
                $prices[] = (int) $row['list_price'];
            }
        }

        if (count($prices) === 0) {
            return ['has_data' => false, 'reason' => 'no_priced_listings'];
        }

        // Sort descending (rank 1 = most expensive)
        rsort($prices);

        // Count how many are cheaper / more expensive than asking price
        $cheaper = 0;
        $moreExpensive = 0;
        $samePrice = 0;
        foreach ($prices as $p) {
            if ($p > $askingPrice) {
                $moreExpensive++;
            } elseif ($p < $askingPrice) {
                $cheaper++;
            } else {
                $samePrice++;
            }
        }

        // Rank: count of listings priced higher + 1
        $rank = $moreExpensive + 1;
        $total = count($prices);

        // Percentile: % of listings priced lower (0 = cheapest, 100 = most expensive)
        $percentile = round(($cheaper / $total) * 100);

        // Position label
        if ($percentile >= 80) {
            $positionLabel = 'Near the top — priced higher than most competition';
            $positionColor = 'red';
        } elseif ($percentile >= 60) {
            $positionLabel = 'Upper range — priced above average competition';
            $positionColor = 'orange';
        } elseif ($percentile >= 40) {
            $positionLabel = 'Mid-range — competitively positioned';
            $positionColor = 'amber';
        } elseif ($percentile >= 20) {
            $positionLabel = 'Lower range — priced below most competition';
            $positionColor = 'green';
        } else {
            $positionLabel = 'Near the bottom — aggressive pricing';
            $positionColor = 'green';
        }

        return [
            'has_data'           => true,
            'price_rank'         => $rank,
            'total_listings'     => $total,
            'listings_cheaper'   => $cheaper,
            'listings_more_expensive' => $moreExpensive,
            'listings_same_price' => $samePrice,
            'price_percentile'   => $percentile,
            'position_label'     => $positionLabel,
            'position_color'     => $positionColor,
            'asking_price'       => $askingPrice,
        ];
    }

    // ── PRICE BRACKETS ─────────────────────────────────────────────────

    /**
     * Group active competition listings into R500K price brackets.
     *
     * Returns an array of brackets, each with range label, count, and whether
     * the asking price falls in that bracket.
     */
    private function compilePriceBrackets(array $activeCompetition, ?int $askingPrice): array
    {
        // Collect non-excluded listing prices
        $prices = [];
        foreach (($activeCompetition['rows'] ?? []) as $row) {
            if (!empty($row['is_excluded'])) continue;
            if (isset($row['list_price']) && (int) $row['list_price'] > 0) {
                $prices[] = (int) $row['list_price'];
            }
        }

        if (count($prices) === 0) {
            return ['has_data' => false, 'brackets' => []];
        }

        $minPrice = min($prices);
        $maxPrice = max($prices);
        $ceiling  = max($maxPrice, $askingPrice ?? 0);
        $floor    = min($minPrice, $askingPrice ?? $minPrice);

        // Adaptive bracket sizing: divide range into ~6 equal brackets, round to clean boundaries
        $range = $ceiling - $floor;
        if ($range <= 0) {
            $range = $ceiling > 0 ? $ceiling * 0.5 : 500000;
        }

        // Choose bracket size based on price range
        if ($range < 1000000) {
            $bracketSize = 100000;      // R100k for small ranges
        } elseif ($range < 3000000) {
            $bracketSize = 250000;      // R250k for medium ranges
        } elseif ($range < 10000000) {
            $bracketSize = 500000;      // R500k for larger ranges
        } else {
            $bracketSize = 1000000;     // R1M for very large ranges
        }

        // Ensure we get 5-8 brackets; adjust if needed
        $rawBracketCount = (int) ceil($range / $bracketSize);
        if ($rawBracketCount < 4 && $bracketSize > 50000) {
            $bracketSize = max(50000, (int) (ceil($range / 6 / 50000) * 50000));
        } elseif ($rawBracketCount > 10) {
            $bracketSize = (int) (ceil($range / 7 / 100000) * 100000);
            if ($bracketSize < 100000) $bracketSize = 100000;
        }

        // Start from a clean boundary below the minimum price
        $startFrom = (int) (floor($floor / $bracketSize) * $bracketSize);
        $numBrackets = (int) ceil(($ceiling - $startFrom) / $bracketSize);
        if ($numBrackets < 1) $numBrackets = 1;

        $askingBracketIdx = null;

        // Build brackets
        $brackets = [];
        foreach ($prices as $p) {
            $idx = min((int) floor(($p - $startFrom) / $bracketSize), $numBrackets - 1);
            if ($idx < 0) $idx = 0;
            if (!isset($brackets[$idx])) {
                $brackets[$idx] = 0;
            }
            $brackets[$idx]++;
        }

        // Determine which bracket the asking price falls in
        if ($askingPrice && $askingPrice > 0) {
            $askingBracketIdx = min((int) floor(($askingPrice - $startFrom) / $bracketSize), $numBrackets - 1);
            if ($askingBracketIdx < 0) $askingBracketIdx = 0;
        }

        // Build the result array
        $result = [];
        $maxCount = max(array_values($brackets + [0 => 1])); // for bar width calc
        for ($i = 0; $i < $numBrackets; $i++) {
            $lower = $startFrom + ($i * $bracketSize);
            $upper = $startFrom + (($i + 1) * $bracketSize);
            $count = $brackets[$i] ?? 0;

            // Only include brackets that have listings OR contain the asking price
            if ($count === 0 && $i !== $askingBracketIdx) continue;

            $result[] = [
                'lower'           => $lower,
                'upper'           => $upper,
                'label'           => 'R ' . number_format($lower, 0, '.', ' ') . ' – R ' . number_format($upper, 0, '.', ' '),
                'count'           => $count,
                'bar_pct'         => $maxCount > 0 ? round($count / $maxCount * 100) : 0,
                'contains_asking' => $i === $askingBracketIdx,
            ];
        }

        return [
            'has_data'       => true,
            'brackets'       => $result,
            'total_priced'   => count($prices),
            'bracket_size'   => $bracketSize,
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
