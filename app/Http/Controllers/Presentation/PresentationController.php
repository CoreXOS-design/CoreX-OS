<?php

namespace App\Http\Controllers\Presentation;

use App\Domain\Presentation\UploadProcessor;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MarketAnalyticsRun;
use App\Models\Presentation;
use App\Models\PresentationSnapshot;
use App\Models\PresentationUpload;
use App\Models\SaleProbabilityRun;
use App\Services\HoldingCost\HoldingCostLeverage;
use App\Services\HoldingCost\HoldingCostService;
use App\Services\MarketAnalytics\Adapters\ImportedListingsAdapter;
use App\Services\MarketAnalytics\Adapters\InternalDealsAdapter;
use App\Services\MarketAnalytics\DTOs\MarketAnalyticsInput;
use App\Services\MarketAnalytics\Helpers\InputHasher;
use App\Services\MarketAnalytics\MarketAnalyticsService;
use App\Services\Presentations\ArticleIngestionService;
use App\Services\Presentations\Evidence\LinkExtractionService;
use App\Services\Presentations\Evidence\UploadExtractionService;
use App\Services\Presentations\Evidence\UrlIngestionService;
use App\Services\Presentations\PresentationBlueprintService;
use App\Services\Presentations\PresentationCompilerService;
use App\Services\Presentations\PresentationNarrativeService;
use App\Services\Presentations\PresentationReadinessService;
use App\Services\Presentations\RecommendationService;
use App\Services\Presentations\PriceBandService;
use App\Services\Presentations\TrajectorySimulationService;
use App\Services\Presentations\UrlSnapshotService;
use App\Services\SaleProbability\DTOs\SaleProbabilityInput;
use App\Services\Presentations\ExplainabilityService;
use App\Services\Presentations\HoldingCostService as PresentationHoldingCostService;
use App\Services\Presentations\PPIService;
use App\Services\SaleProbability\ConfidenceScoringService;
use App\Services\SaleProbability\InterpretationService;
use App\Services\SaleProbability\SaleProbabilityService;
use Illuminate\Http\Request;

class PresentationController extends Controller
{
    /**
     * List all presentations for this user's branch context.
     */
    public function index()
    {
        $presentations = Presentation::with(['snapshots'])
            ->latest()
            ->paginate(25);

        return view('presentations.index', compact('presentations'));
    }

    /**
     * Show the create presentation form.
     * Admins see a branch selector; branch-bound users do not.
     */
    public function create()
    {
        $isAdmin = auth()->user()->isEffectiveAdmin();
        $branches = $isAdmin ? Branch::orderBy('name')->get() : collect();

        return view('presentations.create', compact('branches', 'isAdmin'));
    }

