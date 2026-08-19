<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Performance\DealStatusBreakdownService;
use App\Services\Performance\PerformanceDrilldownService;
use App\Services\Performance\PeriodResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AT-366 (6)+(9) — interactive agency-report backend.
 *   GET /api/v1/performance/deal-breakdown  — per-agent deal qty+value by status bucket (no round-trip toggling).
 *   GET /api/v1/performance/drilldown       — the underlying rows behind a clicked figure.
 * Both are READ-ONLY and strictly scoped to the authenticated user's agency.
 */
class PerformanceDrilldownController extends Controller
{
    private function agencyId(Request $request): int
    {
        $id = $request->user()?->effectiveAgencyId();
        abort_if(!$id, 403, 'No agency context.');
        return (int) $id;
    }

    private function period(Request $request)
    {
        $preset = (string) $request->query('period', 'this_month');
        return app(PeriodResolver::class)->resolve(
            in_array($preset, PeriodResolver::PRESETS, true) ? $preset : 'this_month',
            $request->query('start'), $request->query('end')
        );
    }

    /** Deal breakdown by status bucket — per agent + branch/company rollup (distinct). */
    public function dealBreakdown(Request $request, DealStatusBreakdownService $svc, PerformanceDrilldownService $drill): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $agentId  = $request->filled('agent_id') ? (int) $request->query('agent_id') : null;
        $period   = $this->period($request);

        $cohort = $drill->cohort($agencyId, $branchId, $agentId);
        $out    = $svc->forUsers($cohort, $period, $agencyId);

        $users = DB::table('users')->whereIn('id', $cohort)->pluck('name', 'id');
        $branches = DB::table('users')->whereIn('id', $cohort)->pluck('branch_id', 'id');
        $rows = [];
        // report-consistent rollup = SUM of per-agent (a co-agent deal counts for each of its agents).
        $sum = array_fill_keys(DealStatusBreakdownService::BUCKETS, ['count' => 0, 'value' => 0.0, 'commission' => 0.0]);
        foreach ($out['perAgent'] as $uid => $buckets) {
            foreach (DealStatusBreakdownService::BUCKETS as $b) {
                $sum[$b]['count'] += $buckets[$b]['count'];
                $sum[$b]['value'] += $buckets[$b]['value'];
                $sum[$b]['commission'] += $buckets[$b]['commission'];
            }
            if (($buckets['all']['count'] ?? 0) === 0) continue; // only agents with deals in the rows
            $rows[] = array_merge([
                'agent_id' => (int) $uid, 'agent' => $users[$uid] ?? null, 'branch_id' => $branches[$uid] ?? null,
            ], $buckets);
        }

        return response()->json([
            'agency_id' => $agencyId,
            'scope'     => ['agent_id' => $agentId, 'branch_id' => $branchId],
            'period'    => ['start' => $period->start->toDateString(), 'end' => $period->end->toDateString(), 'label' => $period->label],
            'buckets'   => DealStatusBreakdownService::BUCKETS,
            'status_mapping' => [
                'pending'    => "DR1 accepted_status=P / DR2 status=active",
                'granted'    => "DR1 (accepted_status=G or granted_at) / DR2 status=granted",
                'registered' => "DR1 (accepted_status=R or registration_date) / DR2 (status=registered or actual_registration)",
                'declined'   => "DR1 accepted_status=D / DR2 status=declined",
            ],
            'agents'          => $rows,
            'totals'          => $sum,           // report-consistent (matches the grid deal figure = sum of per-agent)
            'totals_distinct' => $out['distinct'], // honest company total: each deal counted once
        ]);
    }

    /** The rows behind a clicked figure. */
    public function drilldown(Request $request, PerformanceDrilldownService $drill): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $metric   = (string) $request->query('metric', '');
        abort_unless(in_array($metric, PerformanceDrilldownService::METRICS, true), 422, 'Unknown metric.');
        $status = $request->filled('status') ? (string) $request->query('status') : null;
        abort_if($status !== null && !in_array($status, DealStatusBreakdownService::BUCKETS, true), 422, 'Unknown status.');

        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $agentId  = $request->filled('agent_id') ? (int) $request->query('agent_id') : null;
        $period   = $this->period($request);

        $cohort = $drill->cohort($agencyId, $branchId, $agentId);
        $res    = $drill->rows($metric, $cohort, $period, $agencyId, $status);

        return response()->json([
            'agency_id' => $agencyId,
            'metric'    => $metric,
            'scope'     => ['agent_id' => $agentId, 'branch_id' => $branchId],
            'status'    => $metric === 'deals' ? ($status ?? 'all') : null,
            'period'    => ['start' => $period->start->toDateString(), 'end' => $period->end->toDateString(), 'label' => $period->label],
            'count'     => $res['count'],
            'capped_at' => PerformanceDrilldownService::LIMIT,
            'rows'      => $res['rows'],
        ]);
    }
}
