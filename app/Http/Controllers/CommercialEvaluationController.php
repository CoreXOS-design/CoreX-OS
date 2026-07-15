<?php

namespace App\Http\Controllers;

use App\Models\CommercialEvaluation;
use App\Models\CommercialEvaluationFinancial;
use App\Models\CommercialEvaluationComparable;
use App\Models\CommercialEvaluationAsset;
use App\Models\CommercialEvaluationUnit;
use App\Models\CommercialEvaluationCrop;
use App\Models\CommercialEvaluationLivestock;
use App\Models\Branch;
use App\Services\CommercialEvaluation\CommercialEvaluationService;
use App\Services\CommercialEvaluation\CommercialEvaluationPdfService;
use App\Services\PermissionService;
use Illuminate\Http\Request;

class CommercialEvaluationController extends Controller
{
    private function authorizeEvaluation(CommercialEvaluation $evaluation): void
    {
        $user = auth()->user();
        $scope = PermissionService::getDataScope($user, 'commercial_evals');
        if ($scope === 'all') return;
        if ($scope === 'branch' && (int) $evaluation->branch_id === (int) $user->effectiveBranchId()) return;
        if ($scope === 'own' && (int) $evaluation->created_by_user_id === (int) $user->id) return;
        abort(403);
    }

    // ── Index ──

    public function index(Request $request)
    {
        $status = $request->input('status', 'active');
        $showArchived = $status === 'archived';

        if ($showArchived) {
            $query = CommercialEvaluation::onlyTrashed()->visibleTo(auth()->user());
        } else {
            $query = CommercialEvaluation::visibleTo(auth()->user());
        }

        $evaluations = $query->with(['creator'])
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('commercial-evaluations.index', compact('evaluations', 'status', 'showArchived'));
    }

    // ── Create ──

    public function create()
    {
        $scope = PermissionService::getDataScope(auth()->user(), 'commercial_evals'); $isAdmin = $scope === 'all';
        $branches = $isAdmin ? Branch::orderBy('name')->get() : collect();

        return view('commercial-evaluations.create', compact('branches', 'isAdmin'));
    }

    // ── Store ──

    public function store(Request $request)
    {
        $scope = PermissionService::getDataScope(auth()->user(), 'commercial_evals'); $isAdmin = $scope === 'all';

        $rules = [
            'property_type'         => ['required', 'in:commercial,industrial,hospitality,agricultural'],
            'property_name'         => ['required', 'string', 'max:255'],
            'address'               => ['nullable', 'string'],
            'suburb'                => ['nullable', 'string', 'max:255'],
            'town'                  => ['nullable', 'string', 'max:255'],
            'province'              => ['nullable', 'string', 'max:255'],
            'erf_number'            => ['nullable', 'string', 'max:255'],
            'zoning'                => ['nullable', 'string', 'max:255'],
            'total_land_size_m2'    => ['nullable', 'numeric', 'min:0'],
            'total_land_size_ha'    => ['nullable', 'numeric', 'min:0'],
            'total_building_size_m2'=> ['nullable', 'numeric', 'min:0'],
            'year_built'            => ['nullable', 'integer', 'min:1800', 'max:2100'],
            'condition'             => ['nullable', 'in:excellent,good,fair,poor'],
            'asking_price'          => ['nullable', 'numeric', 'min:0'],
            'municipal_evaluation'  => ['nullable', 'numeric', 'min:0'],
            'seller_name'           => ['nullable', 'string', 'max:255'],
            'notes'                 => ['nullable', 'string'],
        ];

        if ($isAdmin) {
            $rules['branch_id'] = ['nullable', 'integer', 'exists:branches,id'];
        }

        $validated = $request->validate($rules);

        // Convert Rand to cents
        if (isset($validated['asking_price'])) {
            $validated['asking_price'] = (int) round($validated['asking_price'] * 100);
        }
        if (isset($validated['municipal_evaluation'])) {
            $validated['municipal_evaluation'] = (int) round($validated['municipal_evaluation'] * 100);
        }

        $evaluation = CommercialEvaluation::create(array_merge($validated, [
            'created_by_user_id' => auth()->id(),
            'branch_id'          => $isAdmin ? ($validated['branch_id'] ?? null) : auth()->user()?->effectiveBranchId(),
            'status'             => 'draft',
        ]));

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Commercial evaluation created.');
    }

