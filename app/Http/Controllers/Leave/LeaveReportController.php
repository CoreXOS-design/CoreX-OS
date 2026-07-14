<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Leave\LeaveApplication;
use App\Models\Leave\LeaveTransaction;
use App\Models\Leave\LeaveType;
use App\Models\Payroll\PayrollEmployee;
use App\Services\Leave\LeaveBalanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LeaveReportController extends Controller
{
    // ── REGISTER ──

    public function register(Request $request)
    {
        $dateFrom = $request->query('from', now()->startOfMonth()->toDateString());
        $dateTo = $request->query('to', now()->endOfMonth()->toDateString());
        $status = $request->query('status');
        $typeFilter = $request->query('type');
        $branchFilter = $request->query('branch');
        $q = $request->query('q');

        $query = LeaveApplication::with('user', 'leaveType', 'decidedBy', 'payrollEmployee.user.branch')
            ->whereBetween('start_date', [$dateFrom, $dateTo])
            ->orderByDesc('submitted_at');

        if ($status) $query->where('status', $status);
        if ($typeFilter) $query->where('leave_type_id', $typeFilter);
        if ($branchFilter) $query->where('branch_id', $branchFilter);
        if ($q) $query->whereHas('user', fn($uq) => $uq->where('name', 'like', "%{$q}%"));

        $applications = $query->paginate(50)->withQueryString();
        $leaveTypes = LeaveType::active()->orderBy('sort_order')->get();
        $branches = \App\Models\Branch::orderBy('name')->get();

        return view('payroll.leave.reports.register', compact(
            'applications', 'dateFrom', 'dateTo', 'status', 'typeFilter', 'branchFilter', 'q', 'leaveTypes', 'branches'
        ));
    }

    public function registerExport(Request $request, string $format)
    {
        $dateFrom = $request->query('from', now()->startOfMonth()->toDateString());
        $dateTo = $request->query('to', now()->endOfMonth()->toDateString());

        $query = LeaveApplication::with('user', 'leaveType', 'decidedBy')
            ->whereBetween('start_date', [$dateFrom, $dateTo])
            ->orderByDesc('submitted_at');

        if ($request->query('status')) $query->where('status', $request->query('status'));
        if ($request->query('type')) $query->where('leave_type_id', $request->query('type'));
        if ($request->query('branch')) $query->where('branch_id', $request->query('branch'));

        $applications = $query->get();

        if ($format === 'xlsx') {
            return $this->exportCsv($applications, 'leave-register');
        }

        // PDF via Puppeteer — deferred to Tier 2 for formatted reports
        return $this->exportCsv($applications, 'leave-register');
    }

    // ── BRANCH SUMMARY ──

    public function branchSummary(Request $request)
    {
        $agencyId = auth()->user()?->effectiveAgencyId();
        $balanceService = new LeaveBalanceService();

        $branches = \App\Models\Branch::where('agency_id', $agencyId)->orderBy('name')->get();
        $annualType = LeaveType::where('code', 'annual_leave')->where('agency_id', $agencyId)->first();
        $sickType = LeaveType::where('code', 'sick_leave')->where('agency_id', $agencyId)->first();

        $summary = [];
        foreach ($branches as $branch) {
            $employees = PayrollEmployee::where('branch_id', $branch->id)->where('is_active', true)->get();
            $branchData = [
                'branch' => $branch,
                'employee_count' => $employees->count(),
                'annual_entitled' => '0.00', 'annual_taken' => '0.00', 'annual_available' => '0.00', 'annual_at_risk' => 0,
                'sick_taken' => '0.00',
                'compliance_flags' => 0,
            ];

            foreach ($employees as $emp) {
                if ($annualType) {
                    $bal = $balanceService->getBalance($emp, $annualType);
                    $branchData['annual_entitled'] = bcadd($branchData['annual_entitled'], $bal['entitlement_days'], 2);
                    $branchData['annual_taken'] = bcadd($branchData['annual_taken'], $bal['taken_days'], 2);
                    $branchData['annual_available'] = bcadd($branchData['annual_available'], $bal['available_days'], 2);
                    if ((float) $bal['available_days'] > ((float) $bal['entitlement_days'] * 1.5)) {
                        $branchData['annual_at_risk']++;
                        $branchData['compliance_flags']++;
                    }
                }
                if ($sickType) {
                    $sBal = $balanceService->getBalance($emp, $sickType);
                    $branchData['sick_taken'] = bcadd($branchData['sick_taken'], $sBal['taken_days'], 2);
                }
            }

            $summary[] = $branchData;
        }

        return view('payroll.leave.reports.branch-summary', compact('summary'));
    }

    // ── ACCRUAL STATEMENT ──

    public function accrualStatement($employeeId)
    {
        $employee = PayrollEmployee::with('user', 'user.branch')->findOrFail($employeeId);
        $agencyId = auth()->user()?->effectiveAgencyId();
        $balanceService = new LeaveBalanceService();

        $leaveTypes = LeaveType::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('is_active', true)
            ->orderBy('sort_order')->get();

        $statements = [];
        foreach ($leaveTypes as $type) {
            $cycleStart = $balanceService->getCurrentCycleStart($employee, $type);
            $balance = $balanceService->getBalance($employee, $type, $cycleStart);

            $transactions = LeaveTransaction::withoutGlobalScopes()
                ->where('payroll_employee_id', $employee->id)
                ->where('leave_type_id', $type->id)
                ->where('cycle_start_date', $cycleStart->toDateString())
                ->with('createdBy')
                ->orderBy('effective_date')
                ->orderBy('id')
                ->get();

            // Running balance
            $running = '0.000';
            foreach ($transactions as $txn) {
                $running = bcadd($running, (string) $txn->days_delta, 3);
                $txn->running_balance = $running;
            }

            $statements[] = [
                'type' => $type,
                'balance' => $balance,
                'transactions' => $transactions,
            ];
        }

        $employees = PayrollEmployee::where('is_active', true)->with('user')->orderBy('created_at')->get();

        return view('payroll.leave.reports.accrual-statement', compact('employee', 'statements', 'employees'));
    }

    // ── AUDIT LOG ──

    public function auditLog(Request $request)
    {
        $dateFrom = $request->query('from', now()->startOfMonth()->toDateString());
        $dateTo = $request->query('to', now()->endOfMonth()->toDateString());
        $txnType = $request->query('txn_type');

        $query = LeaveTransaction::withoutGlobalScopes()
            ->with('user', 'leaveType', 'createdBy')
            ->whereBetween('effective_date', [$dateFrom, $dateTo])
            ->orderByDesc('created_at');

        if ($txnType) $query->where('transaction_type', $txnType);

        $transactions = $query->paginate(100)->withQueryString();

        return view('payroll.leave.reports.audit-log', compact('transactions', 'dateFrom', 'dateTo', 'txnType'));
    }

    // ── CSV EXPORT HELPER ──

    private function exportCsv($applications, string $filename)
    {
        $headers = ['App #', 'Employee', 'Branch', 'Type', 'Start', 'End', 'Working Days', 'Status', 'Submitted', 'Decided By', 'Decided At', 'Affects Pay'];

        $callback = function () use ($applications, $headers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($applications as $app) {
                fputcsv($out, [
                    $app->application_number,
                    $app->user->name ?? '-',
                    $app->payrollEmployee?->user?->branch?->name ?? '-',
                    $app->leaveType->label ?? '-',
                    $app->start_date?->format('Y-m-d'),
                    $app->end_date?->format('Y-m-d'),
                    $app->working_days_requested,
                    $app->status,
                    $app->submitted_at?->format('Y-m-d H:i'),
                    $app->decidedBy->name ?? '-',
                    $app->decided_at?->format('Y-m-d H:i'),
                    $app->affects_payroll ? 'Yes' : 'No',
                ]);
            }
            fclose($out);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}-" . now()->format('Ymd') . ".csv\"",
        ]);
    }
}
