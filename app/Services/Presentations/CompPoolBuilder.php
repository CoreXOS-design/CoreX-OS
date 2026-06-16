<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Agency;
use App\Services\TitleTypeClassifier;

/**
 * AT-22 §1 / §1.5 — the shared comp gate-then-rank + valuation-anchor engine.
 *
 * Before AT-22 the sold-comp pool was selected by suburb-only match (no
 * price/erf gate) at a 1000m radius, so a R2.9M / 1,375m² subject pulled
 * sub-R1M tiny-erf sales and the raw market estimate collapsed to ~R1.1M.
 * This service is the single source of truth for "which comps are true
 * comparables for this subject, and what is the defensible market anchor".
 *
 * It is used by:
 *   • MicSnapshotHydrator — at generation, to decide which candidate rows
 *     are persisted as presentation_sold_comps.
 *   • CmaCoverageService  — at the Generate modal, to produce the market
 *     anchor that the "Suggestion based on suburb data" field binds to
 *     (NOT the asking price).
 *
 * Pipeline (spec §1 Stage A gates → Stage B ranking, §1.5 anchor robustness):
 *   1. TYPE hard-gate — like-for-like property kind first, category
 *      (freehold/sectional) fallback. NEVER cross freehold ↔ sectional.
 *   2. Provisional anchor — median of the type-gated pool (robust: it is
 *      the cleaned, type-matched set, not the raw all-comps median — this
 *      is what fixes the R1.1M trap, §1.5).
 *   3. PRICE-band gate — keep comps within anchor ± price_band_pct.
 *   4. RADIUS gate with widen-if-thin ladder — start at radius_m, widen
 *      through the ladder until min_count resolves or the ceiling is hit.
 *   5. DIVERGENCE guard — if the narrow-pool anchor diverges from the
 *      broader type-gated median by more than divergence_pct, widen once
 *      more (the pool is unrepresentatively thin/low).
 *   6. RANK — erf-size proximity + distance + price-proximity; shortlist
 *      max_count. Exempt comps (same-subject / trusted-internal deals) are
 *      retained regardless of the price gate and the shortlist cap.
 *
 * Selection arithmetic is integer rand (gate logic, not displayed money).
 * Displayed financial figures stay in CmaComputeService (bcmath).
 */
final class CompPoolBuilder
{
    public const DEF_PRICE_BAND_PCT  = 25.0;
    public const DEF_ERF_BAND_PCT    = 30.0;
    public const DEF_RADIUS_M        = 300;
    public const DEF_RADIUS_STEPS    = [300, 600, 1000, 1500, 3000];
    public const DEF_RADIUS_MAX_M    = 3000;
    public const DEF_MIN_COUNT       = 10;
    public const DEF_MAX_COUNT       = 15;
    public const DEF_DIVERGENCE_PCT  = 25.0;
    public const DEF_RANGE_LOWER_PCT = 25;
    public const DEF_RANGE_UPPER_PCT = 75;

    /**
     * PRES-CMA-REALFIX — recommended-band half-widths (± % around the
     * evaluated value / middle). NOT percentiles — the band is derived as
     * lower = middle × (1 − DEF_BAND_LOWER_PCT/100), upper = middle ×
     * (1 + DEF_BAND_UPPER_PCT/100). ASYMMETRIC: reverse-engineering CMA's own
     * stated low/middle/high across 105 evidenced imported reports gave a
     * market norm of ~10% below / ~13% above the indicated value (CMA sits the
     * value closer to the floor than the ceiling). These are the code-level
     * fallback when an agency has no value; the DB column default matches.
     */
    public const DEF_BAND_LOWER_PCT = 10.0;
    public const DEF_BAND_UPPER_PCT = 13.0;

