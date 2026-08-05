<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Services\Performance\AgencyPerformanceReportService;
use App\Services\Performance\PeriodResolver;
use App\Services\Performance\PerformanceScope;
use Illuminate\Http\Request;

/**
 * AT-366 — Agency Performance & ROI report. AT-366-A foundation: renders the
 * scope + period selector and the user → branch → company rollup for the
 * registered metric providers. Coverage grows as providers are added (AT-366-B).
 */
class AgencyPerformanceReportController extends Controller
{
    public function index(Request $request, PeriodResolver $periods, AgencyPerformanceReportService $service)
    {
        $user     = $request->user();
        $agencyId = $user?->effectiveAgencyId();
        abort_if(!$agencyId, 403, 'No agency context for the performance report.');

        $preset = (string) $request->query('period', 'this_month');
        if (!in_array($preset, PeriodResolver::PRESETS, true)) {
            $preset = 'this_month';
        }

        try {
            $period = $periods->resolve($preset, $request->query('start'), $request->query('end'));
        } catch (\InvalidArgumentException $e) {
            // Bad custom range → fall back to the month, surface the reason.
            $preset = 'this_month';
            $period = $periods->resolve('this_month');
            session()->flash('period_error', $e->getMessage());
        }

        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $userId   = $request->filled('user_id') ? (int) $request->query('user_id') : null;

        $scope  = new PerformanceScope((int) $agencyId, $branchId, $userId);
        $report = $service->build($scope, $period);

        return view('performance.agency-report.index', [
            'report' => $report,
            'preset' => $preset,
            'presets' => PeriodResolver::PRESETS,
        ]);
    }
}
