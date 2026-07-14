<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Leave\LeaveTransaction;
use App\Models\Leave\LeaveType;
use App\Models\Leave\StaffTakeOnRecord;
use App\Models\Payroll\PayrollEmployee;
use App\Services\Leave\LeaveAccrualService;
use App\Services\Leave\LeaveBalanceService;
use Illuminate\Http\Request;

class LeaveBalanceController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query('q');
        $branchFilter = $request->query('branch');

        $query = PayrollEmployee::with('user', 'user.branch')
            ->where('is_active', true)
            ->orderBy('created_at');

        if ($q) {
            $query->whereHas('user', fn($uq) => $uq->where('name', 'like', "%{$q}%"));
        }

        if ($branchFilter) {
            $query->where('branch_id', $branchFilter);
        }

        $employees = $query->paginate(25)->withQueryString();

        $agencyId = auth()->user()?->effectiveAgencyId();
        $balanceService = new LeaveBalanceService();
        $annualType = LeaveType::where('code', 'annual_leave')->where('agency_id', $agencyId)->first();
        $sickType = LeaveType::where('code', 'sick_leave')->where('agency_id', $agencyId)->first();
        $frlType = LeaveType::where('code', 'family_responsibility_leave')->where('agency_id', $agencyId)->first();

        $balances = [];
        foreach ($employees as $emp) {
            $balances[$emp->id] = [
                'annual' => $annualType ? $balanceService->getBalance($emp, $annualType) : null,
                'sick'   => $sickType ? $balanceService->getBalance($emp, $sickType) : null,
                'frl'    => $frlType ? $balanceService->getBalance($emp, $frlType) : null,
            ];
        }

        $branches = \App\Models\Branch::orderBy('name')->get();

        return view('payroll.leave.balances.index', compact('employees', 'balances', 'q', 'branchFilter', 'branches'));
    }

    public function show($employeeId)
    {
        $employee = PayrollEmployee::with('user', 'user.branch')->findOrFail($employeeId);

        $agencyId = auth()->user()?->effectiveAgencyId();
        $balanceService = new LeaveBalanceService();
        $leaveTypes = LeaveType::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $balances = [];
        $transactions = [];
        foreach ($leaveTypes as $type) {
            $balances[$type->id] = $balanceService->getBalance($employee, $type);

            $cycleStart = $balanceService->getCurrentCycleStart($employee, $type);
            $transactions[$type->id] = LeaveTransaction::withoutGlobalScopes()
                ->where('payroll_employee_id', $employee->id)
                ->where('leave_type_id', $type->id)
                ->where('cycle_start_date', $cycleStart->toDateString())
                ->with('createdBy')
                ->orderByDesc('created_at')
                ->paginate(10, ['*'], "txn_{$type->id}");
        }

        $takeOn = StaffTakeOnRecord::where('user_id', $employee->user_id)->first();

        return view('payroll.leave.balances.show', compact(
            'employee', 'leaveTypes', 'balances', 'transactions', 'takeOn'
        ));
    }

    public function adjust(Request $request, $employeeId)
    {
        $employee = PayrollEmployee::findOrFail($employeeId);

        $validated = $request->validate([
            'leave_type_id'  => 'required|integer|exists:leave_types,id',
            'days_delta'     => 'required|numeric',
            'reason'         => 'required|string|min:10|max:500',
            'effective_date' => 'nullable|date',
        ]);

        $type = LeaveType::findOrFail($validated['leave_type_id']);
        $accrualService = new LeaveAccrualService();

        $txn = $accrualService->manualAdjustment(
            $employee,
            $type,
            (string) $validated['days_delta'],
            $validated['reason'],
            auth()->user(),
            $validated['effective_date'] ? \Carbon\Carbon::parse($validated['effective_date']) : null
        );

        $balanceService = new LeaveBalanceService();
        $newBalance = $balanceService->getBalance($employee, $type);

        return redirect()->route('payroll.leave.balances.show', $employee)
            ->with('success', "Adjusted {$type->label} by {$validated['days_delta']} days. New available: {$newBalance['available_days']} days.");
    }

    public function recalculate($employeeId)
    {
        $employee = PayrollEmployee::findOrFail($employeeId);

        $accrualService = new LeaveAccrualService();
        $accrualService->accrueForEmployee($employee);

        $balanceService = new LeaveBalanceService();
        $leaveTypes = LeaveType::withoutGlobalScopes()
            ->where('agency_id', $employee->agency_id)
            ->where('is_active', true)->get();

        foreach ($leaveTypes as $type) {
            $balanceService->refreshEntitlement($employee, $type);
        }

        return redirect()->route('payroll.leave.balances.show', $employee)
            ->with('success', 'Balances recalculated from transaction ledger.');
    }
}