    /**
     * PRES-CMA-FIX — subject self-exclusion GPS threshold (metres).
     *
     * A sold comp whose coordinates sit within this radius of the subject
     * IS the subject's own erf — its prior sale, a duplicate scrape, or a
     * deeds-office echo of the same physical property — and must never
     * count as its own comparable (it would leak the subject's own value
     * into the P25–P75 band). 8m mirrors the Match-or-Create GPS strategy
     * (~5m) with a little slack for geocode jitter, while staying far below
     * neighbouring-erf separation. NOT applied to sectional subjects —
     * units in one scheme legitimately share a GPS point and are the BEST
     * comps; for those the address signal alone identifies the same unit.
     */
    public const SUBJECT_SELF_RADIUS_M = 8;

    /**
     * Resolve the agency-configurable thresholds (AT-22 migration columns),
     * each falling back to the service constant when the column is null
     * (legacy agencies).
     *
     * @return array{
     *   price_band_pct: float, erf_band_pct: float, radius_m: int,
     *   radius_steps: list<int>, radius_max_m: int, min_count: int,
     *   max_count: int, divergence_pct: float,
     *   range_lower_pct: int, range_upper_pct: int,
     * }
     */
    public static function configForAgency(?Agency $agency): array
    {
        $steps = self::parseSteps($agency?->comp_radius_widen_steps);

        return [
            'price_band_pct'  => self::floatOr($agency?->comp_price_band_pct, self::DEF_PRICE_BAND_PCT),
            'erf_band_pct'    => self::floatOr($agency?->comp_erf_band_pct, self::DEF_ERF_BAND_PCT),
            'radius_m'        => self::intOr($agency?->comp_radius_m, self::DEF_RADIUS_M),
            'radius_steps'    => $steps,
            'radius_max_m'    => self::intOr($agency?->comp_radius_max_m, self::DEF_RADIUS_MAX_M),
            'min_count'       => self::intOr($agency?->comp_min_count, self::DEF_MIN_COUNT),
            'max_count'       => self::intOr($agency?->comp_max_count, self::DEF_MAX_COUNT),
            'divergence_pct'  => self::floatOr($agency?->anchor_divergence_pct, self::DEF_DIVERGENCE_PCT),
            'range_lower_pct' => self::intOr($agency?->range_lower_pct, self::DEF_RANGE_LOWER_PCT),
            'range_upper_pct' => self::intOr($agency?->range_upper_pct, self::DEF_RANGE_UPPER_PCT),
        ];
    }

