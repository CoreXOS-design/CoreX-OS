<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Leave\LeaveApplication;
use App\Models\Leave\LeaveEntitlement;
use App\Models\Leave\LeaveType;
use App\Models\Payroll\PayrollEmployee;
use App\Services\Leave\LeaveBalanceService;

class LeaveDashboardController extends Controller
{
    public function index()
    {
        $agencyId = auth()->user()?->effectiveAgencyId();

        $activeEmployees = PayrollEmployee::where('is_active', true)->count();
        $approvedThisMonth = LeaveApplication::where('status', 'approved')
            ->where('decided_at', '>=', now()->startOfMonth())->count();
        $pendingApplications = LeaveApplication::where('status', 'submitted')->count();
        $daysTakenThisYear = LeaveApplication::whereIn('status', ['approved', 'taken'])
            ->where('start_date', '>=', now()->startOfYear())
            ->sum('working_days_requested');

        // Compliance warnings
        $warnings = [];
        $balanceService = new LeaveBalanceService();
        $annualType = LeaveType::where('code', 'annual_leave')->where('agency_id', $agencyId)->first();

        if ($annualType) {
            $employees = PayrollEmployee::where('is_active', true)->with('user')->get();
            foreach ($employees as $emp) {
                $balance = $balanceService->getBalance($emp, $annualType);
                $entitlement = (float) $balance['entitlement_days'];
                $available = (float) $balance['available_days'];
                if ($entitlement > 0 && $available > ($entitlement * 1.5)) {
                    $warnings[] = "{$emp->user->name} has " . number_format($available, 1) . " days annual leave accumulated (>" . number_format($entitlement * 1.5, 0) . " days). Encourage taking leave.";
                }
            }
        }

        return view('payroll.leave.dashboard.index', compact(
            'activeEmployees', 'approvedThisMonth', 'pendingApplications',
            'daysTakenThisYear', 'warnings'
        ));
    }
}