    /**
     * Store a newly created presentation.
     * Branch is auto-determined from the user unless they are an admin.
     * Redirects straight to the analysis screen (Prompt A).
     */
    public function store(Request $request)
    {
        $isAdmin = auth()->user()->isEffectiveAdmin();

        $rules = [
            'title'            => ['required', 'string', 'max:255'],
            'property_address' => ['required', 'string', 'max:500'],
            'suburb'           => ['required', 'string', 'max:100'],
            'property_type'    => ['required', 'string', 'in:house,unit,land,other'],
            'bedrooms'         => ['nullable', 'integer', 'min:0', 'max:20'],
            'floor_area_m2'    => ['nullable', 'integer', 'min:0'],
            'seller_name'      => ['nullable', 'string', 'max:255'],
        ];

        if ($isAdmin) {
            $rules['branch_id'] = ['required', 'integer', 'exists:branches,id'];
        }

        $validated = $request->validate($rules);

        $branchId = $isAdmin
            ? (int) $validated['branch_id']
            : (int) auth()->user()->effectiveBranchId();

        $presentation = Presentation::create([
            'title'              => $validated['title'],
            'property_address'   => $validated['property_address'],
            'suburb'             => $validated['suburb'],
            'property_type'      => $validated['property_type'],
            'bedrooms'           => $validated['bedrooms'] ?? null,
            'floor_area_m2'      => $validated['floor_area_m2'] ?? null,
            'seller_name'        => $validated['seller_name'] ?? null,
            'branch_id'          => $branchId,
            'created_by_user_id' => auth()->id(),
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);

        // Intake-first workflow: land on overview with pack readiness visible
        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Presentation created. Upload evidence and add links below, then run analysis when ready.');
    }

    /**
     * Show overview tab for a single presentation.
     */
    public function show(Presentation $presentation)
    {
        $latestSnapshot = $presentation->snapshots()->latest()->first();
        $snapshotCount  = $presentation->snapshots()->count();
        $links          = $presentation->links()->orderBy('created_at')->get();
        $readiness      = (new PresentationReadinessService())->evaluate($presentation);

        // ── Power Panel (UI1) — feature-flagged ──────────────────────────
        $powerPanel = null;
        if (config('features.presentation_power_panel_v1') && $latestSnapshot) {
            $outputs = $latestSnapshot->getOutputSummaryArray();

            $powerPanel = [
                'p30'            => $outputs['p30'] ?? null,
                'p60'            => $outputs['p60'] ?? null,
                'p90'            => $outputs['p90'] ?? null,
                'expected_days'  => $outputs['expected_days'] ?? null,
                'confidence'     => $outputs['confidence'] ?? null,
                'ppi'            => $outputs['ppi'] ?? null,
                'explainability' => $outputs['explainability'] ?? null,
                'holding_cost'   => $outputs['holding_cost'] ?? null,
                'competitive_stock' => $outputs['competitive_stock'] ?? null,
                'snapshot_at'    => $latestSnapshot->created_at,
            ];
        }

        return view('presentations.show', compact(
            'presentation', 'latestSnapshot', 'snapshotCount', 'links', 'readiness', 'powerPanel'
        ));
    }

    /**
     * Show the analysis input form.
     * Pre-populates from the most recent snapshot inputs if one exists,
     * otherwise falls back to the presentation's own stored fields (Prompt A).
     */
    public function analysis(Presentation $presentation)
    {
        // Readiness gate — redirect to intake if evidence is insufficient
        if (!$presentation->isAnalysisReady()) {
            $readiness = (new PresentationReadinessService())->evaluate($presentation);
            $missing   = implode(', ', array_column($readiness['missing_required'], 'label'));
            return redirect()->route('presentations.show', $presentation)
                ->with('error', 'Add the following before running analysis: ' . $missing);
        }

        $isAdmin        = auth()->user()->isEffectiveAdmin();
        $branches       = $isAdmin ? Branch::orderBy('name')->get() : collect();
        $latestSnapshot = $presentation->snapshots()->latest()->first();

        if ($latestSnapshot) {
            $lastInputs = $latestSnapshot->getInputsArray();
        } else {
            // Pre-fill from the presentation's own stored fields
            $lastInputs = array_filter([
                'suburb'    => $presentation->suburb,
                'type'      => $presentation->property_type,
                'bedrooms'  => $presentation->bedrooms,
                'size_m2'   => $presentation->floor_area_m2,
                'branch_id' => $presentation->branch_id,
            ], fn($v) => $v !== null);
        }

        $hasSoldData = false; // no analysis run yet on GET

        return view('presentations.analysis', compact('presentation', 'branches', 'lastInputs', 'isAdmin', 'hasSoldData'));
    }

    /**
     * Store a link (Property24, Lightstone, etc.) on a presentation.
     */
    public function storeLink(Request $request, Presentation $presentation)
    {
        $validated = $request->validate([
            'type'             => ['required', 'string', 'in:property24,lightstone,active_listing,competitor_listing,market_article,other'],
            'url'              => ['required', 'url', 'max:2000'],
            'notes'            => ['nullable', 'string', 'max:500'],
            // Optional property metadata (property24 only)
            'asking_price_inc' => ['nullable', 'integer', 'min:0'],
            'beds'             => ['nullable', 'integer', 'min:0', 'max:20'],
            'baths'            => ['nullable', 'integer', 'min:0', 'max:20'],
            'floor_area_m2'    => ['nullable', 'integer', 'min:0'],
            'erf_m2'           => ['nullable', 'integer', 'min:0'],
            'property_type'    => ['nullable', 'string', 'in:house,unit,land,other'],
            'suburb'           => ['nullable', 'string', 'max:100'],
        ]);

        $link = $presentation->links()->create([
            'type'               => $validated['type'],
            'url'                => $validated['url'],
            'notes'              => $validated['notes'] ?? null,
            'created_by_user_id' => auth()->id(),
            'asking_price_inc'   => $validated['asking_price_inc'] ?? null,
            'beds'               => $validated['beds'] ?? null,
            'baths'              => $validated['baths'] ?? null,
            'floor_area_m2'      => $validated['floor_area_m2'] ?? null,
            'erf_m2'             => $validated['erf_m2'] ?? null,
            'property_type'      => $validated['property_type'] ?? null,
            'suburb'             => $validated['suburb'] ?? null,
        ]);

        // Run deterministic extraction on the new link
        (new LinkExtractionService())->run($link);

        // Prefill presentation fields from link metadata — only if fields are currently empty.
        // Never overwrites existing values. Only applies to property24 links.
        if ($validated['type'] === 'property24') {
            $prefill = [];
            if (empty($presentation->floor_area_m2) && !empty($validated['floor_area_m2'])) {
                $prefill['floor_area_m2'] = (int)$validated['floor_area_m2'];
            }
            if (empty($presentation->bedrooms) && !empty($validated['beds'])) {
                $prefill['bedrooms'] = (int)$validated['beds'];
            }
            if (empty($presentation->property_type) && !empty($validated['property_type'])) {
                $prefill['property_type'] = $validated['property_type'];
            }
            if (empty($presentation->suburb) && !empty($validated['suburb'])) {
                $prefill['suburb'] = $validated['suburb'];
            }
            if (!empty($prefill)) {
                $presentation->update($prefill);
            }
        }

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Link added.');
    }

    /**
     * Delete a link from a presentation.
     */
    public function destroyLink(Presentation $presentation, \App\Models\PresentationLink $link)
    {
        abort_if($link->presentation_id !== $presentation->id, 403);
        $link->delete();

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Link removed.');
    }

    /**
     * Update the type of an existing link.
     */
    public function updateLinkType(Request $request, Presentation $presentation, \App\Models\PresentationLink $link)
    {
        abort_if($link->presentation_id !== $presentation->id, 403);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:property24,lightstone,active_listing,competitor_listing,market_article,other'],
        ]);

