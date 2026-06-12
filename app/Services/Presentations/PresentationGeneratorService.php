<?php

namespace App\Services\Presentations;

use App\Events\PresentationGenerated;
use App\Models\MarketAnalyticsRun;
use App\Models\Presentation;
use App\Models\PresentationSnapshot;
use App\Models\PresentationVersion;
use App\Models\Property;
use App\Models\SaleProbabilityRun;
use App\Services\MarketAnalytics\Adapters\ImportedListingsAdapter;
use App\Services\MarketAnalytics\Adapters\InternalDealsAdapter;
use App\Services\MarketAnalytics\DTOs\MarketAnalyticsInput;
use App\Services\MarketAnalytics\Helpers\InputHasher;
use App\Services\MarketAnalytics\MarketAnalyticsService;
use App\Services\SaleProbability\DTOs\SaleProbabilityInput;
use App\Services\SaleProbability\SaleProbabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Presentations V2 Phase 1 — one-button auto-presentation orchestrator.
 *
 * Atomically:
 *   1. Resolves a Property and hydrates a Presentation (upsert per
 *      property_id + agency_id).
 *   2. Runs MarketAnalyticsService with persist=true and captures the run ID.
 *   3. Runs SaleProbabilityService with persist=true and captures the run ID.
 *   4. Compiles AnalysisDataService output, persists a PresentationSnapshot
 *      with BOTH engine-run IDs linked (fixes the audit-reported silent gap).
 *   5. Calls PresentationCompilerService to assemble a PresentationVersion.
 *   6. Fires PresentationGenerated event.
 *
 * Spec: .ai/specs/presentations.md §3.1
 * Audit: .ai/audits/presentations-audit-2026-05-23.md §2.3 + §7.3
 */
class PresentationGeneratorService
{
    public function __construct(
        private AnalysisDataService $analysisData = new AnalysisDataService(),
        private PresentationCompilerService $compiler = new PresentationCompilerService(),
        private MicSnapshotHydrator $hydrator = new MicSnapshotHydrator(),
        private HoldingCostEstimator $holdingCostEstimator = new HoldingCostEstimator(),
        private SimilarActiveListingsResolver $linkSuggestions = new SimilarActiveListingsResolver(),
        private \App\Services\Geocoding\PropertyGeoBackfillService $geoBackfill = new \App\Services\Geocoding\PropertyGeoBackfillService(),
        private AiSummaryService $aiSummary = new AiSummaryService(),
    ) {}

