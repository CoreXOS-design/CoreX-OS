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
    ) {}

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

        $branchNames = $this->hierarchy->branchNames($scope->agencyId);

        $agentRows = [];
        $branchAgg = [];
        $companyAgg = [];

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

            $agentRows[] = [
                'user_id'      => $uid,
                'name'         => $agent->name,
                'branch_id'    => $branchId,
                'branch_label' => $branchLabel,
                'metrics'      => $metrics,
            ];

            foreach ($metrics as $key => $value) {
                $branchAgg[$branchKey]['label']          = $branchLabel;
                $branchAgg[$branchKey]['metrics'][$key]  = ($branchAgg[$branchKey]['metrics'][$key] ?? 0) + $value;
                $companyAgg[$key]                        = ($companyAgg[$key] ?? 0) + $value;
            }
        }

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
}
