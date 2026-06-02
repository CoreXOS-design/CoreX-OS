<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Agency;
use App\Models\HoldingCostDataPoint;
use App\Models\Presentation;
use App\Models\Property;
use App\Support\Presentations\SuburbMatcher;
use Illuminate\Support\Facades\DB;

/**
 * Holding-cost estimator with Tier 0/1/2 priority chain.
 *
 * Pre-fix shape: $monthly_levies = floor_area_m2 × R/m². On any
 * property where size_m2 wasn't a sectional UNIT floor area (most
 * houses, OR sectional units whose size column held the scheme
 * total) the levy exploded — the Seeskulp R30,000 staging bug.
 *
 * New resolution per component:
 *   Tier 0 — properties.levy / rates_taxes / special_levy (per-property
 *            captured value, authoritative when present).
 *   Tier 1 — learned average across holding_cost_data_points where
 *            is_excluded = 0, keyed per the component's averaging
 *            dimension (levy→scheme, rates→muni+value band,
 *            insurance→value band, utilities/garden/pool→type+suburb,
 *            security→suburb). Falls through if n=0.
 *   Tier 2 — agency default (presentations_default_<component>_zar).
 *
 * The agent's per-presentation override (the per-component edit in
 * Section 6) trumps all three. It's written to two places:
 *   - presentations.monthly_<component>   (the persisted breakdown)
 *   - holding_cost_data_points            (source=agent_override —
 *     feeds future presentations' Tier 1 average)
 *
 * Capture-everything model (GolfPad): every override grows the
 * learned dataset for similar properties. Agency exclude-grid
 * (is_excluded flag, no UI this build) sanitises later.
 *
 * Title_type-branched component set:
 *   sectional_title → levy + rates + insurance + utilities + opp
 *   full_title / vacant_land → rates + insurance + utilities + garden +
 *                              pool + security + opp (NO levy)
 *   null → conservative: rates + insurance + utilities + opp
 *
 * Opportunity cost is CALCULATED (asking × pct / 12) not learned.
 * All money math uses bcmath (per CLAUDE.md non-negotiable for new
 * financial code) — bcadd / bcmul / bcdiv for sums, products,
 * divisions. Caller-side reads remain (float) since the legacy
 * monthly_* columns are float-cast.
 */
final class HoldingCostEstimator
{
    /** bcmath scale for intermediate money math (cents-level precision). */
    public const SCALE = 2;

    /**
     * Minimum n for a Tier 1 learned average to be considered
     * reliable. Below this we fall through to Tier 2 (agency default).
     * 3 is the judgment call: tight enough to avoid one-row anchors,
     * loose enough that thin agencies see Tier 1 kick in early.
     * Could be agency-configurable later — out of scope.
     */
    public const TIER1_MIN_N = 3;