    // ── Show ──

    public function show(CommercialEvaluation $evaluation)
    {
        $this->authorizeEvaluation($evaluation);
        $evaluation->load(['financials', 'comparables', 'assets', 'units', 'crops', 'livestock', 'creator']);

        $cropConfig = config('agricultural_crops.crops', []);
        $livestockConfig = config('agricultural_crops.livestock', []);

        return view('commercial-evaluations.show', compact('evaluation', 'cropConfig', 'livestockConfig'));
    }

    // ── Edit ──

    public function edit(CommercialEvaluation $evaluation)
    {
        $this->authorizeEvaluation($evaluation);
        $scope = PermissionService::getDataScope(auth()->user(), 'commercial_evals'); $isAdmin = $scope === 'all';
        $branches = $isAdmin ? Branch::orderBy('name')->get() : collect();

        return view('commercial-evaluations.edit', compact('evaluation', 'branches', 'isAdmin'));
    }

    // ── Update ──

    public function update(Request $request, CommercialEvaluation $evaluation)
    {
        $this->authorizeEvaluation($evaluation);
        $scope = PermissionService::getDataScope(auth()->user(), 'commercial_evals'); $isAdmin = $scope === 'all';

        $rules = [
            'property_type'         => ['required', 'in:commercial,industrial,hospitality,agricultural'],
            'property_name'         => ['required', 'string', 'max:255'],
            'address'               => ['nullable', 'string'],
            'suburb'                => ['nullable', 'string', 'max:255'],
            'town'                  => ['nullable', 'string', 'max:255'],
            'province'              => ['nullable', 'string', 'max:255'],
            'erf_number'            => ['nullable', 'string', 'max:255'],
            'zoning'                => ['nullable', 'string', 'max:255'],
            'total_land_size_m2'    => ['nullable', 'numeric', 'min:0'],
            'total_land_size_ha'    => ['nullable', 'numeric', 'min:0'],
            'total_building_size_m2'=> ['nullable', 'numeric', 'min:0'],
            'year_built'            => ['nullable', 'integer', 'min:1800', 'max:2100'],
            'condition'             => ['nullable', 'in:excellent,good,fair,poor'],
            'asking_price'          => ['nullable', 'numeric', 'min:0'],
            'municipal_evaluation'  => ['nullable', 'numeric', 'min:0'],
            'seller_name'           => ['nullable', 'string', 'max:255'],
            'notes'                 => ['nullable', 'string'],
        ];

        if ($isAdmin) {
            $rules['branch_id'] = ['nullable', 'integer', 'exists:branches,id'];
        }

        $validated = $request->validate($rules);

        // Convert Rand to cents
        if (isset($validated['asking_price'])) {
            $validated['asking_price'] = (int) round($validated['asking_price'] * 100);
        }
        if (isset($validated['municipal_evaluation'])) {
            $validated['municipal_evaluation'] = (int) round($validated['municipal_evaluation'] * 100);
        }

        if ($isAdmin && isset($validated['branch_id'])) {
            $evaluation->branch_id = $validated['branch_id'];
        }

        $evaluation->update($validated);

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Evaluation details updated.');
    }

    // ── Destroy ──

    public function destroy(CommercialEvaluation $evaluation)
    {
        $this->authorizeEvaluation($evaluation);
        $evaluation->delete();

        return redirect()->route('commercial-evaluations.index')
            ->with('success', 'Evaluation archived.');
    }

    // ══════════════════════════════════════════
    //  Financial Data
    // ══════════════════════════════════════════

