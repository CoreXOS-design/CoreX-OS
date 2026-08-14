<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Performance\AgencyPerformanceReportService;
use App\Services\Performance\BuyerActivityService;
use App\Services\Performance\Period;
use App\Services\Performance\PeriodResolver;
use App\Services\Performance\PerformanceScope;
use Illuminate\Http\Request;

/**
 * AT-366 — Agency Performance & ROI report. Renders the scope + period selector
 * and the agent → branch → company rollup over the registered metric providers,
 * plus branch/agent drill-down (AT-366-D) and the period-scoped buyer-activity
 * view (AT-366-E). Every metric reconciles agent → branch → company.
 */
class AgencyPerformanceReportController extends Controller
{
    public function index(Request $request, PeriodResolver $periods, AgencyPerformanceReportService $service, BuyerActivityService $buyers)
    {
        $user     = $request->user();
        $agencyId = $user?->effectiveAgencyId();
        abort_if(!$agencyId, 403, 'No agency context for the performance report.');

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $userId   = $request->filled('user_id') ? (int) $request->query('user_id') : null;

        $scope  = new PerformanceScope((int) $agencyId, $branchId, $userId);
        $report = $service->build($scope, $period);

        // AT-366-E — company-level buyer-activity summary (period-scoped).
        $buyerActivity = $buyers->rollup($scope, $period);

        return view('performance.agency-report.index', [
            'report' => $report,
            'buyer'  => [
                'metrics'   => $buyerActivity['metrics'],
                'aggregate' => $buyerActivity['company'],
            ],
            'preset' => $preset,
            'presets' => PeriodResolver::PRESETS,
        ]);
    }

    /**
     * AT-366-D — one branch's drill-down: the branch's rolled-up metrics with
     * prior-period trend + the agents attributed to it (point-in-time). The
     * {branch} segment is a numeric branch id or the 'unassigned' sentinel.
     */
    public function branch(Request $request, string $branch, PeriodResolver $periods, AgencyPerformanceReportService $service, BuyerActivityService $buyers)
    {
        $actor    = $request->user();
        $agencyId = $actor?->effectiveAgencyId();
        abort_if(!$agencyId, 403, 'No agency context for the performance report.');
        abort_unless($branch === 'unassigned' || ctype_digit($branch), 404);

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $report = $service->branchJourney((int) $agencyId, $branch, $period);
        abort_if($report['branch'] === null, 404);

        // AT-366-E — this branch's buyer-activity summary, pulled from the same
        // company rollup so it reconciles with the company total.
        $buyerActivity = $buyers->rollup(new PerformanceScope((int) $agencyId), $period);

        return view('performance.agency-report.branch', [
            'report'  => $report,
            'buyer'   => [
                'metrics'   => $buyerActivity['metrics'],
                'aggregate' => $buyerActivity['branches'][$branch]['metrics'] ?? null,
            ],
            'preset'  => $preset,
            'presets' => PeriodResolver::PRESETS,
        ]);
    }

    /**
     * AT-366-C — one agent's journey: every metric for the period paired with its
     * prior-period value (trend). Agency-scoped; owners / out-of-agency 404.
     */
    public function agent(Request $request, User $user, PeriodResolver $periods, AgencyPerformanceReportService $service, BuyerActivityService $buyers)
    {
        $actor    = $request->user();
        $agencyId = $actor?->effectiveAgencyId();
        abort_if(!$agencyId, 403, 'No agency context for the performance report.');
        abort_unless((int) $user->agency_id === (int) $agencyId, 404);

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $journey = $service->agentJourney((int) $agencyId, (int) $user->id, $period);
        abort_if($journey['agent'] === null, 404);

        // AT-366-E — the agent's full period-scoped buyer-activity picture (Q7).
        $buyerActivity = $buyers->agentDetail((int) $agencyId, (int) $user->id, $period);

        return view('performance.agency-report.agent', [
            'journey' => $journey,
            'buyer'   => $buyerActivity,
            'preset'  => $preset,
            'presets' => PeriodResolver::PRESETS,
        ]);
    }

    /**
     * AT-366 (cc1 frontend #8) — whole-company printable: the full report, chrome-free,
     * with a company header. Same agency-scoped build() as index(); print-optimised view.
     */
    public function print(Request $request, PeriodResolver $periods, AgencyPerformanceReportService $service, BuyerActivityService $buyers)
    {
        $user     = $request->user();
        $agencyId = $user?->effectiveAgencyId();
        abort_if(!$agencyId, 403, 'No agency context for the performance report.');

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $scope  = new PerformanceScope((int) $agencyId, null, null);
        $report = $service->build($scope, $period);
        $buyerActivity = $buyers->rollup($scope, $period);

        return view('performance.agency-report.print-company', [
            'report' => $report,
            'buyer'  => ['metrics' => $buyerActivity['metrics'], 'aggregate' => $buyerActivity['company']],
            'agency' => \App\Models\Agency::withoutGlobalScopes()->find((int) $agencyId),
            'preset' => $preset,
        ]);
    }

    /**
     * AT-366 (cc1 frontend #8) — single-agent printable to hand an agent their own
     * figures. Agency-scoped exactly like agent(); out-of-agency users 404.
     */
    public function agentPrint(Request $request, User $user, PeriodResolver $periods, AgencyPerformanceReportService $service, BuyerActivityService $buyers)
    {
        $actor    = $request->user();
        $agencyId = $actor?->effectiveAgencyId();
        abort_if(!$agencyId, 403, 'No agency context for the performance report.');
        abort_unless((int) $user->agency_id === (int) $agencyId, 404);

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $journey = $service->agentJourney((int) $agencyId, (int) $user->id, $period);
        abort_if($journey['agent'] === null, 404);

        return view('performance.agency-report.print-agent', [
            'journey' => $journey,
            'agency'  => \App\Models\Agency::withoutGlobalScopes()->find((int) $agencyId),
            'preset'  => $preset,
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
