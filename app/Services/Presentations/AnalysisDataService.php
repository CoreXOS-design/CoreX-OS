<?php

namespace App\Services\Presentations;

use App\Models\PortalCapture;
use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Services\Presentations\Analytics\AbsorptionInflowService;
use App\Support\MarketAnalytics\OutlierGuard;
use App\Support\Presentations\CompLabel;
use Illuminate\Support\Collection;

/**
 * Compiles all extracted presentation data into structured sections
 * for the analysis data-review display. All computations happen here,
 * NOT in Blade templates.
 */
class AnalysisDataService
{
    // ── CMA headline — size-normalised median-floored blend (STEP 2a) ─────
    // The headline is the median of comparable sold prices, lifted toward the
    // size-normalised value (median R/m² × subject extent) ONLY when it is safe
    // to do so. These knobs are deliberately tunable — Johan calibrates them.
    //
    //   TRUST band  — the blend is applied only when the subject extent and the
    //                 comp pool share a size basis, proxied by the size-basis
    //                 ratio (subject extent ÷ median comp size) sitting in band.
    //                 Below the floor / above the ceiling the extrapolation is
    //                 untrustworthy (sectional units vs a full erf, or a subject
    //                 so much larger than the comps that flat R/m² over-values
    //                 it) → the headline stays the plain median, UNCHANGED.
    //   LIFT ramp   — how strongly the size-normalised value pulls the headline
    //                 up, as a function of how far it exceeds the median. Zero at
    //                 the LOW divergence (so already-sane presentations do not
    //                 move) ramping to full lift at HIGH (a clear under-valuation
    //                 like a much-larger-than-comps stand gets its full uplift).
    private const BLEND_TRUST_RATIO_MIN = 0.4;
    private const BLEND_TRUST_RATIO_MAX = 1.6;
    private const BLEND_LIFT_LOW_PCT    = 30.0;
    private const BLEND_LIFT_HIGH_PCT   = 60.0;

    // Guardrail (STEP 2b) — flag when the headline still can't be trusted.
    private const GUARDRAIL_BASIS_RATIO_HIGH = 2.5;
    private const GUARDRAIL_BASIS_RATIO_LOW  = 0.4;
    private const GUARDRAIL_DIVERGENCE_PCT   = 30.0;

