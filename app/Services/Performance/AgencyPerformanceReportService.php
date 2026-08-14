<?php

namespace App\Services\Performance;

/**
 * AT-366 — the report read-model. Given a scope and a period it:
 *   1. resolves the agent cohort (owners excluded),
 *   2. runs every registered metric provider once over the cohort,
 *   3. rolls the per-agent vectors up to branch (null → "Unassigned") and company.
 *
 * Branch attribution is point-in-time (as of the period end) via
 * BranchAttributionResolver, so it becomes historically accurate as the
 * branch-move enabler accrues history.
 */
class AgencyPerformanceReportService
{
    public function __construct(
        private readonly HierarchyResolver $hierarchy,
        private readonly MetricProviderRegistry $registry,
        private readonly BranchAttributionResolver $branchAttribution,
        private readonly DealStatusBreakdownService $dealStatus,
    ) {}

    /** Empty {qty,value,commission} per status bucket (AT-366 §A frontend contract shape). */
    private function emptyDealStatus(): array
    {
        return [
            'pending'    => ['qty' => 0, 'value' => 0.0, 'commission' => 0.0],
            'granted'    => ['qty' => 0, 'value' => 0.0, 'commission' => 0.0],
            'registered' => ['qty' => 0, 'value' => 0.0, 'commission' => 0.0],
            'declined'   => ['qty' => 0, 'value' => 0.0, 'commission' => 0.0],
        ];
    }

    private function addDealStatus(array &$acc, array $ds): void
    {
        foreach (['pending', 'granted', 'registered', 'declined'] as $b) {
            $acc[$b]['qty']        += $ds[$b]['qty'];
            $acc[$b]['value']      += $ds[$b]['value'];
            $acc[$b]['commission'] += $ds[$b]['commission'];
        }
    }

    public function build(PerformanceScope $scope, Period $period): array
    {
        $agents    = $this->hierarchy->agents($scope);
        $userIds   = $agents->pluck('id')->map(fn ($i) => (int) $i)->all();
        $providers = $this->registry->all();

        // One grouped query per provider for the whole cohort.
        $valuesByKey = [];
        foreach ($providers as $provider) {
            $valuesByKey[$provider->key()] = $provider->forUsers($userIds, $period);
        }
        $metricMeta = array_map(fn ($p) => ['key' => $p->key(), 'label' => $p->label()], $providers);

        // AT-366 §A — per-agent deal status breakdown (qty+value per bucket) for the toggle bar.
        $dealBreak = $this->dealStatus->forUsers($userIds, $period, $scope->agencyId)['perAgent'];

        $branchNames = $this->hierarchy->branchNames($scope->agencyId);

        $agentRows = [];
        $branchAgg = [];
        $companyAgg = [];
        $companyDs  = $this->emptyDealStatus();

        foreach ($agents as $agent) {
            $uid = (int) $agent->id;

            $metrics = [];
            foreach ($providers as $provider) {
                $metrics[$provider->key()] = $valuesByKey[$provider->key()][$uid] ?? 0;
            }

            $currentBranch = $agent->branch_id !== null ? (int) $agent->branch_id : null;
            $branchId      = $this->branchAttribution->branchAt($uid, $period->end, $currentBranch);
            $branchKey     = $branchId !== null ? (string) $branchId : 'unassigned';
            $branchLabel   = $branchId !== null ? ($branchNames[$branchId] ?? ('Branch #' . $branchId)) : 'Unassigned';

            // AT-366 §A — this agent's deal status {qty,value} per bucket (from the breakdown service).
            $pa = $dealBreak[$uid] ?? null;
            $agentDs = $this->emptyDealStatus();
            if ($pa) {
                foreach (['pending', 'granted', 'registered', 'declined'] as $b) {
                    $agentDs[$b] = ['qty' => (int) $pa[$b]['count'], 'value' => (float) $pa[$b]['value'], 'commission' => (float) $pa[$b]['commission']];
                }
            }

            $agentRows[] = [
                'user_id'      => $uid,
                'name'         => $agent->name,
                'branch_id'    => $branchId,
                'branch_label' => $branchLabel,
                'metrics'      => $metrics,
                'deal_status'  => $agentDs,
            ];

            foreach ($metrics as $key => $value) {
                $branchAgg[$branchKey]['label']          = $branchLabel;
                $branchAgg[$branchKey]['metrics'][$key]  = ($branchAgg[$branchKey]['metrics'][$key] ?? 0) + $value;
                $companyAgg[$key]                        = ($companyAgg[$key] ?? 0) + $value;
            }
            if (!isset($branchAgg[$branchKey]['deal_status'])) {
                $branchAgg[$branchKey]['deal_status'] = $this->emptyDealStatus();
            }
            $this->addDealStatus($branchAgg[$branchKey]['deal_status'], $agentDs);
            $this->addDealStatus($companyDs, $agentDs);
        }

        $companyAgg['deal_status'] = $companyDs; // AT-366 §A company rollup row

        return [
            'period'   => $period->toArray(),
            'scope'    => [
                'agency_id' => $scope->agencyId,
                'branch_id' => $scope->branchId,
                'user_id'   => $scope->userId,
                'level'     => $scope->level(),
            ],
            'metrics'  => $metricMeta,
            'company'  => $companyAgg,
            'branches' => $branchAgg,
            'agents'   => $agentRows,
        ];
    }