    /**
     * Run the gate-then-rank pipeline.
     *
     * @param  array  $subject  ['title_type'=>?string, 'property_type'=>?string,
     *                           'lat'=>?float, 'lng'=>?float, 'erf_m2'=>?int,
     *                           'anchor_price'=>?int, 'address'=>?string]
     *                           (anchor_price = subject asking / market estimate
     *                           for the price band; address feeds the subject
     *                           self-exclusion guard.)
     * @param  list<array>  $candidates  each:
     *     ['key'=>mixed, 'price'=>int, 'size_m2'=>?int, 'property_type'=>?string,
     *      'title_type'=>?string, 'lat'=>?float, 'lng'=>?float, 'exempt'=>bool,
     *      'address'=>?string]
     *     ('exempt' = same-subject report OR trusted-internal deal — waives the
     *      PRICE band ONLY; still type-gated, radius-bound and shortlist-capped.
     *      'address' is optional; it feeds the subject self-exclusion guard.)
     * @param  array  $config  output of configForAgency()
     * @return array{
     *   selected: list<array>,        // candidate rows that survived, ranked
     *   selected_keys: list<mixed>,
     *   radius_used: int,
     *   anchor: ?int,                 // robust market anchor (median of selected)
     *   widened: bool,
     *   diagnostics: array,
     * }
     */
    public function select(array $subject, array $candidates, array $config): array
    {
        $classifier   = app(TitleTypeClassifier::class);
        $subjectCat    = $this->category($classifier, $subject['title_type'] ?? null, $subject['property_type'] ?? null);
        $subjectKind   = $this->kind($subject['property_type'] ?? null);
        $sLat = $this->floatOrNull($subject['lat'] ?? null);
        $sLng = $this->floatOrNull($subject['lng'] ?? null);
        $sErf = $this->intOrNull($subject['erf_m2'] ?? null);
        // AT-22 §1.5 — subject-derived band anchor (asking / market estimate).
        // When present it anchors the price gate, so a pool polluted with
        // off-profile sales cannot drag the band down (the R927k/R1.1M trap).
        $sAnchorPrice = $this->intOrNull($subject['anchor_price'] ?? null);
        // PRES-CMA-FIX — subject self-exclusion inputs. Address equality is
        // the type-safe signal (works for sectional too — unit numbers
        // differ); GPS coincidence is the secondary signal, gated off for
        // sectional subjects (scheme-mates share a point).
        $sAddrNorm        = $this->normaliseAddr($subject['address'] ?? null);
        $subjectSectional = ($subjectCat === TitleTypeClassifier::TITLE_SECTIONAL);
        $nSelfExcluded    = 0;

        // Normalise candidates: attach category, kind, distance.
        $norm = [];
        foreach ($candidates as $c) {
            $price = $this->intOrNull($c['price'] ?? null);
            if ($price === null || $price <= 0) {
                continue; // no usable price — cannot be a comp
            }
            $cLat = $this->floatOrNull($c['lat'] ?? null);
            $cLng = $this->floatOrNull($c['lng'] ?? null);
            $dist = ($sLat !== null && $sLng !== null && $cLat !== null && $cLng !== null)
                ? \App\Support\MarketAnalytics\HaversineDistance::distanceMetres($sLat, $sLng, $cLat, $cLng)
                : null;
            // PRES-CMA-FIX — the subject can never be its own comparable.
            // Drop a candidate that resolves to the subject property before
            // it ever reaches the gates/ranking/quartiles.
            if ($this->isSubjectSelf($subjectSectional, $sAddrNorm, $dist, $c['address'] ?? null)) {
                $nSelfExcluded++;
                continue;
            }
            $norm[] = [
                'key'        => $c['key'] ?? null,
                'price'      => $price,
                'size_m2'    => $this->intOrNull($c['size_m2'] ?? null),
                'category'   => $this->category($classifier, $c['title_type'] ?? null, $c['property_type'] ?? null),
                'kind'       => $this->kind($c['property_type'] ?? null),
                'distance_m' => $dist,
                'exempt'     => (bool) ($c['exempt'] ?? false),
                'raw'        => $c,
            ];
        }

        $diagnostics = [
            'n_candidates'           => count($norm),
            'n_subject_self_excluded' => $nSelfExcluded,
        ];

        // ── Stage A.1 — TYPE hard-gate (never cross freehold ↔ sectional) ──
        // A candidate is dropped only when BOTH the subject and candidate
        // resolve to a known, DIFFERENT title category. Unknown/other on
        // either side does not force a drop (fail-open-on-unknown posture).
        // AT-22 round-1: exempt (analyst-vetted) comps are NO LONGER waved
        // through the type gate — a vetted sectional comp must not pollute a
        // freehold pool. Exemption waives the PRICE band only (Johan, 11 Jun).
        $typeGated = array_values(array_filter($norm, function ($c) use ($subjectCat) {
            if ($subjectCat === null || $c['category'] === null
                || $subjectCat === TitleTypeClassifier::TITLE_OTHER
                || $c['category'] === TitleTypeClassifier::TITLE_OTHER) {
                return true;
            }
            return $c['category'] === $subjectCat;
        }));
        $diagnostics['n_after_type'] = count($typeGated);

        if (empty($typeGated)) {
            return $this->emptyResult($config, $diagnostics);
        }

        // ── Stage A.2 — band anchor ────────────────────────────────────────
        // §1.5: prefer the SUBJECT-derived anchor (asking / market estimate)
        // so a polluted pool can't drag the band down. Only when no subject
        // anchor is supplied do we fall back to the type-gated median (which
        // is itself reliable once the type gate has removed off-category
        // sales). The raw all-comps median that produced R1.1M is never used.
        $anchorBroad = $this->median(array_map(fn ($c) => $c['price'], $typeGated));
        $bandAnchor  = ($sAnchorPrice !== null && $sAnchorPrice > 0) ? $sAnchorPrice : $anchorBroad;
        $diagnostics['anchor_broad']   = $anchorBroad;
        $diagnostics['anchor_subject'] = $sAnchorPrice;
        $diagnostics['anchor_used']    = $bandAnchor;

        // ── Stage A.3 — PRICE band gate (around the band anchor) ───────────
        // Exempt (analyst-vetted) comps waive ONLY this price band — they are
        // still type-gated above and radius/shortlist-bound below.
        $bandPct = (float) $config['price_band_pct'];
        $low  = $bandAnchor !== null ? (int) floor($bandAnchor * (1 - $bandPct / 100)) : 0;
        $high = $bandAnchor !== null ? (int) ceil($bandAnchor * (1 + $bandPct / 100)) : PHP_INT_MAX;
        $priceGated = array_values(array_filter($typeGated, function ($c) use ($low, $high) {
            return $c['exempt'] || ($c['price'] >= $low && $c['price'] <= $high);
        }));
        $diagnostics['price_band']    = ['low' => $low, 'high' => $high, 'pct' => $bandPct];
        $diagnostics['n_after_price'] = count($priceGated);

        // ── Stage A.4 — RADIUS gate with widen-if-thin ladder ──────────────
        $ladder = $this->effectiveLadder($config);
        [$selected, $radiusUsed] = $this->selectWithinRadius($priceGated, $ladder, (int) $config['min_count'], $low, $high);
        $diagnostics['radius_ladder'] = $ladder;

        // ── Stage A.5 — DIVERGENCE guard (widen once more if unrepresentative)
        $widened = false;
        $anchorSel = $this->median(array_map(fn ($c) => $c['price'], $selected)) ?? $anchorBroad;
        if ($anchorBroad !== null && $anchorBroad > 0 && $radiusUsed < $this->ceiling($ladder, $config)) {
            $divergence = abs($anchorSel - $anchorBroad) / $anchorBroad * 100;
            if ($divergence > (float) $config['divergence_pct']) {
                $next = $this->nextLadderStep($ladder, $radiusUsed, (int) $config['radius_max_m']);
                if ($next > $radiusUsed) {
                    [$selected, $radiusUsed] = $this->selectWithinRadius($priceGated, [$next], (int) $config['min_count'], $low, $high);
                    $anchorSel = $this->median(array_map(fn ($c) => $c['price'], $selected)) ?? $anchorBroad;
                    $widened = true;
                    $diagnostics['divergence_pct'] = round($divergence, 1);
                }
            }
        }

        // ── Stage B — like-for-like kind preference + RANK + shortlist ─────
        // Like-for-like: if enough same-kind comps survive, prefer them;
        // otherwise keep the category-level pool (spec §1 type fallback).
        if ($subjectKind !== null) {
            $sameKind = array_values(array_filter($selected, fn ($c) => $c['exempt'] || $c['kind'] === $subjectKind));
            if (count($sameKind) >= (int) $config['min_count']) {
                $selected = $sameKind;
            }
        }

        // AT-22 round-1 (premium-comp fix): rank price-proximity against the
        // SUBJECT band anchor (asking / market estimate), NOT the selected-pool
        // median. Ranking on the pool median re-introduces the §1.5 trap inside
        // the shortlist — a pool still containing cheap nearby sales has a low
        // median, so cheap comps score "on-target" and the genuine premium
        // comps sink. Targeting the subject value makes the on-tier comps win
        // the 15 slots. Falls back to the pool median only when no anchor.
        $rankTarget = ($bandAnchor !== null && $bandAnchor > 0) ? $bandAnchor : $anchorSel;
        $ranked   = $this->rank($selected, $rankTarget, $sErf, (float) $config['erf_band_pct']);
        $shortlist = $this->shortlist($ranked, (int) $config['max_count']);

        $anchorFinal = $this->median(array_map(fn ($c) => $c['price'], $shortlist)) ?? $anchorSel;

        $diagnostics['n_selected'] = count($shortlist);

        return [
            'selected'      => array_map(fn ($c) => $c['raw'], $shortlist),
            'selected_keys' => array_map(fn ($c) => $c['key'], $shortlist),
            'radius_used'   => $radiusUsed,
            'anchor'        => $anchorFinal,
            'widened'       => $widened,
            'diagnostics'   => $diagnostics,
        ];
    }

