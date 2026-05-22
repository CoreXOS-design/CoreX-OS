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

            // ── 1. Upsert Presentation ─────────────────────────────────────
            $presentation = Presentation::where('property_id', $propertyId)
                ->where('agency_id', $agencyId)
                ->first();

            $hydrated = $this->hydrateFromProperty($property, $agentUserId, $agencyId, $options);

            if ($presentation) {
                // Re-hydrate light fields only; preserve agent-edited columns
                // like cma_selected_range, exclusions, simulator config.
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
                    'monthly_rates'      => $presentation->monthly_rates ?? $hydrated['monthly_rates'],
                    'monthly_levies'     => $presentation->monthly_levies ?? $hydrated['monthly_levies'],
                    'status'             => 'draft',
                ]);
                $presentation->save();
            } else {
                $presentation = Presentation::create($hydrated);
            }

            // ── 2. Market Analytics run (persist=true) ─────────────────────
            $maInput = new MarketAnalyticsInput(
                suburb:          (string) $presentation->suburb,
                propertyType:    $this->normaliseTypeForAnalytics($presentation->property_type),
                periodMonths:    12,
                bedrooms:        $presentation->bedrooms,
                sourceBranchId:  $presentation->branch_id,
                subjectSizeM2:   $presentation->floor_area_m2 ?: $presentation->erf_size_m2,
                subjectPriceInc: $presentation->asking_price_inc !== null ? (float) $presentation->asking_price_inc : null,
                presentationId:  $presentation->id,
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
            return $supplied !== null ? (int) $supplied : null;
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