    /**
     * AT-366-C — one agent's journey for the period, each metric paired with its
     * value in the equal-length preceding period (delta = trend). Returns
     * ['agent' => null] when the user is not an in-scope agency member (owners
     * excluded), so the caller can 404.
     */
    public function agentJourney(int $agencyId, int $userId, Period $period): array
    {
        $scope    = new PerformanceScope($agencyId, null, $userId);
        $current  = $this->build($scope, $period);
        $previous = $this->build($scope, $period->previous());

        $curAgent  = $current['agents'][0] ?? null;
        $prevAgent = $previous['agents'][0] ?? null;

        if ($curAgent === null) {
            return ['agent' => null];
        }

        $metrics = [];
        foreach ($current['metrics'] as $m) {
            $cur  = $curAgent['metrics'][$m['key']] ?? 0;
            $prev = $prevAgent['metrics'][$m['key']] ?? 0;
            $metrics[] = [
                'key'      => $m['key'],
                'label'    => $m['label'],
                'value'    => $cur,
                'previous' => $prev,
                'delta'    => $cur - $prev,
            ];
        }

        return [
            'agent' => [
                'user_id'      => $curAgent['user_id'],
                'name'         => $curAgent['name'],
                'branch_label' => $curAgent['branch_label'],
            ],
            'period'          => $current['period'],
            'previous_period' => $previous['period'],
            'metrics'         => $metrics,
        ];
    }

    /**
     * AT-366-D — one branch's drill-down: the branch's rolled-up 12 metrics paired
     * with the prior-period value (trend), plus the agents that roll into it.
     *
     * It composes the SAME company build ($scope = agency only) that the top-level
     * report renders, then reads the branch bucket out of it — so a branch's totals
     * are guaranteed to reconcile with the company rollup, and its agents are exactly
     * the ones point-in-time-attributed to it (not the current-branch membership,
     * which can differ after a move). $branchKey is a numeric branch id or the
     * 'unassigned' sentinel. Returns ['branch' => null] for an unknown branch.
     */
    public function branchJourney(int $agencyId, string $branchKey, Period $period): array
    {
        $scope    = new PerformanceScope($agencyId);
        $current  = $this->build($scope, $period);
        $previous = $this->build($scope, $period->previous());

        $branchNames = $this->hierarchy->branchNames($agencyId);

        // Resolve a label. An empty-but-valid branch still gets a name; a branch
        // that only exists as a point-in-time bucket (e.g. since-renamed) falls
        // back to the label the rollup already carried; anything else is a 404.
        if ($branchKey === 'unassigned') {
            $label = 'Unassigned';
        } elseif (ctype_digit($branchKey) && isset($branchNames[(int) $branchKey])) {
            $label = $branchNames[(int) $branchKey];
        } elseif (isset($current['branches'][$branchKey]['label'])) {
            $label = $current['branches'][$branchKey]['label'];
        } else {
            return ['branch' => null];
        }

        $curMetrics  = $current['branches'][$branchKey]['metrics'] ?? [];
        $prevMetrics = $previous['branches'][$branchKey]['metrics'] ?? [];

        $metrics = [];
        foreach ($current['metrics'] as $m) {
            $cur  = $curMetrics[$m['key']] ?? 0;
            $prev = $prevMetrics[$m['key']] ?? 0;
            $metrics[] = [
                'key'      => $m['key'],
                'label'    => $m['label'],
                'value'    => $cur,
                'previous' => $prev,
                'delta'    => $cur - $prev,
            ];
        }

        $targetBranchId = $branchKey === 'unassigned' ? null : (int) $branchKey;
        $agents = array_values(array_filter(
            $current['agents'],
            fn ($a) => $a['branch_id'] === $targetBranchId
        ));

        return [
            'branch' => [
                'key'   => $branchKey,
                'id'    => $targetBranchId,
                'label' => $label,
            ],
            'period'          => $current['period'],
            'previous_period' => $previous['period'],
            'metrics'         => $metrics,          // branch rollup, current vs prior
            'metric_meta'     => $current['metrics'], // headers for the agent table
            'agents'          => $agents,           // agents attributed to this branch
        ];
    }
}
