<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;
use Illuminate\Support\Collection;

/**
 * Build 8a — CoreX's INDEPENDENT CMA compute engine.
 *
 * Today AnalysisDataService::compileCmaValuation reprints the values
 * CMA Info itself stated (cma.lower/middle/upper extracted from the
 * uploaded PDF) with Build 3's condition multiplier on Middle. There
 * is no maths against the actual comp pool — see lineage spec §2.8.
 *
 * This service computes three independent valuations from the in-pool
 * comps:
 *   • method_median      — median of sold_price_inc
 *   • method_mean        — mean of sold_price_inc
 *   • method_rm2_extent  — median R/m² × subject extent
 *
 * Each method produces a condition-adjusted variant alongside the raw.
 * The pool's full distribution (min / p25 / median / p75 / max) is
 * surfaced as pool_stats so the divergence between the methods and
 * CMA Info's stated middle can be diagnosed at-a-glance — pool problem
 * vs method-choice problem.
 *
 * INTERNAL ONLY this build. The output rides on the AnalysisDataService
 * payload as 'cma_computed' (sibling to the existing 'cma_valuation')
 * and is automatically frozen into snapshot_payload at publish via the
 * Build 5 mechanism. No review-screen, PDF, or public/show render.
 *
 * Hard rule: ALL money math uses bcmath. Sums, products, divisions,
 * percentile selection — never native float arithmetic. The condition
 * adjustment likewise uses bcmath here (ConditionAdjustmentService's
 * existing applyToMiddle uses native float — Build 3 contract — but
 * this service must hold the line for its own outputs).
 */
final class CmaComputeService
{
    /** bcmath scale for intermediate money math (cents-level precision). */
    public const SCALE = 2;

    /** Build 8b — minimum viable n for a cleaned pool. Below this the
     *  median becomes too noise-vulnerable to be trusted; we fall back
     *  to the previous, less-cleaned stage and surface a flag in the
     *  output so the consumer knows cleaning was deferred. 5 is the
     *  judgment call: enough comps that a single outlier can't move the
     *  median; small enough that thin-market presentations still get
     *  cleaning when they can. Tunable here if Tinker output shows
     *  a different bar is right. */
    public const MIN_VIABLE_N = 5;

    public const DEFAULT_RECENCY_MONTHS = 36;
    public const DEFAULT_IQR_MULTIPLIER = 1.5;