    /**
     * Auto-fill the presentation's monthly_* columns + write a
     * data-point row per resolved component. Returns a summary array
     * describing what was written and what was skipped, including the
     * Tier each component landed on — surfaced for the audit log + UI.
     *
     * @return array{
     *   property_value: ?int,
     *   wrote: array<string, array{value:int, tier:string, source:string}>,
     *   skipped: array<string, string>,
     * }
     */
    public function estimateAndPersist(Presentation $presentation): array
    {
        $agency   = $presentation->agency_id ? Agency::find($presentation->agency_id) : null;
        $property = $presentation->property;

        $askingPrice = $presentation->asking_price_inc !== null
            ? (int) $presentation->asking_price_inc
            : $this->cmaMiddleFromFields($presentation);

        if ($askingPrice === null || $askingPrice <= 0) {
            return [
                'property_value' => null,
                'wrote'          => [],
                'skipped'        => ['all' => 'no asking price or CMA middle available'],
            ];
        }

        $titleType  = $property?->title_type ?? null;
        $components = $this->componentsFor($titleType);
        $context    = $this->buildContext($presentation, $property, $askingPrice);

        $writes  = [];
        $skipped = [];

        foreach ($components as $component) {
            $col = $this->columnFor($component);
            $current = $col ? $presentation->{$col} : null;
            $hasCurrent = $current !== null && $current !== '' && (float) $current !== 0.0;

            // Skip ONLY when the stored value is a deliberate agent
            // override — a stale auto-fill is NOT an override and must
            // not block Tier 0. The signal is durable: each override
            // writes a holding_cost_data_points row with
            // source=agent_override tied to a version of this
            // presentation (see PresentationReviewController). Without
            // that audit trail, a non-null monthly_* column is
            // auto-fill from a prior estimator pass and re-resolution
            // is the correct behaviour.
            if ($hasCurrent && $this->agentOverrideExists($presentation, $component)) {
                $skipped[$component] = 'agent override present';
                continue;
            }

            // Opportunity cost is calculated, not learned/tiered.
            if ($component === HoldingCostDataPoint::COMPONENT_OPPORTUNITY_COST) {
                $value = $this->calculateOpportunityCost($askingPrice, $agency);
                if ($value === null) {
                    // Keep any non-zero current value rather than blanking
                    // — better than wiping a Tier 2 fallback that was
                    // resolved in an earlier pass.
                    if ($hasCurrent) {
                        $skipped[$component] = 'no opportunity cost rate, kept existing';
                    } else {
                        $skipped[$component] = 'no opportunity cost rate';
                    }
                    continue;
                }
                $presentation->{$col} = $value;
                $writes[$component] = ['value' => $value, 'tier' => 'calculated', 'source' => 'opp_pct'];
                continue;
            }

            // Tier 0 → Tier 1 → Tier 2 resolution. Tier 0 (the
            // property's captured value) WINS over any stale stored
            // value — captured > learned-average > agency-default.
            $resolved = $this->resolveComponent($component, $context, $agency);
            if ($resolved === null) {
                // No tier value. Don't blank a stored value the agent
                // might still be relying on visually (e.g. a Tier 2
                // default that's no longer reachable because the
                // agency setting changed). Surface it in `skipped`.
                if ($hasCurrent) {
                    $skipped[$component] = 'no tier value, kept existing';
                } else {
                    $skipped[$component] = 'no value at any tier';
                }
                continue;
            }
            $presentation->{$col} = $resolved['value'];
            $writes[$component] = $resolved;
        }

        if (!empty($writes)) {
            $presentation->save();
        }

        return [
            'property_value' => $askingPrice,
            'wrote'          => $writes,
            'skipped'        => $skipped,
        ];
    }

    /**
     * Resolve ONE component's monthly value via the Tier 0/1/2 chain.
     * Returns ['value' => int, 'tier' => 'tier0|tier1|tier2', 'source' => string]
     * or null when nothing resolves (caller skips the component).
     *
     * Public so the override endpoint can compute a "what would the
     * tiers suggest" hint for the inline-edit placeholder without
     * persisting.
     *
     * @param  array  $context  built by buildContext()
     */
    public function resolveComponent(string $component, array $context, ?Agency $agency): ?array
    {
        return $this->resolveComponentWithContext($component, $context, $agency);
    }

    /**
     * Build the lookup context once per presentation.
     *
     * @return array{
     *   agency_id: ?int, property_id: ?int, scheme_name: ?string,
     *   suburb_normalised: ?string, municipality: ?string,
     *   property_type: ?string, title_type: ?string,
     *   property_value_band: ?string, property: ?Property,
     *   asking_price: int,
     * }
     */
    public function buildContext(Presentation $presentation, ?Property $property, int $askingPrice): array
    {
        $suburbNorm = $property?->suburb_normalised
            ?? SuburbMatcher::normaliseSuburbToken((string) ($presentation->suburb ?? ''));
        if ($suburbNorm === '') $suburbNorm = null;

        return [
            'agency_id'           => $presentation->agency_id ? (int) $presentation->agency_id : null,
            'property_id'         => $property?->id,
            'scheme_name'         => $this->resolveSchemeName($presentation, $property),
            'suburb_normalised'   => $suburbNorm,
            'municipality'        => null, // No municipalities reference table yet — agency-default falls through.
            'property_type'       => $property?->property_type ?? $presentation->property_type ?? null,
            'title_type'          => $property?->title_type ?? null,
            'property_value_band' => HoldingCostDataPoint::valueBandFor($askingPrice),
            'property'            => $property,
            'asking_price'        => $askingPrice,
        ];
    }