        $link->update(['type' => $validated['type']]);

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Link type updated.');
    }

    /**
     * Save holding cost inputs on the presentation (canonical storage, P15).
     */
    public function updateHoldingCost(Request $request, Presentation $presentation)
    {
        $validated = $request->validate([
            'monthly_bond'             => ['nullable', 'numeric', 'min:0'],
            'monthly_rates'            => ['nullable', 'numeric', 'min:0'],
            'monthly_levies'           => ['nullable', 'numeric', 'min:0'],
            'monthly_insurance'        => ['nullable', 'numeric', 'min:0'],
            'monthly_utilities'        => ['nullable', 'numeric', 'min:0'],
            'monthly_opportunity_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $presentation->update([
            'monthly_bond'             => isset($validated['monthly_bond'])             ? (float) $validated['monthly_bond']             : null,
            'monthly_rates'            => isset($validated['monthly_rates'])            ? (float) $validated['monthly_rates']            : null,
            'monthly_levies'           => isset($validated['monthly_levies'])           ? (float) $validated['monthly_levies']           : null,
            'monthly_insurance'        => isset($validated['monthly_insurance'])        ? (float) $validated['monthly_insurance']        : null,
            'monthly_utilities'        => isset($validated['monthly_utilities'])        ? (float) $validated['monthly_utilities']        : null,
            'monthly_opportunity_cost' => isset($validated['monthly_opportunity_cost']) ? (float) $validated['monthly_opportunity_cost'] : null,
        ]);

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Holding cost inputs saved.');
    }

    /**
     * Handle document upload(s) for a presentation.
     * Supports multiple files in one batch with a user-selected doc_type.
     * Stores file, extracts text, and detects structured fields.
     * Never touches finance logic.
     */
    public function upload(Request $request, Presentation $presentation)
    {
        $request->validate([
            'doc_type'    => ['required', 'string', 'in:suburb_stats,vicinity_sales,cma,market_article,other'],
            'documents'   => ['required', 'array', 'min:1'],
            'documents.*' => ['file', 'max:20480'], // 20 MB each
        ]);

        $docType   = $request->input('doc_type');
        $processor = new UploadProcessor(new \App\Domain\Presentation\TextExtractionService());
        $count     = 0;

        foreach ($request->file('documents') as $file) {
            $upload = $processor->process($file, $presentation, auth()->id(), $docType);
            (new UploadExtractionService())->run($upload);
            $count++;
        }

        $label = $count === 1 ? '1 file uploaded' : "{$count} files uploaded";

        return redirect()->route('presentations.show', $presentation)
            ->with('success', "{$label} and processed.");
    }

    /**
     * Update the document type of an existing upload.
     */
    public function updateUploadType(Request $request, Presentation $presentation, PresentationUpload $upload)
    {
        abort_if($upload->presentation_id !== $presentation->id, 403);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:suburb_stats,vicinity_sales,cma,market_article,other'],
        ]);

        $upload->update(['type' => $validated['type']]);

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Document type updated.');
    }

    /**
     * Save user override on an uploaded document's extracted data.
     * Stores override_json with audit fields (who/when).
     */
    public function saveUploadOverride(Request $request, Presentation $presentation, PresentationUpload $upload)
    {
        abort_if($upload->presentation_id !== $presentation->id, 403);

        $validated = $request->validate([
            'override_data' => ['required', 'array'],
        ]);

        $upload->update([
            'override_json'       => $validated['override_data'],
            'override_by_user_id' => auth()->id(),
            'override_at'         => now(),
        ]);

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Override saved for ' . ($upload->original_filename ?? 'document') . '.');
    }

    /**
     * Clear a user override on an uploaded document, reverting to extracted data.
     */
    public function clearUploadOverride(Request $request, Presentation $presentation, PresentationUpload $upload)
    {
        abort_if($upload->presentation_id !== $presentation->id, 403);

        $upload->update([
            'override_json'       => null,
            'override_by_user_id' => null,
            'override_at'         => null,
        ]);

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Override cleared for ' . ($upload->original_filename ?? 'document') . '.');
    }

    /**
     * Save user override on a link's extracted data.
     * Stores override_json with audit fields (who/when).
     */
    public function saveLinkOverride(Request $request, Presentation $presentation, \App\Models\PresentationLink $link)
    {
        abort_if($link->presentation_id !== $presentation->id, 403);

        $validated = $request->validate([
            'override_data' => ['required', 'array'],
        ]);

        $link->update([
            'override_json'       => $validated['override_data'],
            'override_by_user_id' => auth()->id(),
            'override_at'         => now(),
        ]);

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Override saved for link.');
    }

    /**
     * Clear a user override on a link, reverting to extracted data.
     */
    public function clearLinkOverride(Request $request, Presentation $presentation, \App\Models\PresentationLink $link)
    {
        abort_if($link->presentation_id !== $presentation->id, 403);

        $link->update([
            'override_json'       => null,
            'override_by_user_id' => null,
            'override_at'         => null,
        ]);

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Override cleared for link.');
    }

    /**
     * Re-run extraction on an existing upload.
     */
    public function reExtractUpload(Presentation $presentation, PresentationUpload $upload)
    {
        abort_if($upload->presentation_id !== $presentation->id, 403);

        (new UploadExtractionService())->run($upload);

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Re-extracted: ' . ($upload->original_filename ?? 'document') . '.');
    }

    /**
     * Re-run extraction on an existing link.
     */
    public function reExtractLink(Presentation $presentation, \App\Models\PresentationLink $link)
    {
        abort_if($link->presentation_id !== $presentation->id, 403);

        (new LinkExtractionService())->run($link);

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Re-extracted link.');
    }

    /**
     * Run market analytics + sale probability for a presentation.
     * Validates inputs, calls both services, returns analysis view with results.
     */
    public function compute(Request $request, Presentation $presentation)
    {
        // Readiness gate — redirect to intake if evidence is insufficient
        if (!$presentation->isAnalysisReady()) {
            $readiness = (new PresentationReadinessService())->evaluate($presentation);
            $missing   = implode(', ', array_column($readiness['missing_required'], 'label'));
            return redirect()->route('presentations.show', $presentation)
                ->with('error', 'Add the following before running analysis: ' . $missing);
        }

        // A1: resolve admin status once — used for branch enforcement and branch list
        $isAdmin = auth()->user()->isEffectiveAdmin();

        $validated = $request->validate([
            'suburb'        => ['required', 'string', 'max:100'],
            'type'          => ['required', 'string', 'in:house,unit,land,other'],
            'price'         => ['nullable', 'numeric', 'min:0'],
            'size_m2'       => ['nullable', 'integer', 'min:0'],
            'bedrooms'      => ['nullable', 'integer', 'min:0', 'max:20'],
            'period_months' => ['required', 'integer', 'in:6,12,24'],
            'branch_id'     => ['nullable', 'integer', 'exists:branches,id'],
            // Holding cost inputs (optional)
            'monthly_bond'               => ['nullable', 'numeric', 'min:0'],
            'monthly_rates'              => ['nullable', 'numeric', 'min:0'],
            'monthly_levies'             => ['nullable', 'numeric', 'min:0'],
            'monthly_insurance'          => ['nullable', 'numeric', 'min:0'],
            'monthly_maintenance_buffer' => ['nullable', 'numeric', 'min:0'],
        ]);

        // A1: non-admins are bound to their own branch — ignore any posted branch_id
        $effectiveBranchId = $isAdmin
            ? (isset($validated['branch_id']) ? (int) $validated['branch_id'] : null)
            : (int) auth()->user()->effectiveBranchId();

        $maInput = new MarketAnalyticsInput(
            suburb:          $validated['suburb'],
            propertyType:    $validated['type'],
            periodMonths:    (int) $validated['period_months'],
            bedrooms:        isset($validated['bedrooms']) ? (int) $validated['bedrooms'] : null,
            sourceBranchId:  $effectiveBranchId,
            subjectSizeM2:   isset($validated['size_m2']) ? (int) $validated['size_m2'] : null,
            subjectPriceInc: isset($validated['price']) ? (float) $validated['price'] : null,
            presentationId:  $presentation->id,
        );

        $maService = new MarketAnalyticsService(
            new InternalDealsAdapter(),
            new ImportedListingsAdapter(),
        );

        $maResult = $maService->run($maInput);

        // Retrieve the MA run that was just persisted (by stable inputs hash)
        $inputsHash = InputHasher::hash($maInput);
        $maRun      = MarketAnalyticsRun::where('inputs_hash', $inputsHash)->latest()->first();

        // Run SP service to capture sensitivity array + tag created_by
        $spInput = new SaleProbabilityInput(
            marketAnalyticsRunId:        $maRun->id,
            marketAnalyticsModelVersion: MarketAnalyticsService::MODEL_VERSION,
            marketAnalyticsInputsHash:   $inputsHash,
            marketAnalyticsResult:       $maResult,
        );

        $spResult = (new SaleProbabilityService())->run($spInput, auth()->id());

        // Retrieve the SP run that was just persisted (latest for this MA run)
        $spRun = SaleProbabilityRun::where('market_analytics_run_id', $maRun->id)
            ->latest()
            ->first();

        $branches = $isAdmin ? Branch::orderBy('name')->get() : collect();

        // ── Build snapshot payloads (assembled here, not in Blade) ────────────

        $snapshotInputsPayload = [
            'suburb'        => $validated['suburb'],
            'type'          => $validated['type'],
            'period_months' => (int) $validated['period_months'],
            'price'         => isset($validated['price'])    ? (float) $validated['price']    : null,
            'size_m2'       => isset($validated['size_m2'])  ? (int)   $validated['size_m2']  : null,
            'bedrooms'      => isset($validated['bedrooms']) ? (int)   $validated['bedrooms'] : null,
            'branch_id'     => $effectiveBranchId,
        ];

        // Index sensitivity rows by delta for O(1) lookup of the three quick cards
        $sensitivityByDelta = [];
        foreach ($spResult->sensitivity as $row) {
            $sensitivityByDelta[$row['delta_rands']] = $row;
        }

        $sensitivityCard = static function (int $delta) use ($sensitivityByDelta): ?array {
            $row = $sensitivityByDelta[$delta] ?? null;
            if ($row === null) {
                return null;
            }
            return [
                'delta_rands'            => $row['delta_rands'],
                'adjusted_deviation_pct' => $row['adjusted_deviation_pct'] ?? null,
                'composite_score'        => $row['composite_score'] ?? null,
                'p60'                    => $row['p60'],
                'expected_days'          => $row['expected_days'],
                'skip_reason'            => $row['skip_reason'] ?? null,
            ];
        };

        $domCurveArr = is_array($maResult->domCurve) ? $maResult->domCurve : [];

        $snapshotOutputSummaryPayload = [
            // Probabilities
            'p30'          => $spResult->p30,
            'p60'          => $spResult->p60,
            'p90'          => $spResult->p90,
            'expected_days'=> $spResult->expectedDays,
            'skip_reason'  => $spResult->skipReason,
            // Market evidence
            'months_of_inventory'         => $maResult->monthsOfInventory,
            'demand_supply_ratio'         => $maResult->demandSupplyRatio,
            'price_per_sqm_deviation_pct' => $maResult->pricePerSqmDeviationPct,
            'dom_p25'                     => $domCurveArr['p25'] ?? null,
            'dom_p50'                     => $domCurveArr['p50'] ?? null,
            'dom_p75'                     => $domCurveArr['p75'] ?? null,
            'elasticity_days_per_pct'     => $maResult->elasticityDaysPerPct,
            'elasticity_r_squared'        => $maResult->elasticityRSquared,
            // Sensitivity quick cards (−50 k / −100 k / −150 k only)
            'sensitivity_drop_50k'  => $sensitivityCard(-50000),
            'sensitivity_drop_100k' => $sensitivityCard(-100000),
            'sensitivity_drop_150k' => $sensitivityCard(-150000),
        ];

        $snapshotInputsJson        = json_encode($snapshotInputsPayload,        JSON_THROW_ON_ERROR);
        $snapshotOutputSummaryJson = json_encode($snapshotOutputSummaryPayload, JSON_THROW_ON_ERROR);

        // ── Strategy interpretation ────────────────────────────────────────
        $interpretation = new InterpretationService();
        $strategy       = $interpretation->addStrategyRecommendation($spResult);

        // ── Holding cost engine ────────────────────────────────────────────
        $holdingCost = new HoldingCostService(
            monthlyBond:              (float) ($validated['monthly_bond']               ?? 0),
            monthlyRates:             (float) ($validated['monthly_rates']              ?? 0),
            monthlyLevies:            (float) ($validated['monthly_levies']             ?? 0),
            monthlyInsurance:         (float) ($validated['monthly_insurance']          ?? 0),
            monthlyMaintenanceBuffer: (float) ($validated['monthly_maintenance_buffer'] ?? 0),
        );

        // ── Narrative service (Prompt 6) ───────────────────────────────────
        $narrative = (new PresentationNarrativeService())->build(
            $maResult,
            $spResult,
            $holdingCost,
            $validated,
        );

        // ── Holding cost leverage — R50k drop (Prompt 7) ──────────────────
        $leverage50k = null;
        if ($holdingCost->monthlyTotal() > 0) {
            $drop50kRow = $sensitivityByDelta[-50000] ?? null;
            $daysDelta  = null;

            if (
                $drop50kRow !== null
                && $spResult->expectedDays !== null
                && isset($drop50kRow['expected_days'])
                && $drop50kRow['expected_days'] !== null
            ) {
                // Positive = days saved by the price drop
                $daysDelta = $spResult->expectedDays - (int) $drop50kRow['expected_days'];
            }

            $leverage50k = [
                'equivalent_days' => HoldingCostLeverage::equivalentDaysForPriceDrop(50000, $holdingCost->monthlyTotal()),
                'days_delta'      => $daysDelta,
                'message'         => HoldingCostLeverage::message($holdingCost->monthlyTotal(), 50000),
            ];
        }

        // ── Recommendation service (Prompt 9) ─────────────────────────────
        $recommendation = null;
        if (isset($validated['price']) && (float) $validated['price'] > 0) {
            $recommendation = (new RecommendationService())->generate(
                basePrice:           (float) $validated['price'],
                sensitivityRows:     $spResult->sensitivity,
                monthlyHoldingCost:  $holdingCost->monthlyTotal() > 0 ? $holdingCost->monthlyTotal() : null,
                targetProbability:   0.65,
            );
        }

        // C1: pass sold-data gate to view (determines which panels render)
        $hasSoldData = ($maResult->soldCount ?? 0) > 0;

        return view('presentations.analysis', [
            'presentation'              => $presentation,
            'maResult'                  => $maResult,
            'spResult'                  => $spResult,
            'maRun'                     => $maRun,
            'spRun'                     => $spRun,
            'inputs'                    => $validated,
            'branches'                  => $branches,
            'snapshotInputsJson'        => $snapshotInputsJson,
            'snapshotOutputSummaryJson' => $snapshotOutputSummaryJson,
            'lastInputs'                => [],
            'strategy'                  => $strategy,
            'holdingCost'               => $holdingCost,
            'narrative'                 => $narrative,
            'leverage50k'               => $leverage50k,
            'recommendation'            => $recommendation,
            'isAdmin'                   => $isAdmin,
            'hasSoldData'               => $hasSoldData,
        ]);
    }

    /**
     * Compile a frozen presentation version snapshot.
     * Gated by features.presentation_blueprint config flag.
     * Optionally gated by readiness checklist (P16).
     */
    public function compile(Request $request, Presentation $presentation)
    {
        abort_unless(config("features.presentation_blueprint"), 404);

        // Readiness gate (P16) — blocks unless can_compile or admin force-overrides
        if (config('features.presentation_readiness_check', false)) {
            $readiness = (new PresentationReadinessService())->evaluate($presentation);
            if (!$readiness['can_compile']) {
                $isAdmin   = auth()->user()->isEffectiveAdmin();
                $hasForce  = $request->boolean('force');
                if (!($isAdmin && $hasForce)) {
                    $missing = implode('; ', array_column($readiness['missing_required'], 'label'));
                    return redirect()->route('presentations.show', $presentation)
                        ->with('error', 'Cannot compile — missing required evidence: ' . $missing);
                }
            }
        }

        $version = (new PresentationCompilerService())->compile(
            $presentation->id,
            auth()->id(),
        );

        return redirect()->route("presentations.show", $presentation)
            ->with("success", "Version #" . $version->id . " compiled successfully.");
    }

    /**
     * Store a URL snapshot and trigger ingestion if applicable.
     * Gated by p24_ingestion / private_property_ingestion / article_ingestion feature flags.
     */
    public function storeUrlSnapshot(Request $request, Presentation $presentation)
    {
        $validated = $request->validate([
            'url'         => ['required', 'url', 'max:2000'],
            'source_type' => ['required', 'string', 'in:' . implode(',', UrlSnapshotService::ALLOWED_SOURCE_TYPES)],
            'tags'        => ['nullable', 'array'],
            'tags.*'      => ['string', 'max:100'],
        ]);

        $sourceType = $validated['source_type'];

        // Article flow → ArticleIngestionService handles snapshot + summary
        if ($sourceType === 'article') {
            abort_unless(config('features.article_ingestion'), 403, 'Article ingestion is disabled.');

            $result = (new ArticleIngestionService())->ingest(
                $presentation->id,
                $validated['url'],
                $validated['tags'] ?? [],
            );

            return response()->json([
                'status'  => 'ok',
                'article' => $result,
            ]);
        }

        // Listing flow → UrlSnapshotService + UrlIngestionService
        $snapshot  = (new UrlSnapshotService())->storeSnapshot($presentation->id, $validated['url'], $sourceType);
        $ingestion = null;

        $listingTypes = ['p24_search', 'p24_listing', 'private_property', 'private_property_search', 'private_property_listing'];

        if (in_array($sourceType, $listingTypes, true)) {
            $featureEnabled = match (true) {
                str_starts_with($sourceType, 'p24')             => config('features.p24_ingestion', false),
                str_starts_with($sourceType, 'private_property')=> config('features.private_property_ingestion', false),
                default                                          => false,
            };

            if ($featureEnabled) {
                $ingestion = (new UrlIngestionService())->ingest($presentation->id, $snapshot->id);
            }
        }

        return response()->json([
            'status'    => 'ok',
            'snapshot'  => [
                'id'          => $snapshot->id,
                'url'         => $snapshot->url,
                'source_type' => $snapshot->source_type,
                'http_status' => $snapshot->http_status,
                'hash'        => $snapshot->content_hash,
            ],
            'ingestion' => $ingestion,
        ]);
    }

    /**
     * Re-run analytics + probability WITHOUT persisting — live simulation.
     * Returns the standardised P10 data contract (price, probability, competitive position).
     * No DB writes occur.
     */
    public function simulate(Request $request, Presentation $presentation)
    {
        $validated = $request->validate([
            'suburb'        => ['required', 'string', 'max:100'],
            'type'          => ['required', 'string', 'in:house,unit,land,other'],
            'price'         => ['nullable', 'numeric', 'min:0'],
            'size_m2'       => ['nullable', 'integer', 'min:0'],
            'bedrooms'      => ['nullable', 'integer', 'min:0', 'max:20'],
            'period_months' => ['required', 'integer', 'in:6,12,24'],
            'branch_id'     => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $isAdmin           = auth()->user()->isEffectiveAdmin();
        $effectiveBranchId = $isAdmin
            ? (isset($validated['branch_id']) ? (int) $validated['branch_id'] : null)
            : (int) auth()->user()->effectiveBranchId();

        $subjectPrice = isset($validated['price']) ? (float) $validated['price'] : null;

        $maInput = new MarketAnalyticsInput(
            suburb:          $validated['suburb'],
            propertyType:    $validated['type'],
            periodMonths:    (int) $validated['period_months'],
            bedrooms:        isset($validated['bedrooms']) ? (int) $validated['bedrooms'] : null,
            sourceBranchId:  $effectiveBranchId,
            subjectSizeM2:   isset($validated['size_m2']) ? (int) $validated['size_m2'] : null,
            subjectPriceInc: $subjectPrice,
            presentationId:  $presentation->id,
        );

        $maService = new MarketAnalyticsService(
            new InternalDealsAdapter(),
            new ImportedListingsAdapter(),
        );

        $maResult   = $maService->run($maInput, persist: false);
        $inputsHash = InputHasher::hash($maInput);

        $spInput = new SaleProbabilityInput(
            marketAnalyticsRunId:        null,
            marketAnalyticsModelVersion: MarketAnalyticsService::MODEL_VERSION,
            marketAnalyticsInputsHash:   $inputsHash,
            marketAnalyticsResult:       $maResult,
        );

        $spResult = (new SaleProbabilityService())->run($spInput, createdBy: null, persist: false);

        // ── Competitive position from breakdown ───────────────────────────────
        $breakdown       = $maResult->toBreakdownArray();
        $compStock       = $breakdown['competitive_stock'] ?? null;
        $totalActive     = $compStock['total_active_stock'] ?? 0;
        $belowCount      = $compStock['below_subject_count'] ?? null;
        $aboveCount      = $compStock['above_subject_count'] ?? null;
        $percentilePos   = ($totalActive > 0 && $belowCount !== null)
            ? round($belowCount / $totalActive, 4)
            : null;

        // ── Absorption rate from breakdown ────────────────────────────────────
        $absorptionRate  = $breakdown['absorption_rate']['monthly_sold'] ?? null;

        // ── Confidence scoring (P11) ──────────────────────────────────────────
        $confidence = (new ConfidenceScoringService())->evaluate($maResult, $spResult);

        // ── Explainability block (P12) ────────────────────────────────────────
        $explainability = (new ExplainabilityService())->generate(
            $maResult,
            $spResult,
            $compStock ?? [],
        );

        // ── Holding cost from presentation canonical fields (P15) ─────────────
        $holdingCostMonthly = 0.0;
        $holdingCostResult  = null;
        $hcInputs = [
            'bond_payment'     => (float) ($presentation->monthly_bond             ?? 0),
            'rates'            => (float) ($presentation->monthly_rates            ?? 0),
            'levies'           => (float) ($presentation->monthly_levies           ?? 0),
            'insurance'        => (float) ($presentation->monthly_insurance        ?? 0),
            'utilities'        => (float) ($presentation->monthly_utilities        ?? 0),
            'opportunity_cost' => (float) ($presentation->monthly_opportunity_cost ?? 0),
        ];
        if (array_sum(array_values($hcInputs)) > 0) {
            $holdingCostResult  = (new PresentationHoldingCostService())->calculate($hcInputs);
            $holdingCostMonthly = $holdingCostResult['monthly_total'];
        }

        // ── Presentation Performance Index (P13) ──────────────────────────────
        $ppi = ($spResult->p60 !== null)
            ? (new PPIService())->calculate(
                p60:                $spResult->p60,
                confidenceScore:    $confidence['confidence_score'],
                percentilePosition: $percentilePos ?? 0.5,
                holdingCostMonthly: $holdingCostMonthly,
            )
            : null;

        // ── Persist simulation snapshot (feature-flagged, P14) ───────────────
        if (config('features.presentation_simulate_snapshot', false)) {
            $simulateInputsPayload = [
                'suburb'        => $validated['suburb'],
                'type'          => $validated['type'],
                'price'         => $subjectPrice,
                'size_m2'       => isset($validated['size_m2'])  ? (int) $validated['size_m2']  : null,
                'bedrooms'      => isset($validated['bedrooms']) ? (int) $validated['bedrooms'] : null,
                'period_months' => (int) $validated['period_months'],
                'branch_id'     => $effectiveBranchId,
            ];

            $simulateOutputPayload = [
                'p30'               => $spResult->p30,
                'p60'               => $spResult->p60,
                'p90'               => $spResult->p90,
                'expected_days'     => $spResult->expectedDays,
                'skip_reason'       => $spResult->skipReason ?? null,
                'confidence'        => $confidence,
                'explainability'    => $explainability,
                'ppi'               => $ppi,
                'competitive_stock' => $compStock,
                'holding_cost'      => $holdingCostResult,
            ];

            PresentationSnapshot::create([
                'presentation_id'         => $presentation->id,
                'generated_by_user_id'    => auth()->id(),
                'created_by_user_id'      => auth()->id(),
                'market_analytics_run_id' => null,
                'sale_probability_run_id' => null,
                'inputs_json'             => json_encode($simulateInputsPayload, JSON_THROW_ON_ERROR),
                'output_summary_json'     => json_encode($simulateOutputPayload, JSON_THROW_ON_ERROR),
                'snapshot_json'           => json_encode(
                    ['data_sources' => $maResult->toDataSourcesArray(), 'source' => 'simulate'],
                    JSON_THROW_ON_ERROR
                ),
                'generated_at'            => now(),
            ]);
        }

        return response()->json([
            'price_tested' => $subjectPrice,
            'probability'  => [
                'p30' => $spResult->p30,
                'p60' => $spResult->p60,
                'p90' => $spResult->p90,
            ],
            'expected_days'        => $spResult->expectedDays,
            'competitive_position' => [
                'below_count'         => $belowCount,
                'above_count'         => $aboveCount,
                'percentile_position' => $percentilePos,
            ],
            'stock_pressure_index' => $maResult->demandSupplyRatio,
            'absorption_rate'      => $absorptionRate,
            'data_sources'         => $maResult->toDataSourcesArray(),
            'confidence'           => $confidence,
            'explainability'       => $explainability,
            'ppi'                  => $ppi,
            'holding_cost'         => $holdingCostResult,
        ]);
    }

    /**
     * Live "Brain" simulation screen (UI2).
     * Gated by features.presentation_brain_ui_v1.
     * Read-only — all mutations happen via the fetch()-called endpoints.
     */
    public function brain(Presentation $presentation)
    {
        abort_unless(config('features.presentation_brain_ui_v1'), 404);

        $latestSnapshot = $presentation->snapshots()->latest()->first();
        $lastInputs     = $latestSnapshot ? $latestSnapshot->getInputsArray() : [];

        // Pre-populate defaults from snapshot or presentation fields
        $defaults = [
            'suburb'        => $lastInputs['suburb']        ?? $presentation->suburb ?? '',
            'type'          => $lastInputs['type']          ?? $presentation->property_type ?? 'house',
            'price'         => $lastInputs['price']         ?? null,
            'size_m2'       => $lastInputs['size_m2']       ?? $presentation->floor_area_m2 ?? null,
            'bedrooms'      => $lastInputs['bedrooms']      ?? $presentation->bedrooms ?? null,
            'period_months' => $lastInputs['period_months'] ?? 12,
        ];

        return view('presentations.brain', compact('presentation', 'defaults'));
    }

    /**
     * Multi-step price trajectory simulation (C1).
     * Gated by features.trajectory_simulation_v1.
     * No DB writes.
     */
    public function simulateTrajectory(Request $request, Presentation $presentation)
    {
        abort_unless(config('features.trajectory_simulation_v1'), 404);

        $validated = $request->validate([
            'suburb'        => ['required', 'string', 'max:100'],
            'type'          => ['required', 'string', 'in:house,unit,land,other'],
            'size_m2'       => ['nullable', 'integer', 'min:0'],
            'bedrooms'      => ['nullable', 'integer', 'min:0', 'max:20'],
            'period_months' => ['required', 'integer', 'in:6,12,24'],
            'branch_id'     => ['nullable', 'integer', 'exists:branches,id'],
            'price_steps'   => ['required', 'array', 'min:1', 'max:10'],
            'price_steps.*' => ['required', 'numeric', 'min:0'],
            'days_per_step' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $isAdmin           = auth()->user()->isEffectiveAdmin();
        $effectiveBranchId = $isAdmin
            ? (isset($validated['branch_id']) ? (int) $validated['branch_id'] : null)
            : (int) auth()->user()->effectiveBranchId();

        $baseInputs = [
            'suburb'        => $validated['suburb'],
            'type'          => $validated['type'],
            'size_m2'       => isset($validated['size_m2']) ? (int) $validated['size_m2'] : null,
            'bedrooms'      => isset($validated['bedrooms']) ? (int) $validated['bedrooms'] : null,
            'period_months' => (int) $validated['period_months'],
            'branch_id'     => $effectiveBranchId,
        ];

        $result = (new TrajectorySimulationService())->simulateTrajectory(
            presentation: $presentation,
            baseInputs:   $baseInputs,
            priceSteps:   $validated['price_steps'],
            daysPerStep:  (int) ($validated['days_per_step'] ?? 30),
        );

        return response()->json($result);
    }

    /**
     * Optimal price band scan (C2).
     * Gated by features.price_band_v1.
     * No DB writes.
     */
    public function priceBand(Request $request, Presentation $presentation)
    {
        abort_unless(config('features.price_band_v1'), 404);

        $validated = $request->validate([
            'suburb'        => ['required', 'string', 'max:100'],
            'type'          => ['required', 'string', 'in:house,unit,land,other'],
            'price'         => ['required', 'numeric', 'min:1'],
            'size_m2'       => ['nullable', 'integer', 'min:0'],
            'bedrooms'      => ['nullable', 'integer', 'min:0', 'max:20'],
            'period_months' => ['required', 'integer', 'in:6,12,24'],
            'branch_id'     => ['nullable', 'integer', 'exists:branches,id'],
            'range_percent' => ['nullable', 'numeric', 'min:0.01', 'max:0.30'],
            'steps'         => ['nullable', 'integer', 'min:3', 'max:21'],
        ]);

        $isAdmin           = auth()->user()->isEffectiveAdmin();
        $effectiveBranchId = $isAdmin
            ? (isset($validated['branch_id']) ? (int) $validated['branch_id'] : null)
            : (int) auth()->user()->effectiveBranchId();

        $baseInputs = [
            'suburb'        => $validated['suburb'],
            'type'          => $validated['type'],
            'size_m2'       => isset($validated['size_m2']) ? (int) $validated['size_m2'] : null,
            'bedrooms'      => isset($validated['bedrooms']) ? (int) $validated['bedrooms'] : null,
            'period_months' => (int) $validated['period_months'],
            'branch_id'     => $effectiveBranchId,
        ];

        $result = (new PriceBandService())->findOptimalBand(
            presentation: $presentation,
            currentPrice: (float) $validated['price'],
            baseInputs:   $baseInputs,
            rangePercent:  (float) ($validated['range_percent'] ?? 0.08),
            steps:         (int) ($validated['steps'] ?? 9),
        );

        return response()->json($result);
    }

    /**
     * Competitive threat ranking (C3).
     * Gated by features.competitive_threat_v1.
     * No DB writes.
     */
    public function competitiveThreats(Request $request, Presentation $presentation)
    {
        abort_unless(config('features.competitive_threat_v1'), 404);

        $validated = $request->validate([
            'price'   => ['nullable', 'numeric', 'min:0'],
            'size_m2' => ['nullable', 'integer', 'min:0'],
            'limit'   => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $result = (new \App\Services\MarketAnalytics\CompetitiveThreatService())->rankThreats(
            presentation: $presentation,
            subjectPrice: isset($validated['price']) ? (float) $validated['price'] : null,
            subjectSizeM2: isset($validated['size_m2']) ? (int) $validated['size_m2'] : null,
            limit:         (int) ($validated['limit'] ?? 5),
        );

        return response()->json($result);
    }
}