    // ── Radius selection ────────────────────────────────────────────────

    /**
     * Select price-gated comps within the radius ladder, widening until
     * min_count resolves or the ladder ends. Coord-less comps (distance
     * null — matched by suburb) always count (the suburb fallback keeps
     * rural/coord-less mandates resolvable, spec §1). Exempt comps are NO
     * LONGER radius-exempt (AT-22 round-1) — exemption waives price only.
     *
     * @param  list<array>  $pool
     * @param  list<int>    $ladder
     * @param  ?int  $bandLow   price-band lower bound; only in-band comps count
     * @param  ?int  $bandHigh  price-band upper bound (toward min_count stop)
     * @return array{0: list<array>, 1: int}  [selected, radius_used]
     */
    private function selectWithinRadius(array $pool, array $ladder, int $minCount, ?int $bandLow = null, ?int $bandHigh = null): array
    {
        $lastSelected = [];
        $lastRadius   = $ladder[0] ?? self::DEF_RADIUS_M;
        foreach ($ladder as $r) {
            // AT-22 round-1: exempt comps no longer bypass radius — only
            // coord-less comps (distance null, suburb-matched) still pass, to
            // keep rural/coord-less mandates resolvable (spec §1).
            $sel = array_values(array_filter($pool, function ($c) use ($r) {
                return $c['distance_m'] === null || $c['distance_m'] <= $r;
            }));
            $lastSelected = $sel;
            $lastRadius   = $r;
            // AT-22 round-1 (premium-comp fix): the ladder stops only when
            // enough PROFILE-MATCHING (in price-band) comps resolve — cheap
            // exempt comps that waived the band must NOT halt the widen before
            // the on-tier comps (which for a premium home sit further out) are
            // reached. PRES 87: 22 sub-R1.8M sales satisfied the count at 600m
            // and hid the 5 premium ≥R2M comps at 600–1000m. Falls back to
            // total count when no band anchor is supplied.
            $qualifying = ($bandLow === null || $bandHigh === null)
                ? count($sel)
                : count(array_filter($sel, fn ($c) => $c['price'] >= $bandLow && $c['price'] <= $bandHigh));
            if ($qualifying >= $minCount) {
                return [$sel, $r];
            }
        }
        // Ladder exhausted — return the widest result we got.
        return [$lastSelected, $lastRadius];
    }