    /**
     * Compute CoreX's independent CMA outputs.
     *
     * Caller provides the already-loaded comp collection. The pool
     * membership rule (whereNull('deleted_at') + included_comp_ids_json
     * whitelist) is enforced by the caller before handing in — this
     * service trusts whatever it receives as "in pool".
     *
     * @param  Presentation                 $presentation
     * @param  Collection<PresentationSoldComp> $inPoolComps
     * @param  bool                         $isSectional
     * @param  array{pct?: ?float, label?: ?string, source?: string}  $conditionContext
     * @return array
     */
    public function compute(
        Presentation $presentation,
        Collection $inPoolComps,
        bool $isSectional,
        array $conditionContext = [],
    ): array {
        // CMA Info's stated middle — copied alongside as the at-a-glance
        // delta anchor. Read from extracted presentation_fields directly
        // so we don't depend on compileCmaValuation's output shape.
        $cmaInfoStatedMiddle = $this->intOrNull(
            optional($presentation->fields->firstWhere('field_key', 'cma.middle_range'))->final_value
        );

        $conditionPct = isset($conditionContext['pct']) && is_numeric($conditionContext['pct'])
            ? (float) $conditionContext['pct']
            : null;

        $extent = $this->resolveSubjectExtentM2($presentation, $isSectional);

        // Build 8b — agency-configurable cleaning controls. Defaults
        // kick in for legacy agencies or when the column is null.
        $agency       = $presentation->agency;
        $recencyMo    = (int) ($agency?->cma_compute_recency_months ?? self::DEFAULT_RECENCY_MONTHS);
        $iqrMult      = (float) ($agency?->cma_compute_iqr_multiplier ?? self::DEFAULT_IQR_MULTIPLIER);

        // AT-22 §5 — agency-configurable range percentiles. The recommended
        // range is the comp distribution P25–P75 (defaults) around the CMA
        // mid; these tune the band edges. Spec §5.
        $rangeLowerPct = (int) ($agency?->range_lower_pct ?? CompPoolBuilder::DEF_RANGE_LOWER_PCT);
        $rangeUpperPct = (int) ($agency?->range_upper_pct ?? CompPoolBuilder::DEF_RANGE_UPPER_PCT);
        $lowerFrac = max(0.0, min(1.0, $rangeLowerPct / 100));
        $upperFrac = max(0.0, min(1.0, $rangeUpperPct / 100));

        // PRES-CMA-REALFIX — recommended-band half-widths (± % around the
        // evaluated value / middle). These are surfaced in pool_stats so
        // compileCmaValuation derives lower/upper = middle ∓ pct WITHOUT
        // re-resolving the agency. Distinct from range_*_pct (percentiles).
        $bandLowerPct = (float) ($agency?->cma_band_lower_pct ?? CompPoolBuilder::DEF_BAND_LOWER_PCT);
        $bandUpperPct = (float) ($agency?->cma_band_upper_pct ?? CompPoolBuilder::DEF_BAND_UPPER_PCT);

        // ── PRE-CLEAN POOL (Build 8a contract) ──────────────────────────
        $preCleanPrices       = $this->extractPrices($inPoolComps);
        $preCleanPricesWithSz = $this->extractPricesWithSize($inPoolComps);
        $nTotalPre            = count($preCleanPrices);
        $nWithSizePre         = count($preCleanPricesWithSz);

        // ── CLEAN: recency cut, then IQR on R/m² ────────────────────────
        $cleaning = $this->cleanPool($inPoolComps, $recencyMo, $iqrMult);
        $cleanedComps         = $cleaning['cleaned_comps'];
        $cleanedPrices        = $this->extractPrices($cleanedComps);
        $cleanedPricesWithSz  = $this->extractPricesWithSize($cleanedComps);

        // AT-22 §5 — distribution (min / p25 / median / p75 / max) now
        // computed on the CLEANED pool so the recommended range (cma_lower
        // = p25 → cma_upper = p75) is consistent with cma_middle (the
        // cleaned-pool median) and free of the sub-R1M tiny-erf sales that
        // the gate + cleaning remove. Pre-AT-22 these came off the raw
        // pre-clean pool, so lower/upper leaked outliers the middle didn't.
        // n_total / n_with_size stay = the full pre-clean pool size (their
        // established meaning); the pre-clean distribution is preserved under
        // 'preclean' for diagnostics. New-generations / live-recompute only;
        // frozen snapshots keep their issued values.
        $nWithSizeCleaned = count($cleanedPricesWithSz);
        $poolStats = array_merge(
            $this->poolStats($cleanedPrices, $nWithSizeCleaned, $lowerFrac, $upperFrac),
            [
                'n_total'              => $nTotalPre,
                'n_with_size'          => $nWithSizePre,
                'n_cleaned'            => count($cleanedPrices),
                'range_lower_pct'      => $rangeLowerPct,
                'range_upper_pct'      => $rangeUpperPct,
                'band_lower_pct'       => $bandLowerPct,
                'band_upper_pct'       => $bandUpperPct,
                'n_after_recency_cut'  => $cleaning['n_after_recency'],
                'n_after_outlier_cut'  => $cleaning['n_after_outlier'],
                'excluded_by_recency'  => $nTotalPre - $cleaning['n_after_recency'],
                'excluded_by_outlier'  => $cleaning['n_after_recency'] - $cleaning['n_after_outlier'],
                'cleaning_fallback'    => $cleaning['fallback'],  // null | 'outlier_too_thin' | 'recency_too_thin'
                'recency_months_used'  => $recencyMo,
                'iqr_multiplier_used'  => $iqrMult,
                'preclean'             => $this->poolStats($preCleanPrices, $nWithSizePre),
            ]
        );

        // Methods compute off the CLEANED pool; preclean sub-key carries
        // the Build 8a values for side-by-side delta inspection.
        return [
            'cma_info_stated_middle' => $cmaInfoStatedMiddle,
            'pool_stats'             => $poolStats,
            'method_median' => array_merge(
                $this->methodMedian($cleanedPrices, $conditionPct),
                ['preclean' => $this->methodMedian($preCleanPrices, $conditionPct)],
            ),
            'method_mean' => array_merge(
                $this->methodMean($cleanedPrices, $conditionPct),
                ['preclean' => $this->methodMean($preCleanPrices, $conditionPct)],
            ),
            'method_rm2_extent' => array_merge(
                $this->methodRm2Extent($cleanedPricesWithSz, $extent, $conditionPct),
                ['preclean' => $this->methodRm2Extent($preCleanPricesWithSz, $extent, $conditionPct)],
            ),
        ];
    }

