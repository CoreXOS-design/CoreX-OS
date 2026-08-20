<?php

namespace App\Http\Controllers\BuyersReport;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BuyersReport\BuyersReportDrilldownService;
use App\Services\BuyersReport\BuyersReportScope;
use App\Services\BuyersReport\BuyersReportScopeResolver;
use App\Services\BuyersReport\BuyersReportService;
use App\Services\Performance\BuyerActivityService;
use App\Services\Performance\HierarchyResolver;
use App\Services\Performance\Period;
use App\Services\Performance\PeriodResolver;
use App\Services\Performance\PerformanceScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Buyers Report — first pass (Johan, 2026-08-20): Needs Attention list +
 * tiles + per-agent table, real data. Second pass (2026-08-20): tile-click
 * drill-down. Agent/branch dedicated pages and period comparison follow.
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

        [$period, $preset] = $this->resolvePeriod($request, $periods);

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

    /**
     * Tile-click drill-down — mirrors the ROI report's {title,total,columns,rows}
     * contract. Scope comes from BuyersReportScopeResolver exactly as index()
     * does, never from raw request trust. An optional agent_id further narrows
     * to one row of the "By agent" table, but only if that agent is already
     * inside the resolved cohort — never honoured on its own.
     */
    public function drilldown(
        Request $request,
        PeriodResolver $periods,
        BuyersReportScopeResolver $scopeResolver,
        HierarchyResolver $hierarchy,
        BuyersReportDrilldownService $drill,
    ) {
        $user = $request->user();

        $requestedLevel = $request->filled('scope') ? (string) $request->query('scope') : null;
        $requestedBranchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $requestedUserId = $request->filled('user_id') ? (int) $request->query('user_id') : null;

        $scope = $scopeResolver->resolve($user, $requestedLevel, $requestedBranchId, $requestedUserId);

        [$period] = $this->resolvePeriod($request, $periods);

        $metric = (string) $request->query('metric', '');
        abort_unless(in_array($metric, BuyersReportDrilldownService::METRICS, true), 422, 'Unknown metric.');

        $perfScope = new PerformanceScope($scope->agencyId, $scope->branchId, $scope->userId);
        $agents    = $hierarchy->agents($perfScope);
        $userIds   = $agents->pluck('id')->map(fn ($i) => (int) $i)->all();

        $agentFilterName = null;
        if ($request->filled('agent_id')) {
            $agentId = (int) $request->query('agent_id');
            if (in_array($agentId, $userIds, true)) {
                $userIds = [$agentId];
                $agentFilterName = $agents->firstWhere('id', $agentId)?->name;
            } else {
                $userIds = [];
            }
        }

        $res = $drill->rows($metric, $userIds, $period, $scope->agencyId);

        return response()->json([
            'title'   => $this->drilldownTitle($metric, $scope, $period, $res['count'], $agentFilterName),
            'total'   => $res['count'],
            'columns' => $drill->columns($metric),
            'rows'    => $res['rows'],
        ]);
    }

    private function drilldownTitle(string $metric, BuyersReportScope $scope, $period, int $total, ?string $agentFilterName): string
    {
        $noun = [
            'buyers' => 'buyers held', 'buyers_added' => 'buyers added', 'buyers_won' => 'buyers won',
            'appointments' => 'appointments', 'comms_email' => 'emails', 'comms_whatsapp' => 'WhatsApps',
            'lost' => 'buyers lost', 'lost_value' => 'buyers lost',
        ][$metric] ?? $metric;

        $who = $agentFilterName;
        if ($who === null) {
            $who = match ($scope->level) {
                BuyersReportScope::LEVEL_OWN => 'You',
                BuyersReportScope::LEVEL_BRANCH => $scope->branchId
                    ? (string) (DB::table('branches')->where('id', $scope->branchId)->value('name') ?? 'Branch')
                    : 'Branch',
                default => 'Company',
            };
        }

        return trim("{$total} {$noun}") . " — {$who} · " . $period->label;
    }

    /**
     * One agent's dedicated page — the report pinned to just them (own-level
     * scope, userId forced to the TARGET, not the viewer), plus the richer
     * per-buyer breakdown BuyerActivityService::agentDetail() already
     * computes (state, days-in-state, days-in-pipeline, last worked,
     * in-period appointments/comms, lost list + reasons).
     *
     * Authorization is canViewAgent(), NOT "is this user in my agency" —
     * that agency-only check is the exact gap the ROI report's agent()
     * has (Johan, AT-366-D audit): a branch_manager could view any agent
     * in the agency by editing the URL. Here a branch-ceiling viewer is
     * held to their OWN branch's agents; an own-ceiling viewer only to
     * themselves.
     */
    public function agent(
        Request $request,
        User $user,
        PeriodResolver $periods,
        BuyersReportScopeResolver $scopeResolver,
        BuyersReportService $service,
        BuyerActivityService $buyerActivity,
    ) {
        $actor = $request->user();
        abort_unless($scopeResolver->canViewAgent($actor, (int) $user->id), 404);

        $agencyId = (int) $actor->effectiveAgencyId();
        $scope = new BuyersReportScope($agencyId, BuyersReportScope::LEVEL_OWN, userId: (int) $user->id);

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $report = $service->build($scope, $period);
        $attention = $service->needsAttention($scope);
        $detail = $buyerActivity->agentDetail($agencyId, (int) $user->id, $period);

        return view('buyers-report.agent', [
            'targetUser' => $user,
            'scope'      => $scope,
            'report'     => $report,
            'attention'  => $attention,
            'detail'     => $detail,
            'preset'     => $preset,
            'presets'    => PeriodResolver::PRESETS,
        ]);
    }

    /**
     * One branch's dedicated page — the report pinned to that branch,
     * whichever agent the viewer is. Authorization is canViewBranch(): an
     * agency-ceiling viewer can open any branch in their agency; a
     * branch-ceiling viewer only their own; an own-ceiling viewer never
     * gets a branch page at all.
     */
    public function branch(
        Request $request,
        string $branch,
        PeriodResolver $periods,
        BuyersReportScopeResolver $scopeResolver,
        BuyersReportService $service,
    ) {
        $actor = $request->user();
        abort_unless(ctype_digit($branch), 404);
        $branchId = (int) $branch;
        abort_unless($scopeResolver->canViewBranch($actor, $branchId), 404);

        $agencyId = (int) $actor->effectiveAgencyId();
        $scope = new BuyersReportScope($agencyId, BuyersReportScope::LEVEL_BRANCH, branchId: $branchId);

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $report = $service->build($scope, $period);
        $attention = $service->needsAttention($scope);
        $branchName = (string) (DB::table('branches')->where('id', $branchId)->value('name') ?? 'Branch');

        return view('buyers-report.branch', [
            'branchName' => $branchName,
            'scope'      => $scope,
            'report'     => $report,
            'attention'  => $attention,
            'preset'     => $preset,
            'presets'    => PeriodResolver::PRESETS,
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
