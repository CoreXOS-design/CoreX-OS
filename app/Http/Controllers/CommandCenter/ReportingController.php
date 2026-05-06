<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Services\ReportingService;
use Illuminate\Http\Request;

class ReportingController extends Controller
{
    public function agentDashboard(Request $request)
    {
        $user = auth()->user();
        $days = (int) $request->get('days', 30);
        $service = app(ReportingService::class);

        return view('command-center.reporting.agent', [
            'user' => $user,
            'days' => $days,
            'metrics' => $service->getAgentMetrics($user->id, $days),
            'funnel' => $service->getConversionFunnel(['user_id' => $user->id], $days),
            'insights' => $service->getAgentInsights($user->id, $days),
        ]);
    }

    public function agencyDashboard(Request $request)
    {
        $user = auth()->user();
        if (!in_array($user->role, ['admin', 'super_admin', 'owner'])) abort(403);
        $agencyId = $user->effectiveAgencyId() ?? 1;
        $days = (int) $request->get('days', 30);
        $service = app(ReportingService::class);

        return view('command-center.reporting.agency', [
            'user' => $user, 'days' => $days,
            'metrics' => $service->getAgencyMetrics($agencyId, $days),
            'branchComparison' => $service->getBranchComparison($agencyId, $days),
            'insights' => $service->getAgencyInsights($agencyId, $days),
        ]);
    }

    public function branchDashboard(Request $request)
    {
        $user = auth()->user();
        $branchId = (int) ($request->get('branch_id') ?: $user->branch_id ?: 1);
        $days = (int) $request->get('days', 30);
        $service = app(ReportingService::class);

        // BM: only their branch. Admin/owner: any branch.
        if (!in_array($user->role, ['admin', 'super_admin', 'owner']) && (int) $user->branch_id !== $branchId) {
            abort(403);
        }

        $branches = in_array($user->role, ['admin', 'super_admin', 'owner'])
            ? \App\Models\Branch::withoutGlobalScopes()->where('agency_id', $user->effectiveAgencyId() ?? 1)->get(['id', 'name'])
            : collect();

        return view('command-center.reporting.branch', [
            'user' => $user,
            'branchId' => $branchId,
            'days' => $days,
            'metrics' => $service->getBranchMetrics($branchId, $days),
            'leaderboard' => $service->getLeaderboardForBranch($branchId, $days),
            'funnel' => $service->getConversionFunnel(['branch_id' => $branchId], $days),
            'insights' => $service->getBranchInsights($branchId, $days),
            'branches' => $branches,
        ]);
    }
}
