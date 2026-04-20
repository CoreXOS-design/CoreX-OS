<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmployeeScreeningDashboardController extends Controller
{
    public function index(Request $request)
    {
        $agencyId = Auth::user()->effectiveAgencyId();

        $staff = User::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        $totalStaff = $staff->count();
        $clearCount = $staff->where('screening_status', 'clear')->count();
        $flaggedCount = $staff->where('screening_status', 'concerns_flagged')->count();
        $overdueCount = $staff->where('screening_status', 'overdue')->count()
            + $staff->filter(fn($u) => $u->screening_status === 'clear' && $u->screening_due_on && $u->screening_due_on < now())->count();
        $pendingCount = $staff->where('screening_status', 'pre_employment_pending')->count();
        $neverCount = $staff->where('screening_status', 'never_screened')->count();

        $staffData = $staff->map(function ($user) {
            return [
                'user'           => $user,
                'risk_tier'      => $user->risk_tier ?? 'medium',
                'status'         => $user->screening_status ?? 'never_screened',
                'screening_due'  => $user->screening_due_on,
                'latest'         => $user->latestScreening(),
            ];
        });

        // Filters
        if ($request->filled('risk_tier')) {
            $staffData = $staffData->filter(fn($s) => $s['risk_tier'] === $request->risk_tier);
        }
        if ($request->filled('status')) {
            $staffData = $staffData->filter(fn($s) => $s['status'] === $request->status);
        }
        if ($request->filled('search')) {
            $search = strtolower($request->search);
            $staffData = $staffData->filter(fn($s) => str_contains(strtolower($s['user']->name), $search));
        }

        // Sort by next due ascending (most urgent first)
        $staffData = $staffData->sortBy(function ($s) {
            if (!$s['screening_due']) return '9999-12-31';
            return $s['screening_due'];
        })->values();

        return view('compliance.screening-dashboard.index', [
            'staffData'    => $staffData,
            'totalStaff'   => $totalStaff,
            'clearCount'   => $clearCount,
            'flaggedCount' => $flaggedCount,
            'overdueCount' => $overdueCount,
            'pendingCount' => $pendingCount,
            'neverCount'   => $neverCount,
        ]);
    }
}
