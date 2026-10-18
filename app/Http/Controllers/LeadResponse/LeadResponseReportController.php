<?php

namespace App\Http\Controllers\LeadResponse;

use App\Http\Controllers\Controller;
use App\Services\BuyersReport\BuyersReportScope;
use App\Services\BuyersReport\BuyersReportScopeResolver;
use App\Services\LeadResponse\LeadResponseService;
use App\Services\Performance\HierarchyResolver;
use App\Services\Performance\Period;
use App\Services\Performance\PeriodResolver;
use App\Services\Performance\PerformanceScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lead Response report (Johan, 2026-10-07; .ai/specs/lead-response-time.md §5) — its own page under Reports.
 *
 * Scoping is the Buyers Report's, deliberately: BuyersReportScopeResolver derives the level and cohort from the
 * viewer's own role ceiling (agent = own, branch manager = branch, admin = agency), a requested level is only ever
 * clamped DOWN, and a branch/agent id from the URL is only honoured at agency ceiling after an agency-ownership
 * check. Every figure, table row and popup list comes from the ONE LeadResponseService calculation, so a number and
 * the list behind it cannot disagree.
 */
class LeadResponseReportController extends Controller
{
    public function index(Request $request, PeriodResolver $periods, BuyersReportScopeResolver $scopeResolver, LeadResponseService $svc)
    {
        $data = $this->build($request, $periods, $scopeResolver, $svc, withComparison: true);

        $q = array_filter([
            'scope' => $data['scope']->level, 'branch_id' => $data['scope']->branchId, 'user_id' => $data['scope']->userId,
            'period' => $data['preset'], 'start' => $request->query('start'), 'end' => $request->query('end'),
        ], fn ($v) => $v !== null && $v !== '');
        $data['drilldownBase'] = route('lead-response-report.drilldown') . '?' . http_build_query($q);
        $data['presets'] = PeriodResolver::PRESETS;
        $data['compareModes'] = PeriodResolver::COMPARE_MODES;

        return view('lead-response-report.index', $data);
    }

    /** The leads behind any figure — the same cohort as the page, optionally narrowed to one in-cohort agent / one source. */
    public function drilldown(Request $request, PeriodResolver $periods, BuyersReportScopeResolver $scopeResolver, LeadResponseService $svc)
    {
        $data = $this->build($request, $periods, $scopeResolver, $svc);
        $scope = $data['scope'];
        $userIds = $data['userIds'];

        $agentFilterName = null;
        if ($request->filled('agent_id')) {
            $agentId = (int) $request->query('agent_id');
            if (in_array($agentId, $userIds, true)) {
                $userIds = [$agentId];
                $agentFilterName = collect($data['agentRows'])->firstWhere('user_id', $agentId)['name'] ?? null;
            } else {
                $userIds = []; // never honoured outside the cohort
            }
        }

        $subtype = (string) $request->query('subtype', 'received');
        $subtype = in_array($subtype, LeadResponseService::SUBTYPES, true) ? $subtype : 'received';
        $source = $request->filled('source') ? (string) $request->query('source') : null;
        if ($source !== null && ! array_key_exists($source, LeadResponseService::SOURCES)) {
            abort(422, 'Unknown source.');
        }

        $res = $svc->rows($scope->agencyId, $data['period'], $userIds, $subtype, null, $source);
        $noun = [
            'received' => 'leads received', 'in_target' => 'leads answered in target', 'late' => 'leads answered late',
            'waiting' => 'leads not yet contacted', 'overdue' => 'leads not yet contacted and past target',
            'responded' => 'answered leads (response times)',
        ][$subtype];
        $who = $agentFilterName ?? $data['scopeLabelShort'];
        if ($source !== null) {
            $who .= ' · ' . LeadResponseService::SOURCES[$source];
        }

        return response()->json([
            'title'     => trim("{$res['count']} {$noun}") . " — {$who} · " . $data['period']->label,
            'total'     => $res['count'],
            'columns'   => $svc->columns(),
            'rows'      => $res['rows'],
            'truncated' => $res['truncated'],
            'level'     => null,
            'subtype'   => null,
        ]);
    }

    public function print(Request $request, PeriodResolver $periods, BuyersReportScopeResolver $scopeResolver, LeadResponseService $svc)
    {
        return view('lead-response-report.print', $this->printData($request, $periods, $scopeResolver, $svc));
    }

