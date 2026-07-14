<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Payroll\PayrollDeductionType;
use App\Models\Payroll\PayrollEarningType;
use App\Models\Payroll\PayrollEmployee;
use App\Models\Payroll\PayrollEmployeeDeduction;
use App\Models\Payroll\PayrollEmployeeEarning;
use App\Models\User;
use App\Models\UserBankingDetail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollEmployeeController extends Controller
{
    // ── LIST ──

    public function index(Request $request)
    {
        $status = $request->query('status', 'active');
        $q = $request->query('q');

        $query = PayrollEmployee::with(['user', 'user.branch'])
            ->orderBy('created_at', 'desc');

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'inactive') {
            $query->inactive();
        } elseif ($status === 'terminated') {
            $query->terminated();
        }

        if ($q) {
            $query->whereHas('user', function ($uq) use ($q) {
                $uq->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $employees = $query->paginate(25)->withQueryString();

        // Attach current basic salary to each employee for display
        foreach ($employees as $emp) {
            $basicType = PayrollEarningType::where('code', 'basic')->first();
            $emp->basic_salary = $basicType
                ? $emp->currentEarnings()->where('earning_type_id', $basicType->id)->value('amount')
                : null;
        }

        $counts = [
            'all'        => PayrollEmployee::count(),
            'active'     => PayrollEmployee::active()->count(),
            'inactive'   => PayrollEmployee::inactive()->count(),
            'terminated' => PayrollEmployee::terminated()->count(),
        ];

        return view('payroll.employees.index', compact('employees', 'status', 'q', 'counts'));
    }

    // ── CREATE ──

    public function create()
    {
        $agencyId = auth()->user()?->effectiveAgencyId();

        // Users not yet on payroll (excluding soft-deleted payroll employees)
        $eligibleUsers = User::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereDoesntHave('payrollEmployee')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'designation', 'id_number', 'date_of_birth', 'branch_id']);

        return view('payroll.employees.create', compact('eligibleUsers'));
    }

    // ── STORE ──

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id'              => [
                'required', 'integer',
                Rule::exists('users', 'id')->where('agency_id', auth()->user()?->effectiveAgencyId()),
            ],
            'employment_date'      => 'required|date',
            'designation_snapshot' => 'required|string|max:100',
            'date_of_birth'        => 'nullable|date',
            'tax_reference_number' => 'nullable|string|max:20',
            'pay_day_of_month'     => 'required|integer|min:1|max:31',
            'notes'                => 'nullable|string|max:2000',
            // Banking (optional)
            'bank_name'            => 'nullable|string|max:100',
            'account_holder'       => 'nullable|string|max:150',
            'branch_code'          => 'nullable|string|max:10',
            'account_number'       => 'nullable|string|max:30',
            'account_type'         => 'nullable|in:cheque,savings,transmission',
        ]);

        // Prevent duplicate payroll employee
        $existing = PayrollEmployee::where('user_id', $validated['user_id'])->first();
        if ($existing) {
            return back()->withInput()->with('error', 'This user is already on payroll.');
        }

        $user = User::findOrFail($validated['user_id']);

        // Update user DOB/tax ref if provided and not yet set
        if (!empty($validated['date_of_birth']) && !$user->date_of_birth) {
            $user->date_of_birth = $validated['date_of_birth'];
        }
        if (!empty($validated['tax_reference_number']) && !$user->tax_reference_number) {
            $user->tax_reference_number = $validated['tax_reference_number'];
        }
        $user->save();

        // Create payroll employee
        $employee = PayrollEmployee::create([
            'user_id'              => $user->id,
            'branch_id'            => $user->branch_id,
            'employment_date'      => $validated['employment_date'],
            'designation_snapshot' => $validated['designation_snapshot'],
            'pay_frequency'        => 'monthly',
            'pay_day_of_month'     => $validated['pay_day_of_month'],
            'is_active'            => true,
            'notes'                => $validated['notes'],
            'created_by'           => auth()->id(),
        ]);

        // Create default earnings: Basic Salary at R0
        $basicType = PayrollEarningType::where('code', 'basic')->first();
        if ($basicType) {
            PayrollEmployeeEarning::create([
                'payroll_employee_id' => $employee->id,
                'earning_type_id'     => $basicType->id,
                'amount'              => 0,
                'effective_from'      => $validated['employment_date'],
                'created_by'          => auth()->id(),
            ]);
        }

        // Create default deductions: PAYE and UIF (auto-calculated, amount 0)
        $statutoryTypes = PayrollDeductionType::where('is_statutory', true)->get();
        foreach ($statutoryTypes as $st) {
            PayrollEmployeeDeduction::create([
                'payroll_employee_id' => $employee->id,
                'deduction_type_id'   => $st->id,
                'amount'              => 0,
                'effective_from'      => $validated['employment_date'],
                'override_statutory'  => false,
                'created_by'          => auth()->id(),
            ]);
        }

        // Banking details (if provided)
        if (!empty($validated['bank_name']) && !empty($validated['account_number'])) {
            UserBankingDetail::create([
                'user_id'        => $user->id,
                'account_holder' => $validated['account_holder'] ?? $user->name,
                'bank_name'      => $validated['bank_name'],
                'branch_code'    => $validated['branch_code'],
                'account_number' => $validated['account_number'],
                'account_type'   => $validated['account_type'] ?? 'cheque',
                'is_primary'     => true,
            ]);
        }

        return redirect()->route('payroll.employees.show', $employee)
            ->with('success', "{$user->name} added to payroll.");
    }

    // ── SHOW (PROFILE) ──

    public function show($id)
    {
        $employee = PayrollEmployee::with([
            'user', 'user.branch', 'user.bankingDetail',
        ])->findOrFail($id);

        $currentEarnings = $employee->currentEarnings()
            ->with('earningType', 'createdBy')
            ->orderBy('earning_type_id')
            ->get();

        $currentDeductions = $employee->currentDeductions()
            ->with('deductionType', 'createdBy')
            ->orderBy('deduction_type_id')
            ->get();

        $earningTypes = PayrollEarningType::active()->orderBy('sort_order')->get();
        $deductionTypes = PayrollDeductionType::active()->orderBy('sort_order')->get();

        // Payslip history
        $payslips = $employee->payslips()
            ->orderBy('period_month', 'desc')
            ->get();

        // Audit log: all earnings + deductions changes
        $auditEarnings = PayrollEmployeeEarning::withTrashed()
            ->where('payroll_employee_id', $employee->id)
            ->with('earningType', 'createdBy')
            ->orderBy('created_at', 'desc')
            ->get();

        $auditDeductions = PayrollEmployeeDeduction::withTrashed()
            ->where('payroll_employee_id', $employee->id)
            ->with('deductionType', 'createdBy')
            ->orderBy('created_at', 'desc')
            ->get();

        // YTD stats (from finalised payslips in current tax year: 1 Mar to 28 Feb)
        $taxYearStart = Carbon::now()->month >= 3
            ? Carbon::create(Carbon::now()->year, 3, 1)
            : Carbon::create(Carbon::now()->year - 1, 3, 1);

        $ytdStats = $employee->payslips()
            ->where('period_month', '>=', $taxYearStart)
            ->whereHas('run', function ($q) { $q->where('status', 'finalised'); })
            ->selectRaw('
                COUNT(*) as payslip_count,
                COALESCE(SUM(total_earnings), 0) as ytd_gross,
                COALESCE(SUM(paye_amount), 0) as ytd_paye
            ')
            ->first();

        return view('payroll.employees.show', compact(
            'employee', 'currentEarnings', 'currentDeductions',
            'earningTypes', 'deductionTypes', 'payslips',
            'auditEarnings', 'auditDeductions', 'ytdStats'
        ));
    }

    // ── EDIT ──

    public function edit($id)
    {
        $employee = PayrollEmployee::with('user')->findOrFail($id);

        return view('payroll.employees.edit', compact('employee'));
    }

    // ── UPDATE ──

    public function update(Request $request, $id)
    {
        $employee = PayrollEmployee::findOrFail($id);

        $validated = $request->validate([
            'employment_date'      => 'required|date',
            'designation_snapshot' => 'required|string|max:100',
            'date_of_birth'        => 'nullable|date',
            'tax_reference_number' => 'nullable|string|max:20',
            'pay_day_of_month'     => 'required|integer|min:1|max:31',
            'notes'                => 'nullable|string|max:2000',
        ]);

        $employee->update([
            'employment_date'      => $validated['employment_date'],
            'designation_snapshot' => $validated['designation_snapshot'],
            'pay_day_of_month'     => $validated['pay_day_of_month'],
            'notes'                => $validated['notes'],
        ]);

        // Sync DOB and tax ref to user record
        $user = $employee->user;
        if (!empty($validated['date_of_birth'])) {
            $user->date_of_birth = $validated['date_of_birth'];
        }
        if (!empty($validated['tax_reference_number'])) {
            $user->tax_reference_number = $validated['tax_reference_number'];
        }
        $user->save();

        return redirect()->route('payroll.employees.show', $employee)
            ->with('success', 'Profile updated.');
    }

    // ── DESTROY (soft) ──

    public function destroy($id)
    {
        $employee = PayrollEmployee::findOrFail($id);
        $employee->delete();

        return redirect()->route('payroll.employees.index')
            ->with('success', "\"{$employee->user->name}\" removed from payroll.");
    }

    // ── DEACTIVATE / REACTIVATE ──

    public function deactivate($id)
    {
        $employee = PayrollEmployee::findOrFail($id);
        $employee->update(['is_active' => false]);

        return redirect()->route('payroll.employees.show', $employee)
            ->with('success', "{$employee->user->name} deactivated. They will be skipped in future runs.");
    }

    public function reactivate($id)
    {
        $employee = PayrollEmployee::findOrFail($id);
        $employee->update(['is_active' => true]);

        return redirect()->route('payroll.employees.show', $employee)
            ->with('success', "{$employee->user->name} reactivated.");
    }

    // ══════════════════════════════════════════════════════════════
    // EARNINGS CRUD
    // ══════════════════════════════════════════════════════════════

    public function storeEarning(Request $request, $employeeId)
    {
        $employee = PayrollEmployee::findOrFail($employeeId);

        $validated = $request->validate([
            'earning_type_id' => 'required|exists:payroll_earning_types,id',
            'amount'          => 'required|numeric|min:0',
            'effective_from'  => 'required|date',
            'notes'           => 'nullable|string|max:500',
        ]);

        PayrollEmployeeEarning::create([
            'payroll_employee_id' => $employee->id,
            'earning_type_id'     => $validated['earning_type_id'],
            'amount'              => $validated['amount'],
            'effective_from'      => $validated['effective_from'],
            'notes'               => $validated['notes'],
            'created_by'          => auth()->id(),
        ]);

        return redirect()->route('payroll.employees.show', $employee)
            ->with('success', 'Earning added.');
    }

    public function updateEarning(Request $request, $employeeId, $earningId)
    {
        $employee = PayrollEmployee::findOrFail($employeeId);
        $earning = PayrollEmployeeEarning::where('payroll_employee_id', $employee->id)
            ->findOrFail($earningId);

        $validated = $request->validate([
            'amount'         => 'required|numeric|min:0',
            'effective_from' => 'required|date',
            'notes'          => 'nullable|string|max:500',
        ]);

        // Effective-dating: close old row, create new one
        $earning->update([
            'effective_to' => Carbon::parse($validated['effective_from'])->subDay(),
        ]);

        PayrollEmployeeEarning::create([
            'payroll_employee_id' => $employee->id,
            'earning_type_id'     => $earning->earning_type_id,
            'amount'              => $validated['amount'],
            'effective_from'      => $validated['effective_from'],
            'notes'               => $validated['notes'],
            'created_by'          => auth()->id(),
        ]);

        return redirect()->route('payroll.employees.show', $employee)
            ->with('success', 'Earning updated with new effective date.');
    }

    public function destroyEarning($employeeId, $earningId)
    {
        $employee = PayrollEmployee::findOrFail($employeeId);
        $earning = PayrollEmployeeEarning::where('payroll_employee_id', $employee->id)
            ->findOrFail($earningId);

        // Close effective range and soft-delete
        $earning->update(['effective_to' => now()->toDateString()]);
        $earning->delete();

        return redirect()->route('payroll.employees.show', $employee)
            ->with('success', 'Earning removed.');
    }

    // ══════════════════════════════════════════════════════════════
    // DEDUCTIONS CRUD
    // ══════════════════════════════════════════════════════════════

    public function storeDeduction(Request $request, $employeeId)
    {
        $employee = PayrollEmployee::findOrFail($employeeId);

        $validated = $request->validate([
            'deduction_type_id'  => 'required|exists:payroll_deduction_types,id',
            'amount'             => 'required|numeric|min:0',
            'effective_from'     => 'required|date',
            'override_statutory' => 'boolean',
            'notes'              => 'nullable|string|max:500',
        ]);

        PayrollEmployeeDeduction::create([
            'payroll_employee_id' => $employee->id,
            'deduction_type_id'   => $validated['deduction_type_id'],
            'amount'              => $validated['amount'],
            'effective_from'      => $validated['effective_from'],
            'override_statutory'  => $validated['override_statutory'] ?? false,
            'notes'               => $validated['notes'],
            'created_by'          => auth()->id(),
        ]);

        return redirect()->route('payroll.employees.show', $employee)
            ->with('success', 'Deduction added.');
    }

    public function updateDeduction(Request $request, $employeeId, $deductionId)
    {
        $employee = PayrollEmployee::findOrFail($employeeId);
        $deduction = PayrollEmployeeDeduction::where('payroll_employee_id', $employee->id)
            ->findOrFail($deductionId);

        $validated = $request->validate([
            'amount'             => 'required|numeric|min:0',
            'effective_from'     => 'required|date',
            'override_statutory' => 'boolean',
            'notes'              => 'nullable|string|max:500',
        ]);

        // Effective-dating: close old row, create new one
        $deduction->update([
            'effective_to' => Carbon::parse($validated['effective_from'])->subDay(),
        ]);

        PayrollEmployeeDeduction::create([
            'payroll_employee_id' => $employee->id,
            'deduction_type_id'   => $deduction->deduction_type_id,
            'amount'              => $validated['amount'],
            'effective_from'      => $validated['effective_from'],
            'override_statutory'  => $validated['override_statutory'] ?? $deduction->override_statutory,
            'notes'               => $validated['notes'],
            'created_by'          => auth()->id(),
        ]);

        return redirect()->route('payroll.employees.show', $employee)
            ->with('success', 'Deduction updated with new effective date.');
    }

    public function destroyDeduction($employeeId, $deductionId)
    {
        $employee = PayrollEmployee::findOrFail($employeeId);
        $deduction = PayrollEmployeeDeduction::where('payroll_employee_id', $employee->id)
            ->findOrFail($deductionId);

        // Don't allow deleting statutory deductions
        if ($deduction->deductionType && $deduction->deductionType->is_statutory) {
            abort(403, 'Statutory deductions cannot be removed. Use the override toggle instead.');
        }

        $deduction->update(['effective_to' => now()->toDateString()]);
        $deduction->delete();

        return redirect()->route('payroll.employees.show', $employee)
            ->with('success', 'Deduction removed.');
    }

    // ══════════════════════════════════════════════════════════════
    // BANKING
    // ══════════════════════════════════════════════════════════════

    public function storeBanking(Request $request, $employeeId)
    {
        $employee = PayrollEmployee::findOrFail($employeeId);

        $validated = $request->validate([
            'account_holder' => 'required|string|max:150',
            'bank_name'      => 'required|string|max:100',
            'branch_code'    => 'required|string|max:10',
            'account_number' => 'required|string|max:30',
            'account_type'   => 'required|in:cheque,savings,transmission',
        ]);

        UserBankingDetail::create([
            'user_id'        => $employee->user_id,
            'account_holder' => $validated['account_holder'],
            'bank_name'      => $validated['bank_name'],
            'branch_code'    => $validated['branch_code'],
            'account_number' => $validated['account_number'],
            'account_type'   => $validated['account_type'],
            'is_primary'     => true,
        ]);

        return redirect()->route('payroll.employees.show', $employee)
            ->with('success', 'Banking details saved.');
    }

    public function updateBanking(Request $request, $employeeId)
    {
        $employee = PayrollEmployee::findOrFail($employeeId);

        $validated = $request->validate([
            'account_holder' => 'required|string|max:150',
            'bank_name'      => 'required|string|max:100',
            'branch_code'    => 'required|string|max:10',
            'account_number' => 'required|string|max:30',
            'account_type'   => 'required|in:cheque,savings,transmission',
        ]);

        $banking = UserBankingDetail::where('user_id', $employee->user_id)->first();

        if ($banking) {
            $banking->update($validated);
        } else {
            UserBankingDetail::create(array_merge($validated, [
                'user_id'    => $employee->user_id,
                'is_primary' => true,
            ]));
        }

        return redirect()->route('payroll.employees.show', $employee)
            ->with('success', 'Banking details updated.');
    }
}
