<?php

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Models\PresentationVersion;

/**
 * Assembles and stores a frozen version snapshot of a presentation.
 *
 * This service is PURE ASSEMBLY — it never re-runs analytics or recomputes
 * market data. It reads already-persisted MA and SP run data from the latest
 * PresentationSnapshot, assembles the data_snapshot_json, integrates holding
 * costs if available, and stores a PresentationVersion row.
 *
 * Calling compile() twice on the same presentation creates two version rows
 * with incrementing IDs (no deduplication by design).
 */
class PresentationCompilerService
{
    public function __construct(
        private PresentationBlueprintService $blueprint = new PresentationBlueprintService(),
        private HoldingCostService           $holdingCost = new HoldingCostService(),
    ) {}

    public function compile(int $presentationId, int $compiledBy): PresentationVersion
    {
        $presentation = Presentation::with([
            'fields',
            'links',
            'uploads',
            'soldComps',
            'activeListings',
            'snapshots',
            'articles',
        ])->findOrFail($presentationId);

        $latestSnapshot = $presentation->snapshots()->latest()->first();

        // ── Resolve run IDs from the latest snapshot ───────────────────────
        $analyticsRunId    = $latestSnapshot?->market_analytics_run_id;
        $probabilityRunId  = $latestSnapshot?->sale_probability_run_id;
        $snapshotOutputs   = $latestSnapshot ? ($latestSnapshot->getOutputSummaryArray() ?? []) : [];
        $snapshotInputs    = $latestSnapshot ? ($latestSnapshot->getInputsArray() ?? []) : [];

        // ── Holding cost: canonical presentation fields first, fallback to snapshot (P15) ──
        $holdingCostResult = null;
        $holdingCostInputs = [
            'bond_payment'     => (float) ($presentation->monthly_bond             ?? $snapshotInputs['monthly_bond']               ?? 0),
            'rates'            => (float) ($presentation->monthly_rates            ?? $snapshotInputs['monthly_rates']              ?? 0),
            'levies'           => (float) ($presentation->monthly_levies           ?? $snapshotInputs['monthly_levies']             ?? 0),
            'insurance'        => (float) ($presentation->monthly_insurance        ?? $snapshotInputs['monthly_insurance']          ?? 0),
            'utilities'        => (float) ($presentation->monthly_utilities        ?? $snapshotInputs['monthly_maintenance_buffer'] ?? 0),
            'opportunity_cost' => (float) ($presentation->monthly_opportunity_cost ?? 0),
        ];
        if (array_sum(array_values($holdingCostInputs)) > 0) {
            $holdingCostResult = $this->holdingCost->calculate($holdingCostInputs);
        }

        // ── Assemble snapshot ──────────────────────────────────────────────
        $sections = $this->blueprint->getBlueprint(PresentationBlueprintService::CURRENT_VERSION);

        $snapshot = [
            'blueprint_version'  => PresentationBlueprintService::CURRENT_VERSION,
            'compiled_at'        => now()->toIso8601String(),
            'sections'           => $sections,
            'presentation'       => [
                'id'               => $presentation->id,
                'title'            => $presentation->title,
                'property_address' => $presentation->property_address,
                'suburb'           => $presentation->suburb,
                'property_type'    => $presentation->property_type,
                'bedrooms'         => $presentation->bedrooms,
                'floor_area_m2'    => $presentation->floor_area_m2,
                'seller_name'      => $presentation->seller_name,
                'currency'         => $presentation->currency,
            ],
            'evidence'           => [
                'sold_comps_count'      => $presentation->soldComps->count(),
                'active_listings_count' => $presentation->activeListings->count(),
                'upload_count'          => $presentation->uploads->count(),
                'links_count'           => $presentation->links->count(),
            ],
            'analytics'          => $snapshotOutputs,
            'analytics_inputs'   => $snapshotInputs,
            'competitive_stock'  => $snapshotOutputs['competitive_stock'] ?? null,
            'holding_cost'       => $holdingCostResult,
            'confidence'         => $snapshotOutputs['confidence']     ?? null,
            'explainability'     => $snapshotOutputs['explainability'] ?? null,
            'ppi'                => $snapshotOutputs['ppi']            ?? null,
            'articles'           => $presentation->articles->map(fn ($a) => [
                'url'           => $a->url,
                'summary'       => $a->ai_summary_text,
                'tags'          => $a->tags_json,
                'snapshot_hash' => $a->content_hash,
                'fetched_at'    => $a->fetched_at?->toIso8601String(),
                'ai_model'      => $a->ai_summary_model,
            ])->values()->all(),
        ];

        // Build 4 — seed enabled_sections_json from the agency's defaults.
        // This makes the version's section list explicit at compile time,
        // so the review screen + PDF render from a single source of truth.
        // Dependencies are enforced — if the agency defaulted Pricing
        // Strategy ON but CMA OFF, the cascade pulls Pricing Strategy
        // back to OFF with a [PRES-WARN] log.
        $agency = $presentation->agency_id
            ? \App\Models\Agency::find($presentation->agency_id)
            : null;
        $enabledSections = $agency
            ? $agency->sectionDefaults()
            : array_fill_keys(array_keys(PresentationVersion::SECTIONS_CATALOGUE), true);
        $enabledSections = $this->enforceSectionDependencies($enabledSections, $presentation->id);

        // PDF readiness gate (auto-summary build) — Executive Summary
        // copy-forward from the most recent prior version. Every new
        // version inherits the agent's accepted summary text + edited
        // flag so Compile never erases work AND never triggers a fresh
        // AI call. When no prior version had a summary (or this is the
        // first compile after that build shipped), the fields stay null
        // and the readiness gate blocks the PDF until the agent
        // regenerates from the Exec Summary panel.
        $previousVersion = PresentationVersion::where('presentation_id', $presentation->id)
            ->whereNotNull('ai_summary_text')
            ->orderByDesc('id')
            ->first();

        $createPayload = [
            'presentation_id'       => $presentation->id,
            'compiled_by'           => $compiledBy,
            'blueprint_version'     => PresentationBlueprintService::CURRENT_VERSION,
            'analytics_run_id'      => $analyticsRunId,
            'probability_run_id'    => $probabilityRunId,
            'data_snapshot_json'    => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'compiled_at'           => now(),
            'enabled_sections_json' => $enabledSections,
        ];
        if ($previousVersion) {
            // PRESERVE ai_summary_edited_by_agent — an edit stays flagged
            // across versions until the agent regenerates. Decision
            // locked in the auto-summary build prompt.
            $createPayload['ai_summary_text']            = $previousVersion->ai_summary_text;
            $createPayload['ai_summary_raw_text']        = $previousVersion->ai_summary_raw_text;
            $createPayload['ai_variant_id']              = $previousVersion->ai_variant_id;
            $createPayload['ai_summary_edited_by_agent'] = $previousVersion->ai_summary_edited_by_agent;
            $createPayload['ai_summary_generated_at']    = $previousVersion->ai_summary_generated_at;
            $createPayload['ai_summary_model']           = $previousVersion->ai_summary_model;
            $createPayload['ai_summary_prompt_hash']     = $previousVersion->ai_summary_prompt_hash;
        }

        return PresentationVersion::create($createPayload);
    }

    /**
     * Build 4 — cascade OFF for dependent sections when their
     * dependency is OFF. Logs every cascade so we can audit how often
     * agency defaults are internally inconsistent.
     *
     * @param  array<string, bool>  $sections
     * @param  int                  $presentationId  for log context
     * @return array<string, bool>
     */
    private function enforceSectionDependencies(array $sections, int $presentationId): array
    {
        foreach (PresentationVersion::SECTION_DEPENDENCIES as $dependent => $deps) {
            if (!($sections[$dependent] ?? true)) continue;
            foreach ($deps as $dep) {
                if (!($sections[$dep] ?? true)) {
                    \Illuminate\Support\Facades\Log::warning('[PRES-WARN] section dependency cascade — disabling dependent', [
                        'presentation_id' => $presentationId,
                        'dependent'       => $dependent,
                        'missing'         => $dep,
                    ]);
                    $sections[$dependent] = false;
                    break;
                }
            }
        }
        // Floor sections coerce to true.
        foreach (PresentationVersion::SECTION_FLOOR as $floor) {
            $sections[$floor] = true;
        }
        return $sections;
    }
}