    /**
     * Generate (or regenerate) a presentation for a Property.
     *
     * @param  array{asking_price?:int|null}  $options
     */
    public function generateForProperty(
        int $propertyId,
        int $agentUserId,
        int $agencyId,
        array $options = [],
    ): PresentationVersion {
        return DB::transaction(function () use ($propertyId, $agentUserId, $agencyId, $options) {
            $property = Property::findOrFail($propertyId);

            // Keystone — belt-and-braces. The controller gate at
            // PresentationGeneratorController::generate rejects blank
            // property_type with a user-facing message. This assertion
            // catches future non-controller callers (jobs, tests, CLI)
            // that might bypass the HTTP path. A thrown exception is
            // fine here — non-controller callers don't need a polished
            // error surface, they just need to fail loudly.
            if (trim((string) ($property->property_type ?? '')) === '') {
                throw new \RuntimeException(
                    'Cannot generate presentation: property has no property_type.'
                );
            }

            // ── 1. Upsert Presentation ─────────────────────────────────────
            $presentation = Presentation::where('property_id', $propertyId)
                ->where('agency_id', $agencyId)
                ->first();

            $hydrated = $this->hydrateFromProperty($property, $agentUserId, $agencyId, $options);

            if ($presentation) {
                // Re-hydrate light fields only; preserve agent-edited columns
                // like cma_selected_range, exclusions, simulator config.
                //
                // monthly_rates / monthly_levies are deliberately NOT carried
                // forward here. The pre-Tier-fix estimator wrote stale values
                // (the floor_area_m2 × R/m² levy bug; per-million rates default)
                // that the old `?? $hydrated` guard then froze in place across
                // every regenerate. HoldingCostEstimator (called below at
                // step 3.6) now owns the policy: Tier 0 (property's captured
                // levy / rates_taxes / special_levy) wins over any stale
                // stored value when no holding_cost_data_points agent-override
                // row exists for the component. A real override is preserved
                // by the same gate — see HoldingCostEstimator::agentOverrideExists.
                // monthly_bond keeps the only-when-null guard because there is
                // no Tier-0 source for it.
                $presentation->fill([
                    'title'              => $hydrated['title'],
                    'property_address'   => $hydrated['property_address'],
                    'suburb'             => $hydrated['suburb'],
                    'property_type'      => $hydrated['property_type'],
                    'bedrooms'           => $hydrated['bedrooms'],
                    'bathrooms'          => $hydrated['bathrooms'],
                    'garages_parking'    => $hydrated['garages_parking'],
                    'erf_size_m2'        => $hydrated['erf_size_m2'],
                    'floor_area_m2'      => $hydrated['floor_area_m2'],
                    'asking_price_inc'   => $hydrated['asking_price_inc'] ?? $presentation->asking_price_inc,
                    'monthly_bond'       => $presentation->monthly_bond ?? $hydrated['monthly_bond'],
                    'status'             => 'draft',
                ]);
                $presentation->save();
            } else {
                $presentation = Presentation::create($hydrated);
            }

            // Phase 3b — persist per-presentation scope override (null lets
            // future generations inherit the agency default).
            if (array_key_exists('comp_scope', $options) || array_key_exists('comp_radius_m', $options)) {
                $presentation->fill([
                    'comp_scope'    => $options['comp_scope']    ?? $presentation->comp_scope,
                    'comp_radius_m' => $options['comp_radius_m'] ?? $presentation->comp_radius_m,
                ])->save();
            }

            // ── 2. Market Analytics run (persist=true) ─────────────────────
            // Phase 3b — resolve comp scope + radius from presentation override
            // first, then agency default. Subject GPS comes from the linked
            // Property record when present; null is safe (adapter degrades to
            // suburb match per row).
            $agency       = $presentation->agency_id ? \App\Models\Agency::find($presentation->agency_id) : null;
            $compScope    = $presentation->comp_scope
                ?? $agency?->presentations_default_comp_scope
                ?? MarketAnalyticsInput::SCOPE_RADIUS_ALL;
            $compRadiusM  = (int) ($presentation->comp_radius_m
                ?? $agency?->presentations_default_radius_m
                ?? 1000);
            $property     = $presentation->property_id ? \App\Models\Property::find($presentation->property_id) : null;
            $subjectLat   = $property?->latitude !== null && $property?->latitude !== '' ? (float) $property->latitude : null;
            $subjectLng   = $property?->longitude !== null && $property?->longitude !== '' ? (float) $property->longitude : null;

            $maInput = new MarketAnalyticsInput(
                suburb:           (string) $presentation->suburb,
                propertyType:     $this->normaliseTypeForAnalytics($presentation->property_type),
                periodMonths:     12,
                bedrooms:         $presentation->bedrooms,
                sourceBranchId:   $presentation->branch_id,
                subjectSizeM2:    $presentation->floor_area_m2 ?: $presentation->erf_size_m2,
                subjectPriceInc:  $presentation->asking_price_inc !== null ? (float) $presentation->asking_price_inc : null,
                presentationId:   $presentation->id,
                compScope:        $compScope,
                compRadiusM:      $compRadiusM,
                subjectLatitude:  $subjectLat,
                subjectLongitude: $subjectLng,
                // Phase 3h Step 9 — subject's demo flag determines whether
                // adapters read demo or real comp/deal data. Real subjects
                // (default) never see synthetic data, and vice versa.
                subjectIsDemo:    (bool) ($property->is_demo ?? false),
            );

            $maService = new MarketAnalyticsService(
                new InternalDealsAdapter(),
                new ImportedListingsAdapter(),
            );
            $maResult = $maService->run($maInput, persist: true);

            $maInputsHash = InputHasher::hash($maInput);
            $maRun = MarketAnalyticsRun::where('inputs_hash', $maInputsHash)
                ->latest('id')
                ->first();

            if (!$maRun) {
                // The service persisted with this hash but we couldn't find it.
                // Don't fail the whole generation — log + continue with null.
                Log::warning('PresentationGeneratorService: MA run persisted but not retrievable', [
                    'presentation_id' => $presentation->id,
                    'inputs_hash'     => $maInputsHash,
                ]);
            }

            // ── 3. Sale Probability run (persist=true) ─────────────────────
            $spInput = new SaleProbabilityInput(
                marketAnalyticsRunId:        $maRun?->id,
                marketAnalyticsModelVersion: MarketAnalyticsService::MODEL_VERSION,
                marketAnalyticsInputsHash:   $maInputsHash,
                marketAnalyticsResult:       $maResult,
            );
            $spResult = (new SaleProbabilityService())->run($spInput, createdBy: $agentUserId, persist: true);

            $spRun = $maRun
                ? SaleProbabilityRun::where('market_analytics_run_id', $maRun->id)->latest('id')->first()
                : null;

            // ── 3.4. Phase 3f C3 — ensure property has GPS before hydration ─
            // The hydrator's radius_all branch is meaningless without GPS for
            // the subject. Resolve it now synchronously (fast for cache hits;
            // typically completes in <100ms when the address has been seen
            // before via a prior CMA import).
            if ($property->latitude === null || $property->longitude === null) {
                try {
                    $this->geoBackfill->backfillProperty($property);
                    $property->refresh();
                } catch (\Throwable $e) {
                    \Log::warning('Pre-hydration GPS backfill failed', [
                        'property_id' => $property->id,
                        'err'         => $e->getMessage(),
                    ]);
                }
            }

            // ── 3.5. Phase 3d — MIC snapshot hydration ─────────────────────
            // Before AnalysisDataService runs, copy any matching MIC evidence
            // (market_report_comp_rows + suburb/CMA market_data_points) into
            // presentation_sold_comps + presentation_active_listings +
            // presentation_fields. Downstream consumers keep reading from the
            // presentation_* tables unchanged.
            $hydrationSummary = $this->hydrator->hydrateForPresentation($presentation);

            // ── 3.6. Phase 3e E — holding-cost auto-fill ───────────────────
            // Now that CMA middle / asking price + property type are settled,
            // auto-fill any monthly_* cost columns the agent left null. The
            // estimator never clobbers an agent-supplied value.
            $presentation->refresh();
            $holdingCostSummary = $this->holdingCostEstimator->estimateAndPersist($presentation);

            // ── 4. AnalysisDataService compile + PresentationSnapshot ──────
            $presentation->refresh();
            $computed = $this->analysisData->compile($presentation);

            $snapshot = PresentationSnapshot::create([
                'presentation_id'         => $presentation->id,
                'generated_by_user_id'    => $agentUserId,
                'created_by_user_id'      => $agentUserId,
                'computed_json'           => json_encode($computed, JSON_THROW_ON_ERROR),
                'snapshot_json'           => '{}',
                'inputs_json'             => json_encode($maInput->toCanonicalArray(), JSON_THROW_ON_ERROR),
                'output_summary_json'     => json_encode($this->buildOutputSummary($maResult, $spResult), JSON_THROW_ON_ERROR),
                'market_analytics_run_id' => $maRun?->id,
                'sale_probability_run_id' => $spRun?->id,
                'generated_at'            => now(),
            ]);

            // ── 5. Compile a PresentationVersion ───────────────────────────
            $version = $this->compiler->compile($presentation->id, $agentUserId);

            // Phase 3d — stamp hydration summary on the version row.
            // Phase 3e E — fold in the holding-cost estimator outcome so
            // QA can see what was auto-filled vs left for the agent.
            // Phase 3e F — link suggestions from competing active listings.
            $version->hydration_summary_json = array_merge(
                $hydrationSummary,
                [
                    'holding_cost_autofill' => $holdingCostSummary,
                    'link_suggestions'      => $this->linkSuggestions->suggestFor($presentation),
                ],
            );

            // Build 2 — stamp the new per-version review lifecycle. Every
            // fresh generate enters 'awaiting_review' and the agent goes
            // through the review screen before the version is 'published'.
            // The Controller redirects the agent to the review URL, not
            // straight to the public/show. See Build 2 prompt §B.
            $version->review_status      = \App\Models\PresentationVersion::REVIEW_AWAITING;
            $version->awaiting_review_at = now();
            $version->save();

            // ── 5b. AT-27 Phase A — DEFER the Executive Summary ────────────
            // The exec summary is NO LONGER generated here. It must narrate
            // CONFIRMED numbers (never provisional), so it is generated only
            // when the agent clicks "Confirm & Generate" on the Analysis
            // screen (AT-27 Phase B), from the frozen confirmed snapshot.
            //
            // A fresh draft version therefore carries NO summary: clear any
            // value the compiler copied forward from a previous version so the
            // PDF readiness gate correctly blocks download until the agent
            // confirms on Analysis. (Removing the auto-generate + carryover
            // from the generate path is AT-27 decision 1/2.)
            $version->forceFill([
                'ai_summary_text'            => null,
                'ai_summary_raw_text'        => null,
                'ai_variant_id'              => null,
                'ai_summary_edited_by_agent' => false,
                'ai_summary_generated_at'    => null,
                'ai_summary_model'           => null,
                'ai_summary_prompt_hash'     => null,
            ])->save();
            $version->refresh();

            // ── 6. Fire event ──────────────────────────────────────────────
            PresentationGenerated::dispatch($presentation, $version);

            return $version;
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Build the Presentation row payload from a Property record.
     *
     * @return array<string,mixed>
     */
    private function hydrateFromProperty(
        Property $property,
        int $agentUserId,
        int $agencyId,
        array $options,
    ): array {
        $address = $property->buildDisplayAddress();
        $askingPrice = $this->resolveAskingPrice($property, $options);

        return [
            'agency_id'          => $agencyId,
            'branch_id'          => $property->branch_id,
            'created_by_user_id' => $agentUserId,
            'property_id'        => $property->id,
            'title'              => $property->title ?: $address,
            'property_address'   => $address,
            'suburb'             => $property->suburb,
            'property_type'      => $this->normaliseTypeForPresentation($property->property_type),
            'bedrooms'           => $property->beds !== null ? (int) $property->beds : null,
            'bathrooms'          => $property->baths !== null ? (int) $property->baths : null,
            'garages_parking'    => $property->garages !== null ? (int) $property->garages : null,
            'erf_size_m2'        => $property->erf_size_m2 !== null ? (int) $property->erf_size_m2 : null,
            'floor_area_m2'      => $property->size_m2 !== null ? (int) $property->size_m2 : null,
            'asking_price_inc'   => $askingPrice,
            'monthly_bond'       => 0.0,
            'monthly_rates'      => $property->rates_taxes !== null ? (float) $property->rates_taxes : 0.0,
            'monthly_levies'     => $property->levy !== null ? (float) $property->levy : 0.0,
            'monthly_insurance'  => 0.0,
            'monthly_utilities'  => 0.0,
            'monthly_opportunity_cost' => 0.0,
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ];
    }

    private function resolveAskingPrice(Property $property, array $options): ?int
    {
        if (array_key_exists('asking_price', $options)) {
            $supplied = $options['asking_price'];
            if ($supplied === null || $supplied === '') return null;
            return (int) round((float) $supplied);
        }
        return $property->price !== null ? (int) $property->price : null;
    }

    /**
     * Map Property.property_type to the presentations.property_type enum.
     * Validator (PresentationController::store) accepts:
     *   house, townhouse, apartment, duplex, vacant_land, farm, other
     */
    private function normaliseTypeForPresentation(?string $type): string
    {
        $key = strtolower(trim((string) $type));
        $key = preg_replace('/[\s\/]+/', '_', $key);

        return match (true) {
            str_contains($key, 'house') && !str_contains($key, 'town') => 'house',
            str_contains($key, 'town')                                  => 'townhouse',
            str_contains($key, 'apartment'), str_contains($key, 'flat') => 'apartment',
            str_contains($key, 'duplex')                                => 'duplex',
            str_contains($key, 'land'), str_contains($key, 'vacant')    => 'vacant_land',
            str_contains($key, 'farm')                                  => 'farm',
            default                                                     => 'other',
        };
    }

    /**
     * Map the presentation property_type to the restricted set MarketAnalytics
     * accepts: house | unit | land | other.
     */
    private function normaliseTypeForAnalytics(?string $type): string
    {
        return match ($type) {
            'house'                                              => 'house',
            'townhouse', 'apartment', 'duplex'                   => 'unit',
            'vacant_land'                                        => 'land',
            default                                              => 'other',
        };
    }

    /**
     * Compact output-summary payload for snapshot persistence. Mirrors the
     * fields PresentationSnapshot::getOutputSummaryArray() readers expect.
     */
    private function buildOutputSummary(
        \App\Services\MarketAnalytics\DTOs\MarketAnalyticsResult $ma,
        \App\Services\SaleProbability\DTOs\SaleProbabilityResult $sp,
    ): array {
        $domCurve = is_array($ma->domCurve) ? $ma->domCurve : [];
        $breakdown = $ma->toBreakdownArray();

        return [
            'p30'                         => $sp->p30,
            'p60'                         => $sp->p60,
            'p90'                         => $sp->p90,
            'expected_days'               => $sp->expectedDays,
            'skip_reason'                 => $sp->skipReason,
            'months_of_inventory'         => $ma->monthsOfInventory,
            'demand_supply_ratio'         => $ma->demandSupplyRatio,
            'price_per_sqm_deviation_pct' => $ma->pricePerSqmDeviationPct,
            'dom_p25'                     => $domCurve['p25'] ?? null,
            'dom_p50'                     => $domCurve['p50'] ?? null,
            'dom_p75'                     => $domCurve['p75'] ?? null,
            'elasticity_days_per_pct'     => $ma->elasticityDaysPerPct,
            'elasticity_r_squared'        => $ma->elasticityRSquared,
            'competitive_stock'           => $breakdown['competitive_stock'] ?? null,
        ];
    }
}