    // ── Build 8b — cleaning pipeline ────────────────────────────────────

    /**
     * Apply recency cut then R/m² IQR fences (lower AND upper) to the comp pool.
     *
     * Order matters: recency first (drops ancient outliers), THEN IQR
     * on the survivors (self-calibrates against the remaining pool's
     * distribution, not against ancient comps).
     *
     * Min-n floor protects against over-prune. Two-level fallback:
     *   - If IQR cut would leave < MIN_VIABLE_N → use recency-only,
     *     flag 'outlier_too_thin'.
     *   - If recency-only is also < MIN_VIABLE_N → use full pre-clean,
     *     flag 'recency_too_thin'.
     *   - Empty pool falls through to empty results.
     *
     * @return array{
     *   cleaned_comps:    Collection<PresentationSoldComp>,
     *   n_after_recency:  int,
     *   n_after_outlier:  int,
     *   fallback:         ?string,
     * }
     */
    private function cleanPool(Collection $allComps, int $recencyMonths, float $iqrMultiplier): array
    {
        // Recency cut: drop comps whose sold_date is older than now()
        // minus $recencyMonths. Null dates fall through (not excluded —
        // we can't say they're old, the analyst put them in for a reason).
        $cutoff = \Illuminate\Support\Carbon::now()
            ->subMonths(max(0, $recencyMonths))
            ->toDateString();
        $afterRecency = $allComps->filter(function ($c) use ($cutoff) {
            if (empty($c->sold_date)) return true;
            $d = (string) ($c->sold_date instanceof \DateTimeInterface
                ? $c->sold_date->format('Y-m-d')
                : $c->sold_date);
            return $d >= $cutoff;
        })->values();
        $nAfterRecency = $afterRecency->count();

        // IQR cut on R/m² — BOTH lower (median − k×IQR) and upper (Q3 + k×IQR)
        // fences. Comps without size_m2 are NOT evaluated by R/m² so they pass
        // through unchanged.
        $afterOutlier  = $this->applyIqrFences($afterRecency, $iqrMultiplier);
        $nAfterOutlier = $afterOutlier->count();

        // Min-n fallback ladder.
        if ($nAfterOutlier >= self::MIN_VIABLE_N) {
            return [
                'cleaned_comps'   => $afterOutlier,
                'n_after_recency' => $nAfterRecency,
                'n_after_outlier' => $nAfterOutlier,
                'fallback'        => null,
            ];
        }
        if ($nAfterRecency >= self::MIN_VIABLE_N) {
            return [
                'cleaned_comps'   => $afterRecency,
                'n_after_recency' => $nAfterRecency,
                'n_after_outlier' => $nAfterOutlier,
                'fallback'        => 'outlier_too_thin',
            ];
        }
        // Both cuts too aggressive — fall back to the unfiltered pool.
        return [
            'cleaned_comps'   => $allComps->values(),
            'n_after_recency' => $nAfterRecency,
            'n_after_outlier' => $nAfterOutlier,
            'fallback'        => $allComps->count() > 0 ? 'recency_too_thin' : null,
        ];
    }