    /** @param list<int> $ladder */
    private function nextLadderStep(array $ladder, int $current, int $ceiling): int
    {
        foreach ($ladder as $r) {
            if ($r > $current) {
                return min($r, $ceiling);
            }
        }
        return min($ceiling, max($current, end($ladder) ?: $current));
    }

    /** @param list<int> $ladder */
    private function ceiling(array $ladder, array $config): int
    {
        return min((int) $config['radius_max_m'], (int) (end($ladder) ?: $config['radius_max_m']));
    }

    /**
     * Effective ladder: every configured widen step that is >= the initial
     * radius and <= the ceiling, starting at the initial radius. Always at
     * least one rung.
     *
     * @return list<int>
     */
    private function effectiveLadder(array $config): array
    {
        $initial = (int) $config['radius_m'];
        $max     = (int) $config['radius_max_m'];
        $steps   = $config['radius_steps'] ?: self::DEF_RADIUS_STEPS;

        $ladder = [$initial];
        foreach ($steps as $s) {
            $s = (int) $s;
            if ($s > $initial && $s <= $max) {
                $ladder[] = $s;
            }
        }
        if ($max > end($ladder)) {
            $ladder[] = $max;
        }
        $ladder = array_values(array_unique(array_filter($ladder, fn ($r) => $r > 0)));
        sort($ladder);
        return $ladder ?: [self::DEF_RADIUS_M];
    }

    // ── Ranking ───────────────────────────────────────────────────────────

