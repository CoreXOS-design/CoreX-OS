<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Leave\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveTypeController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'all');
        $q = $request->query('q');

        $query = LeaveType::orderBy('sort_order')->orderBy('label');

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        } elseif ($status === 'system') {
            $query->where('is_system', true);
        } elseif ($status === 'custom') {
            $query->where('is_system', false);
        }

        if ($q) {
            $query->where(function ($qb) use ($q) {
                $qb->where('label', 'like', "%{$q}%")
                   ->orWhere('code', 'like', "%{$q}%");
            });
        }

        $types = $query->paginate(25)->withQueryString();

        $counts = [
            'all'      => LeaveType::count(),
            'active'   => LeaveType::where('is_active', true)->count(),
            'inactive' => LeaveType::where('is_active', false)->count(),
            'system'   => LeaveType::where('is_system', true)->count(),
            'custom'   => LeaveType::where('is_system', false)->count(),
        ];

        return view('payroll.leave.types.index', compact('types', 'status', 'q', 'counts'));
    }

    public function create()
    {
        $type = new LeaveType();
        $nextSort = (LeaveType::max('sort_order') ?? 0) + 10;

        return view('payroll.leave.types.create', compact('type', 'nextSort'));
    }

    public function store(Request $request)
    {
        $agencyId = auth()->user()->effectiveAgencyId();

        $validated = $request->validate($this->validationRules($agencyId));

        $validated['is_system'] = false;
        $validated['sort_order'] = $validated['sort_order'] ?? ((LeaveType::max('sort_order') ?? 0) + 10);
        $validated['is_active'] = $validated['is_active'] ?? true;

        LeaveType::create($validated);

        return redirect()->route('payroll.leave.types.index')
            ->with('success', "Leave type \"{$validated['label']}\" created.");
    }

    public function edit($id)
    {
        $type = LeaveType::findOrFail($id);

        $locked = [
            'code'            => $type->is_system,
            'category'        => $type->is_system,
            'entitlement'     => $type->is_system,
            'cycle'           => $type->is_system,
            'accrual'         => $type->is_system,
            'is_paid'         => $type->is_system,
            'is_uif'          => $type->is_system,
            'payout'          => $type->is_system,
        ];

        return view('payroll.leave.types.edit', compact('type', 'locked'));
    }

    public function update(Request $request, $id)
    {
        $type = LeaveType::findOrFail($id);
        $agencyId = auth()->user()->effectiveAgencyId();

        $validated = $request->validate($this->validationRules($agencyId, $type->id));

        // Defence in depth: system types have locked fields
        if ($type->is_system) {
            unset(
                $validated['code'], $validated['category'],
                $validated['entitlement_days_per_cycle'], $validated['entitlement_days_per_cycle_six_day'],
                $validated['cycle_months'], $validated['accrual_method'], $validated['accrual_rate_per_days'],
                $validated['is_paid'], $validated['is_uif_claimable'], $validated['payout_on_termination']
            );
        }

        $type->update($validated);

        return redirect()->route('payroll.leave.types.index')
            ->with('success', "Leave type \"{$type->label}\" updated.");
    }

    public function destroy($id)
    {
        $type = LeaveType::findOrFail($id);

        if ($type->is_system) {
            abort(403, 'System leave types (BCEA-mandated) cannot be deleted. Deactivate instead.');
        }

        $appCount = $type->applications()->count();
        $entCount = $type->entitlements()->count();
        $refCount = $appCount + $entCount;

        if ($refCount > 0) {
            abort(422, "This leave type is in use on {$appCount} application(s) and {$entCount} entitlement(s) and cannot be deleted. Deactivate it instead.");
        }

        $type->delete();

        return redirect()->route('payroll.leave.types.index')
            ->with('success', "\"{$type->label}\" deleted.");
    }

    private function validationRules(int $agencyId, ?int $ignoreId = null): array
    {
        $codeUnique = Rule::unique('leave_types')->where('agency_id', $agencyId)->whereNull('deleted_at');
        if ($ignoreId) {
            $codeUnique = $codeUnique->ignore($ignoreId);
        }

        return [
            'code'                               => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/', $codeUnique],
            'label'                              => 'required|string|max:150',
            'description'                        => 'nullable|string',
            'category'                           => 'required|in:annual,sick,family_responsibility,parental,study,unpaid,special,other',
            'is_paid'                            => 'boolean',
            'is_uif_claimable'                   => 'boolean',
            'requires_documentation'             => 'boolean',
            'documentation_label'                => 'nullable|string|max:150',
            'documentation_threshold_days'       => 'nullable|integer|min:0|max:30',
            'entitlement_days_per_cycle'         => 'required|numeric|min:0|max:999.99',
            'entitlement_days_per_cycle_six_day' => 'required|numeric|min:0|max:999.99',
            'cycle_months'                       => 'required|integer|min:0|max:60',
            'accrual_method'                     => 'required|in:full_at_start,accrual_per_day_worked,accrual_first_six_months,none',
            'accrual_rate_per_days'              => 'nullable|integer|min:1|max:365',
            'accrual_starts_at_employment_date'  => 'boolean',
            'requires_pre_approval'              => 'boolean',
            'min_advance_notice_days'            => 'required|integer|min:0|max:365',
            'allows_negative_balance'            => 'boolean',
            'carries_over_to_next_cycle'         => 'boolean',
            'forfeit_after_months'               => 'nullable|integer|min:0',
            'payout_on_termination'              => 'boolean',
            'affects_payroll'                    => 'boolean',
            'is_active'                          => 'boolean',
            'sort_order'                         => 'nullable|integer|min:0',
        ];
    }
}