    public function storeFinancials(Request $request, CommercialEvaluation $evaluation)
    {
        $this->authorizeEvaluation($evaluation);
        $validated = $request->validate([
            'financial_year'        => ['required', 'string', 'max:20'],
            'period_months'         => ['nullable', 'integer', 'min:1', 'max:24'],
            'gross_revenue'         => ['nullable', 'numeric', 'min:0'],
            'rental_income'         => ['nullable', 'numeric', 'min:0'],
            'room_revenue'          => ['nullable', 'numeric', 'min:0'],
            'food_beverage_revenue' => ['nullable', 'numeric', 'min:0'],
            'other_income'          => ['nullable', 'numeric', 'min:0'],
            'vacancy_rate'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rates_taxes'           => ['nullable', 'numeric', 'min:0'],
            'insurance'             => ['nullable', 'numeric', 'min:0'],
            'utilities'             => ['nullable', 'numeric', 'min:0'],
            'maintenance'           => ['nullable', 'numeric', 'min:0'],
            'management_fees'       => ['nullable', 'numeric', 'min:0'],
            'salaries_wages'        => ['nullable', 'numeric', 'min:0'],
            'security'              => ['nullable', 'numeric', 'min:0'],
            'marketing'             => ['nullable', 'numeric', 'min:0'],
            'food_beverage_cost'    => ['nullable', 'numeric', 'min:0'],
            'farm_operating_costs'  => ['nullable', 'numeric', 'min:0'],
            'other_expenses'        => ['nullable', 'numeric', 'min:0'],
        ]);

        // Convert Rand fields to cents
        $centFields = [
            'gross_revenue', 'rental_income', 'room_revenue', 'food_beverage_revenue',
            'other_income', 'rates_taxes', 'insurance', 'utilities', 'maintenance',
            'management_fees', 'salaries_wages', 'security', 'marketing',
            'food_beverage_cost', 'farm_operating_costs', 'other_expenses',
        ];

        foreach ($centFields as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = (int) round($validated[$field] * 100);
            }
        }

        // Compute totals
        $totalIncome = ($validated['gross_revenue'] ?? 0)
            + ($validated['rental_income'] ?? 0)
            + ($validated['room_revenue'] ?? 0)
            + ($validated['food_beverage_revenue'] ?? 0)
            + ($validated['other_income'] ?? 0);

        $totalExpenses = ($validated['rates_taxes'] ?? 0)
            + ($validated['insurance'] ?? 0)
            + ($validated['utilities'] ?? 0)
            + ($validated['maintenance'] ?? 0)
            + ($validated['management_fees'] ?? 0)
            + ($validated['salaries_wages'] ?? 0)
            + ($validated['security'] ?? 0)
            + ($validated['marketing'] ?? 0)
            + ($validated['food_beverage_cost'] ?? 0)
            + ($validated['farm_operating_costs'] ?? 0)
            + ($validated['other_expenses'] ?? 0);

        $validated['total_expenses'] = $totalExpenses;
        $validated['net_operating_income'] = $totalIncome - $totalExpenses;
        $validated['ebitda'] = $validated['net_operating_income']; // Simplified — same as NOI for now