    /**
     * Rank by a composite of erf-size proximity, distance closeness, and
     * price proximity to the anchor (all normalised 0..1, higher = better).
     * Exempt comps get a small boost so they sort to the front but are not
     * the only thing that matters.
     *
     * @param  list<array>  $pool
     * @return list<array>  ranked desc, each with an added '_score'
     */
    private function rank(array $pool, ?int $anchor, ?int $subjectErf, float $erfBandPct): array
    {
        foreach ($pool as &$c) {
            $erfScore = 1.0;
            if ($subjectErf !== null && $subjectErf > 0 && $c['size_m2'] !== null && $c['size_m2'] > 0) {
                $delta = abs($c['size_m2'] - $subjectErf) / $subjectErf; // 0 = identical
                // Full marks within the band, decaying beyond it.
                $erfScore = $delta <= ($erfBandPct / 100) ? 1.0 : max(0.0, 1.0 - ($delta - $erfBandPct / 100));
            }
            $distScore = 1.0;
            if ($c['distance_m'] !== null) {
                $distScore = 1.0 / (1.0 + ($c['distance_m'] / 1000)); // 0m→1.0, 1km→0.5
            }
            $priceScore = 1.0;
            if ($anchor !== null && $anchor > 0) {
                $pd = abs($c['price'] - $anchor) / $anchor;
                $priceScore = max(0.0, 1.0 - $pd);
            }
            // AT-22 round-1 (premium-comp fix): rank on PROFILE SIMILARITY —
            // value-tier (price proximity to the subject anchor) and erf size
            // dominate; raw distance is a tiebreaker, not the lead factor. The
            // old weighting (dist 0.35 + a 0.15 exempt boost) let cheap, near,
            // vetted sales out-rank genuine premium comps a little further out.
            // Exemption no longer inflates rank — it only waives the price band
            // for inclusion eligibility (Johan, 11 Jun).
            $c['_score'] = 0.40 * $priceScore + 0.35 * $erfScore + 0.25 * $distScore;
        }
        unset($c);
        usort($pool, fn ($a, $b) => $b['_score'] <=> $a['_score']);
        return $pool;
    }

    /**
     * Shortlist to max_count, but NEVER drop an exempt comp (same-subject /
     * trusted-internal). Exempt rows are retained in full; non-exempt fill
     * the remaining slots by rank.
     *
     * @param  list<array>  $ranked
     * @return list<array>
     */
    private function shortlist(array $ranked, int $maxCount): array
    {
        if ($maxCount <= 0 || count($ranked) <= $maxCount) {
            return $ranked;
        }
        // AT-22 round-1: exempt (analyst-vetted) comps no longer bypass the
        // cap — they compete for the max_count slots by rank like any other
        // comp. They still sort to the front via the +0.15 exemption boost in
        // rank(), so a genuinely relevant vetted comp wins its slot; it just
        // can't blow the pool past the cap (PRES 87: 94 exempt → capped to 15).
        return array_slice($ranked, 0, $maxCount);
    }

    // ── Subject self-exclusion ──────────────────────────────────────────────

    /**
     * PRES-CMA-FIX — true when a candidate IS the subject property and must
     * be dropped from its own comparable-sales pool.
     *
     * Two signals, in priority order:
     *   1. Address equality (normalised) — strongest, type-safe. A comp at
     *      the subject's exact address is the same unit, even inside a
     *      sectional scheme (unit numbers differ between neighbours).
     *   2. GPS coincidence within SUBJECT_SELF_RADIUS_M — a sold comp on the
     *      subject's own coordinates is the subject's erf. SKIPPED for
     *      sectional subjects: scheme-mates legitimately share one GPS point
     *      and are the best comps; a coordinate cut would gut that pool.
     */
    private function isSubjectSelf(bool $subjectSectional, ?string $subjAddrNorm, ?float $distanceM, ?string $candAddr): bool
    {
        if ($subjAddrNorm !== null && $subjAddrNorm !== '') {
            $candNorm = $this->normaliseAddr($candAddr);
            if ($candNorm !== null && $candNorm === $subjAddrNorm) {
                return true;
            }
        }
        if (!$subjectSectional && $distanceM !== null && $distanceM <= self::SUBJECT_SELF_RADIUS_M) {
            return true;
        }
        return false;
    }