    // ── Tier 0 — per-property captured value ────────────────────────────

    private function tier0(string $component, array $context): ?int
    {
        $property = $context['property'] ?? null;
        if (!$property) return null;

        switch ($component) {
            case HoldingCostDataPoint::COMPONENT_LEVY:
                $v = $property->levy ?? null;
                // properties.special_levy is additive — fold in when
                // present. bcadd per the money-math convention; precision
                // risk is nil at these magnitudes but consistency with
                // the rest of the estimator matters more.
                if ($v !== null) {
                    $sum = bcadd(
                        (string) (int) $v,
                        (string) (int) ($property->special_levy ?? 0),
                        self::SCALE,
                    );
                    return (int) $sum;
                }
                return null;

            case HoldingCostDataPoint::COMPONENT_RATES:
                $v = $property->rates_taxes ?? null;
                return $v !== null ? (int) $v : null;

            default:
                // No per-property column for the other components today.
                return null;
        }
    }

    // ── Tier 1 — learned average ────────────────────────────────────────

    /**
     * Returns ['value' => int, 'n' => int] when n ≥ TIER1_MIN_N, else null.
     */
    private function tier1(string $component, array $context): ?array
    {
        if (!$context['agency_id']) return null;

        $query = HoldingCostDataPoint::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $context['agency_id'])
            ->where('component', $component)
            ->where('is_excluded', false)
            ->whereNull('deleted_at');

        // Apply the averaging key(s) per component. When a key is
        // missing on the subject context, Tier 1 falls through —
        // we can't average against an unknown dimension.
        switch ($component) {
            case HoldingCostDataPoint::COMPONENT_LEVY:
                if (!$context['scheme_name']) return null;
                $query->where('scheme_name', $context['scheme_name']);
                break;

            case HoldingCostDataPoint::COMPONENT_RATES:
                if (!$context['property_value_band']) return null;
                if ($context['municipality']) {
                    $query->where('municipality', $context['municipality']);
                } elseif ($context['suburb_normalised']) {
                    $query->where('suburb_normalised', $context['suburb_normalised']);
                } else {
                    return null;
                }
                $query->where('property_value_band', $context['property_value_band']);
                break;

            case HoldingCostDataPoint::COMPONENT_INSURANCE:
                if (!$context['property_value_band']) return null;
                $query->where('property_value_band', $context['property_value_band']);
                break;

            case HoldingCostDataPoint::COMPONENT_UTILITIES:
            case HoldingCostDataPoint::COMPONENT_GARDEN:
            case HoldingCostDataPoint::COMPONENT_POOL:
                if (!$context['property_type'] || !$context['suburb_normalised']) return null;
                $query->where('property_type', $context['property_type'])
                      ->where('suburb_normalised', $context['suburb_normalised']);
                break;

            case HoldingCostDataPoint::COMPONENT_SECURITY:
                if (!$context['suburb_normalised']) return null;
                $query->where('suburb_normalised', $context['suburb_normalised']);
                break;

            default:
                return null;
        }

        // Pull values, average in bcmath. Median would also be fine
        // here — using mean for the v1 because it's symmetric with
        // the future exclude-grid (outliers manually pruned, mean
        // then reflects the kept set).
        $values = $query->pluck('monthly_value_zar')->all();
        $n = count($values);
        if ($n < self::TIER1_MIN_N) return null;