        $evaluation->financials()->create($validated);

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Financial data added.');
    }

    public function updateFinancials(Request $request, CommercialEvaluation $evaluation, CommercialEvaluationFinancial $financial)
    {
        $this->authorizeEvaluation($evaluation);
        // The financial row must belong to this evaluation — never let a mismatched
        // URL edit another evaluation's captured financials.
        abort_unless((int) $financial->commercial_evaluation_id === (int) $evaluation->id, 404);
        $before = $financial->only($financial->getFillable());
        $validated = $request->validate([
            'financial_year'        => ['required', 'string', 'max:20'],
            'period_months'         => ['nullable', 'integer', 'min:1', 'max:24'],
            'gross_revenue'         => ['nullable', 'numeric', 'min:0'],
            'rental_income'         => ['nullable', 'numeric', 'min:0'],
            'room_revenue'          => ['nullable', 'numeric', 'min:0'],
            'food_beverage_revenue' => ['nullable', 'numeric', 'min:0'],
            'other_income'          => ['nullable', 'numeric', 'min:0'],
            'vacancy_rate'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rates_taxes'           => ['nullable', 'numeric', 'min:0'],
            'insurance'             => ['nullable', 'numeric', 'min:0'],
            'utilities'             => ['nullable', 'numeric', 'min:0'],
            'maintenance'           => ['nullable', 'numeric', 'min:0'],
            'management_fees'       => ['nullable', 'numeric', 'min:0'],
            'salaries_wages'        => ['nullable', 'numeric', 'min:0'],
            'security'              => ['nullable', 'numeric', 'min:0'],
            'marketing'             => ['nullable', 'numeric', 'min:0'],
            'food_beverage_cost'    => ['nullable', 'numeric', 'min:0'],
            'farm_operating_costs'  => ['nullable', 'numeric', 'min:0'],
            'other_expenses'        => ['nullable', 'numeric', 'min:0'],
        ]);

        $centFields = [
            'gross_revenue', 'rental_income', 'room_revenue', 'food_beverage_revenue',
            'other_income', 'rates_taxes', 'insurance', 'utilities', 'maintenance',
            'management_fees', 'salaries_wages', 'security', 'marketing',
            'food_beverage_cost', 'farm_operating_costs', 'other_expenses',
        ];

        foreach ($centFields as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = (int) round($validated[$field] * 100);
            }
        }

        $totalIncome = ($validated['gross_revenue'] ?? 0)
            + ($validated['rental_income'] ?? 0)
            + ($validated['room_revenue'] ?? 0)
            + ($validated['food_beverage_revenue'] ?? 0)
            + ($validated['other_income'] ?? 0);

        $totalExpenses = ($validated['rates_taxes'] ?? 0)
            + ($validated['insurance'] ?? 0)
            + ($validated['utilities'] ?? 0)
            + ($validated['maintenance'] ?? 0)
            + ($validated['management_fees'] ?? 0)
            + ($validated['salaries_wages'] ?? 0)
            + ($validated['security'] ?? 0)
            + ($validated['marketing'] ?? 0)
            + ($validated['food_beverage_cost'] ?? 0)
            + ($validated['farm_operating_costs'] ?? 0)
            + ($validated['other_expenses'] ?? 0);

        $validated['total_expenses'] = $totalExpenses;
        $validated['net_operating_income'] = $totalIncome - $totalExpenses;
        $validated['ebitda'] = $validated['net_operating_income'];

        $financial->update($validated);

        // Audit trail — who edited which financial-year row, and the before→after of
        // every field that actually changed.
        \Illuminate\Support\Facades\Log::info('commercial-evaluation financial edited', [
            'evaluation_id'  => $evaluation->id,
            'financial_id'   => $financial->id,
            'financial_year' => $financial->financial_year,
            'user_id'        => auth()->id(),
            'agency_id'      => $financial->agency_id,
            'changed'        => collect($financial->only($financial->getFillable()))
                ->filter(fn ($v, $k) => ($before[$k] ?? null) !== $v)
                ->map(fn ($v, $k) => ['from' => $before[$k] ?? null, 'to' => $v])
                ->all(),
        ]);

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Financial data updated.');
    }

    // ══════════════════════════════════════════
    //  Comparables
    // ══════════════════════════════════════════

    public function storeComparable(Request $request, CommercialEvaluation $evaluation)
    {
        $this->authorizeEvaluation($evaluation);
        $validated = $request->validate([
            'address'       => ['required', 'string', 'max:255'],
            'suburb'        => ['nullable', 'string', 'max:255'],
            'property_type' => ['required', 'string', 'max:255'],
            'size_m2'       => ['nullable', 'numeric', 'min:0'],
            'size_ha'       => ['nullable', 'numeric', 'min:0'],
            'sale_price'    => ['nullable', 'numeric', 'min:0'],
            'sale_date'     => ['nullable', 'date'],
            'notes'         => ['nullable', 'string', 'max:500'],
            'source'        => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($validated['sale_price'])) {
            $validated['sale_price'] = (int) round($validated['sale_price'] * 100);
        }

        // Compute price per m2/ha
        if (($validated['sale_price'] ?? 0) > 0 && ($validated['size_m2'] ?? 0) > 0) {
            $validated['price_per_m2'] = (int) round($validated['sale_price'] / $validated['size_m2']);
        }
        if (($validated['sale_price'] ?? 0) > 0 && ($validated['size_ha'] ?? 0) > 0) {
            $validated['price_per_ha'] = (int) round($validated['sale_price'] / $validated['size_ha']);
        }

        $evaluation->comparables()->create($validated);

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Comparable sale added.');
    }

    public function destroyComparable(CommercialEvaluation $evaluation, CommercialEvaluationComparable $comparable)
    {
        $this->authorizeEvaluation($evaluation);
        $comparable->delete();

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Comparable removed.');
    }

    // ══════════════════════════════════════════
    //  Assets
    // ══════════════════════════════════════════

    public function storeAsset(Request $request, CommercialEvaluation $evaluation)
    {
        $this->authorizeEvaluation($evaluation);
        $validated = $request->validate([
            'category'        => ['required', 'string', 'max:255'],
            'description'     => ['required', 'string', 'max:255'],
            'quantity'        => ['nullable', 'integer', 'min:1'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        if (isset($validated['estimated_value'])) {
            $validated['estimated_value'] = (int) round($validated['estimated_value'] * 100);
        }

        $evaluation->assets()->create($validated);

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Asset added.');
    }

    public function destroyAsset(CommercialEvaluation $evaluation, CommercialEvaluationAsset $asset)
    {
        $this->authorizeEvaluation($evaluation);
        $asset->delete();

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Asset removed.');
    }

    // ══════════════════════════════════════════
    //  Rental Units
    // ══════════════════════════════════════════

    public function storeUnit(Request $request, CommercialEvaluation $evaluation)
    {
        $this->authorizeEvaluation($evaluation);
        $validated = $request->validate([
            'unit_name'       => ['required', 'string', 'max:255'],
            'tenant_name'     => ['nullable', 'string', 'max:255'],
            'size_m2'         => ['nullable', 'numeric', 'min:0'],
            'monthly_rental'  => ['nullable', 'numeric', 'min:0'],
            'lease_start'     => ['nullable', 'date'],
            'lease_end'       => ['nullable', 'date'],
            'is_vacant'       => ['nullable', 'boolean'],
            'escalation_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        if (isset($validated['monthly_rental'])) {
            $validated['monthly_rental'] = (int) round($validated['monthly_rental'] * 100);
        }

        $validated['is_vacant'] = $request->boolean('is_vacant');

        $evaluation->units()->create($validated);

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Rental unit added.');
    }

    public function destroyUnit(CommercialEvaluation $evaluation, CommercialEvaluationUnit $unit)
    {
        $this->authorizeEvaluation($evaluation);
        $unit->delete();

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Rental unit removed.');
    }

    // ══════════════════════════════════════════
    //  Crops & Orchards (agricultural)
    // ══════════════════════════════════════════

    public function storeCrop(Request $request, CommercialEvaluation $evaluation)
    {
        $this->authorizeEvaluation($evaluation);
        $validated = $request->validate([
            'crop_type'              => ['required', 'string', 'max:50'],
            'variety'                => ['nullable', 'string', 'max:255'],
            'hectares'               => ['required', 'numeric', 'min:0.01'],
            'year_planted'           => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'trees_per_hectare'      => ['nullable', 'integer', 'min:1'],
            'current_yield_tons_per_ha' => ['nullable', 'numeric', 'min:0'],
            'current_price_per_ton'  => ['nullable', 'numeric', 'min:0'],
            'annual_cost_per_ha'     => ['nullable', 'numeric', 'min:0'],
            'notes'                  => ['nullable', 'string', 'max:1000'],
            'guidance_answers'       => ['nullable', 'json'],
        ]);

        // Decode JSON string from form
        if (!empty($validated['guidance_answers'])) {
            $validated['guidance_answers'] = json_decode($validated['guidance_answers'], true);
        }

        $cropConfig = config('agricultural_crops.crops.' . $validated['crop_type'], []);

        // Convert Rand to cents
        if (isset($validated['current_price_per_ton'])) {
            $validated['current_price_per_ton'] = (int) round($validated['current_price_per_ton'] * 100);
        } else {
            $validated['current_price_per_ton'] = $cropConfig['current_price_per_ton'] ?? null;
        }

        if (isset($validated['annual_cost_per_ha'])) {
            $validated['annual_cost_per_ha'] = (int) round($validated['annual_cost_per_ha'] * 100);
        } else {
            $validated['annual_cost_per_ha'] = $cropConfig['cost_per_ha'] ?? null;
        }

        // Auto-calculate lifecycle fields
        $currentYear = (int) date('Y');
        $lifespan = $cropConfig['lifespan_years'] ?? null;
        $peakYield = $cropConfig['peak_yield_tons_per_ha'] ?? null;

        $validated['expected_lifespan_years'] = $lifespan;
        $validated['expected_peak_yield_tons_per_ha'] = $peakYield;

        if (isset($validated['year_planted'])) {
            $age = $currentYear - $validated['year_planted'];
            $validated['age_years'] = max(0, $age);
            if ($lifespan !== null) {
                $validated['remaining_productive_years'] = max(0, $lifespan - $age);
            }
            // Interpolate yield percentage from curve
            $yieldCurve = $cropConfig['yield_curve'] ?? [];
            $validated['yield_percentage'] = $this->interpolateYieldCurve($yieldCurve, $age);
        }

        // Pre-fill trees per hectare from config if not provided
        if (empty($validated['trees_per_hectare']) && !empty($cropConfig['typical_trees_per_ha'])) {
            $validated['trees_per_hectare'] = $cropConfig['typical_trees_per_ha'];
        }

        // Calculate total trees
        if (!empty($validated['trees_per_hectare'])) {
            $validated['total_trees'] = (int) round($validated['hectares'] * $validated['trees_per_hectare']);
        }

        // Calculate annual revenue (cents): hectares × actualYield(t/ha) × price_per_ton(cents)
        if ($peakYield && $validated['yield_percentage'] && $validated['current_price_per_ton']) {
            $actualYield = $peakYield * ($validated['yield_percentage'] / 100);
            $validated['annual_revenue'] = (int) round($validated['hectares'] * $actualYield * $validated['current_price_per_ton']);
        }

        // Set current yield from peak × yield%
        if ($peakYield && $validated['yield_percentage']) {
            $validated['current_yield_tons_per_ha'] = round($peakYield * ($validated['yield_percentage'] / 100), 2);
        }

        $evaluation->crops()->create($validated);

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Crop added.');
    }

    public function destroyCrop(CommercialEvaluation $evaluation, CommercialEvaluationCrop $crop)
    {
        $this->authorizeEvaluation($evaluation);
        $crop->delete();

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Crop removed.');
    }

    // ══════════════════════════════════════════
    //  Livestock (agricultural)
    // ══════════════════════════════════════════

    public function storeLivestock(Request $request, CommercialEvaluation $evaluation)
    {
        $this->authorizeEvaluation($evaluation);
        $validated = $request->validate([
            'livestock_type'              => ['required', 'string', 'max:50'],
            'breed'                       => ['nullable', 'string', 'max:255'],
            'head_count'                  => ['required', 'integer', 'min:1'],
            'breeding_stock_count'        => ['nullable', 'integer', 'min:0'],
            'value_per_head'              => ['nullable', 'numeric', 'min:0'],
            'carrying_capacity_ha_per_lsu'=> ['nullable', 'numeric', 'min:0'],
            'hectares_used'               => ['nullable', 'numeric', 'min:0'],
            'notes'                       => ['nullable', 'string', 'max:1000'],
            'guidance_answers'            => ['nullable', 'json'],
        ]);

        // Decode JSON string from form
        if (!empty($validated['guidance_answers'])) {
            $validated['guidance_answers'] = json_decode($validated['guidance_answers'], true);
        }

        $lsConfig = config('agricultural_crops.livestock.' . $validated['livestock_type'], []);

        // Convert Rand to cents for value_per_head
        if (isset($validated['value_per_head'])) {
            $validated['value_per_head'] = (int) round($validated['value_per_head'] * 100);
        } else {
            $validated['value_per_head'] = $lsConfig['value_per_head'] ?? null;
        }

        // Pre-fill carrying capacity from config if not provided
        if (empty($validated['carrying_capacity_ha_per_lsu']) && !empty($lsConfig['carrying_capacity_ha'])) {
            $validated['carrying_capacity_ha_per_lsu'] = $lsConfig['carrying_capacity_ha'];
        }

        // Calculate total value
        if ($validated['value_per_head']) {
            $validated['total_value'] = $validated['head_count'] * $validated['value_per_head'];
        }

        // Calculate annual revenue and cost from config per-head rates
        if (!empty($lsConfig['annual_revenue_per_head'])) {
            $validated['annual_revenue'] = $validated['head_count'] * $lsConfig['annual_revenue_per_head'];
        }
        if (!empty($lsConfig['annual_cost_per_head'])) {
            $validated['annual_cost'] = $validated['head_count'] * $lsConfig['annual_cost_per_head'];
        }

        $evaluation->livestock()->create($validated);

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Livestock added.');
    }

    public function destroyLivestock(CommercialEvaluation $evaluation, CommercialEvaluationLivestock $livestock)
    {
        $this->authorizeEvaluation($evaluation);
        $livestock->delete();

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Livestock removed.');
    }

    /**
     * Interpolate yield percentage from a sparse yield curve.
     */
    private function interpolateYieldCurve(array $curve, int $age): ?float
    {
        if (empty($curve)) {
            return null;
        }

        ksort($curve);
        $keys = array_keys($curve);

        // Before first data point
        if ($age <= $keys[0]) {
            return (float) $curve[$keys[0]];
        }

        // After last data point
        $lastKey = end($keys);
        if ($age >= $lastKey) {
            return (float) $curve[$lastKey];
        }

        // Find bracketing keys and interpolate
        $prevKey = $keys[0];
        foreach ($keys as $k) {
            if ($k == $age) {
                return (float) $curve[$k];
            }
            if ($k > $age) {
                $nextKey = $k;
                $range = $nextKey - $prevKey;
                if ($range == 0) {
                    return (float) $curve[$prevKey];
                }
                $fraction = ($age - $prevKey) / $range;
                return round($curve[$prevKey] + $fraction * ($curve[$nextKey] - $curve[$prevKey]), 2);
            }
            $prevKey = $k;
        }

        return null;
    }

    // ══════════════════════════════════════════
    //  Run Evaluation
    // ══════════════════════════════════════════

    public function evaluate(CommercialEvaluation $evaluation)
    {
        $this->authorizeEvaluation($evaluation);
        $service = new CommercialEvaluationService();
        $result  = $service->evaluate($evaluation);

        $evaluation->update([
            'evaluation_json'       => $result,
            'recommended_range_low' => $result['recommended']['low'],
            'recommended_range_mid' => $result['recommended']['mid'],
            'recommended_range_high'=> $result['recommended']['high'],
            'primary_method'        => $result['recommended']['primary_method'],
            'evaluated_at'          => now(),
        ]);

        $methodCount = count($result['methods_used'] ?? []);

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', "Market evaluation complete — {$methodCount} method(s) applied.");
    }

    // ══════════════════════════════════════════
    //  PDF Download
    // ══════════════════════════════════════════

    public function downloadPdf(CommercialEvaluation $evaluation)
    {
        $this->authorizeEvaluation($evaluation);
        // Run evaluation if not already done
        if (!$evaluation->evaluated_at) {
            $service = new CommercialEvaluationService();
            $result  = $service->evaluate($evaluation);

            $evaluation->update([
                'evaluation_json'       => $result,
                'recommended_range_low' => $result['recommended']['low'],
                'recommended_range_mid' => $result['recommended']['mid'],
                'recommended_range_high'=> $result['recommended']['high'],
                'primary_method'        => $result['recommended']['primary_method'],
                'evaluated_at'          => now(),
            ]);
        }

        $pdfService = new CommercialEvaluationPdfService();
        $html       = $pdfService->generate($evaluation);

        $filename = 'Commercial_Evaluation_'
            . preg_replace('/[^A-Za-z0-9_-]/', '_', $evaluation->property_name ?? 'Report')
            . '_' . now()->format('Y-m-d')
            . '.html';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    // ── Restore soft-deleted ──

    public function restore($id)
    {
        abort_unless(auth()->user()->hasPermission('commercial_evaluations.edit'), 403);
        $record = CommercialEvaluation::onlyTrashed()->findOrFail($id);
        $record->restore();
        return redirect()->back()->with('success', 'Record restored.');
    }
}
