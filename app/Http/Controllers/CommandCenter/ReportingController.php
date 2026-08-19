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
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);   // AT-253 Rule 17
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

        // 'admin'/'super_admin'/'owner' here are agency-level role STRINGS, not
        // necessarily true platform System Owners — do not conflate the two.
        // Only User::isOwnerRole() (Role.is_owner flag) identifies a genuine
        // platform owner, who bypasses agency scoping entirely.
        $isAgencyAdmin = in_array($user->role, ['admin', 'super_admin', 'owner']);
        $isPlatformOwner = $user->isOwnerRole();

        // BM: only their own branch. Agency admin/owner: any branch, but ONLY
        // within their own agency — branch_id is untrusted client input and must
        // be verified server-side against effectiveAgencyId(). Platform System
        // Owner: bypasses entirely.
        if (!$isPlatformOwner) {
            if ($isAgencyAdmin) {
                $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
                $branchAgencyId = (int) \App\Models\Branch::withoutGlobalScopes()->where('id', $branchId)->value('agency_id');
                if ($agencyId === 0 || $branchAgencyId !== $agencyId) {
                    abort(403);
                }
            } elseif ((int) $user->branch_id !== $branchId) {
                abort(403);
            }
        }

        $branches = ($isPlatformOwner || $isAgencyAdmin)
            ? \App\Models\Branch::withoutGlobalScopes()->where('agency_id', (int) ($user->effectiveAgencyId() ?: 0))->get(['id', 'name']) // AT-253 Rule 17
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