    /**
     * Apply BOTH IQR outlier fences on R/m² — lower AND upper:
     *
     *   lower fence = median − (multiplier × IQR)   [median-anchored, aggressive]
     *   upper fence = Q3     + (multiplier × IQR)    [Q3-anchored Tukey]
     *
     * where Q1 / median / Q3 are type-7 percentiles of the in-pool R/m²
     * list. Comps without size_m2 pass through unconditionally (no R/m² to
     * evaluate; their absolute price still counts for median/mean).
     *
     * PRES-CMA-SELLER-VOICE (Johan, 2026-06-15) — added the UPPER fence.
     * Pre-fix only the lower side was cut, so a single high outlier (e.g. a
     * lone 2024 sale ~R2,995,000 in a pool whose genuine P75 is ~R2,650,000)
     * survived and became the recommended-band ceiling (cma_upper = p75),
     * over-stating what comparable homes actually sold for. The upper fence
     * is the standard Tukey Q3 + k×IQR with the SAME agency multiplier lever
     * as the lower fence — agency-configurable, never hardcoded.
     *
     * @param  Collection<PresentationSoldComp>  $comps
     * @return Collection<PresentationSoldComp>
     */
    private function applyIqrFences(Collection $comps, float $multiplier): Collection
    {
        // Collect R/m² per comp where computable.
        $rm2List = [];
        foreach ($comps as $c) {
            if ($c->sold_price_inc === null || $c->size_m2 === null || (int) $c->size_m2 <= 0) continue;
            $rm2List[] = bcdiv((string) (int) $c->sold_price_inc, (string) (int) $c->size_m2, self::SCALE);
        }
        if (count($rm2List) < self::MIN_VIABLE_N) {
            // Pool too thin to compute a sensible fence — skip the cut.
            return $comps;
        }
        usort($rm2List, fn($a, $b) => bccomp($a, $b, self::SCALE));
        // Type-7 percentiles for Q1/median/Q3 — same fix as the
        // tile-driving poolStats. On even-length R/m² pools the
        // pre-fix nearest-rank indexer produced a fence anchored on
        // the wrong values, drifting the IQR cleaning decision.
        $q1  = $this->percentile($rm2List, 0.25);
        $med = $this->percentile($rm2List, 0.5);
        $q3  = $this->percentile($rm2List, 0.75);
        $iqr = bcsub($q3, $q1, self::SCALE);
        $multStr = number_format($multiplier, 4, '.', '');
        $iqrScaled = bcmul($iqr, $multStr, self::SCALE);
        // Lower fence = median − k×IQR. Median anchor is more aggressive than
        // Q1 anchor — catches noise that pushes Q1 down into the outlier zone.
        $lowerFence = bcsub($med, $iqrScaled, self::SCALE);
        // Upper fence = Q3 + k×IQR (standard Tukey). Q3 anchor is gentler than
        // a median anchor would be, so genuinely high suburbs are not gutted —
        // only the lone sale that sits well beyond the spread is dropped.
        $upperFence = bcadd($q3, $iqrScaled, self::SCALE);

        return $comps->filter(function ($c) use ($lowerFence, $upperFence) {
            if ($c->sold_price_inc === null || $c->size_m2 === null || (int) $c->size_m2 <= 0) {
                return true; // no R/m² to evaluate — pass through
            }
            $rm2 = bcdiv((string) (int) $c->sold_price_inc, (string) (int) $c->size_m2, self::SCALE);
            return bccomp($rm2, $lowerFence, self::SCALE) >= 0
                && bccomp($rm2, $upperFence, self::SCALE) <= 0;
        })->values();
    }

    // ── Distribution stats ──────────────────────────────────────────────

