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
use App\Services\Presentations\Evidence\UploadExtractionService;
use App\Services\Presentations\PresentationNarrativeService;
use App\Services\Presentations\RecommendationService;
use App\Services\SaleProbability\DTOs\SaleProbabilityInput;
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

        // Go straight to analysis screen — no stop at overview (Prompt A)
        return redirect()->route('presentations.analysis', $presentation)
            ->with('success', 'Presentation created. Fill in the inputs below and run the analysis.');
    }

    /**
     * Show overview tab for a single presentation.
     */
    public function show(Presentation $presentation)
    {
        $latestSnapshot = $presentation->snapshots()->latest()->first();
        $snapshotCount  = $presentation->snapshots()->count();
        $links          = $presentation->links()->orderBy('created_at')->get();

        return view('presentations.show', compact('presentation', 'latestSnapshot', 'snapshotCount', 'links'));
    }

    /**
     * Show the analysis input form.
     * Pre-populates from the most recent snapshot inputs if one exists,
     * otherwise falls back to the presentation's own stored fields (Prompt A).
     */
    public function analysis(Presentation $presentation)
    {
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
            'type'             => ['required', 'string', 'in:property24,lightstone,other'],
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

        $presentation->links()->create([
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
     * Handle a document upload for a presentation.
     * Stores file, extracts text, and detects structured fields.
     * Never touches finance logic.
     */
    public function upload(Request $request, Presentation $presentation)
    {
        $request->validate([
            'document' => ['required', 'file', 'max:20480'], // 20 MB
        ]);

        $processor = new UploadProcessor(new \App\Domain\Presentation\TextExtractionService());
        $upload    = $processor->process($request->file('document'), $presentation, auth()->id());

        (new UploadExtractionService())->run($upload);

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'File uploaded and processed.');
    }

    /**
     * Run market analytics + sale probability for a presentation.
     * Validates inputs, calls both services, returns analysis view with results.
     */
    public function compute(Request $request, Presentation $presentation)
    {
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
}
