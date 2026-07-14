<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Payroll\PayrollDeductionType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollDeductionTypeController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'all');
        $q = $request->query('q');

        $query = PayrollDeductionType::orderBy('sort_order')->orderBy('label');

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if ($q) {
            $query->where(function ($qb) use ($q) {
                $qb->where('label', 'like', "%{$q}%")
                   ->orWhere('code', 'like', "%{$q}%");
            });
        }

        $types = $query->paginate(25)->withQueryString();

        $counts = [
            'all'      => PayrollDeductionType::count(),
            'active'   => PayrollDeductionType::where('is_active', true)->count(),
            'inactive' => PayrollDeductionType::where('is_active', false)->count(),
        ];

        return view('payroll.deduction-types.index', compact('types', 'status', 'q', 'counts'));
    }

    public function create()
    {
        $type = new PayrollDeductionType();
        $nextSort = (PayrollDeductionType::max('sort_order') ?? 0) + 10;

        return view('payroll.deduction-types.create', compact('type', 'nextSort'));
    }

    public function store(Request $request)
    {
        $agencyId = auth()->user()?->effectiveAgencyId();

        $validated = $request->validate([
            'code'             => [
                'required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('payroll_deduction_types')->where('agency_id', $agencyId)->whereNull('deleted_at'),
            ],
            'label'            => 'required|string|max:100',
            'sars_source_code' => 'nullable|regex:/^\d{4}$/',
            'is_statutory'     => 'required|boolean',
            'sort_order'       => 'nullable|integer|min:0',
            'is_active'        => 'boolean',
        ]);

        $validated['is_system'] = false;
        $validated['sort_order'] = $validated['sort_order'] ?? ((PayrollDeductionType::max('sort_order') ?? 0) + 10);
        $validated['is_active'] = $validated['is_active'] ?? true;

        PayrollDeductionType::create($validated);

        return redirect()->route('payroll.deduction-types.index')
            ->with('success', "Deduction type \"{$validated['label']}\" created.");
    }

    public function edit($id)
    {
        $type = PayrollDeductionType::findOrFail($id);

        $locked = [
            'code'      => $type->is_system,
            'sars'      => $type->is_system,
            'statutory' => $type->is_statutory,
        ];

        return view('payroll.deduction-types.edit', compact('type', 'locked'));
    }

    public function update(Request $request, $id)
    {
        $type = PayrollDeductionType::findOrFail($id);
        $agencyId = auth()->user()?->effectiveAgencyId();

        $validated = $request->validate([
            'code'             => [
                'required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('payroll_deduction_types')->where('agency_id', $agencyId)->whereNull('deleted_at')->ignore($type->id),
            ],
            'label'            => 'required|string|max:100',
            'sars_source_code' => 'nullable|regex:/^\d{4}$/',
            'is_statutory'     => 'required|boolean',
            'sort_order'       => 'nullable|integer|min:0',
            'is_active'        => 'boolean',
        ]);

        // Defence in depth: statutory types have locked code/sars/statutory flag
        if ($type->is_statutory) {
            unset($validated['code'], $validated['sars_source_code'], $validated['is_statutory']);
        }

        // Defence in depth: system types also lock code/sars
        if ($type->is_system && !$type->is_statutory) {
            unset($validated['code'], $validated['sars_source_code']);
        }

        $type->update($validated);

        return redirect()->route('payroll.deduction-types.index')
            ->with('success', "Deduction type \"{$type->label}\" updated.");
    }

    public function destroy($id)
    {
        $type = PayrollDeductionType::findOrFail($id);

        if ($type->is_system) {
            abort(403, 'System deduction types cannot be deleted.');
        }

        if ($type->is_statutory) {
            abort(403, 'Statutory deduction types cannot be deleted.');
        }

        $refCount = $type->employeeDeductions()->count();
        if ($refCount > 0) {
            abort(422, "Deduction type is in use on {$refCount} employee profile(s) and cannot be deleted. Deactivate it instead.");
        }

        $type->delete();

        return redirect()->route('payroll.deduction-types.index')
            ->with('success', "\"{$type->label}\" deleted.");
    }
}