    /**
     * pool_stats — Min / P25 / Median / P75 / Max of sold_price_inc,
     * plus the two counts the user asked for (total, with-size).
     *
     * Percentile rule: nearest-rank on the sorted list:
     *   p25 = sorted[floor(n * 0.25)]
     *   median = sorted[floor(n * 0.5)]
     *   p75 = sorted[floor(n * 0.75)]
     * Matches the Phase A probe output exactly so the discovery numbers
     * reproduce.
     *
     * @param  list<string> $sortedPricesAsc   already sorted ascending
     * @param  int          $nWithSize
     * @return array
     */
    private function poolStats(array $sortedPricesAsc, int $nWithSize, float $lowerFrac = 0.25, float $upperFrac = 0.75): array
    {
        $n = count($sortedPricesAsc);
        if ($n === 0) {
            return [
                'n_total'     => 0,
                'n_with_size' => 0,
                'min'         => null,
                'p25'         => null,
                'median'      => null,
                'p75'         => null,
                'max'         => null,
            ];
        }
        // p25/p75 are the recommended-range slots — the fraction is
        // agency-configurable (AT-22 §5), defaulting to the textbook
        // quartiles. The key names stay p25/p75 (consumers read those
        // slots); range_lower_pct/range_upper_pct in pool_stats record
        // the actual fractions used.
        return [
            'n_total'     => $n,
            'n_with_size' => $nWithSize,
            'min'         => $this->toInt($sortedPricesAsc[0]),
            'p25'         => $this->toInt($this->percentile($sortedPricesAsc, $lowerFrac)),
            'median'      => $this->toInt($this->percentile($sortedPricesAsc, 0.5)),
            'p75'         => $this->toInt($this->percentile($sortedPricesAsc, $upperFrac)),
            'max'         => $this->toInt($sortedPricesAsc[$n - 1]),
        ];
    }

    /**
     * Type-7 linear-interpolation percentile (NumPy / R / Excel default).
     *
     *   idx = (n - 1) * p     (0-based)
     *   integer idx → return $sorted[idx]
     *   fractional idx → bcmath linear interpolate between
     *                    $sorted[floor(idx)] and $sorted[ceil(idx)]
     *
     * Replaces the pre-fix nearest-rank `$sorted[floor(n*p)]` pattern
     * which grabbed the upper-middle element on even-count pools at
     * p=0.5 (producing the wrong median for n in {2,4,6,...}). Same
     * pattern duplicated at four call sites — all routed through this
     * helper to fix the class structurally. For p=0.5 on even n,
     * Type-7 reduces exactly to "average of the two middle elements"
     * — the textbook even-count median.
     *
     * bcmath ONLY for the new arithmetic (per CLAUDE.md money-math
     * non-negotiable). Caller guards empty pools — helper assumes
     * count ≥ 1.
     *
     * @param  list<string> $sortedAsc  bcmath-numeric strings, ascending
     * @param  float        $p          0.0–1.0
     * @return string                   bcmath at self::SCALE
     */
    private function percentile(array $sortedAsc, float $p): string
    {
        $n = count($sortedAsc);
        if ($n === 1) {
            return (string) $sortedAsc[0];
        }

        $idx     = ($n - 1) * $p;
        $lowerIx = (int) floor($idx);
        $upperIx = (int) ceil($idx);

        if ($lowerIx === $upperIx) {
            return (string) $sortedAsc[$lowerIx];
        }

        $lower = (string) $sortedAsc[$lowerIx];
        $upper = (string) $sortedAsc[$upperIx];
        $diff  = bcsub($upper, $lower, self::SCALE);

        // Fraction is bounded to (0, 1) here — floor/ceil bracket idx.
        // number_format pins the bcmath string at fixed precision so
        // locale settings can't slip a comma into the decimal mark.
        $fracStr = number_format($idx - $lowerIx, 6, '.', '');
        $delta   = bcmul($diff, $fracStr, self::SCALE);

        return bcadd($lower, $delta, self::SCALE);
    }

    // ── method_median ───────────────────────────────────────────────────

