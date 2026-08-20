<?php

namespace App\Http\Controllers\BuyersReport;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BuyersReport\BuyersReportDrilldownService;
use App\Services\BuyersReport\BuyersReportScope;
use App\Services\BuyersReport\BuyersReportScopeResolver;
use App\Services\BuyersReport\BuyersReportService;
use App\Services\BuyersReport\DemandAnalysisService;
use App\Services\BuyersReport\PipelineStateService;
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
        $type  = $this->resolveType($request);

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $report = $service->build($scope, $period, $type);
        $attention = $service->needsAttention($scope, 50, $type);

        [$comparison, $comparisonMeta, $compareMode] = $this->resolveComparison($request, $periods, $service, $scope, $period, $report, $type);

        return view('buyers-report.index', array_merge([
            'scope'      => $scope,
            'report'     => $report,
            'attention'  => $attention,
            'preset'     => $preset,
            'presets'    => PeriodResolver::PRESETS,
            'type'       => $type,
            'types'      => BuyersReportService::TYPES,
            'compareMode'    => $compareMode,
            'compareModes'   => PeriodResolver::COMPARE_MODES,
            'comparison'     => $comparison,
            'comparisonMeta' => $comparisonMeta,
        ], $this->sectionBData($scope, $period)));
    }

    /**
     * "What buyers do we have now" (Johan, 2026-08-20) -- the snapshot
     * section shared by index/agent/branch: pipeline-state counts (must
     * equal the pipeline board's own stateCounts() for the same scope --
     * see PipelineStateService's docblock), the held-vs-pipeline
     * reconciliation for the existing period tiles, and the demand-
     * analysis facets/coverage the type-tick + price-slider panel needs
     * on first render (the live filter itself is a separate AJAX call,
     * see demand()).
     */
    private function sectionBData(BuyersReportScope $scope, Period $period): array
    {
        $pipelineStates = app(PipelineStateService::class);
        $demand = app(DemandAnalysisService::class);

        $perfScope = new PerformanceScope($scope->agencyId, $scope->branchId, $scope->userId);
        $reportUserIds = app(HierarchyResolver::class)->agents($perfScope)->pluck('id')->map(fn ($i) => (int) $i)->all();

        return [
            'pipelineSnapshot' => $pipelineStates->snapshot($scope),
            'pipelineMovement' => $pipelineStates->movement($scope, $period),
            'heldVsPipeline'   => $pipelineStates->explainHeldVsSnapshotGap($scope, $reportUserIds),
            'demandFacets'     => $demand->facets($scope),
            'demandCoverage'   => $demand->coverage($scope),
        ];
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
        $type  = $this->resolveType($request);

        [$period] = $this->resolvePeriod($request, $periods);

        $metric = (string) $request->query('metric', '');
        abort_unless($metric === 'pipeline_state' || in_array($metric, BuyersReportDrilldownService::METRICS, true), 422, 'Unknown metric.');

        if ($metric === 'pipeline_state') {
            return $this->pipelineStateDrilldown($request, $scope, $period);
        }

        $perfScope = new PerformanceScope($scope->agencyId, $scope->branchId, $scope->userId);
        $agents    = $hierarchy->agents($perfScope);
        $userIds   = $agents->pluck('id')->map(fn ($i) => (int) $i)->all();

        $agentFilterName = null;
        $agentId = null;
        if ($request->filled('agent_id')) {
            $agentId = (int) $request->query('agent_id');
            if (in_array($agentId, $userIds, true)) {
                $userIds = [$agentId];
                $agentFilterName = $agents->firstWhere('id', $agentId)?->name;
            } else {
                $userIds = [];
                $agentId = null;
            }
        }

        // 'lost'/'lost_value' only — real|auto (Johan, 2026-08-20 lost-section
        // redesign). Anything else defaults to 'real' so a stray/missing
        // subtype never silently surfaces auto (housekeeping) losses as the
        // business number.
        $subtype = $request->query('subtype', 'real');
        $subtype = in_array($subtype, ['real', 'auto'], true) ? $subtype : 'real';
        // A specific agent_id (e.g. clicked from the by-agent table) already
        // identifies who — skip the per-agent summary and go straight to
        // their buyer list. Without one, default to the summary so the
        // agent attribution is never skipped (Johan: "the agent who lost it
        // is critical ... do not lose it").
        $level = $request->query('level');
        if (!in_array($level, ['agents', 'buyers'], true)) {
            $level = $agentId !== null ? 'buyers' : 'agents';
        }

        $res = $drill->rows($metric, $userIds, $period, $scope->agencyId, $type, $subtype, $level, $agentId);

        return response()->json([
            'title'     => $this->drilldownTitle($metric, $scope, $period, $res['count'], $agentFilterName, $subtype),
            'total'     => $res['count'],
            'columns'   => $drill->columns($metric, in_array($metric, ['lost', 'lost_value'], true) ? $level : null),
            'rows'      => $res['rows'],
            'truncated' => $res['truncated'],
            'level'     => in_array($metric, ['lost', 'lost_value'], true) ? $level : null,
            'subtype'   => in_array($metric, ['lost', 'lost_value'], true) ? $subtype : null,
        ]);
    }

    private function drilldownTitle(string $metric, BuyersReportScope $scope, $period, int $total, ?string $agentFilterName, ?string $subtype = null): string
    {
        $noun = [
            'buyers' => 'buyers held', 'buyers_added' => 'buyers added', 'buyers_won' => 'buyers won',
            'appointments' => 'appointments', 'comms_email' => 'emails', 'comms_whatsapp' => 'WhatsApps',
            'lost' => 'buyers lost', 'lost_value' => 'buyers lost',
        ][$metric] ?? $metric;

        if (in_array($metric, ['lost', 'lost_value'], true) && $subtype !== null) {
            $noun = $subtype === 'auto' ? 'auto losses (no activity)' : 'real losses';
        }

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
     * "What buyers do we have now" pipeline-state drill: state -> per-agent
     * summary -> that agent's actual buyers, right now (no period — this is
     * the snapshot, not the movement, section). Deliberately does NOT reuse
     * the HierarchyResolver-derived $userIds the rest of drilldown() uses —
     * that list is narrower than the pipeline board's own visibility (see
     * PipelineStateService's docblock) and using it here would silently
     * hide agents the board itself shows, defeating the whole reconciliation
     * this feature exists for. Authorization is instead the scope's own
     * own/branch/agency ceiling, checked directly against `users`.
     */
    private function pipelineStateDrilldown(Request $request, BuyersReportScope $scope, Period $period)
    {
        $state = $request->query('subtype');
        $state = ($state === null || $state === 'no_state') ? null : (string) $state;
        if ($state !== null && !array_key_exists($state, \App\Services\BuyersReport\PipelineStateService::STATES)) {
            abort(422, 'Unknown pipeline state.');
        }

        $level = $request->query('level') === 'buyers' ? 'buyers' : 'agents';
        $agentId = $request->filled('agent_id') ? (int) $request->query('agent_id') : null;

        $service = app(\App\Services\BuyersReport\PipelineStateService::class);
        $agentFilterName = null;

        if ($level === 'buyers' && $agentId !== null) {
            abort_unless($this->authorizePipelineStateAgent($scope, $agentId), 404);
            $agentFilterName = $agentId === \App\Services\BuyersReport\PipelineStateService::AGENT_UNASSIGNED
                ? 'Unassigned'
                : DB::table('users')->where('id', $agentId)->value('name');
            $res = $service->buyersForAgentInState($agentId, $state);
            $columns = [
                ['key' => 'name', 'label' => 'Buyer', 'align' => 'left'],
                ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
                ['key' => 'days_in_state', 'label' => 'Days in state', 'align' => 'right'],
                ['key' => 'last_worked', 'label' => 'Last worked', 'align' => 'left', 'format' => 'date'],
            ];
        } else {
            $level = 'agents';
            $res = $service->agentSummaryForState($scope, $state);
            $columns = [
                ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
                ['key' => 'count', 'label' => 'Buyers', 'align' => 'right'],
            ];
        }

        $stateLabel = $state === null ? 'No state recorded' : \App\Services\BuyersReport\PipelineStateService::STATES[$state];
        $who = $agentFilterName ?? match ($scope->level) {
            BuyersReportScope::LEVEL_OWN => 'You',
            BuyersReportScope::LEVEL_BRANCH => $scope->branchId
                ? (string) (DB::table('branches')->where('id', $scope->branchId)->value('name') ?? 'Branch')
                : 'Branch',
            default => 'Company',
        };

        return response()->json([
            'title'     => "{$res['count']} {$stateLabel} — {$who} · right now",
            'total'     => $res['count'],
            'columns'   => $columns,
            'rows'      => $res['rows'],
            'truncated' => false,
            'level'     => $level,
            'subtype'   => $state ?? 'no_state',
        ]);
    }

    private function authorizePipelineStateAgent(BuyersReportScope $scope, int $agentId): bool
    {
        // Unassigned (agent_id IS NULL) buyers only ever surface at agency
        // scope — BuyerPipelineScope's own/branch filters are agent_id
        // IN(...)/= comparisons, which SQL NULL never satisfies, so an
        // own/branch-scoped summary can never legitimately contain this
        // sentinel in the first place.
        if ($agentId === \App\Services\BuyersReport\PipelineStateService::AGENT_UNASSIGNED) {
            return $scope->level === BuyersReportScope::LEVEL_AGENCY;
        }
        if ($scope->level === BuyersReportScope::LEVEL_OWN) {
            return $agentId === $scope->userId;
        }
        if ($scope->level === BuyersReportScope::LEVEL_BRANCH) {
            if ($scope->branchId === null) {
                return $agentId === $scope->userId;
            }

            return DB::table('users')->where('id', $agentId)->where('branch_id', $scope->branchId)->whereNull('deleted_at')->exists();
        }

        return DB::table('users')->where('id', $agentId)->where('agency_id', $scope->agencyId)->exists();
    }

    /**
     * Demand analysis live filter (Johan, 2026-08-20): property type ticks
     * (multi-select, OR) + a price range slider, overlap matching on both
     * axes. Returns the SAME {count, rows} shape every drilldown uses, so
     * the panel's live count and its list can never disagree (they come
     * from one response, not two separate queries).
     */
    public function demand(Request $request, BuyersReportScopeResolver $scopeResolver, DemandAnalysisService $demand)
    {
        $user = $request->user();
        $requestedLevel = $request->filled('scope') ? (string) $request->query('scope') : null;
        $requestedBranchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $requestedUserId = $request->filled('user_id') ? (int) $request->query('user_id') : null;
        $scope = $scopeResolver->resolve($user, $requestedLevel, $requestedBranchId, $requestedUserId);

        $types = array_values(array_filter((array) $request->query('types', [])));
        $priceMin = $request->filled('price_min') ? (int) $request->query('price_min') : null;
        $priceMax = $request->filled('price_max') ? (int) $request->query('price_max') : null;

        $res = $demand->filter($scope, $types, $priceMin, $priceMax);

        return response()->json($res);
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
        $type  = $this->resolveType($request);

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $report = $service->build($scope, $period, $type);
        $attention = $service->needsAttention($scope, 50, $type);
        $detail = $buyerActivity->agentDetail($agencyId, (int) $user->id, $period);

        [$comparison, $comparisonMeta, $compareMode] = $this->resolveComparison($request, $periods, $service, $scope, $period, $report, $type);

        return view('buyers-report.agent', array_merge([
            'targetUser' => $user,
            'scope'      => $scope,
            'report'     => $report,
            'attention'  => $attention,
            'detail'     => $detail,
            'preset'     => $preset,
            'presets'    => PeriodResolver::PRESETS,
            'type'       => $type,
            'types'      => BuyersReportService::TYPES,
            'compareMode'    => $compareMode,
            'compareModes'   => PeriodResolver::COMPARE_MODES,
            'comparison'     => $comparison,
            'comparisonMeta' => $comparisonMeta,
        ], $this->sectionBData($scope, $period)));
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
        $type  = $this->resolveType($request);

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $report = $service->build($scope, $period, $type);
        $attention = $service->needsAttention($scope, 50, $type);
        $branchName = (string) (DB::table('branches')->where('id', $branchId)->value('name') ?? 'Branch');

        [$comparison, $comparisonMeta, $compareMode] = $this->resolveComparison($request, $periods, $service, $scope, $period, $report, $type);

        return view('buyers-report.branch', array_merge([
            'branchName' => $branchName,
            'scope'      => $scope,
            'report'     => $report,
            'attention'  => $attention,
            'preset'     => $preset,
            'presets'    => PeriodResolver::PRESETS,
            'type'       => $type,
            'types'      => BuyersReportService::TYPES,
            'compareMode'    => $compareMode,
            'compareModes'   => PeriodResolver::COMPARE_MODES,
            'comparison'     => $comparison,
            'comparisonMeta' => $comparisonMeta,
        ], $this->sectionBData($scope, $period)));
    }

    /**
     * Johan (2026-08-20, live review): "no ways to say buyer / leads".
     * Real, data-supported buckets only — see BuyersReportService::TYPES.
     * null = no filter, the existing/unchanged behaviour.
     */
    private function resolveType(Request $request): ?string
    {
        $type = $request->filled('type') ? (string) $request->query('type') : null;

        return ($type !== null && array_key_exists($type, BuyersReportService::TYPES)) ? $type : null;
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

    /**
     * 2026-08-20 (period comparison, second pass) — mirrors
     * AgencyPerformanceReportController's resolveComparisonPeriod() +
     * compareBuyerRollup() wiring exactly: "comparison off" is byte-
     * identical to before this existed ($report is built once regardless);
     * an invalid custom range fails soft to comparison-off with the error
     * flashed, never a 500. $scope is ALREADY the resolved/clamped scope —
     * this never re-derives it, so the comparison period can never be
     * computed against a wider cohort than the current period was.
     *
     * @return array{0: ?array, 1: ?array, 2: string} [comparison, comparisonMeta, compareMode]
     */
    private function resolveComparison(Request $request, PeriodResolver $periods, BuyersReportService $service, BuyersReportScope $scope, Period $period, array $current, ?string $type = null): array
    {
        $mode = (string) $request->query('compare', 'off');
        if (!in_array($mode, PeriodResolver::COMPARE_MODES, true)) {
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

        $previous = $service->build($scope, $comparePeriod, $type);
        $comparison = $service->compare($current, $previous);

        $comparisonMeta = [
            'period'          => $comparePeriod->toArray(),
            'mode'            => $mode,
            'unequal_length'  => $period->lengthInDays() !== $comparePeriod->lengthInDays(),
            'period_days'     => $period->lengthInDays(),
            'comparison_days' => $comparePeriod->lengthInDays(),
            'phrase' => match ($mode) {
                'previous'       => 'vs previous period',
                'same_last_year' => 'vs same period last year',
                'custom'         => 'vs ' . $comparePeriod->label,
                default          => 'vs comparison period',
            },
        ];

        return [$comparison, $comparisonMeta, $mode];
    }
}
