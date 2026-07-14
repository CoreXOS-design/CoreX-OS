<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Payroll\PayrollEarningType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollEarningTypeController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'all');
        $q = $request->query('q');

        $query = PayrollEarningType::orderBy('sort_order')->orderBy('label');

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
            'all'      => PayrollEarningType::count(),
            'active'   => PayrollEarningType::where('is_active', true)->count(),
            'inactive' => PayrollEarningType::where('is_active', false)->count(),
        ];

        return view('payroll.earning-types.index', compact('types', 'status', 'q', 'counts'));
    }

    public function create()
    {
        $type = new PayrollEarningType();
        $nextSort = (PayrollEarningType::max('sort_order') ?? 0) + 10;

        return view('payroll.earning-types.create', compact('type', 'nextSort'));
    }

    public function store(Request $request)
    {
        $agencyId = auth()->user()?->effectiveAgencyId();

        $validated = $request->validate([
            'code'                     => [
                'required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('payroll_earning_types')->where('agency_id', $agencyId)->whereNull('deleted_at'),
            ],
            'label'                    => 'required|string|max:100',
            'sars_source_code'         => 'nullable|regex:/^\d{4}$/',
            'is_taxable'               => 'required|boolean',
            'is_fringe_benefit'        => 'required|boolean',
            'affects_uif_remuneration' => 'required|boolean',
            'affects_sdl_remuneration' => 'required|boolean',
            'sort_order'               => 'nullable|integer|min:0',
            'is_active'                => 'boolean',
        ]);

        $validated['is_system'] = false;
        $validated['sort_order'] = $validated['sort_order'] ?? ((PayrollEarningType::max('sort_order') ?? 0) + 10);
        $validated['is_active'] = $validated['is_active'] ?? true;

        PayrollEarningType::create($validated);

        return redirect()->route('payroll.earning-types.index')
            ->with('success', "Earning type \"{$validated['label']}\" created.");
    }

    public function edit($id)
    {
        $type = PayrollEarningType::findOrFail($id);

        $locked = [
            'code'    => $type->is_system,
            'sars'    => $type->is_system,
            'taxable' => $type->is_system,
        ];

        return view('payroll.earning-types.edit', compact('type', 'locked'));
    }

    public function update(Request $request, $id)
    {
        $type = PayrollEarningType::findOrFail($id);
        $agencyId = auth()->user()?->effectiveAgencyId();

        $validated = $request->validate([
            'code'                     => [
                'required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('payroll_earning_types')->where('agency_id', $agencyId)->whereNull('deleted_at')->ignore($type->id),
            ],
            'label'                    => 'required|string|max:100',
            'sars_source_code'         => 'nullable|regex:/^\d{4}$/',
            'is_taxable'               => 'required|boolean',
            'is_fringe_benefit'        => 'required|boolean',
            'affects_uif_remuneration' => 'required|boolean',
            'affects_sdl_remuneration' => 'required|boolean',
            'sort_order'               => 'nullable|integer|min:0',
            'is_active'                => 'boolean',
        ]);

        // Defence in depth: system types have locked code/sars/taxable
        if ($type->is_system) {
            unset($validated['code'], $validated['sars_source_code'], $validated['is_taxable'],
                  $validated['is_fringe_benefit'], $validated['affects_uif_remuneration'],
                  $validated['affects_sdl_remuneration']);
        }

        $type->update($validated);

        return redirect()->route('payroll.earning-types.index')
            ->with('success', "Earning type \"{$type->label}\" updated.");
    }

    public function destroy($id)
    {
        $type = PayrollEarningType::findOrFail($id);

        if ($type->is_system) {
            abort(403, 'System earning types cannot be deleted.');
        }

        $refCount = $type->employeeEarnings()->count();
        if ($refCount > 0) {
            abort(422, "Earning type is in use on {$refCount} employee profile(s) and cannot be deleted. Deactivate it instead.");
        }

        $type->delete();

        return redirect()->route('payroll.earning-types.index')
            ->with('success', "\"{$type->label}\" deleted.");
    }
}