    /**
     * Lower-case, strip punctuation, collapse whitespace — so "12 Smith St."
     * and "12  smith st" compare equal. Returns null for empty input.
     */
    private function normaliseAddr(?string $addr): ?string
    {
        if ($addr === null) {
            return null;
        }
        $a = mb_strtolower(trim($addr));
        $a = preg_replace('/[^a-z0-9]+/u', ' ', $a) ?? '';
        $a = trim(preg_replace('/\s+/', ' ', $a) ?? '');
        return $a === '' ? null : $a;
    }

    // ── Classification helpers ─────────────────────────────────────────────

    /** Title category (freehold/sectional/vacant/other) via the keystone classifier. */
    private function category(TitleTypeClassifier $classifier, ?string $titleType, ?string $propertyType): ?string
    {
        if ($titleType !== null && $titleType !== '') {
            return $titleType;
        }
        return $classifier->fromPropertyType($propertyType);
    }

    /**
     * Coarse property "kind" for the like-for-like first pass: house,
     * apartment, townhouse, vacant, other. Distinct from title category —
     * two sectional units (apartment vs townhouse) share a category but
     * differ in kind, so like-for-like prefers the closer match first.
     */
    private function kind(?string $propertyType): ?string
    {
        $t = mb_strtolower(trim((string) $propertyType));
        if ($t === '') {
            return null;
        }
        return match (true) {
            str_contains($t, 'apartment'), str_contains($t, 'flat'), str_contains($t, 'penthouse'), str_contains($t, 'studio') => 'apartment',
            str_contains($t, 'townhouse'), str_contains($t, 'duplex'), str_contains($t, 'simplex'), str_contains($t, 'cluster'), str_contains($t, 'maisonette') => 'townhouse',
            str_contains($t, 'vacant'), str_contains($t, 'plot'), str_contains($t, 'stand'), str_contains($t, 'land'), str_contains($t, 'erf') => 'vacant',
            str_contains($t, 'house'), str_contains($t, 'home'), str_contains($t, 'residence'), str_contains($t, 'villa') => 'house',
            default => 'other',
        };
    }

    // ── Stats ───────────────────────────────────────────────────────────────

    /**
     * Type-7 linear-interpolated median (matches CmaComputeService's
     * even-count contract). Integer rand output.
     *
     * @param  list<int>  $values
     */
    private function median(array $values): ?int
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $idx   = ($n - 1) * 0.5;
        $lo    = (int) floor($idx);
        $hi    = (int) ceil($idx);
        if ($lo === $hi) {
            return (int) $values[$lo];
        }
        return (int) round($values[$lo] + ($values[$hi] - $values[$lo]) * ($idx - $lo));
    }

    private function emptyResult(array $config, array $diagnostics): array
    {
        return [
            'selected'      => [],
            'selected_keys' => [],
            'radius_used'   => (int) $config['radius_m'],
            'anchor'        => null,
            'widened'       => false,
            'diagnostics'   => $diagnostics,
        ];
    }

    // ── Config parsing ──────────────────────────────────────────────────────

    /** @return list<int> */
    private static function parseSteps($csv): array
    {
        if (!is_string($csv) || trim($csv) === '') {
            return self::DEF_RADIUS_STEPS;
        }
        $out = [];
        foreach (explode(',', $csv) as $p) {
            $p = (int) trim($p);
            if ($p > 0) {
                $out[] = $p;
            }
        }
        sort($out);
        return $out ?: self::DEF_RADIUS_STEPS;
    }

    private static function floatOr($v, float $default): float
    {
        return ($v !== null && is_numeric($v)) ? (float) $v : $default;
    }

    private static function intOr($v, int $default): int
    {
        return ($v !== null && is_numeric($v)) ? (int) $v : $default;
    }

    private function floatOrNull($v): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        return (float) $v;
    }

    private function intOrNull($v): ?int
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        return (int) $v;
    }
}
