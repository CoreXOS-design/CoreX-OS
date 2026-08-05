<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Performance\AgencyPerformanceReportService;
use App\Services\Performance\Period;
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

        [$period, $preset] = $this->resolvePeriod($request, $periods);

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

    /**
     * AT-366-C — one agent's journey: every metric for the period paired with its
     * prior-period value (trend). Agency-scoped; owners / out-of-agency 404.
     */
    public function agent(Request $request, User $user, PeriodResolver $periods, AgencyPerformanceReportService $service)
    {
        $actor    = $request->user();
        $agencyId = $actor?->effectiveAgencyId();
        abort_if(!$agencyId, 403, 'No agency context for the performance report.');
        abort_unless((int) $user->agency_id === (int) $agencyId, 404);

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $journey = $service->agentJourney((int) $agencyId, (int) $user->id, $period);
        abort_if($journey['agent'] === null, 404);

        return view('performance.agency-report.agent', [
            'journey' => $journey,
            'preset'  => $preset,
            'presets' => PeriodResolver::PRESETS,
        ]);
    }

    /** @return array{0: Period, 1: string} resolved period + effective preset */
    private function resolvePeriod(Request $request, PeriodResolver $periods): array
    {
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

        return [$period, $preset];
    }
}