    /**
     * @param  list<string> $sortedPricesAsc
     */
    private function methodMedian(array $sortedPricesAsc, ?float $conditionPct): array
    {
        $n = count($sortedPricesAsc);
        if ($n === 0) {
            return $this->emptyMethodResult($conditionPct);
        }
        // Type-7 median via percentile helper — for even n this averages
        // the two middle elements in bcmath (textbook even-count
        // median). Pre-fix the nearest-rank `floor(n*0.5)` indexer
        // grabbed the upper of the two middles, producing wrong values
        // for every even-length pool.
        $raw = $this->percentile($sortedPricesAsc, 0.5);
        return $this->methodResult($raw, $conditionPct, $n);
    }

    // ── method_mean ─────────────────────────────────────────────────────

    /**
     * @param  list<string> $sortedPricesAsc
     */
    private function methodMean(array $sortedPricesAsc, ?float $conditionPct): array
    {
        $n = count($sortedPricesAsc);
        if ($n === 0) {
            return $this->emptyMethodResult($conditionPct);
        }
        $sum = '0';
        foreach ($sortedPricesAsc as $p) {
            $sum = bcadd($sum, $p, self::SCALE);
        }
        $raw = bcdiv($sum, (string) $n, 0); // integer rand for display
        return $this->methodResult($raw, $conditionPct, $n);
    }

    // ── method_rm2_extent ───────────────────────────────────────────────

    /**
     * Median R/m² across comps with both price and size, multiplied by
     * the subject's extent.
     *
     * @param  list<array{price:string, size:int}> $pricesWithSize
     * @param  ?int  $subjectExtent
     */
    private function methodRm2Extent(array $pricesWithSize, ?int $subjectExtent, ?float $conditionPct): array
    {
        if (empty($pricesWithSize) || $subjectExtent === null || $subjectExtent <= 0) {
            return [
                'rm2_median'         => null,
                'subject_extent_m2'  => $subjectExtent,
                'raw'                => null,
                'condition_adjusted' => null,
                'condition_pct'      => $conditionPct,
                'n'                  => count($pricesWithSize),
            ];
        }
        $rm2s = [];
        foreach ($pricesWithSize as $row) {
            $rm2s[] = bcdiv($row['price'], (string) $row['size'], self::SCALE);
        }
        sort($rm2s, SORT_STRING);
        // Re-sort numerically — bcmath strings sort lexicographically in
        // SORT_STRING ('100.00' < '99.00'). Use a usort callback wrapping
        // bccomp for correctness.
        usort($rm2s, fn($a, $b) => bccomp($a, $b, self::SCALE));
        // Type-7 median (same fix as methodMedian) — even-count pools
        // now average the two middle R/m² values instead of grabbing
        // the upper-middle.
        $rm2Median = $this->percentile($rm2s, 0.5);
        $raw       = bcmul($rm2Median, (string) $subjectExtent, 0);

        return [
            'rm2_median'         => $this->toInt($rm2Median),
            'subject_extent_m2'  => $subjectExtent,
            'raw'                => $this->toInt($raw),
            'condition_adjusted' => $this->applyConditionBc($raw, $conditionPct),
            'condition_pct'      => $conditionPct,
            'n'                  => count($pricesWithSize),
        ];
    }

    // ── Subject extent picker ───────────────────────────────────────────

    /**
     * Mirrors Build 7's per-title_type extent read-order so the engine
     * picks the same extent the review screen + PDF size row use.
     *   sectional   → floor area (property.size_m2 || presentation.floor_area_m2)
     *   full/vacant → erf size   (property.erf_size_m2 || presentation.erf_size_m2)
     *   other/null  → try erf first, then size, both layers
     */
    private function resolveSubjectExtentM2(Presentation $presentation, bool $isSectional): ?int
    {
        if ($isSectional) {
            return $this->intOrNull($presentation->property?->size_m2)
                ?? $this->intOrNull($presentation->floor_area_m2);
        }
        return $this->intOrNull($presentation->property?->erf_size_m2)
            ?? $this->intOrNull($presentation->erf_size_m2)
            ?? $this->intOrNull($presentation->property?->size_m2)
            ?? $this->intOrNull($presentation->floor_area_m2);
    }

