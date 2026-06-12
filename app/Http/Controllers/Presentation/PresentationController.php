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
use App\Services\Presentations\AnalysisDataService;
use App\Services\Presentations\ConditionAdjustmentService;
use App\Services\Presentations\AiSummaryService;
use App\Models\PresentationVersion;
use App\Services\Presentations\PricingSimulatorService;
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
use App\Support\Presentation\LinkImportedFieldPresenter;
use App\Services\PermissionService;
use Illuminate\Http\Request;

class PresentationController extends Controller
{
    /**
     * Abort unless the authenticated user can access this presentation.
     */
    private function authorizePresentation(Presentation $presentation): void
    {
        $user = auth()->user();
        $scope = PermissionService::getDataScope($user, 'presentations');

        if ($scope === 'all') {
            return;
        }

        if ($scope === 'branch' && (int) $presentation->branch_id === (int) $user->effectiveBranchId()) {
            return;
        }

        if ($scope === 'own' && (int) $presentation->created_by_user_id === (int) $user->id) {
            return;
        }

        abort(403);
    }

    /**
     * List all presentations for this user's branch context.
     */
    public function index(Request $request)
    {
        // Active/Archived filter (soft-delete toggle)
        $status = $request->input('status', 'active');
        $showArchived = $status === 'archived';

        if ($showArchived) {
            $query = Presentation::onlyTrashed()->visibleTo(auth()->user())->with(['snapshots', 'creator']);
        } else {
            $query = Presentation::visibleTo(auth()->user())->with(['snapshots', 'creator']);
        }

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('property_address', 'like', "%{$search}%")
                  ->orWhere('seller_name', 'like', "%{$search}%")
                  ->orWhere('suburb', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%");
            });
        }

        // Filters
        if ($recordStatus = $request->input('record_status')) {
            $query->where('status', $recordStatus);
        }
        if ($propertyType = $request->input('property_type')) {
            $query->where('property_type', $propertyType);
        }
        if ($agent = $request->input('agent')) {
            $query->where('created_by_user_id', $agent);
        }

        // Sort
        $sortField = $request->input('sort', 'created_at');
        $sortDir = $request->input('direction', 'desc');
        $allowed = ['created_at', 'property_address', 'status', 'updated_at', 'suburb', 'seller_name'];
        if (!in_array($sortField, $allowed)) {
            $sortField = 'created_at';
        }
        $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');

        $presentations = $query->paginate(20)->withQueryString();

        // Data for filter dropdowns
        $statuses = Presentation::visibleTo(auth()->user())->distinct()->pluck('status')->filter()->sort()->values();
        $propertyTypes = Presentation::visibleTo(auth()->user())->whereNotNull('property_type')->distinct()->pluck('property_type')->sort()->values();
        $agents = collect();
        $indexScope = PermissionService::getDataScope(auth()->user(), 'presentations');
        if ($indexScope === 'all' || $indexScope === 'branch') {
            $agents = \App\Models\User::orderBy('name')
                ->whereIn('id', Presentation::visibleTo(auth()->user())->distinct()->pluck('created_by_user_id'))
                ->get(['id', 'name']);
        }

        return view('presentations.index', compact('presentations', 'statuses', 'propertyTypes', 'agents', 'status', 'showArchived'));
    }

    /**
     * Show the create presentation form.
     * Admins see a branch selector; branch-bound users do not.
     */
    public function create()
    {
        $scope = PermissionService::getDataScope(auth()->user(), 'presentations');
        $isAdmin = $scope === 'all';
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
        $scope = PermissionService::getDataScope(auth()->user(), 'presentations');
        $isAdmin = $scope === 'all';

        $rules = [
            'title'            => ['required', 'string', 'max:255'],
            'property_address' => ['required', 'string', 'max:500'],
            'suburb'           => ['required', 'string', 'max:100'],
            'property_type'    => ['required', 'string', 'in:house,townhouse,apartment,duplex,vacant_land,farm,other'],
            'bedrooms'         => ['required', 'integer', 'min:0', 'max:20'],
            'bathrooms'        => ['nullable', 'integer', 'min:0', 'max:20'],
            'asking_price_inc' => ['required', 'integer', 'min:0'],
            'erf_size_m2'      => ['nullable', 'integer', 'min:0'],
            'floor_area_m2'    => ['nullable', 'integer', 'min:0'],
            'garages_parking'  => ['nullable', 'integer', 'min:0', 'max:10'],
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
            'bedrooms'           => $validated['bedrooms'],
            'bathrooms'          => $validated['bathrooms'] ?? null,
            'asking_price_inc'   => $validated['asking_price_inc'],
            'erf_size_m2'        => $validated['erf_size_m2'] ?? null,
            'floor_area_m2'      => $validated['floor_area_m2'] ?? null,
            'garages_parking'    => $validated['garages_parking'] ?? null,
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
     * Show form to edit presentation property details.
     */
    public function edit(Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        $scope = PermissionService::getDataScope(auth()->user(), 'presentations');
        $isAdmin = $scope === 'all';
        $branches = $isAdmin ? Branch::orderBy('name')->get() : collect();

        return view('presentations.edit', compact('presentation', 'branches', 'isAdmin'));
    }

    /**
     * Update presentation property details.
     */
    public function update(Request $request, Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        $scope = PermissionService::getDataScope(auth()->user(), 'presentations');
        $isAdmin = $scope === 'all';

        $rules = [
            'title'            => ['required', 'string', 'max:255'],
            'property_address' => ['required', 'string', 'max:500'],
            'suburb'           => ['required', 'string', 'max:100'],
            'property_type'    => ['required', 'string', 'in:house,townhouse,apartment,duplex,vacant_land,farm,other'],
            'bedrooms'         => ['required', 'integer', 'min:0', 'max:20'],
            'bathrooms'        => ['nullable', 'integer', 'min:0', 'max:20'],
            'asking_price_inc' => ['required', 'integer', 'min:0'],
            'erf_size_m2'      => ['nullable', 'integer', 'min:0'],
            'floor_area_m2'    => ['nullable', 'integer', 'min:0'],
            'garages_parking'  => ['nullable', 'integer', 'min:0', 'max:10'],
            'seller_name'      => ['nullable', 'string', 'max:255'],
        ];

        if ($isAdmin) {
            $rules['branch_id'] = ['required', 'integer', 'exists:branches,id'];
        }

        $validated = $request->validate($rules);

        $updateData = [
            'title'            => $validated['title'],
            'property_address' => $validated['property_address'],
            'suburb'           => $validated['suburb'],
            'property_type'    => $validated['property_type'],
            'bedrooms'         => $validated['bedrooms'],
            'bathrooms'        => $validated['bathrooms'] ?? null,
            'asking_price_inc' => $validated['asking_price_inc'],
            'erf_size_m2'      => $validated['erf_size_m2'] ?? null,
            'floor_area_m2'    => $validated['floor_area_m2'] ?? null,
            'garages_parking'  => $validated['garages_parking'] ?? null,
            'seller_name'      => $validated['seller_name'] ?? null,
        ];

        if ($isAdmin && isset($validated['branch_id'])) {
            $updateData['branch_id'] = (int) $validated['branch_id'];
        }

        $presentation->update($updateData);

        $message = 'Details updated.';
        if ($presentation->wasChanged(['suburb', 'asking_price_inc', 'bedrooms', 'bathrooms', 'property_type', 'erf_size_m2', 'floor_area_m2'])) {
            $message .= ' Run Analysis again to refresh calculations.';
        }

        return redirect()->route('presentations.show', $presentation)
            ->with('success', $message);
    }

    /**
     * Show overview tab for a single presentation.
     */
    public function show(Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        $latestSnapshot = $presentation->snapshots()->latest()->first();
        $snapshotCount  = $presentation->snapshots()->count();
        $links          = $presentation->links()->orderBy('created_at')->get();
        // Pack Readiness section removed from the show view — auto-presentation
        // guarantees data capture, so the checklist contradicted itself. The
        // PresentationReadinessService is still used by analysis.blade.php +
        // by the (currently flagged-off) compile gate, so the class lives on.
        $latestVersion  = $presentation->versions()->latest('compiled_at')->first();

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

        // ── Link Imported Field Views (feature-flagged) ────────────────
        $linkViews = [];
        if (config('features.presentation_link_details_v1', true)) {
            $presenter = new LinkImportedFieldPresenter();
            foreach ($links as $link) {
                $linkViews[$link->id] = $presenter->build($link);
            }
        }

        $scope = PermissionService::getDataScope(auth()->user(), 'presentations');
        $isAdmin = $scope === 'all';

        // Polling cursor init values for live updates
        $maxCaptureId        = $presentation->portalCaptures()->max('id') ?? 0;
        $maxCaptureUpdatedAt = $presentation->portalCaptures()->max('updated_at') ?? '';
        $maxLinkUpdatedAt    = $links->max('updated_at')?->toIso8601String() ?? '';

        // ── Article suggestions (feature-flagged) ────────────────────────
        $addedArticles     = collect();
        $suggestedArticles = collect();
        if (config('features.article_suggestions_v1')) {
            $addedArticles     = $presentation->articles()->latest()->get();
            $suggestedArticles = (new \App\Services\Articles\ArticleMatcherService())->suggest($presentation);
        }

        // ── Buyer demand intelligence (Module 13) ──────────────────────
        $buyerDemand = null;
        if ($presentation->listing_id) {
            $agencyId = $presentation->agency_id ?? (auth()->user()->effectiveAgencyId() ?? 1);
            $buyerDemand = app(\App\Services\PropertyMatchScoringService::class)
                ->getBuyerDemandForProperty($presentation->listing_id, $agencyId);
        }

        return view('presentations.show', compact(
            'presentation', 'latestSnapshot', 'snapshotCount', 'links', 'powerPanel',
            'linkViews', 'isAdmin', 'latestVersion',
            'maxCaptureId', 'maxCaptureUpdatedAt', 'maxLinkUpdatedAt',
            'addedArticles', 'suggestedArticles', 'buyerDemand'
        ));
    }

    /**
     * Show the analysis input form.
     * Pre-populates from the most recent snapshot inputs if one exists,
     * otherwise falls back to the presentation's own stored fields (Prompt A).
     */
    public function analysis(Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        $latestSnapshot = $presentation->snapshots()->latest()->first();

        // Readiness gate — redirect to intake if evidence is insufficient
        // BUT allow access if a snapshot already exists (agent can view previous analysis)
        if (!$latestSnapshot && !$presentation->isAnalysisReady()) {
            $readiness = (new PresentationReadinessService())->evaluate($presentation);
            $missing   = implode(', ', array_column($readiness['missing_required'], 'label'));
            return redirect()->route('presentations.show', $presentation)
                ->with('error', 'Add the following before running analysis: ' . $missing);
        }

        // AT-27 C1a — the property is the single source of truth. If it was
        // edited since these comps were hydrated, re-hydrate + reset curation
        // and loop the agent to Review to re-curate (the notice shows there).
        if (app(\App\Services\Presentations\PresentationCompFreshnessService::class)->refreshIfStale($presentation)) {
            $draft = $presentation->versions()->latest('compiled_at')->first();
            if ($draft) {
                return redirect()->route('presentations.review.show', $draft->id);
            }
        }

        // Compile extracted-data review (all computation in service, not Blade)
        // AnalysisDataService reads asking_price directly from the presentation record
        $analysisData = (new AnalysisDataService())->compile($presentation);

        $latestVersion = $presentation->versions()->latest('compiled_at')->first();
        $readiness     = (new PresentationReadinessService())->evaluate($presentation);

        // AT-27 Phase B.3 — the "What's in your presentation" section toggles
        // live HERE now (inclusion decisions belong where the numbers are
        // visible). Same data the review screen used; persisted per version via
        // presentations.review.sections. Null version → empty (toggles render
        // only once a draft version exists).
        $sectionsCatalogue = PresentationVersion::SECTIONS_CATALOGUE;
        $sectionFloor      = PresentationVersion::SECTION_FLOOR;
        $sectionDeps       = PresentationVersion::SECTION_DEPENDENCIES;
        $sectionSnapshot   = [];
        if ($latestVersion) {
            foreach ($sectionsCatalogue as $sKey => $_label) {
                $sectionSnapshot[$sKey] = $latestVersion->isSectionEnabled($sKey);
            }
        }

        return view('presentations.analysis', compact(
            'presentation', 'analysisData', 'latestSnapshot', 'latestVersion', 'readiness',
            'sectionsCatalogue', 'sectionFloor', 'sectionDeps', 'sectionSnapshot'
        ));
    }

    /**
     * Run Analysis: save asking price, compile analysis data, freeze snapshot, redirect back.
     */
    public function runAnalysis(Request $request, Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        $validated = $request->validate([
            'asking_price_inc' => ['nullable', 'integer', 'min:0'],
        ]);

        // Save asking price + reset exclusions (row indices may shift on re-run)
        $presentation->update([
            'asking_price_inc'              => $validated['asking_price_inc'] ?? null,
            'excluded_active_listing_indices' => null,
        ]);
        $presentation->refresh();

        // Compile analysis data from all extracted sources
        $analysisData = (new AnalysisDataService())->compile($presentation);

        // Save snapshot with computed_json + timestamp
        PresentationSnapshot::create([
            'presentation_id'      => $presentation->id,
            'generated_by_user_id' => auth()->id(),
            'created_by_user_id'   => auth()->id(),
            'computed_json'        => json_encode($analysisData, JSON_THROW_ON_ERROR),
            'snapshot_json'        => '{}',
            'generated_at'         => now(),
        ]);

        // AT-27 Phase B — stay on the Analysis working surface after a re-run
        // (the agent keeps editing here; Confirm & Generate is the forward step).
        return redirect()->route('presentations.analysis', $presentation)
            ->with('success', 'Analysis recomputed — review the numbers, then Confirm & Generate.');
    }

    /**
     * AT-27 Phase B — "Confirm & Generate" on the Analysis surface.
     *
     * The single point where the presentation is finalised. After the agent has
     * worked the numbers on Analysis, this:
     *   1. recompiles the report from the confirmed presentation state,
     *   2. takes the ONE snapshot freeze (resolved condition + full payload)
     *      onto the current draft version — the freeze that used to live in the
     *      review "publish" path (now retired), and
     *   3. generates the Executive Summary from those CONFIRMED numbers (never
     *      provisional), then publishes the version and sends the agent to the
     *      Overview/dispatch screen.
     */
    public function confirmAndGenerate(Request $request, Presentation $presentation)
    {
        $this->authorizePresentation($presentation);

        $version = $presentation->versions()->latest('compiled_at')->first();
        if (!$version) {
            return redirect()->route('presentations.analysis', $presentation)
                ->with('error', 'Nothing to confirm yet — generate the presentation first.');
        }

        // 1 + 2. Single freeze (moved from the retired review publish path):
        // snapshot the resolved condition, then freeze the full compiled payload
        // so the seller/Overview always sees the confirmed numbers even if the
        // underlying property / comps / suburb stats shift later.
        $resolver = app(ConditionAdjustmentService::class);
        $resolved = $resolver->resolveLive($version, $presentation);
        $resolver->snapshotOnVersion($version, $resolved['level']);

        $presentation->load(['property', 'fields', 'soldComps', 'activeListings']);
        $payload = (new AnalysisDataService())->compile($presentation, $version);

        $version->forceFill([
            'review_status'     => PresentationVersion::REVIEW_PUBLISHED,
            'published_at'      => $version->published_at ?? now(),
            'snapshot_payload'  => $payload,
            'snapshot_taken_at' => now(),
        ])->save();

        // 3. Executive Summary — generated NOW, from the confirmed/frozen data.
        // generateDefaultAndAccept NEVER throws; an AI failure leaves
        // ai_summary_text null and the PDF gate blocks until the agent
        // regenerates from the Exec Summary panel.
        if ($user = $request->user()) {
            (new AiSummaryService())->generateDefaultAndAccept($presentation, $version, $user);
        }

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Presentation confirmed — your executive summary was generated from the confirmed numbers.');
    }

    /**
     * AT-27 — re-open a CONFIRMED (published) version back to a mutable draft.
     *
     * The confirm model is "confirmed means final", so editing is locked once a
     * version is published. This is the explicit "edit after confirm" path: it
     * un-freezes the version (review_status → in_analysis) so the agent can
     * edit holding costs / comps / sections again, WITHOUT discarding the frozen
     * snapshot_payload — the seller keeps seeing the prior confirmed snapshot
     * until the next Confirm & Generate overwrites it. No silent locks: every
     * locked surface points the agent here.
     */
    public function reopenForEditing(Request $request, Presentation $presentation)
    {
        $this->authorizePresentation($presentation);

        $version = $presentation->versions()->latest('compiled_at')->first();
        if ($version && $version->review_status === PresentationVersion::REVIEW_PUBLISHED) {
            // Keep snapshot_payload / published_at — re-confirm overwrites them.
            $version->forceFill(['review_status' => PresentationVersion::REVIEW_IN_ANALYSIS])->save();
        }

        return redirect()->route('presentations.analysis', $presentation)
            ->with('success', 'Re-opened for editing — make your changes, then Confirm & Generate to re-freeze the snapshot.');
    }

    // AT-27 C1a (option 1) — subject editing REMOVED. The property is the single
    // source of truth; a presentation never mutates it. Wrong subject details are
    // corrected on the property edit screen; PresentationCompFreshnessService then
    // re-hydrates + resets curation on the agent's return (see analysis() above /
    // PresentationReviewController::show).

    /**
     * AJAX: save analysis selection changes (CMA range, vicinity range, excluded listings).
     */
    public function updateAnalysisSelections(Request $request, Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        $validated = $request->validate([
            'cma_selected_range'             => ['sometimes', 'string', 'in:lower,middle,upper'],
            'vicinity_selected_range'        => ['sometimes', 'string', 'in:lower,middle,upper'],
            'excluded_active_listing_indices' => ['sometimes', 'nullable', 'array'],
            'excluded_active_listing_indices.*' => ['integer', 'min:0'],
        ]);

        $updates = [];
        if ($request->has('cma_selected_range')) {
            $updates['cma_selected_range'] = $validated['cma_selected_range'];
        }
        if ($request->has('vicinity_selected_range')) {
            $updates['vicinity_selected_range'] = $validated['vicinity_selected_range'];
        }
        if ($request->has('excluded_active_listing_indices')) {
            $updates['excluded_active_listing_indices'] = $validated['excluded_active_listing_indices'];
        }

        if (!empty($updates)) {
            $presentation->update($updates);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Store a link (Property24, Lightstone, etc.) on a presentation.
     */
    public function storeLink(Request $request, Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
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

        // Attempt extraction — non-blocking. Link stays 'pending' if fetch fails.
        try {
            (new LinkExtractionService())->run($link);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Link extraction failed for link #' . $link->id . ': ' . $e->getMessage());
        }

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

        // Return JSON for AJAX requests, redirect otherwise
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'link'    => [
                    'id'                => $link->id,
                    'type'              => $link->type,
                    'url'               => $link->url,
                    'notes'             => $link->notes,
                    'extraction_status' => $link->extraction_status ?? 'pending',
                    'portal_capture_id' => $link->portal_capture_id,
                    'extracted_at'      => $link->extracted_at?->toIso8601String(),
                ],
            ]);
        }

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Link added.');
    }

    /**
     * Delete a link from a presentation.
     */
    public function destroyLink(Presentation $presentation, \App\Models\PresentationLink $link)
    {
        $this->authorizePresentation($presentation);
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
        $this->authorizePresentation($presentation);
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
        $this->authorizePresentation($presentation);
        $validated = $request->validate([
            'asking_price_inc'         => ['nullable', 'integer', 'min:0'],
            'monthly_bond'             => ['nullable', 'numeric', 'min:0'],
            'monthly_rates'            => ['nullable', 'numeric', 'min:0'],
            'monthly_levies'           => ['nullable', 'numeric', 'min:0'],
            'monthly_insurance'        => ['nullable', 'numeric', 'min:0'],
            'monthly_utilities'        => ['nullable', 'numeric', 'min:0'],
            'monthly_opportunity_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $updates = [];

        // Asking price (whole rands, bigint)
        if ($request->has('asking_price_inc')) {
            $updates['asking_price_inc'] = isset($validated['asking_price_inc']) ? (int) $validated['asking_price_inc'] : null;
        }

        // Holding cost fields (floats)
        foreach (['monthly_bond', 'monthly_rates', 'monthly_levies', 'monthly_insurance', 'monthly_utilities', 'monthly_opportunity_cost'] as $field) {
            if ($request->has($field)) {
                $updates[$field] = isset($validated[$field]) ? (float) $validated[$field] : null;
            }
        }

        if (!empty($updates)) {
            $presentation->update($updates);
        }

        $message = $request->has('asking_price_inc') && !$request->has('monthly_bond')
            ? 'Asking price saved.'
            : 'Holding cost inputs saved.';

        return redirect()->route('presentations.show', $presentation)
            ->with('success', $message);
    }

    /**
     * Handle document upload(s) for a presentation.
     * Supports multiple files in one batch with a user-selected doc_type.
     * Stores file, extracts text, and detects structured fields.
     * Never touches finance logic.
     */
    public function upload(Request $request, Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        $request->validate([
            'doc_type'    => ['required', 'string', 'in:auto,suburb_stats,vicinity_sales,cma,market_article,other'],
            'documents'   => ['required', 'array', 'min:1'],
            'documents.*' => ['file', 'max:20480'], // 20 MB each
        ]);

        $docType   = $request->input('doc_type');
        // 'auto' means let the system detect — pass null to UploadProcessor
        $effectiveDocType = $docType === 'auto' ? null : $docType;
        $processor = new UploadProcessor(new \App\Domain\Presentation\TextExtractionService());
        $count     = 0;

        foreach ($request->file('documents') as $file) {
            $upload = $processor->process($file, $presentation, auth()->id(), $effectiveDocType);
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
        $this->authorizePresentation($presentation);
        abort_if($upload->presentation_id !== $presentation->id, 403);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:suburb_stats,vicinity_sales,cma,market_article,other'],
        ]);

        $upload->update(['type' => $validated['type']]);

        return redirect()->route('presentations.show', $presentation)
            ->with('success', 'Document type updated.');
    }

    /**
     * Delete an uploaded document and all related extracted data.
     * Removes: presentation_fields, presentation_sold_comps, presentation_active_listings
     * that originated from this upload (via source_upload_id).
     */
    public function destroyUpload(Presentation $presentation, PresentationUpload $upload)
    {
        $this->authorizePresentation($presentation);
        abort_if($upload->presentation_id !== $presentation->id, 403);

        $filename = $upload->original_filename ?? 'document';

        // Delete related extracted data (hard delete — derivative, re-extractable)
        \App\Models\PresentationField::where('source_upload_id', $upload->id)->delete();
        \App\Models\PresentationSoldComp::where('source_upload_id', $upload->id)->delete();
        \App\Models\PresentationActiveListing::where('source_upload_id', $upload->id)->delete();

        // Soft delete — stored file preserved on disk for potential restore
        $upload->delete();

        return redirect()->route('presentations.show', $presentation)
            ->with('success', "Archived \"{$filename}\" and removed extracted data.");
    }

    /**
     * Save user override on an uploaded document's extracted data.
     * Stores override_json with audit fields (who/when).
     */
    public function saveUploadOverride(Request $request, Presentation $presentation, PresentationUpload $upload)
    {
        $this->authorizePresentation($presentation);
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
        $this->authorizePresentation($presentation);
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
        $this->authorizePresentation($presentation);
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
        $this->authorizePresentation($presentation);
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
        $this->authorizePresentation($presentation);
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
        $this->authorizePresentation($presentation);
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
        $this->authorizePresentation($presentation);
        // Readiness gate — redirect to intake if evidence is insufficient
        if (!$presentation->isAnalysisReady()) {
            $readiness = (new PresentationReadinessService())->evaluate($presentation);
            $missing   = implode(', ', array_column($readiness['missing_required'], 'label'));
            return redirect()->route('presentations.show', $presentation)
                ->with('error', 'Add the following before running analysis: ' . $missing);
        }

        // A1: resolve admin status once — used for branch enforcement and branch list
        $scope = PermissionService::getDataScope(auth()->user(), 'presentations');
        $isAdmin = $scope === 'all';

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
        $this->authorizePresentation($presentation);
        abort_unless(config("features.presentation_blueprint"), 404);

        // Readiness gate (P16) — blocks unless can_compile or admin force-overrides
        if (config('features.presentation_readiness_check', false)) {
            $readiness = (new PresentationReadinessService())->evaluate($presentation);
            if (!$readiness['can_compile']) {
                $compileScope = PermissionService::getDataScope(auth()->user(), 'presentations');
                $isAdmin   = $compileScope === 'all';
                $hasForce  = $request->boolean('force');
                if (!($isAdmin && $hasForce)) {
                    $missing = implode('; ', array_column($readiness['missing_required'], 'label'));
                    return redirect()->route('presentations.show', $presentation)
                        ->with('error', 'Cannot compile — missing required evidence: ' . $missing);
                }
            }
        }

        // PDF readiness gate (auto-summary build) — defence-in-depth.
        // The show-screen UI already disables this button when
        // ai_summary_text is null, so this fires only on a hand-crafted
        // POST. Same boolean as the UI gate (latestVersion + summary
        // present). When no version exists yet the auto-summary hook
        // hasn't run, which means the agent hasn't generated yet —
        // that's a different prerequisite ("Run Analysis first") that
        // the UI surfaces correctly.
        $latestForGate = $presentation->versions()->latest('compiled_at')->first();
        if (!$latestForGate || empty($latestForGate->ai_summary_text)) {
            return redirect()->route('presentations.show', $presentation)
                ->with('error', !$latestForGate
                    ? 'Cannot compile — run Analysis first to produce a compiled snapshot.'
                    : 'Cannot compile — generate the Executive Summary before compiling the pack.');
        }

        $version = (new PresentationCompilerService())->compile(
            $presentation->id,
            auth()->id(),
        );

        // Generate the PDF HTML file
        (new \App\Services\Presentations\PresentationPdfService())->generate($version);

        return redirect()->route("presentations.show", $presentation)
            ->with("success", "Pack compiled — Version #" . $version->id . ". Use the Download PDF button to view.");
    }

    /**
     * Store a URL snapshot and trigger ingestion if applicable.
     * Gated by p24_ingestion / private_property_ingestion / article_ingestion feature flags.
     */
    public function storeUrlSnapshot(Request $request, Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
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
        $this->authorizePresentation($presentation);
        $validated = $request->validate([
            'suburb'        => ['required', 'string', 'max:100'],
            'type'          => ['required', 'string', 'in:house,unit,land,other'],
            'price'         => ['nullable', 'numeric', 'min:0'],
            'size_m2'       => ['nullable', 'integer', 'min:0'],
            'bedrooms'      => ['nullable', 'integer', 'min:0', 'max:20'],
            'period_months' => ['required', 'integer', 'in:6,12,24'],
            'branch_id'     => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $scope             = PermissionService::getDataScope(auth()->user(), 'presentations');
        $isAdmin           = $scope === 'all';
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
     * Legacy Brain route → redirect to Pricing Simulator.
     */
    public function brain(Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        return redirect()->route('presentations.pricing-simulator', $presentation);
    }

    /**
     * Multi-step price trajectory simulation (C1).
     * Gated by features.trajectory_simulation_v1.
     * No DB writes.
     */
    public function simulateTrajectory(Request $request, Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
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

        $scope             = PermissionService::getDataScope(auth()->user(), 'presentations');
        $isAdmin           = $scope === 'all';
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
        $this->authorizePresentation($presentation);
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

        $scope             = PermissionService::getDataScope(auth()->user(), 'presentations');
        $isAdmin           = $scope === 'all';
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
        $this->authorizePresentation($presentation);
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

    // ══════════════════════════════════════════════════════════════════════
    // PRICING SIMULATOR
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Pricing Simulator page — deterministic scenario comparison.
     */
    public function pricingSimulator(Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        abort_unless(config('features.pricing_simulator_v1'), 404);

        $analysisService  = new AnalysisDataService();
        $simulatorService = new PricingSimulatorService();

        $analysisData    = $analysisService->compile($presentation);
        $defaultScenarios = $simulatorService->defaultScenarios($analysisData);

        // Load saved config if exists
        $savedConfig = $presentation->simulator_config_json;

        // Use saved scenarios if available, otherwise compute fresh defaults
        if ($savedConfig && !empty($savedConfig['scenarios'])) {
            $scenarios = $savedConfig['scenarios'];
            $config = $savedConfig['config'] ?? [
                'commission_pct'      => 7.5,
                'transfer_cost_pct'   => 4.0,
                'monthly_holding_cost' => $analysisData['holding_cost']['monthly_total'] ?? 0,
            ];
            $narrative = $savedConfig['narrative'] ?? '';
        } else {
            $config = [
                'commission_pct'      => 7.5,
                'transfer_cost_pct'   => 4.0,
                'monthly_holding_cost' => $analysisData['holding_cost']['monthly_total'] ?? 0,
            ];
            $scenarios = $simulatorService->compute($defaultScenarios, $config, $analysisData);
            $narrative = $simulatorService->generateNarrative($scenarios, $config, $analysisData);
        }

        $includeInPdf = $savedConfig['include_in_pdf'] ?? false;

        return view('presentations.pricing-simulator', compact(
            'presentation', 'analysisData', 'scenarios', 'config',
            'narrative', 'defaultScenarios', 'includeInPdf'
        ));
    }

    /**
     * Compute pricing simulator scenarios (AJAX).
     */
    public function computePricingSimulator(Request $request, Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        abort_unless(config('features.pricing_simulator_v1'), 404);

        $validated = $request->validate([
            'commission_pct'       => ['required', 'numeric', 'min:0', 'max:15'],
            'transfer_cost_pct'    => ['required', 'numeric', 'min:0', 'max:10'],
            'monthly_holding_cost' => ['required', 'integer', 'min:0'],
            'scenarios'            => ['required', 'array', 'min:1', 'max:8'],
            'scenarios.*.label'    => ['required', 'string', 'max:50'],
            'scenarios.*.price'    => ['required', 'integer', 'min:1'],
        ]);

        $analysisService  = new AnalysisDataService();
        $simulatorService = new PricingSimulatorService();

        $analysisData = $analysisService->compile($presentation);
        $config = [
            'commission_pct'      => $validated['commission_pct'],
            'transfer_cost_pct'   => $validated['transfer_cost_pct'],
            'monthly_holding_cost' => $validated['monthly_holding_cost'],
        ];

        $computed  = $simulatorService->compute($validated['scenarios'], $config, $analysisData);
        $narrative = $simulatorService->generateNarrative($computed, $config, $analysisData);

        return response()->json([
            'scenarios' => $computed,
            'narrative' => $narrative,
        ]);
    }

    /**
     * Save pricing simulator configuration + results (AJAX).
     */
    public function savePricingSimulator(Request $request, Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        abort_unless(config('features.pricing_simulator_v1'), 404);

        $validated = $request->validate([
            'config'         => ['required', 'array'],
            'scenarios'      => ['required', 'array'],
            'narrative'      => ['required', 'string'],
            'include_in_pdf' => ['required', 'boolean'],
        ]);

        $presentation->update([
            'simulator_config_json' => [
                'config'         => $validated['config'],
                'scenarios'      => $validated['scenarios'],
                'narrative'      => $validated['narrative'],
                'include_in_pdf' => $validated['include_in_pdf'],
                'computed_at'    => now()->toISOString(),
            ],
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Standalone presenter view for pricing simulator.
     */
    public function pricingSimulatorPresent(Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        abort_unless(config('features.pricing_simulator_v1'), 404);

        $savedConfig = $presentation->simulator_config_json;
        abort_unless($savedConfig && !empty($savedConfig['scenarios']), 404);

        $agent = \App\Models\User::find($presentation->created_by_user_id);

        // Compile stock absorption data for context display
        $analysisData = (new AnalysisDataService())->compile($presentation);

        return view('presentations.pricing-simulator-present', [
            'presentation' => $presentation,
            'config'       => $savedConfig['config'],
            'scenarios'    => $savedConfig['scenarios'],
            'narrative'    => $savedConfig['narrative'] ?? '',
            'agentName'    => $agent->name ?? 'Agent',
            'stock'        => $analysisData['stock_absorption'] ?? [],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // SELLER LIVE PROBABILITY SCREEN
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Seller Live — dark full-screen probability tool for listing appointments.
     */
    public function sellerLive(Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        $analysisService  = new AnalysisDataService();
        $simulatorService = new PricingSimulatorService();

        $analysisData = $analysisService->compile($presentation);

        // Config — same defaults as pricing simulator
        $savedConfig = $presentation->simulator_config_json;
        $config = $savedConfig['config'] ?? [
            'commission_pct'       => 7.5,
            'transfer_cost_pct'    => 4.0,
            'monthly_holding_cost' => $analysisData['holding_cost']['monthly_total'] ?? 0,
        ];

        // CMA values
        $cmaLower  = $analysisData['cma_valuation']['cma_lower'] ?? null;
        $cmaMiddle = $analysisData['cma_valuation']['cma_middle'] ?? null;
        $cmaUpper  = $analysisData['cma_valuation']['cma_upper'] ?? null;

        // Subject property
        $askingPrice = $analysisData['subject_property']['asking_price'] ?? 0;
        $address     = $presentation->property_address ?? $analysisData['subject_property']['address'] ?? 'Property';

        // Active competition listings (prices for competing count calc)
        $activeRows = $analysisData['active_competition']['rows'] ?? [];
        $listingPrices = [];
        foreach ($activeRows as $row) {
            if (!empty($row['is_excluded'])) continue;
            $lp = (int) ($row['list_price'] ?? 0);
            if ($lp > 0) $listingPrices[] = $lp;
        }

        // Monthly sales rate
        $annualSales = (int) ($analysisData['suburb_overview']['sales_count'] ?? 12);
        if ($annualSales < 1) $annualSales = 12;
        $monthlySalesRate = round($annualSales / 12, 2);

        // Pack all data for client-side JS (camelCase keys for JS consumption)
        $liveData = [
            'askingPrice'        => (int) $askingPrice,
            'cmaLower'           => $cmaLower ? (int) $cmaLower : null,
            'cmaMiddle'          => $cmaMiddle ? (int) $cmaMiddle : null,
            'cmaUpper'           => $cmaUpper ? (int) $cmaUpper : null,
            'commissionPct'      => (float) $config['commission_pct'],
            'transferCostPct'    => (float) $config['transfer_cost_pct'],
            'monthlyHoldingCost' => (float) $config['monthly_holding_cost'],
            'listingPrices'      => $listingPrices,
            'monthlySalesRate'   => $monthlySalesRate,
        ];

        return view('presentations.seller-live', [
            'presentation' => $presentation,
            'liveData'     => $liveData,
        ]);
    }

    /**
     * Capture the seller-live selected price point for pack inclusion.
     */
    public function captureSellerLive(Request $request, Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        $validated = $request->validate([
            'price'        => ['required', 'integer', 'min:0'],
            'probability'  => ['required', 'string'],
            'net_proceeds' => ['required', 'integer'],
        ]);

        $presentation->update([
            'seller_live_capture_json' => [
                'price'        => $validated['price'],
                'probability'  => $validated['probability'],
                'net_proceeds' => $validated['net_proceeds'],
                'captured_at'  => now()->toIso8601String(),
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Captured at R ' . number_format($validated['price'], 0, '.', ',') . ' — included in pack',
        ]);
    }

    // ── Restore soft-deleted ──

    public function restore($id)
    {
        abort_unless(auth()->user()->hasPermission('presentations.edit'), 403);
        $record = Presentation::onlyTrashed()->findOrFail($id);
        $record->restore();
        return redirect()->back()->with('success', 'Record restored.');
    }
}