    /**
     * Compile all extracted data into display-ready sections.
     *
     * Asking price is read from the presentation's asking_price_inc column.
     *
     * @param  Presentation  $presentation
     * @return array  Keyed by section name
     */
    public function compile(Presentation $presentation, ?\App\Models\PresentationVersion $version = null): array
    {
        $fields         = $presentation->fields->keyBy('field_key');
        $soldComps      = $presentation->soldComps()->with('sourceUpload')->get();
        $activeListings = $presentation->activeListings;
        $askingPrice    = $presentation->asking_price_inc;

        // Read agent selections from presentation record
        $cmaSelectedRange      = $presentation->cma_selected_range ?? 'middle';
        $vicinitySelectedRange = $presentation->vicinity_selected_range ?? 'middle';
        $excludedIndices       = $presentation->excluded_active_listing_indices ?? [];

        // Build 3 — condition adjustment context. The version (if
        // supplied) is passed through so compileCmaValuation can resolve
        // override > property > none. When compile() is called without
        // a version (e.g. the pre-version analysis tab), baseline only.
        $conditionContext = $this->resolveConditionContext($presentation, $version);

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

        // Build 7 — sectional check reads title_type (keystone single
        // source of truth). Vicinity-field hint stays as a fallback
        // for legacy presentations whose property row pre-dates the
        // keystone backfill — the saved hint is still honest there.
        $isSectional = ($presentation->property?->title_type === 'sectional_title')
            || ($fields->get('vicinity.property_type')?->final_value === 'sectional');

        // Build 8a + tick-wire fix — CoreX's independent CMA compute engine
        // now honours the version's included_comp_ids_json whitelist so
        // the agent's tick UI flows into the computed bands. Mirrors the
        // controller's compRows-path filter (PresentationReviewController
        // L78-79) so display state (checkbox visual) and compute state
        // (the engine's pool) cannot diverge.
        //
        // Whitelist semantics:
        //   null  → no opinion yet — use ALL loaded comps (default).
        //   []    → agent has explicitly unticked everything — empty pool,
        //           tiles fall to null and render '—'.
        //   [ids] → only the listed comp IDs.
        // The distinction between null and [] matters — `?:` would conflate
        // them; we test for null explicitly.
        $whitelist = $version?->included_comp_ids_json;
        if ($whitelist === null) {
            $inPoolComps = $soldComps;
        } else {
            $whitelistSet = array_flip(array_map('intval', $whitelist));
            $inPoolComps  = $soldComps->filter(fn ($c) => isset($whitelistSet[(int) $c->id]))->values();
        }

        $cmaComputed = (new CmaComputeService())->compute(
            $presentation, $inPoolComps, $isSectional, $conditionContext,
        );

        // Build 8 — extract cma_valuation to a variable so the Price
        // Position table (built inside compileKeyInsights) can read the
        // SAME computed + condition-scaled lower/middle/upper the tiles
        // render. Pre-fix compileKeyInsights re-read the raw
        // cma.middle_range field directly, which produced a different
        // "vs CMA Evaluation (middle)" benchmark from the tile value.
        // STEP 2a — size-normalised median-floored blend. Computed ONCE here and
        // shared by the headline (compileCmaValuation) and the STEP 2b guardrail
        // so the two can never disagree about which value the seller sees.
        $blend = $this->computeSizeNormalisedBlend($inPoolComps, $cmaComputed);

        $cmaValuation = $this->compileCmaValuation($fields, $askingPrice, $cmaSelectedRange, $conditionContext, $cmaComputed, $blend);

        // CMA VALUATION SANITY GUARDRAIL (STEP 2b) — additive, surfacing-only.
        // Flags when the headline STILL cannot be trusted after the blend: the
        // pool is not size-comparable (basis mismatch), or the size-normalised
        // value still diverges hard from the value shown (the explosion cases,
        // where the blend deliberately fell back to the plain median). A lifted
        // headline that now agrees with the size-normalised evidence (Harrison)
        // is clean. Injected into cma_valuation so it rides the existing
        // pass-through to the review screen — no controller change.
        $cmaValuation['valuation_guardrail'] = $this->computeValuationGuardrail($blend);

        return [
            'subject_property'   => $this->compileSubjectProperty($presentation, $fields, $askingPrice),
            'suburb_overview'    => $suburbOverview,
            // AT-18 BUG-1: the recent-sales table AND the page-8 price
            // distribution (PresentationPdfService reads comparable_sales.*.rows)
            // must honour the agent's comp whitelist — pass $inPoolComps, not the
            // unfiltered $soldComps. The CMA path (above) already uses $inPoolComps.
            'comparable_sales'   => $this->compileComparableSales(
                $inPoolComps,
                $presentation->property_address,
                (bool) ($presentation->agency?->ss_show_complex_section ?? true),
                $presentation->property?->complex_name,
                // AT-78 FIX 3 — hide from the DISPLAY comps table the exact comps
                // the valuation engine already rejected as price outliers (IQR
                // R/m² fence), so an agent doesn't have to manually untick a
                // R13m sale on a R2.5m CMA. Agency-toggleable; the threshold is
                // the agency's existing cma_compute_iqr_multiplier.
                array_map('intval', $cmaComputed['outlier_excluded_comp_ids'] ?? []),
                (bool) ($presentation->agency?->cma_hide_display_outliers ?? true),
            ),
            'cma_valuation'      => $cmaValuation,
            'cma_computed'       => $cmaComputed,
            'competitor_stock'   => $this->compileCompetitorStock($presentation, $version),
            'active_competition' => $activeCompetition,
            'stock_absorption'   => $stockAbsorption,
            'inflow_absorption'  => $inflowAbsorption,
            'price_position'     => $this->compilePricePosition($activeCompetition, $askingPrice),
            'price_brackets'     => $this->compilePriceBrackets($activeCompetition, $askingPrice),
            'holding_cost'       => $this->compileHoldingCost($presentation),
            'key_insights'       => $this->compileKeyInsights($fields, $askingPrice, $cmaSelectedRange, $vicinitySelectedRange, $cmaValuation),
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
        // Build 1 — BUG-2 / BUG-8 fallback chain for extent_m2:
        //   1. extracted CMA field         subject.extent_m2
        //   2. presentation row snapshot   presentations.erf_size_m2
        //   3. live source property        properties.erf_size_m2
        //   4. presentation row snapshot   presentations.floor_area_m2  (sectional usually)
        // Any nulls fall through; only the first non-null wins.
        $extent = $this->intOrNull($fields->get('subject.extent_m2')?->final_value)
               ?? $this->intOrNull($p->erf_size_m2 ?? null)
               ?? $this->intOrNull($p->property?->erf_size_m2 ?? null)
               ?? $this->intOrNull($p->floor_area_m2 ?? null);
        if ($extent === null) {
            \Illuminate\Support\Facades\Log::info('[PRES-WARN] subject extent_m2 unresolved at every fallback', [
                'presentation_id' => $p->id,
            ]);
        }

        // SS identity — show complex + unit instead of the street name when
        // BOTH structured columns are populated (data-presence trigger, not a
        // title_type gate: title_type is heuristic and can misclassify; filled
        // columns are the stronger signal, and this also covers full-title
        // cluster homes inside a named complex). Reuse Property::buildDisplayAddress()
        // so the format ("Unit 17, Brock Manor, Margate") is one source of truth.
        // Computed here so it freezes into snapshot_payload at publish, never
        // recomputed at render. Falls back to the flat street address otherwise.
        // AT-78 FIX 2 — the LIVE property_address wins. It is re-hydrated from
        // the Property (buildDisplayAddress) on every generate, so an agent who
        // corrects a wrong address and regenerates sees the correction. The
        // extracted `subject.address` field is frozen at CMA-import time and was
        // previously taking precedence, so a corrected address never showed on
        // regenerate. Fall back to the extracted field only when the property
        // carries no address at all.
        $address        = $p->property_address ?: ($fields->get('subject.address')?->final_value);
        $property       = $p->property;
        $displayAddress = $address;
        if ($property && filled($property->complex_name) && filled($property->unit_number)) {
            $displayAddress = $property->buildDisplayAddress();
        }

        return [
            'address'        => $address,
            'display_address' => $displayAddress,
            'complex_name'   => $property?->complex_name,
            'unit_number'    => $property?->unit_number,
            'suburb'         => $fields->get('subject.suburb')?->final_value ?? $p->suburb,
            'erf'            => $fields->get('subject.erf')?->final_value,
            'extent_m2'      => $extent,
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

    /**
     * @param  bool  $separateComplex  When true, same-scheme ("complex") sales get
     *   their own group; when false (agency suppressed the section) they fold
     *   back into the vicinity group so they are never lost. Baked in at
     *   compile so it freezes into snapshot_payload at publish.
     * @param  ?string  $subjectScheme  The subject's scheme name (Property.complex_name).
     *   Complex membership is SAME-SCHEME ONLY: a comp lands in the complex group
     *   iff its scheme_name matches this (case-insensitive, trimmed). A sectional
     *   comp from a DIFFERENT scheme is a vicinity sale, never a complex sale.
     */
    private function compileComparableSales(Collection $soldComps, ?string $subjectAddress = null, bool $separateComplex = true, ?string $subjectScheme = null, array $excludedOutlierIds = [], bool $hideOutliers = true): array
    {
        $groups = [
            'vicinity'     => [],
            'complex'      => [],
            'cma_comps'    => [],
            'street_sales' => [],
        ];

        // Normalise subject address for exclusion matching
        $normalSubject = $subjectAddress ? strtolower(trim($subjectAddress)) : null;

        // AT-78 FIX 3 — the valuation engine's price-outlier set, hidden from
        // the display table when the agency toggle is on. Not silent: the count
        // is reported in cma_computed.pool_stats.excluded_by_outlier.
        $outlierSet     = ($hideOutliers && !empty($excludedOutlierIds)) ? array_flip($excludedOutlierIds) : [];
        $hiddenOutliers = 0;

        foreach ($soldComps as $comp) {
            if (!empty($outlierSet) && isset($outlierSet[(int) $comp->id])) {
                $hiddenOutliers++;
                continue;
            }
            $raw    = is_string($comp->raw_row_json) ? json_decode($comp->raw_row_json, true) : ($comp->raw_row_json ?? []);
            $source = $raw['source'] ?? 'unknown';
            $sizeM2 = $comp->size_m2 ?: ($raw['extent_m2'] ?? null);

            // Build a never-blank display label so sectional comps with
            // scheme+section but no street address still identify on the
            // review screen / PDF table / map tooltip. Single source of
            // truth via CompLabel — same logic used by the PDF map
            // tooltip loop. Subject-exclusion still uses the raw address
            // (matches the existing comparison), so labels like
            // "Seeskulp, Section 8" never accidentally suppress comps
            // when the subject is "4 Tucker Avenue".
            $rowAddress    = $raw['address'] ?? null;
            $displayLabel  = CompLabel::build($raw, $comp->suburb, $comp->id ?? null);

            // Exclude subject property from comps
            if ($normalSubject && $rowAddress && str_contains(strtolower(trim($rowAddress)), $normalSubject)) {
                continue;
            }

            $row = [
                'address'      => $displayLabel,
                'distance_m'   => $raw['distance_m'] ?? null,
                'erf_no'       => $raw['erf_no'] ?? null,
                'extent_m2'    => $sizeM2 ? (int) $sizeM2 : null,
                // Stacked multi-section comps carry BOTH extents ("65/22") for
                // the rendered cell; extent_m2 stays the summed math basis for
                // sorting / closest-match. Null for ordinary single rows, which
                // render number_format(extent_m2) as before.
                'extent_display' => (isset($raw['extent_display']) && is_string($raw['extent_display']) && $raw['extent_display'] !== '')
                    ? $raw['extent_display']
                    : null,
                'sale_date'    => $comp->sold_date ? $comp->sold_date->format('Y/m/d') : null,
                'sale_price'   => $comp->sold_price_inc,
                'price_per_m2' => $raw['price_per_m2']
                    ?? ($sizeM2 > 0 && $comp->sold_price_inc > 0
                        ? (int) round($comp->sold_price_inc / $sizeM2)
                        : null),
                // Phase 3i — flag comps that came from HFC's own deal book so
                // Section 3 can render a "HFC sold this" badge as a seller
                // proof point.
                'hfc_sold'     => in_array($source, ['internal_deals', 'internal_deals_v1'], true)
                                  || !empty($raw['hfc_sold']),
            ];

            // Phase 3e B — drop implausible columns (parser bleed, bad data)
            // before they pollute averages and analytics. Row is kept; only
            // the offending column is nulled.
            $row = OutlierGuard::sanitiseRow($row);
            if (empty($row['sale_price'])) {
                // Without a sale_price the row is useless for comparable-sales
                // averaging; skip it but log via parser_version chain (already
                // captured in raw_row_json).
                continue;
            }

            // Base group by source. cma_comps / street_sales keep their own
            // distinct groups. EVERY other source — full-title 'vicinity_sales',
            // the legacy 'vicinity_sales_sectional', the source-agnostic
            // MicSnapshotHydrator 'mic_snapshot', and anything unknown — starts
            // in 'vicinity'. The complex promotion below is the ONLY path into
            // the dedicated section, and it is gated on a scheme match, so the
            // comp's source no longer decides complex membership.
            $key = match ($source) {
                'cma_comps'    => 'cma_comps',
                'street_sales' => 'street_sales',
                default        => 'vicinity',
            };

            // SS complex routing — SAME-SCHEME ONLY. The dedicated "Recent sales
            // in {scheme}" section is for sales in the SUBJECT'S OWN scheme, not
            // for sectional sales in general. A comp is promoted to 'complex' iff
            // its scheme_name matches the subject's scheme (case-insensitive,
            // trimmed). A sectional comp from a DIFFERENT scheme (e.g. Loscona or
            // Suntide Cabanas when the subject is Pumula) stays in 'vicinity' —
            // it is an area sale, not a same-building comp. Freehold comps (no
            // scheme) and schemeless subjects never match, so the complex group
            // is empty for them and the section does not render. Gated by the
            // agency toggle; when suppressed ($separateComplex=false), even
            // same-scheme comps stay in 'vicinity' so they are never dropped.
            if ($key === 'vicinity' && $separateComplex
                && $this->compMatchesSubjectScheme($raw, $subjectScheme)) {
                $key = 'complex';
            }

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
        $groups = ['vicinity' => [], 'complex' => [], 'cma_comps' => [], 'street_sales' => []];
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

        if ($hiddenOutliers > 0) {
            \Illuminate\Support\Facades\Log::info('[PRES] hid valuation-outlier comps from display table', [
                'hidden_count' => $hiddenOutliers,
            ]);
        }

        return $result;
    }

    /**
     * True when a comp belongs in the SUBJECT'S complex group: the comp's
     * scheme_name matches the subject's scheme (case-insensitive, trimmed).
     *
     * This is the SAME-SCHEME rule — the dedicated complex section shows only
     * sales in the subject's own scheme. A sectional comp from a different
     * scheme is a vicinity sale, not a complex sale. Returns false when EITHER
     * side has no scheme, so freehold comps and schemeless subjects never
     * populate the complex group (the section then does not render).
     *
     * The subject's scheme comes from Property.complex_name; the comp's from
     * raw_row_json['scheme_name'] (sourced from market_report_comp_rows.
     * scheme_name). Comps whose scheme was lost at capture (scheme_name NULL —
     * the known parser gap) will not match until re-imported with the scheme
     * populated; they remain honest vicinity sales in the meantime.
     *
     * @param  array<string, mixed>  $raw  decoded raw_row_json
     */
    private function compMatchesSubjectScheme(array $raw, ?string $subjectScheme): bool
    {
        $subject = $subjectScheme !== null ? trim($subjectScheme) : '';
        if ($subject === '') {
            return false;
        }

        $compScheme = isset($raw['scheme_name']) ? trim((string) $raw['scheme_name']) : '';
        if ($compScheme === '') {
            return false;
        }

        return mb_strtolower($compScheme) === mb_strtolower($subject);
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

    /**
     * Build 3 — resolve which condition the valuation should use.
     *
     * For PUBLISHED versions we honour the SNAPSHOT (defends historic
     * PDFs against agency-settings drift). For live editing (review
     * screen, recalc endpoint) we delegate to ConditionAdjustmentService
     * which reads version-override > property > none.
     *
     * @return array{pct: ?float, label: ?string, source: string}
     */
    private function resolveConditionContext(Presentation $presentation, ?\App\Models\PresentationVersion $version): array
    {
        if ($version === null) {
            return ['pct' => null, 'label' => null, 'source' => 'no_version'];
        }

        // Published versions: honour the frozen snapshot.
        if ($version->review_status === \App\Models\PresentationVersion::REVIEW_PUBLISHED
            && $version->condition_adjustment_pct !== null) {
            return [
                'pct'    => (float) $version->condition_adjustment_pct,
                'label'  => $version->condition_label,
                'source' => 'version_snapshot',
            ];
        }

        // Live path (review screen, recalc endpoint).
        $resolver = app(\App\Services\Presentations\ConditionAdjustmentService::class);
        $resolved = $resolver->resolveLive($version, $presentation);
        $level = $resolved['level'];
        if ($level === null) {
            return ['pct' => null, 'label' => null, 'source' => 'none'];
        }
        return [
            'pct'    => (float) $level->adjustment_pct,
            'label'  => $level->name,
            'source' => $resolved['source'],
        ];
    }

    private function compileCmaValuation(Collection $fields, ?int $askingPrice, string $cmaSelectedRange = 'middle', array $conditionContext = [], array $cmaComputed = [], array $blend = []): array
    {
        // CMA Info benchmark — what the source PDF stated. Used as an
        // INTERNAL reference line on the review screen so the agent can
        // sanity-check CoreX vs the PDF. NOT used as the headline tile
        // value any more (Phase B lock-in: tiles show CoreX-computed).
        // NOT rendered on the seller PDF.
        $cmaInfoLower  = $this->intOrNull($fields->get('cma.lower_range')?->final_value);
        $cmaInfoMiddle = $this->intOrNull($fields->get('cma.middle_range')?->final_value);
        $cmaInfoUpper  = $this->intOrNull($fields->get('cma.upper_range')?->final_value);

        // Build 1 — middle-band fallback for the benchmark line when
        // the CMA Info parser missed the "Middle Range" label
        // ("Mid Range" / "Median Range" / line-break in label).
        // Synthesise a midpoint at RENDER time so the benchmark line
        // remains useful — applies to the benchmark only, NOT to the
        // tile values (which now come from cma_computed).
        $cmaInfoMiddleFromFallback = false;
        if ($cmaInfoMiddle === null && $cmaInfoLower !== null && $cmaInfoUpper !== null && $cmaInfoUpper >= $cmaInfoLower) {
            $cmaInfoMiddle = (int) round(($cmaInfoLower + $cmaInfoUpper) / 2);
            $cmaInfoMiddleFromFallback = true;
        }

        // CoreX-computed tile values — locked to MEDIAN per Phase B.
        //   lower  = pool_stats.p25  (25th percentile of selected comps)
        //   middle = method_median.condition_adjusted (or .raw with no condition)
        //   upper  = pool_stats.p75
        // When the agent unticks all comps, pool_stats values are null →
        // tiles render '—'. CmaComputeService Build 8b cleaning preserves
        // any min-n fallback behaviour automatically.
        $poolStats     = $cmaComputed['pool_stats']     ?? [];
        $methodMedian  = $cmaComputed['method_median']  ?? [];

        $lowerBaseline  = $this->intOrNull($poolStats['p25']    ?? null);
        $upperBaseline  = $this->intOrNull($poolStats['p75']    ?? null);
        // STEP 2a — the middle baseline is the size-normalised median-floored
        // blend (computeSizeNormalisedBlend). It EQUALS the plain median unless
        // the subject is a genuinely larger, same-basis stand than its comps, in
        // which case it is lifted toward (median R/m² × extent). Falls back to
        // the raw median if the blend was not supplied (defensive).
        $medianRaw      = $this->intOrNull($methodMedian['raw'] ?? null);
        $middleBaseline = array_key_exists('headline_baseline', $blend)
            ? $this->intOrNull($blend['headline_baseline'])
            : $medianRaw;

        // PRES-CMA-REALFIX (Johan, 2026-06-16) — the recommended band is the
        // EVALUATED VALUE (middle) ± a tight, agency-configurable %. The middle
        // is the comp-median (the "indicated value"); the agent's condition %
        // is applied to it EXACTLY ONCE here, before the band is derived. The
        // band edges are NO LONGER the raw pool P25/P75 — sourcing them from
        // percentiles let a type-contaminated pool blow the band out to ±20%+
        // (band-evidence pass: clean same-type pools cluster ~±6–7%, mixed
        // pools ~±23%). Deriving lower/upper from the middle keeps the band
        // tight and symmetric around the value the seller must hear. The raw
        // P25/median/P75 remain in pool_stats for the "Why This Range?"
        // evidence rows only ($lowerBaseline/$upperBaseline above).
        //
        // Condition is applied ONCE and ONLY here. Every other historical
        // condition multiplier stays dormant/unread: CmaComputeService's
        // method_median.condition_adjusted (we read .raw), and
        // ConditionAdjustmentService::applyToBand / applyToMiddle (not invoked
        // from this render path). No path applies condition twice.
        $conditionPct     = isset($conditionContext['pct']) && is_numeric($conditionContext['pct'])
            ? (float) $conditionContext['pct']
            : null;
        $conditionLabel   = $conditionContext['label'] ?? null;
        $conditionSource  = $conditionContext['source'] ?? 'none';

        // Evaluated value = indicated (median) value with condition applied
        // once. When no condition is resolved (the current state for every
        // presentation — condition data is unpopulated) the multiplier is 1×,
        // so the middle stays the indicated value.
        $conditionApplied = false;
        $middle = $middleBaseline;
        if ($middle !== null && $conditionPct !== null && abs($conditionPct) > 0.0001) {
            $middle = (int) round($middleBaseline * (1 + $conditionPct / 100));
            $conditionApplied = true;
        }
        if ($conditionSource === 'none') {
            \Illuminate\Support\Facades\Log::info('[PRES-INFO] cma_valuation_baseline_only (no condition resolved)');
        }

        // Band half-widths from pool_stats (surfaced by CmaComputeService from
        // agency settings cma_band_lower_pct / cma_band_upper_pct; default 7%).
        // lower/upper are derived from the (condition-adjusted) middle.
        $bandLowerPct = isset($poolStats['band_lower_pct']) && is_numeric($poolStats['band_lower_pct'])
            ? (float) $poolStats['band_lower_pct']
            : (float) CompPoolBuilder::DEF_BAND_LOWER_PCT;
        $bandUpperPct = isset($poolStats['band_upper_pct']) && is_numeric($poolStats['band_upper_pct'])
            ? (float) $poolStats['band_upper_pct']
            : (float) CompPoolBuilder::DEF_BAND_UPPER_PCT;

        $lower  = $middle !== null ? (int) round($middle * (1 - $bandLowerPct / 100)) : null;
        $upper  = $middle !== null ? (int) round($middle * (1 + $bandUpperPct / 100)) : null;

        $middleFromFallback = false;

        $vicinityLower  = $this->intOrNull($fields->get('vicinity.lower_range')?->final_value);
        $vicinityMiddle = $this->intOrNull($fields->get('vicinity.middle_range')?->final_value);
        $vicinityUpper  = $this->intOrNull($fields->get('vicinity.upper_range')?->final_value);
        $vicinityPpm2   = $this->intOrNull($fields->get('vicinity.avg_price_per_m2')?->final_value);

        $selectedValue = match($cmaSelectedRange) {
            'lower' => $lower,
            'upper' => $upper,
            default => $middle,
        };

        // PRES-CMA-SELLER-VOICE (Johan, 2026-06-15) — the asking-vs-value
        // comparison is ALWAYS measured against the EVALUATED VALUE (the
        // middle), never the selected range. Pre-fix it used $selectedValue;
        // when the agent had picked 'upper' the seller saw "asking −3.3% vs
        // (upper) → Ok/green" while the asking was in fact +15% OVER the
        // evaluated value. The middle is the honest market reference.
        $askingVsCmaPct = null;
        if ($askingPrice && $middle && $middle > 0) {
            $askingVsCmaPct = round(($askingPrice - $middle) / $middle * 100, 1);
        }

        return [
            'cma_lower'                 => $lower,
            'cma_middle'                => $middle,
            'cma_middle_baseline'       => $middleBaseline,
            'cma_middle_from_fallback'  => $middleFromFallback,
            'cma_upper'                 => $upper,
            'selected_range'            => $cmaSelectedRange,
            'selected_value'            => $selectedValue,
            'vicinity_lower'            => $vicinityLower,
            'vicinity_middle'           => $vicinityMiddle,
            'vicinity_upper'            => $vicinityUpper,
            'vicinity_ppm2'             => $vicinityPpm2,
            'asking_price'              => $askingPrice,
            'asking_vs_cma_pct'         => $askingVsCmaPct,
            // PRES-CMA-REALFIX — overpriced ⟺ asking sits ABOVE the upper band
            // (middle × (1 + band_upper_pct)). No softening: an asking above
            // the band that comparable homes actually sold within is overpriced.
            'is_overpriced'             => $askingPrice !== null && $upper !== null && $askingPrice > $upper,
            // Build 3 — condition adjustment surfacing.
            'condition_applied'         => $conditionApplied,
            'condition_pct'             => $conditionPct,
            'condition_label'           => $conditionLabel,
            'condition_source'          => $conditionSource,
            // Tick-wire build — review-screen-only INTERNAL benchmark.
            // Holds the values the source CMA Info PDF stated. Surfaced
            // for agent QA only; NEVER rendered on the seller PDF.
            // null when no CMA Info data has been parsed for this
            // presentation.
            'cma_info_benchmark'        => [
                'lower'         => $cmaInfoLower,
                'middle'        => $cmaInfoMiddle,
                'upper'         => $cmaInfoUpper,
                'from_fallback' => $cmaInfoMiddleFromFallback,
            ],
            // Pool size that drove the tile values. Surfaced so the JS
            // patch helper can show "12 of 17 comps included" subtitle
            // and the review-screen can warn when zero comps are
            // ticked (tiles fall to null).
            'compute_pool_n'            => (int) ($cmaComputed['pool_stats']['n_total'] ?? 0),
            // STEP 2a — 'median' when the headline is the plain comp-median,
            // 'size_adjusted' when the size-normalised blend lifted it.
            'compute_method'            => ($blend['lifted'] ?? false) ? 'size_adjusted' : 'median',
            'headline_lifted'           => (bool) ($blend['lifted'] ?? false),
            'headline_median_raw'       => $medianRaw,
            'size_normalised_value'     => $blend['rm2'] ?? null,
            'headline_uplift_pct'       => $blend['uplift_applied_pct'] ?? null,
        ];
    }

    /**
     * STEP 2a — size-normalised, median-floored, same-basis-guarded blend.
     *
     * Produces the headline baseline the seller sees. The comp-median is the
     * FLOOR; the size-normalised value (median R/m² × subject extent) is allowed
     * to LIFT it, but only when that is trustworthy and only in proportion to how
     * clearly the subject is under-valued by the raw median:
     *
     *   - trustworthy ⟺ the size-normalised value exists AND the size-basis ratio
     *     (subject extent ÷ median comp size) sits in [MIN, MAX]. Outside the band
     *     the comps and subject are not size-comparable, or the subject is so much
     *     larger that flat R/m² over-values it (the explosion cases) → NO lift,
     *     headline stays the plain median.
     *   - lift only upward (never below the median floor) and only by
     *     weight × (rm2 − median), where weight ramps 0→1 across
     *     [LIFT_LOW_PCT, LIFT_HIGH_PCT] of median-relative uplift. Already-sane
     *     presentations (small uplift) therefore do not move; a clearly
     *     larger-than-comps stand (Harrison, +60%) gets its full uplift.
     *
     * Shared by the headline (compileCmaValuation) and the guardrail
     * (computeValuationGuardrail) so the two agree by construction.
     */
    private function computeSizeNormalisedBlend(Collection $inPoolComps, array $cmaComputed): array
    {
        $median        = $this->intOrNull($cmaComputed['method_median']['raw'] ?? null);
        $rm2Row        = $cmaComputed['method_rm2_extent'] ?? [];
        $rm2           = $this->intOrNull($rm2Row['raw'] ?? null);
        $subjectExtent = $this->intOrNull($rm2Row['subject_extent_m2'] ?? null);

        // Comp sold-price band + median comp size (only comps carrying a size).
        $prices = $inPoolComps->map(fn ($c) => (int) ($c->sold_price_inc ?? 0))
            ->filter(fn ($p) => $p > 0)->sort()->values();
        $sizes  = $inPoolComps->map(fn ($c) => (int) ($c->size_m2 ?? 0))
            ->filter(fn ($s) => $s > 0)->sort()->values();
        $minPrice = $prices->count() ? (int) $prices->first() : null;
        $maxPrice = $prices->count() ? (int) $prices->last()  : null;
        $medComp  = $sizes->count() ? (int) $sizes[intdiv($sizes->count(), 2)] : null;

        $basisRatio    = ($medComp && $subjectExtent) ? round($subjectExtent / $medComp, 2) : null;
        $basisMismatch = $basisRatio !== null
            && ($basisRatio > self::GUARDRAIL_BASIS_RATIO_HIGH || $basisRatio < self::GUARDRAIL_BASIS_RATIO_LOW);

        // Signed uplift the size-normalised value would apply to the median.
        $upliftPct = ($median && $rm2) ? round(($rm2 - $median) / $median * 100, 1) : null;

        // Trust gate — same-basis, in-band ratio.
        $trustworthy = $rm2 !== null && $median !== null && $basisRatio !== null
            && $basisRatio >= self::BLEND_TRUST_RATIO_MIN
            && $basisRatio <= self::BLEND_TRUST_RATIO_MAX;

        // Median floor; lift only when trustworthy AND the size-normalised value
        // sits ABOVE the median (a larger-than-comps subject).
        $headlineBaseline = $median;
        $weight           = 0.0;
        $lifted           = false;
        if ($trustworthy && $median !== null && $rm2 !== null && $rm2 > $median) {
            $span   = self::BLEND_LIFT_HIGH_PCT - self::BLEND_LIFT_LOW_PCT;
            $weight = $span > 0
                ? max(0.0, min(1.0, (($upliftPct ?? 0) - self::BLEND_LIFT_LOW_PCT) / $span))
                : (($upliftPct ?? 0) >= self::BLEND_LIFT_HIGH_PCT ? 1.0 : 0.0);
            if ($weight > 0) {
                $headlineBaseline = (int) round($median + ($rm2 - $median) * $weight);
                $lifted           = true;
            }
        }

        // Divergence of the size-normalised value from the value ACTUALLY shown
        // (the blended headline). ≈0 once a headline has been lifted to meet it;
        // large when the blend fell back to the median (the explosion cases).
        $headlineDivergencePct = ($headlineBaseline && $rm2)
            ? round(abs($rm2 - $headlineBaseline) / $headlineBaseline * 100, 1)
            : null;

        return [
            'median'                  => $median,
            'rm2'                     => $rm2,
            'subject_extent_m2'       => $subjectExtent,
            'median_comp_size_m2'     => $medComp,
            'comp_price_min'          => $minPrice,
            'comp_price_max'          => $maxPrice,
            'basis_ratio'             => $basisRatio,
            'basis_mismatch'          => $basisMismatch,
            'trustworthy'             => $trustworthy,
            'weight'                  => round($weight, 3),
            'lifted'                  => $lifted,
            'headline_baseline'       => $headlineBaseline,
            'uplift_available_pct'    => $upliftPct,          // rm2 vs median (what COULD lift)
            'uplift_applied_pct'      => ($median && $headlineBaseline)
                ? round(($headlineBaseline - $median) / $median * 100, 1) : null,
            'headline_divergence_pct' => $headlineDivergencePct, // rm2 vs shown headline
        ];
    }

    /**
     * CMA valuation SANITY GUARDRAIL (STEP 2b) — surfacing only, never mutates
     * the headline. Reads the shared blend so it flags exactly the presentations
     * whose headline could NOT be safely corrected by STEP 2a:
     *
     *   - basis mismatch — comps and subject not size-comparable (ratio > 2.5×
     *     or < 0.4×): a per-m² valuation is meaningless, the blend kept the median.
     *   - residual divergence — the size-normalised value still differs from the
     *     value SHOWN by > 30% (the explosion cases, where the blend deliberately
     *     fell back to the plain median). A headline that was lifted to meet the
     *     size-normalised evidence (Harrison) has ≈0 residual divergence → clean.
     *
     * Silent when there is nothing to cross-check (no size / no extent).
     */
    private function computeValuationGuardrail(array $blend): array
    {
        $rm2             = $blend['rm2'] ?? null;
        $median          = $blend['median'] ?? null;
        $headlineShown   = $blend['headline_baseline'] ?? null;
        $basisRatio      = $blend['basis_ratio'] ?? null;
        $basisMismatch   = (bool) ($blend['basis_mismatch'] ?? false);
        $divergencePct   = $blend['headline_divergence_pct'] ?? null;

        $divergesHard = $divergencePct !== null && $divergencePct > self::GUARDRAIL_DIVERGENCE_PCT;
        $flagged      = $divergesHard || $basisMismatch;

        $reasons = [];
        if ($basisMismatch) {
            $reasons[] = 'comp_size_basis_mismatch';
        }
        if ($divergesHard) {
            $reasons[] = 'diverges_from_size_normalised';
        }

        $severity = null;
        $message  = null;
        if ($flagged) {
            $severity = ($basisMismatch || ($divergencePct !== null && $divergencePct > 100))
                ? 'high' : 'review';
            if ($basisMismatch) {
                $message = 'This valuation may be unreliable: the comparable sales and this property are not size-comparable '
                    . '(e.g. sectional units measured against a full-title erf), so a per-m² valuation is not meaningful. '
                    . 'Check the comparable selection and the subject extent before relying on the headline figure.';
            } else {
                $message = 'Heads up — a size-normalised cross-check (median R/m² × this property\'s extent) values it around R'
                    . number_format((int) $rm2) . ', which still differs from the headline by ' . $divergencePct . '%. '
                    . 'The comparables may not price this property\'s size well — review the comparable selection before sending.';
            }
        }

        return [
            'flagged'             => $flagged,
            'severity'            => $severity,       // 'high' | 'review' | null
            'reasons'             => $reasons,
            'message'             => $message,
            'median_value'        => $median,
            'rm2_value'           => $rm2,
            'headline_shown'      => $headlineShown,
            'divergence_pct'      => $divergencePct,  // rm2 vs headline shown
            'basis_ratio'         => $basisRatio,
            'basis_mismatch'      => $basisMismatch,
            'subject_extent_m2'   => $blend['subject_extent_m2'] ?? null,
            'median_comp_size_m2' => $blend['median_comp_size_m2'] ?? null,
            'comp_price_min'      => $blend['comp_price_min'] ?? null,
            'comp_price_max'      => $blend['comp_price_max'] ?? null,
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

            // Same never-blank label discipline as sold comps — sectional
            // listings with scheme+section but no street address render
            // as "Scheme, Section N" instead of "—".
            $displayLabel = CompLabel::build($raw, $al->suburb ?? null, $al->id ?? null);

            $rows[] = [
                'address'        => $displayLabel,
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

        // Phase 3e B — sanitise each row's price/extent/dom columns before
        // dedup/avg. Out-of-band columns become null; rows whose list_price
        // can't be salvaged are dropped (they'd skew avg_asking_price).
        $rows = array_values(array_filter(
            array_map(fn ($r) => OutlierGuard::sanitiseRow($r), $rows),
            fn ($r) => !empty($r['list_price']),
        ));

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

    /**
     * Competitor Stock — scored Active Competition section. Reuses
     * Core Matches' PropertyMatchScoringService via a thin
     * synthetic-ContactMatch adapter (see CompetitorStockMatchService).
     *
     * Returns:
     *   matches            full scored set ≥ agency min_score
     *   included_ids       the version's whitelist (null = all, [] = empty)
     *   visible            matches filtered to the whitelist (what the
     *                      seller-PDF renders); review screen shows ALL
     *                      with ticked-state per row.
     */
    private function compileCompetitorStock(Presentation $presentation, ?PresentationVersion $version): array
    {
        $property = $presentation->property;
        if (!$property) {
            return ['matches' => [], 'included_ids' => null, 'visible' => [], 'display_cap' => null];
        }

        $service = app(CompetitorStockMatchService::class);
        $matches = $service->findCompetitors($property)->all();

        $agency     = $presentation->agency_id ? \App\Models\Agency::find($presentation->agency_id) : null;
        $displayCap = (int) ($agency?->competitor_stock_default_display_count ?? 10);

        $whitelist = $version?->included_competitor_ids_json;

        if ($whitelist === null) {
            // No tick state yet — default visible set is the TOP N auto-
            // picks by score DESC. matches is already sorted, so just
            // slice. The remainder live in the modal until the agent
            // explicitly ticks them in.
            $visible = $displayCap > 0
                ? array_slice($matches, 0, $displayCap)
                : $matches;
        } else {
            // Decision B — visible = UNION of (rows in matches whose IDs
            // are on the whitelist) + (whitelisted IDs that fall OUTSIDE
            // the auto-pool, scored separately).
            //
            // The second branch covers the case where the agent widened
            // the modal filters to add a row beyond the default price /
            // suburb / beds tolerance — without this, that row would
            // silently drop from the PDF because matches ∩ whitelist
            // wouldn't contain it.
            //
            // The Level-1 family gate is enforced INSIDE scoreListingsByIds
            // (cross-family IDs are dropped), so the union can never leak
            // a House into a sectional subject's visible set.
            $whitelistSet = array_flip(array_map('intval', $whitelist));
            $autoPoolIncluded = array_values(array_filter(
                $matches,
                fn (array $m) => isset($whitelistSet[(int) $m['listing_id']]),
            ));

            $autoPoolIds = array_flip(array_map(
                fn (array $m) => (int) $m['listing_id'],
                $matches,
            ));
            $extraIds = array_values(array_filter(
                $whitelist,
                fn ($id) => !isset($autoPoolIds[(int) $id]),
            ));

            $extras = $extraIds !== []
                ? $service->scoreListingsByIds($property, $extraIds)->all()
                : [];

            // Merge + dedupe by listing_id (shouldn't collide given the
            // disjoint construction, but defence in depth) + sort by
            // score DESC. min_score threshold is INTENTIONALLY skipped
            // for whitelist rows — the agent's deliberate tick overrides
            // the auto-threshold (a low-scoring deliberate pick still
            // publishes).
            $combined = array_merge($autoPoolIncluded, $extras);
            $seen = [];
            $deduped = [];
            foreach ($combined as $row) {
                $id = (int) $row['listing_id'];
                if (isset($seen[$id])) continue;
                $seen[$id] = true;
                $deduped[] = $row;
            }
            usort($deduped, fn ($a, $b) => (int) $b['score'] <=> (int) $a['score']);
            $visible = $deduped;
        }

        // CMA-map plotted counts. The map plots only rows with real
        // lat/lng — never a fake fallback. The caption surfaces the
        // honest count to the agent ("12 of 30 plotted · 18 no
        // location"). Computed over `visible` so the section header
        // matches what actually renders on the map.
        $plotted = 0;
        $unplotted = 0;
        foreach ($visible as $row) {
            if (!empty($row['latitude']) && !empty($row['longitude'])) {
                $plotted++;
            } else {
                $unplotted++;
            }
        }

        // Build 8 — canonical seller-facing competition denominator.
        // The agent curates `visible` via the review screen; every
        // seller-facing COUNT or rank/percentile/position VERDICT
        // (PDF §1 + §5 tiles, public-show headline, teaser, agent
        // analysis-tab card, pricing-simulator verdict, AI prose)
        // now reads these derived keys instead of the legacy
        // active_competition.count / price_position pipeline. Three
        // different denominators in §5 collapse to one.
        //
        // visible rows are pre-filtered to whereBetween('price', ...)
        // upstream in CompetitorStockMatchService::loadCandidates,
        // so every row has a non-null int price.
        $competingPrices = array_values(array_filter(
            array_map(static fn (array $row) => isset($row['price']) ? (int) $row['price'] : 0, $visible),
            static fn (int $p) => $p > 0,
        ));
        $askingPrice  = $presentation->asking_price_inc !== null ? (int) $presentation->asking_price_inc : null;
        $canonicalPos = $this->rankPriceAgainstPool($askingPrice, $competingPrices);
        $canonicalBkt = $this->bracketPricesAgainstAsking($competingPrices, $askingPrice);

        return [
            'matches'      => $matches,
            'included_ids' => $whitelist,
            'visible'      => $visible,
            'display_cap'  => $displayCap > 0 ? $displayCap : null,
            'map_plotted_count'   => $plotted,
            'map_unplotted_count' => $unplotted,
            // Canonical denominator + verdict (Build 8 single-source).
            'competing_count'           => count($visible),
            'competing_with_price'      => count($competingPrices),
            'price_position_canonical'  => $canonicalPos,
            'price_brackets_canonical'  => $canonicalBkt,
        ];
    }

    private function compileHoldingCost(Presentation $p): array
    {
        // Title-type-branched component set — only render the components
        // that apply to this property's title type (sectional gets levy
        // + utilities; freehold gets garden/pool/security; both get
        // rates/insurance/opp). HoldingCostEstimator's componentsFor()
        // is the single source of truth — re-use the same branching
        // here so the breakdown lines mirror the components that get
        // resolved and overridden.
        $titleType = $p->property?->title_type ?? null;
        $estimator = app(HoldingCostEstimator::class);
        $components = $estimator->componentsFor($titleType);

        // Map component key → display label + presentation column.
        $componentMap = [
            'rates'            => ['label' => 'Rates',           'col' => 'monthly_rates'],
            'levy'             => ['label' => 'Levies',          'col' => 'monthly_levies'],
            'insurance'        => ['label' => 'Insurance',       'col' => 'monthly_insurance'],
            'utilities'        => ['label' => 'Utilities',       'col' => 'monthly_utilities'],
            'garden'           => ['label' => 'Garden service',  'col' => 'monthly_garden'],
            'pool'             => ['label' => 'Pool service',    'col' => 'monthly_pool'],
            'security'         => ['label' => 'Security',        'col' => 'monthly_security'],
            'bond'             => ['label' => 'Bond payment',    'col' => 'monthly_bond'],
            'opportunity_cost' => ['label' => 'Opportunity cost','col' => 'monthly_opportunity_cost'],
        ];

        // Bond is independent of title type — always show.
        $components = array_values(array_unique(array_merge(['bond'], $components)));

        // AT-22 item 3 — per-line provenance so the panel is no longer an
        // opaque set of rand figures. Each line states HOW it was derived:
        // the calculated opportunity-cost formula, the agency Tier-2 default
        // (with its formula), or a captured/custom value when the stored
        // figure diverges from the agency default. Derived from the agency
        // config + asking — no estimator re-run, no stored breakdown needed.
        $agency = $p->agency;
        $asking = (float) ($p->asking_price_inc ?? 0);
        $perM   = $asking / 1_000_000;
        $oppPct = (float) ($agency?->presentations_default_opportunity_cost_pct ?? 8);
        $defaults = [
            'rates'     => (int) round(((int) ($agency?->presentations_default_rates_per_million_zar    ?? 800)) * $perM),
            'insurance' => (int) round(((int) ($agency?->presentations_default_insurance_per_million_zar ?? 200)) * $perM),
            'utilities' => (int) ($agency?->presentations_default_utilities_zar ?? 1200),
            'garden'    => (int) ($agency?->presentations_default_garden_zar    ?? 800),
            'pool'      => (int) ($agency?->presentations_default_pool_zar       ?? 600),
            'security'  => (int) ($agency?->presentations_default_security_zar   ?? 1500),
        ];
        $defaultFormula = [
            'rates'     => 'Agency default — R' . number_format((int) ($agency?->presentations_default_rates_per_million_zar ?? 800)) . '/million × value',
            'insurance' => 'Agency default — R' . number_format((int) ($agency?->presentations_default_insurance_per_million_zar ?? 200)) . '/million × value',
            'utilities' => 'Agency default (flat monthly)',
            'garden'    => 'Agency default (flat monthly)',
            'pool'      => 'Agency default (flat monthly)',
            'security'  => 'Agency default (flat monthly)',
        ];
        $sourceFor = function (string $component, float $value) use ($asking, $oppPct, $defaults, $defaultFormula, $titleType) {
            if ($component === 'opportunity_cost') {
                return ['source' => 'calculated', 'detail' => 'Calculated — asking R' . number_format($asking) . ' × ' . rtrim(rtrim(number_format($oppPct, 2), '0'), '.') . '% ÷ 12'];
            }
            if ($component === 'bond') {
                return $value > 0
                    ? ['source' => 'agent', 'detail' => 'Entered by agent']
                    : ['source' => 'unset', 'detail' => 'Not set — enter the monthly bond if relevant'];
            }
            if ($component === 'levy') {
                return $titleType === \App\Services\TitleTypeClassifier::TITLE_FULL
                    ? ['source' => 'na', 'detail' => 'Not applicable (freehold)']
                    : ['source' => 'tiered', 'detail' => 'From the property levy, or agency default'];
            }
            if (isset($defaults[$component])) {
                return abs($value - $defaults[$component]) <= 1.0
                    ? ['source' => 'agency_default', 'detail' => $defaultFormula[$component]]
                    : ['source' => 'custom', 'detail' => 'Captured from the property or set by the agent (differs from the agency default of R' . number_format($defaults[$component]) . ')'];
            }
            return ['source' => 'tiered', 'detail' => 'Derived'];
        };

        $breakdown   = [];
        $components_meta = [];
        foreach ($components as $component) {
            if (!isset($componentMap[$component])) continue;
            $label = $componentMap[$component]['label'];
            $col   = $componentMap[$component]['col'];
            $value = (float) ($p->{$col} ?? 0);
            $src   = $sourceFor($component, $value);
            $breakdown[$label] = $value;
            $components_meta[] = [
                'component'     => $component,
                'label'         => $label,
                'column'        => $col,
                'value'         => $value,
                'source'        => $src['source'],        // AT-22 item 3 — provenance tag
                'source_detail' => $src['detail'],        // AT-22 item 3 — human formula/source
            ];
        }

        // Build 8 — monthly_total comes from the canonical
        // HoldingCostEstimator::monthlyTotalFor so the AI summary,
        // teaser blade, trajectory sim, and this itemised breakdown
        // can never diverge. Same components, same bcmath sum, same
        // int rand. The breakdown[] above still drives the per-row
        // table; only the headline figure goes through the canonical
        // path.
        $monthly = (float) $estimator->monthlyTotalFor($p);

        return [
            'breakdown'       => $breakdown,
            'monthly_total'   => $monthly,
            'projected_3m'    => $monthly * 3,
            'projected_6m'    => $monthly * 6,
            'projected_12m'   => $monthly * 12,
            // Per-component metadata for the inline-edit UI — each row
            // exposes its component key, column name, and current value
            // so the JS knows what to POST on override.
            'components'      => $components_meta,
            'title_type'      => $titleType,
        ];
    }

    // ── 7. KEY INSIGHTS ──────────────────────────────────────────────────

    private function compileKeyInsights(Collection $fields, ?int $askingPrice, string $cmaSelectedRange = 'middle', string $vicinitySelectedRange = 'middle', array $cmaValuation = []): array
    {
        if (!$askingPrice) {
            return ['asking_price_set' => false, 'comparisons' => []];
        }

        // Build 8 — single source of truth. Read the CMA band from the
        // already-computed cma_valuation block (CoreX-computed +
        // condition-scaled) so the Price Position "vs CMA Evaluation"
        // benchmark uses the SAME middle the tiles render. Pre-fix this
        // method re-read cma.middle_range field directly — that's the
        // raw value parsed from the source CMA Info PDF, never touched
        // by the compute pipeline or the condition multiplier. The
        // resulting R715k benchmark contradicted the R864k tile.
        //
        // Fall back to the field reads ONLY for very old / un-compiled
        // versions where cma_valuation is empty (e.g. legacy tests
        // that bypassed compile()). New code paths always pass it in.
        $cmaLower  = $cmaValuation['cma_lower']  ?? null;
        $cmaUpper  = $cmaValuation['cma_upper']  ?? null;
        $cmaMiddle = $cmaValuation['cma_middle'] ?? null;
        if ($cmaLower === null && $cmaUpper === null && $cmaMiddle === null) {
            // Legacy fallback — same as the original compileKeyInsights
            // contract for callers that didn't compile a cma_valuation.
            $cmaLower  = $this->intOrNull($fields->get('cma.lower_range')?->final_value);
            $cmaUpper  = $this->intOrNull($fields->get('cma.upper_range')?->final_value);
            $cmaMiddle = $this->intOrNull($fields->get('cma.middle_range')?->final_value);
            if ($cmaMiddle === null && $cmaLower !== null && $cmaUpper !== null && $cmaUpper >= $cmaLower) {
                $cmaMiddle = (int) round(($cmaLower + $cmaUpper) / 2);
            }
        }
        // PRES-CMA-SELLER-VOICE — the seller-facing "vs evaluated value"
        // benchmark is ALWAYS the middle (the evaluated value), never the
        // agent-selected range. Anchoring on 'upper' let an above-market
        // asking read as within tolerance.
        $vicinityValue = match($vicinitySelectedRange) {
            'lower'  => $this->intOrNull($fields->get('vicinity.lower_range')?->final_value),
            'upper'  => $this->intOrNull($fields->get('vicinity.upper_range')?->final_value),
            default  => $this->intOrNull($fields->get('vicinity.middle_range')?->final_value)
                        ?? $this->intOrNull($fields->get('vicinity.average_price')?->final_value),
        };

        $benchmarks = [
            [
                'label'     => 'vs evaluated value (middle)',
                'benchmark' => $cmaMiddle,
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
        // Build 8 — thin wrapper. Extract the legacy active_competition.rows
        // prices and delegate to rankPriceAgainstPool() so the same Type-7
        // percentile + label thresholds drive both this legacy output AND
        // the new competitor_stock.price_position_canonical surfaced for
        // seller-facing tiles.
        $prices = [];
        foreach (($activeCompetition['rows'] ?? []) as $row) {
            if (!empty($row['is_excluded'])) continue;
            if (isset($row['list_price']) && (int) $row['list_price'] > 0) {
                $prices[] = (int) $row['list_price'];
            }
        }
        return $this->rankPriceAgainstPool($askingPrice, $prices);
    }

    /**
     * Build 8 — single source of truth for the rank / percentile / label
     * verdict shape. Called by both compilePricePosition (legacy
     * active_competition pool) AND compileCompetitorStock (canonical
     * scored visible pool) so the two surfaces can NEVER produce
     * different label thresholds or percentile maths.
     *
     * Empty-pool / no-asking-price → has_data=false (do not synthesise
     * "#1 of 1 / 0th percentile" off an empty set).
     *
     * @param array<int, int> $pool prices in cents/rand units (whole int).
     * @return array{
     *   has_data: bool, price_rank?: int, total_listings?: int,
     *   listings_cheaper?: int, listings_more_expensive?: int, listings_same_price?: int,
     *   price_percentile?: int, position_label?: string, position_color?: string,
     *   asking_price?: int, reason?: string
     * }
     */
    private function rankPriceAgainstPool(?int $askingPrice, array $pool): array
    {
        if (!$askingPrice || $askingPrice <= 0) {
            return ['has_data' => false, 'reason' => 'no_asking_price'];
        }

        $pool = array_values(array_filter(
            array_map('intval', $pool),
            fn (int $p) => $p > 0,
        ));
        if (count($pool) === 0) {
            return ['has_data' => false, 'reason' => 'no_priced_listings'];
        }

        // Sort descending (rank 1 = most expensive).
        rsort($pool);

        $cheaper = 0;
        $moreExpensive = 0;
        $samePrice = 0;
        foreach ($pool as $p) {
            if ($p > $askingPrice) {
                $moreExpensive++;
            } elseif ($p < $askingPrice) {
                $cheaper++;
            } else {
                $samePrice++;
            }
        }

        // Phase 3e D — rank reads "subject's position among all priced
        // properties INCLUDING the subject", so the denominator includes
        // the subject too.
        $rank  = $moreExpensive + 1;
        $total = count($pool) + 1;

        // Percentile: % of pool priced LOWER than subject (0 = cheapest,
        // 100 = most expensive). Denominator includes the subject so a
        // subject above every competitor lands near 100.
        $percentile = $total > 0 ? (int) round(($cheaper / $total) * 100) : 0;

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
            'has_data'                => true,
            'price_rank'              => $rank,
            'total_listings'          => $total,
            'listings_cheaper'        => $cheaper,
            'listings_more_expensive' => $moreExpensive,
            'listings_same_price'     => $samePrice,
            'price_percentile'        => $percentile,
            'position_label'          => $positionLabel,
            'position_color'          => $positionColor,
            'asking_price'            => $askingPrice,
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
        // Build 8 — thin wrapper. Extract the legacy active_competition.rows
        // prices and delegate to bracketPricesAgainstAsking() so the same
        // bracket-sizing/binning logic drives both this legacy output AND
        // the new competitor_stock.price_brackets_canonical surfaced for
        // the seller-facing §5 chart.
        $prices = [];
        foreach (($activeCompetition['rows'] ?? []) as $row) {
            if (!empty($row['is_excluded'])) continue;
            if (isset($row['list_price']) && (int) $row['list_price'] > 0) {
                $prices[] = (int) $row['list_price'];
            }
        }
        return $this->bracketPricesAgainstAsking($prices, $askingPrice);
    }

    /**
     * Build 8 — single source of truth for the price-bracket distribution
     * chart shape. Called by both compilePriceBrackets (legacy pool) AND
     * compileCompetitorStock (canonical scored pool).
     *
     * @param array<int, int> $prices
     * @return array{has_data: bool, brackets: array<int, array{lower:int,upper:int,label:string,count:int,bar_pct:int,contains_asking:bool}>, total_priced?: int, bracket_size?: int}
     */
    private function bracketPricesAgainstAsking(array $prices, ?int $askingPrice): array
    {
        $prices = array_values(array_filter(
            array_map('intval', $prices),
            fn (int $p) => $p > 0,
        ));
        if (count($prices) === 0) {
            return ['has_data' => false, 'brackets' => []];
        }

        $minPrice = min($prices);
        $maxPrice = max($prices);
        $ceiling  = max($maxPrice, $askingPrice ?? 0);
        $floor    = min($minPrice, $askingPrice ?? $minPrice);

        $range = $ceiling - $floor;
        if ($range <= 0) {
            $range = $ceiling > 0 ? $ceiling * 0.5 : 500000;
        }

        if ($range < 1000000) {
            $bracketSize = 100000;
        } elseif ($range < 3000000) {
            $bracketSize = 250000;
        } elseif ($range < 10000000) {
            $bracketSize = 500000;
        } else {
            $bracketSize = 1000000;
        }

        $rawBracketCount = (int) ceil($range / $bracketSize);
        if ($rawBracketCount < 4 && $bracketSize > 50000) {
            $bracketSize = max(50000, (int) (ceil($range / 6 / 50000) * 50000));
        } elseif ($rawBracketCount > 10) {
            $bracketSize = (int) (ceil($range / 7 / 100000) * 100000);
            if ($bracketSize < 100000) $bracketSize = 100000;
        }

        $startFrom = (int) (floor($floor / $bracketSize) * $bracketSize);
        $numBrackets = (int) ceil(($ceiling - $startFrom) / $bracketSize);
        if ($numBrackets < 1) $numBrackets = 1;

        $askingBracketIdx = null;
        $brackets = [];
        foreach ($prices as $p) {
            $idx = min((int) floor(($p - $startFrom) / $bracketSize), $numBrackets - 1);
            if ($idx < 0) $idx = 0;
            if (!isset($brackets[$idx])) $brackets[$idx] = 0;
            $brackets[$idx]++;
        }
        if ($askingPrice && $askingPrice > 0) {
            $askingBracketIdx = min((int) floor(($askingPrice - $startFrom) / $bracketSize), $numBrackets - 1);
            if ($askingBracketIdx < 0) $askingBracketIdx = 0;
        }

        $result = [];
        $maxCount = max(array_values($brackets + [0 => 1]));
        for ($i = 0; $i < $numBrackets; $i++) {
            $lower = $startFrom + ($i * $bracketSize);
            $upper = $startFrom + (($i + 1) * $bracketSize);
            $count = $brackets[$i] ?? 0;
            if ($count === 0 && $i !== $askingBracketIdx) continue;

            $result[] = [
                'lower'           => $lower,
                'upper'           => $upper,
                'label'           => 'R ' . number_format($lower, 0, '.', ' ') . ' – R ' . number_format($upper, 0, '.', ' '),
                'count'           => $count,
                'bar_pct'         => $maxCount > 0 ? (int) round($count / $maxCount * 100) : 0,
                'contains_asking' => $i === $askingBracketIdx,
            ];
        }

        return [
            'has_data'     => true,
            'brackets'     => $result,
            'total_priced' => count($prices),
            'bracket_size' => $bracketSize,
        ];
    }

    // ── HELPERS ───────────────────────────────────────────────────────────

    private function calcMonthlyHolding(Presentation $p): float
    {
        // Build 8 — delegate to the canonical method. Pre-fix this
        // hardcoded six columns (no garden/pool/security), under-
        // counting freehold properties that had those populated.
        return (float) app(HoldingCostEstimator::class)->monthlyTotalFor($p);
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