    public function pdf(Request $request, PeriodResolver $periods, BuyersReportScopeResolver $scopeResolver, LeadResponseService $svc)
    {
        $data = $this->printData($request, $periods, $scopeResolver, $svc);
        $filename = 'lead-response-report-' . \Illuminate\Support\Str::slug($data['scopeLabel']) . '-' . now()->format('Y-m-d') . '.pdf';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('lead-response-report.print', $data)->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    private function printData(Request $request, PeriodResolver $periods, BuyersReportScopeResolver $scopeResolver, LeadResponseService $svc): array
    {
        $data = $this->build($request, $periods, $scopeResolver, $svc, withComparison: true);
        $data['branding'] = \App\Models\Agency::publicBrandingFor($data['scope']->agencyId);
        $data['generatedAt'] = now();

        return $data;
    }

    /**
     * Scope comes ONLY from the resolver; the cohort is the agents inside that scope; the figures are the one
     * calculation over that cohort.
     */
    private function build(Request $request, PeriodResolver $periods, BuyersReportScopeResolver $scopeResolver, LeadResponseService $svc, bool $withComparison = false): array
    {
        $requestedLevel = $request->filled('scope') ? (string) $request->query('scope') : null;
        $requestedBranchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $requestedUserId = $request->filled('user_id') ? (int) $request->query('user_id') : null;

        $scope = $scopeResolver->resolve($request->user(), $requestedLevel, $requestedBranchId, $requestedUserId);
        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $agents = app(HierarchyResolver::class)->agents(new PerformanceScope($scope->agencyId, $scope->branchId, $scope->userId));
        $userIds = $agents->pluck('id')->map(fn ($i) => (int) $i)->all();

        $scopeLabelShort = match ($scope->level) {
            BuyersReportScope::LEVEL_OWN => 'You',
            BuyersReportScope::LEVEL_BRANCH => $scope->branchId
                ? (string) (DB::table('branches')->where('id', $scope->branchId)->value('name') ?? 'Branch')
                : 'Branch',
            default => 'Company',
        };
        $scopeLabel = match ($scope->level) {
            BuyersReportScope::LEVEL_OWN => (string) (DB::table('users')->where('id', $scope->userId)->value('name') ?? 'Agent'),
            BuyersReportScope::LEVEL_BRANCH => $scopeLabelShort,
            default => 'Whole agency',
        };

        $current = $svc->report($scope->agencyId, $period, $userIds);
        [$comparison, $comparisonMeta, $compareMode] = $withComparison
            ? $this->resolveComparison($request, $periods, $svc, $scope->agencyId, $period, $userIds, $current)
            : [null, null, 'off'];

        return [
            'compareMode'     => $compareMode,
            'comparison'      => $comparison,
            'comparisonMeta'  => $comparisonMeta,
            'scope'           => $scope,
            'scopeLabel'      => $scopeLabel,
            'scopeLabelShort' => $scopeLabelShort,
            'period'          => $period,
            'preset'          => $preset,
            'periodLabel'     => $period->label,
            'userIds'         => $userIds,
            'agentRows'       => $agents->map(fn ($a) => ['user_id' => (int) $a->id, 'name' => $a->name])->values()->all(),
            'leadResponse'    => $current,
        ];
    }

    private function resolvePeriod(Request $request, PeriodResolver $periods): array
    {
        $preset = (string) $request->query('period', 'this_month');
        if (! in_array($preset, PeriodResolver::PRESETS, true)) {
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

    /**
     * Same wiring as the Buyers Report / Performance & ROI report (PeriodResolver::resolveComparison, same modes,
     * same meta + phrase), over the SAME already-clamped cohort — the comparison period can never reach a wider set
     * of agents than the current one. An invalid custom range fails soft to "off" with the message flashed.
     *
     * @return array{0: ?array, 1: ?array, 2: string}
     */
    private function resolveComparison(Request $request, PeriodResolver $periods, LeadResponseService $svc, int $agencyId, Period $period, array $userIds, array $current): array
    {
        $mode = (string) $request->query('compare', 'off');
        if (! in_array($mode, PeriodResolver::COMPARE_MODES, true)) {
            $mode = 'off';
        }

        try {
            $comparePeriod = $periods->resolveComparison($mode, $period, $request->query('compare_start'), $request->query('compare_end'));
        } catch (\InvalidArgumentException $e) {
            session()->flash('compare_error', $e->getMessage());

            return [null, null, 'off'];
        }

        if ($comparePeriod === null) {
            return [null, null, $mode];
        }

        $comparison = $svc->compare($current, $svc->report($agencyId, $comparePeriod, $userIds));

        return [$comparison, [
            'period'          => $comparePeriod->toArray(),
            'mode'            => $mode,
            'unequal_length'  => $period->lengthInDays() !== $comparePeriod->lengthInDays(),
            'period_days'     => $period->lengthInDays(),
            'comparison_days' => $comparePeriod->lengthInDays(),
            'phrase'          => match ($mode) {
                'previous'       => 'vs previous period',
                'same_last_year' => 'vs same period last year',
                'custom'         => 'vs ' . $comparePeriod->label,
                default          => 'vs comparison period',
            },
        ], $mode];
    }
}
