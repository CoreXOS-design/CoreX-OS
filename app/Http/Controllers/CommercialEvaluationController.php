<?php

namespace App\Http\Controllers;

use App\Models\CommercialEvaluation;
use App\Models\CommercialEvaluationFinancial;
use App\Models\CommercialEvaluationComparable;
use App\Models\CommercialEvaluationAsset;
use App\Models\CommercialEvaluationUnit;
use App\Models\Branch;
use Illuminate\Http\Request;

class CommercialEvaluationController extends Controller
{
    // ── Index ──

    public function index()
    {
        $user = auth()->user();
        $isAdmin = $user->isEffectiveAdmin();

        $query = CommercialEvaluation::with(['creator'])
            ->latest();

        if (!$isAdmin) {
            $query->where('created_by_user_id', $user->id);
        }

        $evaluations = $query->paginate(25);

        return view('commercial-evaluations.index', compact('evaluations'));
    }

    // ── Create ──

    public function create()
    {
        $isAdmin = auth()->user()->isEffectiveAdmin();
        $branches = $isAdmin ? Branch::orderBy('name')->get() : collect();

        return view('commercial-evaluations.create', compact('branches', 'isAdmin'));
    }

    // ── Store ──

    public function store(Request $request)
    {
        $isAdmin = auth()->user()->isEffectiveAdmin();

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
            'branch_id'          => $isAdmin ? ($validated['branch_id'] ?? null) : auth()->user()->effectiveBranchId(),
            'status'             => 'draft',
        ]));

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Commercial evaluation created.');
    }

    // ── Show ──

    public function show(CommercialEvaluation $evaluation)
    {
        $evaluation->load(['financials', 'comparables', 'assets', 'units', 'creator']);

        return view('commercial-evaluations.show', compact('evaluation'));
    }

    // ── Edit ──

    public function edit(CommercialEvaluation $evaluation)
    {
        $isAdmin = auth()->user()->isEffectiveAdmin();
        $branches = $isAdmin ? Branch::orderBy('name')->get() : collect();

        return view('commercial-evaluations.edit', compact('evaluation', 'branches', 'isAdmin'));
    }

    // ── Update ──

    public function update(Request $request, CommercialEvaluation $evaluation)
    {
        $isAdmin = auth()->user()->isEffectiveAdmin();

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
        $evaluation->delete();

        return redirect()->route('commercial-evaluations.index')
            ->with('success', 'Evaluation deleted.');
    }

    // ══════════════════════════════════════════
    //  Financial Data
    // ══════════════════════════════════════════

    public function storeFinancials(Request $request, CommercialEvaluation $evaluation)
    {
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

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Financial data updated.');
    }

    // ══════════════════════════════════════════
    //  Comparables
    // ══════════════════════════════════════════

    public function storeComparable(Request $request, CommercialEvaluation $evaluation)
    {
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
        $comparable->delete();

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Comparable removed.');
    }

    // ══════════════════════════════════════════
    //  Assets
    // ══════════════════════════════════════════

    public function storeAsset(Request $request, CommercialEvaluation $evaluation)
    {
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
        $asset->delete();

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Asset removed.');
    }

    // ══════════════════════════════════════════
    //  Rental Units
    // ══════════════════════════════════════════

    public function storeUnit(Request $request, CommercialEvaluation $evaluation)
    {
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
        $unit->delete();

        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('success', 'Rental unit removed.');
    }

    // ══════════════════════════════════════════
    //  Run Evaluation (Phase 2 placeholder)
    // ══════════════════════════════════════════

    public function evaluate(CommercialEvaluation $evaluation)
    {
        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('info', 'Evaluation engine coming in Phase 2.');
    }

    // ══════════════════════════════════════════
    //  PDF Download (Phase 2 placeholder)
    // ══════════════════════════════════════════

    public function downloadPdf(CommercialEvaluation $evaluation)
    {
        return redirect()->route('commercial-evaluations.show', $evaluation)
            ->with('info', 'PDF download coming in Phase 2.');
    }
}