    // ── Pool extraction ─────────────────────────────────────────────────

    /**
     * Sorted (ascending, bcmath-correct) list of in-pool sold prices.
     *
     * @return list<string>
     */
    private function extractPrices(Collection $comps): array
    {
        $prices = [];
        foreach ($comps as $c) {
            if ($c->sold_price_inc === null) continue;
            $p = (string) (int) $c->sold_price_inc;
            if (bccomp($p, '0', self::SCALE) <= 0) continue;
            $prices[] = $p;
        }
        usort($prices, fn($a, $b) => bccomp($a, $b, self::SCALE));
        return $prices;
    }

    /**
     * In-pool comps that contributed BOTH a non-null price AND a
     * non-zero size_m2. Used as the R/m² denominator. Order does not
     * matter for the median calc — we sort the derived R/m² list.
     *
     * @return list<array{price:string, size:int}>
     */
    private function extractPricesWithSize(Collection $comps): array
    {
        $out = [];
        foreach ($comps as $c) {
            if ($c->sold_price_inc === null) continue;
            $sz = $c->size_m2 !== null ? (int) $c->size_m2 : 0;
            if ($sz <= 0) continue; // div-by-zero guard
            $p = (string) (int) $c->sold_price_inc;
            if (bccomp($p, '0', self::SCALE) <= 0) continue;
            $out[] = ['price' => $p, 'size' => $sz];
        }
        return $out;
    }

    // ── Condition adjustment (bcmath) ───────────────────────────────────

    /**
     * Apply the condition pct to a base value using bcmath. Mirrors
     * ConditionAdjustmentService::applyToMiddle's behaviour but holds
     * the bcmath discipline this build requires for new financial math.
     *
     *   adjusted = round(base * (1 + pct/100))
     */
    private function applyConditionBc(?string $base, ?float $pct): ?int
    {
        if ($base === null) return null;
        if ($pct === null || abs($pct) < 0.005) {
            return $this->toInt($base);
        }
        // pct as a bcmath-safe string. number_format avoids locale woes.
        $pctStr = number_format($pct, 6, '.', '');
        // factor = 1 + pct/100
        $factor = bcadd('1', bcdiv($pctStr, '100', 8), 8);
        $adjusted = bcmul($base, $factor, 4);
        // Round to nearest integer rand (away from zero, simple half-up).
        $adjusted = $this->bcRoundHalfUp($adjusted);
        return $this->toInt($adjusted);
    }

    private function bcRoundHalfUp(string $val): string
    {
        // Add 0.5 (positive) or -0.5 (negative) then truncate via bcadd 0.
        $half = bccomp($val, '0', 8) >= 0 ? '0.5' : '-0.5';
        $adj  = bcadd($val, $half, 8);
        return bcadd($adj, '0', 0);
    }

    // ── Shared method-result shape ──────────────────────────────────────

    private function methodResult(string $raw, ?float $conditionPct, int $n): array
    {
        return [
            'raw'                => $this->toInt($raw),
            'condition_adjusted' => $this->applyConditionBc($raw, $conditionPct),
            'condition_pct'      => $conditionPct,
            'n'                  => $n,
        ];
    }

    private function emptyMethodResult(?float $conditionPct): array
    {
        return [
            'raw'                => null,
            'condition_adjusted' => null,
            'condition_pct'      => $conditionPct,
            'n'                  => 0,
        ];
    }

    // ── Small utilities ─────────────────────────────────────────────────

    private function intOrNull($v): ?int
    {
        if ($v === null) return null;
        if (is_string($v) && trim($v) === '') return null;
        if (is_numeric($v)) {
            $i = (int) $v;
            return $i !== 0 || ((string) $v === '0' || $v === 0)
                ? $i
                : null;
        }
        return null;
    }

    /** Bcmath-string → int. Truncates fraction; no float round-trip. */
    private function toInt(string $bcStr): int
    {
        $whole = bcadd($bcStr, '0', 0);
        return (int) $whole;
    }
}