        $sum = '0';
        foreach ($values as $v) {
            $sum = bcadd($sum, (string) (int) $v, self::SCALE);
        }
        $avg = bcdiv($sum, (string) $n, 0);

        return ['value' => (int) $avg, 'n' => $n];
    }

    // ── Tier 2 — agency default ─────────────────────────────────────────

    private function tier2(string $component, ?Agency $agency): ?int
    {
        switch ($component) {
            case HoldingCostDataPoint::COMPONENT_RATES:
                // rates default is per-million, applied to subject's
                // asking price. Tier 2 ONLY when neither Tier 0 nor
                // Tier 1 produced a value — we still need the actual
                // monthly rand figure, so multiply at write time.
                $perMillion = (int) ($agency?->presentations_default_rates_per_million_zar ?? 800);
                // Caller's resolveComponent has access to asking_price
                // via context — but tier2() is component-only. Pull it
                // from the override-time read by accepting agency-only
                // here and re-multiplying in resolveComponent via the
                // context. For now we return per-million × asking
                // through a workaround: this method gets called via
                // resolveComponent which has context. See tier2WithContext.
                return $perMillion;

            case HoldingCostDataPoint::COMPONENT_INSURANCE:
                return (int) ($agency?->presentations_default_insurance_per_million_zar ?? 200);

            case HoldingCostDataPoint::COMPONENT_LEVY:
                // Pre-fix legacy default was R/m² — DELIBERATELY DROPPED.
                // With no per-scheme Tier 1 average and no per-property
                // Tier 0, levy has NO sensible flat fallback. Returning
                // null surfaces the gap to the agent (skipped with
                // 'no value at any tier') rather than fabricating one.
                return null;

            case HoldingCostDataPoint::COMPONENT_UTILITIES:
                return (int) ($agency?->presentations_default_utilities_zar ?? 1200);

            case HoldingCostDataPoint::COMPONENT_GARDEN:
                return (int) ($agency?->presentations_default_garden_zar ?? 800);

            case HoldingCostDataPoint::COMPONENT_POOL:
                return (int) ($agency?->presentations_default_pool_zar ?? 600);

            case HoldingCostDataPoint::COMPONENT_SECURITY:
                return (int) ($agency?->presentations_default_security_zar ?? 1500);

            default:
                return null;
        }
    }

    /**
     * Tier 2 with asking-price multiplication for value-driven
     * components (rates, insurance). Called by resolveComponent so it
     * knows the subject's asking price.
     */
    private function resolveTier2(string $component, ?Agency $agency, int $askingPrice): ?int
    {
        $perMillionOrFlat = $this->tier2($component, $agency);
        if ($perMillionOrFlat === null) return null;

        $millionsStr = bcdiv((string) $askingPrice, '1000000', 6);

        switch ($component) {
            case HoldingCostDataPoint::COMPONENT_RATES:
            case HoldingCostDataPoint::COMPONENT_INSURANCE:
                // Multiply by asking millions in bcmath.
                $value = bcmul((string) $perMillionOrFlat, $millionsStr, self::SCALE);
                return (int) bcadd($value, '0', 0);

            default:
                return $perMillionOrFlat;
        }
    }

    // ── Opportunity cost — calculated, not learned ──────────────────────

    private function calculateOpportunityCost(int $askingPrice, ?Agency $agency): ?int
    {
        $pct = $agency?->presentations_default_opportunity_cost_pct;
        if ($pct === null) return null;
        $pctStr = number_format((float) $pct, 6, '.', '');
        // (askingPrice × pct / 100) / 12
        $annual  = bcmul((string) $askingPrice, bcdiv($pctStr, '100', 8), self::SCALE);
        $monthly = bcdiv($annual, '12', 0);
        return (int) $monthly;
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Components per title_type. opportunity_cost always included;
     * levy gates on sectional_title.
     *
     * @return string[]
     */
    public function componentsFor(?string $titleType): array
    {
        if ($titleType === 'sectional_title') {
            return HoldingCostDataPoint::COMPONENTS_SECTIONAL;
        }
        if ($titleType === 'full_title' || $titleType === 'vacant_land') {
            return HoldingCostDataPoint::COMPONENTS_FREEHOLD;
        }
        // Unknown title — conservative subset that applies everywhere.
        return [
            HoldingCostDataPoint::COMPONENT_RATES,
            HoldingCostDataPoint::COMPONENT_INSURANCE,
            HoldingCostDataPoint::COMPONENT_UTILITIES,
            HoldingCostDataPoint::COMPONENT_OPPORTUNITY_COST,
        ];
    }

    /**
     * Build 8 — canonical monthly holding-cost total. Single source of
     * truth for the monthly total across the presentation/AI/PDF/teaser
     * paths. Pre-fix five ad-hoc summations across the codebase summed
     * different subsets of the same DB columns:
     *
     *   - AnalysisDataService::compileHoldingCost used componentsFor()
     *     + bond — branched by title_type (freehold gets garden/pool/
     *     security; sectional gets levy/utilities). CORRECT.
     *   - AnalysisDataService::calcMonthlyHolding hardcoded six columns
     *     (no garden/pool/security). UNDERCOUNT.
     *   - AiSummaryService::buildFactsArray hardcoded the same six
     *     columns. UNDERCOUNT.
     *   - TrajectorySimulationService::resolveMonthlyHoldingCost
     *     hardcoded the same six. UNDERCOUNT.
     *   - resources/views/presentations/public/teaser.blade.php
     *     hardcoded five (also missing bond). UNDERCOUNT.
     *
     * For a freehold with garden / pool / security populated, the
     * undercount paths produced a different "monthly total" than the
     * itemised breakdown — Johan's R13,992 vs R8,492 contradiction.
     *
     * This method centralises the branching once. Bond is always
     * included (independent of title type, per
     * compileHoldingCost L902-903). Sum runs in bcmath at SCALE=2 so a
     * future move to decimal-stored monthly columns lands cleanly;
     * today the persisted columns are whole-rand floats, so the int
     * cast at the end is lossless. Null columns coerce to 0.
     */
    public function monthlyTotalFor(Presentation $presentation): int
    {
        $titleType  = $presentation->property?->title_type ?? null;
        $components = array_values(array_unique(array_merge(
            [HoldingCostDataPoint::COMPONENT_BOND],
            $this->componentsFor($titleType),
        )));

        $sum = '0';
        foreach ($components as $component) {
            $col = $this->columnFor($component);
            if ($col === null) continue;
            $raw = $presentation->{$col} ?? 0;
            if (!is_numeric($raw)) continue;
            $sum = bcadd($sum, (string) $raw, 2);
        }

        // Round-half-up via bcadd 0.5 + truncate to integer scale.
        $half    = bccomp($sum, '0', 2) >= 0 ? '0.5' : '-0.5';
        $rounded = bcadd(bcadd($sum, $half, 2), '0', 0);
        return (int) $rounded;
    }

    /**
     * Map component → presentations column. Components without a
     * dedicated column return null (none today after the migration —
     * all 9 components have columns).
     */
    public function columnFor(string $component): ?string
    {
        return match ($component) {
            HoldingCostDataPoint::COMPONENT_RATES            => 'monthly_rates',
            HoldingCostDataPoint::COMPONENT_LEVY             => 'monthly_levies',
            HoldingCostDataPoint::COMPONENT_INSURANCE        => 'monthly_insurance',
            HoldingCostDataPoint::COMPONENT_UTILITIES        => 'monthly_utilities',
            HoldingCostDataPoint::COMPONENT_GARDEN           => 'monthly_garden',
            HoldingCostDataPoint::COMPONENT_POOL             => 'monthly_pool',
            HoldingCostDataPoint::COMPONENT_SECURITY         => 'monthly_security',
            HoldingCostDataPoint::COMPONENT_BOND             => 'monthly_bond',
            HoldingCostDataPoint::COMPONENT_OPPORTUNITY_COST => 'monthly_opportunity_cost',
            default                                          => null,
        };
    }

    /**
     * Override resolveComponent to delegate Tier 2 through the
     * asking-price-aware path. Keeping the separate methods readable
     * while resolving the value-driven multiplications.
     */
    private function resolveComponentWithContext(string $component, array $context, ?Agency $agency): ?array
    {
        $tier0 = $this->tier0($component, $context);
        if ($tier0 !== null) {
            return ['value' => $tier0, 'tier' => 'tier0', 'source' => 'property_record'];
        }
        $tier1 = $this->tier1($component, $context);
        if ($tier1 !== null) {
            return ['value' => $tier1['value'], 'tier' => 'tier1', 'source' => 'learned_n=' . $tier1['n']];
        }
        $tier2 = $this->resolveTier2($component, $agency, $context['asking_price']);
        if ($tier2 !== null && $tier2 > 0) {
            return ['value' => $tier2, 'tier' => 'tier2', 'source' => 'agency_default'];
        }
        return null;
    }

    /**
     * Public single-component resolver — the one resolveComponent
     * actually uses. Kept as a separate entry point for the override
     * endpoint's "what would the tier suggest" hint.
     */
    public function resolveOne(string $component, Presentation $presentation): ?array
    {
        $agency = $presentation->agency_id ? Agency::find($presentation->agency_id) : null;
        $property = $presentation->property;
        $asking = $presentation->asking_price_inc ?? $this->cmaMiddleFromFields($presentation);
        if (!$asking) return null;
        $context = $this->buildContext($presentation, $property, (int) $asking);
        return $this->resolveComponentWithContext($component, $context, $agency);
    }

    private function resolveSchemeName(Presentation $presentation, ?Property $property): ?string
    {
        // Property layer ships scheme info as separate fields; for now
        // the most reliable signal is the property's first capture
        // (scheme_name on tracked_properties via promoted_to). Falls
        // through cleanly when unknown — levy Tier 1 skips, Tier 2 is
        // null anyway, and the agent can override.
        $tracked = DB::table('tracked_properties')
            ->where('promoted_to_property_id', $property?->id ?? 0)
            ->whereNotNull('complex_name')
            ->orderByDesc('id')
            ->value('complex_name');
        if ($tracked) return (string) $tracked;
        return null;
    }

    /**
     * Detect a DELIBERATE agent override for (presentation, component).
     * Distinguishes a real agent edit from a stale auto-fill so Tier 0
     * (captured property values) can overwrite the latter without
     * clobbering the former.
     *
     * The signal: a holding_cost_data_points row with
     * source=agent_override tied to ANY version of this presentation.
     * The override endpoint
     * (PresentationReviewController::setHoldingCostComponent) creates
     * exactly one such row per agent edit — that's the durable audit
     * trail. No row → no override → re-resolution allowed.
     */
    private function agentOverrideExists(Presentation $presentation, string $component): bool
    {
        $versionIds = $presentation->versions()->pluck('id')->all();
        if (empty($versionIds)) return false;

        return HoldingCostDataPoint::query()
            ->withoutGlobalScopes()
            ->whereIn('presentation_version_id', $versionIds)
            ->where('component', $component)
            ->where('source', HoldingCostDataPoint::SOURCE_AGENT_OVERRIDE)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function cmaMiddleFromFields(Presentation $presentation): ?int
    {
        $field = $presentation->fields()
            ->where('field_key', 'cma.middle_range')
            ->first();
        if (!$field) return null;
        $val = (int) preg_replace('/[^\d]/', '', (string) ($field->final_value ?? ''));
        return $val > 0 ? $val : null;
    }
}
