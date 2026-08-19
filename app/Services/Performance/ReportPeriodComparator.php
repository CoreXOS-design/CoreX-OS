<?php

namespace App\Services\Performance;

/**
 * 2026-08-19 (Johan, period-comparison) — builds a SEPARATE, parallel
 * comparison structure from two raw AgencyPerformanceReportService::build()
 * outputs (current + comparison period). Deliberately does NOT mutate
 * $current's own company/branches/agents/deal_status shapes — those stay
 * exactly the raw-number shape they always were, so the existing deal-status
 * toggle math (statusQty()/statusValue()/statusCommission() in
 * index.blade.php) keeps working completely unchanged whether comparison is
 * on or off. The view reads this new structure ADDITIVELY, alongside the
 * unchanged primary data, to render delta/%/direction — never in place of it.
 *
 * See .ai/specs/at366-period-comparison.md §5.
 */
class ReportPeriodComparator
{
    public const STATUS_DIRECTIONS = [
        'pending'    => 'neutral',
        'granted'    => 'higher_is_better',
        'registered' => 'higher_is_better',
        'declined'   => 'lower_is_better',
    ];

    public function __construct(private readonly MetricProviderRegistry $registry) {}

    /** @return array{company: array, branches: array, agents: array} */
    public function build(array $current, array $previous): array
    {
        $directions = [];
        foreach ($this->registry->all() as $provider) {
            $directions[$provider->key()] = $provider->direction();
        }

        return [
            'company'  => $this->compareRow($current['company'], $previous['company'] ?? [], $directions),
            'branches' => $this->compareBranches($current['branches'], $previous['branches'] ?? [], $directions),
            'agents'   => $this->compareAgents($current['agents'], $previous['agents'] ?? [], $directions),
        ];
    }

    /** One flat row: {metric_key: value, ..., deal_status: {...}} → same keys, each wrapped in a PeriodComparison shape. */
    private function compareRow(array $curRow, array $prevRow, array $directions): array
    {
        $out = [];
        foreach ($directions as $key => $direction) {
            $out[$key] = PeriodComparison::compute(
                (float) ($curRow[$key] ?? 0),
                (float) ($prevRow[$key] ?? 0),
                $direction,
            );
        }
        $out['deal_status'] = $this->compareDealStatus($curRow['deal_status'] ?? [], $prevRow['deal_status'] ?? []);

        return $out;
    }

    private function compareDealStatus(array $curDs, array $prevDs): array
    {
        $empty = ['qty' => 0, 'value' => 0.0, 'commission' => 0.0];
        $out = [];
        foreach (self::STATUS_DIRECTIONS as $bucket => $direction) {
            $cur  = $curDs[$bucket]  ?? $empty;
            $prev = $prevDs[$bucket] ?? $empty;
            $out[$bucket] = [
                'qty'        => PeriodComparison::compute((float) $cur['qty'], (float) $prev['qty'], $direction),
                'value'      => PeriodComparison::compute((float) $cur['value'], (float) $prev['value'], $direction),
                'commission' => PeriodComparison::compute((float) $cur['commission'], (float) $prev['commission'], $direction),
            ];
        }

        return $out;
    }

    /**
     * Branch keys can differ between the two periods — an agent's point-in-time
     * branch attribution (BranchAttributionResolver) can resolve differently for
     * the current vs the comparison window (e.g. moved branches between them).
     * Union the keys rather than assuming they match.
     */
    private function compareBranches(array $curBranches, array $prevBranches, array $directions): array
    {
        $keys = array_unique(array_merge(array_keys($curBranches), array_keys($prevBranches)));
        $out = [];
        foreach ($keys as $key) {
            $curRow  = $curBranches[$key]  ?? null;
            $prevRow = $prevBranches[$key] ?? null;
            $out[$key] = [
                'label' => $curRow['label'] ?? $prevRow['label'] ?? (string) $key,
                'metrics' => $this->compareRow(
                    ($curRow['metrics'] ?? []) + ['deal_status' => $curRow['deal_status'] ?? []],
                    ($prevRow['metrics'] ?? []) + ['deal_status' => $prevRow['deal_status'] ?? []],
                    $directions,
                ),
            ];
        }

        return $out;
    }

    /**
     * The agent cohort is the SAME set of user_ids in both builds
     * (HierarchyResolver::agents() doesn't vary by period) — a lookup-merge
     * by user_id, not a full union, though coded defensively in case that
     * assumption is ever violated by a future change.
     */
    private function compareAgents(array $curAgents, array $prevAgents, array $directions): array
    {
        $prevByUser = [];
        foreach ($prevAgents as $a) {
            $prevByUser[$a['user_id']] = $a;
        }

        $out = [];
        foreach ($curAgents as $a) {
            $prevA = $prevByUser[$a['user_id']] ?? null;
            $out[] = [
                'user_id'      => $a['user_id'],
                'name'         => $a['name'],
                'branch_id'    => $a['branch_id'],
                'branch_label' => $a['branch_label'],
                'metrics'      => $this->compareRow(
                    $a['metrics'] + ['deal_status' => $a['deal_status'] ?? []],
                    ($prevA['metrics'] ?? []) + ['deal_status' => $prevA['deal_status'] ?? []],
                    $directions,
                ),
            ];
        }

        return $out;
    }
}
