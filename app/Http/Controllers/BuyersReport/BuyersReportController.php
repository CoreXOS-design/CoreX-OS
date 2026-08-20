<?php

namespace App\Http\Controllers\BuyersReport;

use App\Http\Controllers\Controller;
use App\Services\BuyersReport\BuyersReportScopeResolver;
use App\Services\BuyersReport\BuyersReportService;
use App\Services\Performance\PeriodResolver;
use Illuminate\Http\Request;

/**
 * Buyers Report — first pass (Johan, 2026-08-20): Needs Attention list +
 * tiles + per-agent table, real data, no drill-down yet (tile-click modal,
 * agent/branch pages, and period comparison wired into the UI are the
 * second pass, deliberately deferred so there is something real to look at
 * today rather than something complete later).
 */
class BuyersReportController extends Controller
{
    public function index(
        Request $request,
        PeriodResolver $periods,
        BuyersReportScopeResolver $scopeResolver,
        BuyersReportService $service,
    ) {
        $user = $request->user();

        // Scope comes ONLY from here — the resolver derives it from the
        // viewer's own identity/permission ceiling. requestedBranchId/
        // requestedUserId are only ever honoured at agency ceiling, and only
        // after confirming they belong to this agency. See
        // BuyersReportScopeResolver's docblock for why this isn't a plain
        // copy of AgencyPerformanceReportController's scoping.
        $requestedLevel = $request->filled('scope') ? (string) $request->query('scope') : null;
        $requestedBranchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $requestedUserId = $request->filled('user_id') ? (int) $request->query('user_id') : null;

        $scope = $scopeResolver->resolve($user, $requestedLevel, $requestedBranchId, $requestedUserId);

        $preset = (string) $request->query('period', 'this_month');
        if (!in_array($preset, PeriodResolver::PRESETS, true)) {
            $preset = 'this_month';
        }
        try {
            $period = $periods->resolve($preset, $request->query('start'), $request->query('end'));
        } catch (\InvalidArgumentException $e) {
            $preset = 'this_month';
            $period = $periods->resolve('this_month');
            session()->flash('period_error', $e->getMessage());
        }

        $report = $service->build($scope, $period);
        $attention = $service->needsAttention($scope);

        return view('buyers-report.index', [
            'scope'      => $scope,
            'report'     => $report,
            'attention'  => $attention,
            'preset'     => $preset,
            'presets'    => PeriodResolver::PRESETS,
        ]);
    }
}
