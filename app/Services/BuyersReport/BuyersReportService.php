<?php

namespace App\Services\BuyersReport;

use App\Services\Performance\BranchAttributionResolver;
use App\Services\Performance\BuyerActivityService;
use App\Services\Performance\HierarchyResolver;
use App\Services\Performance\Period;
use App\Services\Performance\PerformanceScope;
use App\Services\Performance\Providers\BuyersWonProvider;
use Illuminate\Support\Facades\DB;

/**
 * Buyers Report — the read-model. Composes EXISTING, unmodified services
 * (BuyerActivityService for the 6 core metrics it already computes and rolls
 * up correctly; HierarchyResolver/BranchAttributionResolver for the same
 * agent -> branch -> company bucketing the ROI report uses) with the new
 * pieces this report needs (buyers won, the Needs Attention list).
 *
 * Deliberately does NOT reimplement buyer-state computation, activity
 * logging, or lost-record recording — those stay owned by BuyerStateService
 * and friends. This class only READS what already exists and shapes it for
 * the report.
 *
 * $scope MUST come from BuyersReportScopeResolver::resolve() — already
 * validated against the viewer's own ceiling. This class trusts it
 * completely and does no further permission checking itself.
 */
class BuyersReportService
{
    public function __construct(
        private readonly HierarchyResolver $hierarchy,
        private readonly BuyerActivityService $buyerActivity,
        private readonly BuyersWonProvider $wonProvider,
    ) {}

    /**
     * Company/branch/agent rollup for the period — BuyerActivityService's 6
     * metrics plus buyers_won, in the identical {metrics,company,branches,agents}
     * shape so the view (and ReportPeriodComparator, reused unmodified for
     * period comparison) can treat this exactly like the ROI report's data.
     */
    public function build(BuyersReportScope $scope, Period $period): array
    {
        $perfScope = new PerformanceScope($scope->agencyId, $scope->branchId, $scope->userId);
        $rollup    = $this->buyerActivity->rollup($perfScope, $period);

        $agents  = $this->hierarchy->agents($perfScope);
        $userIds = $agents->pluck('id')->map(fn ($i) => (int) $i)->all();
        $won     = $this->wonProvider->forUsers($userIds, $period);

        $companyWon = 0;
        $branchWon  = [];

        foreach ($rollup['agents'] as &$agentRow) {
            $uid = (int) $agentRow['user_id'];
            $w   = $won[$uid] ?? 0;
            $agentRow['metrics']['buyers_won'] = $w;
            $companyWon += $w;

            $branchKey = $agentRow['branch_id'] !== null ? (string) $agentRow['branch_id'] : 'unassigned';
            $branchWon[$branchKey] = ($branchWon[$branchKey] ?? 0) + $w;
        }
        unset($agentRow);

        $rollup['company']['buyers_won'] = $companyWon;
        foreach ($rollup['branches'] as $key => &$b) {
            $b['metrics']['buyers_won'] = $branchWon[$key] ?? 0;
        }
        unset($b);

        $rollup['metrics'][] = ['key' => 'buyers_won', 'label' => 'Buyers won', 'currency' => false, 'direction' => 'higher_is_better'];

        return $rollup;
    }

    /**
     * The action list at the top of the report — problems first, longest-
     * neglected first. Three groups, per the layout sketch: cold/lost buyers
     * needing attention, buyers parked on purpose (shown separately, not
     * mixed into the worry list), and recent losses with reason + value.
     *
     * One grouped query for the cohort, not a per-agent loop — same
     * discipline every provider in this codebase already follows.
     */
    public function needsAttention(BuyersReportScope $scope, int $limit = 50): array
    {
        $perfScope = new PerformanceScope($scope->agencyId, $scope->branchId, $scope->userId);
        $agents    = $this->hierarchy->agents($perfScope);
        $userIds   = $agents->pluck('id')->map(fn ($i) => (int) $i)->all();

        if (empty($userIds)) {
            return ['attention' => [], 'parked' => [], 'recent_losses' => []];
        }

        // Cold/lost buyers, with their most recent state-entry (for days-in-state)
        // and whether the LATEST transition was a manual placement (AT-74) —
        // a manual placement is shown separately, not mixed into the worry list.
        $buyers = DB::table('contacts as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.agent_id')
            ->select([
                'c.id', 'c.first_name', 'c.last_name', 'c.agent_id', 'c.branch_id',
                'c.buyer_state', 'c.last_activity_at', 'c.last_contacted_at',
                'u.name as agent_name',
            ])
            ->where('c.agency_id', $scope->agencyId)
            ->where('c.is_buyer', 1)
            ->whereNull('c.deleted_at')
            ->whereIn('c.agent_id', $userIds)
            ->whereIn('c.buyer_state', ['cold', 'lost'])
            ->get();

        if ($buyers->isEmpty()) {
            return ['attention' => [], 'parked' => [], 'recent_losses' => $this->recentLosses($userIds, $limit)];
        }

        $buyerIds = $buyers->pluck('id')->map(fn ($i) => (int) $i)->all();

        // Latest transition per buyer -> when they entered their CURRENT state,
        // and whether that entry was a manual placement.
        $latest = DB::table('buyer_state_transitions')
            ->select('contact_id', 'to_state', 'reason', DB::raw('MAX(occurred_at) as entered_at'))
            ->whereIn('contact_id', $buyerIds)
            ->groupBy('contact_id', 'to_state', 'reason')
            ->get()
            ->groupBy('contact_id');

        $now = now();
        $attention = [];
        $parked = [];

        foreach ($buyers as $b) {
            $rows = $latest->get((int) $b->id, collect());
            // The row matching this buyer's CURRENT state, most recently entered.
            $currentStateRows = $rows->where('to_state', $b->buyer_state)->sortByDesc('entered_at');
            $enteredAt = $currentStateRows->first()->entered_at ?? null;
            $isManual  = $currentStateRows->first()->reason === 'manual_override';

            $daysInState = $enteredAt ? (int) abs(\Carbon\CarbonImmutable::parse($enteredAt)->diffInDays($now)) : null;

            $row = [
                'contact_id'     => (int) $b->id,
                'name'           => trim(($b->first_name ?? '') . ' ' . ($b->last_name ?? '')) ?: 'Unnamed buyer',
                'agent_id'       => (int) $b->agent_id,
                'agent_name'     => $b->agent_name ?? 'Unassigned',
                'state'          => $b->buyer_state,
                'days_in_state'  => $daysInState,
                'last_worked_at' => $this->greatest($b->last_contacted_at, $b->last_activity_at),
            ];

            if ($isManual) {
                $parked[] = $row;
            } else {
                $attention[] = $row;
            }
        }

        // Longest-neglected first.
        usort($attention, fn ($a, $z) => ($z['days_in_state'] ?? 0) <=> ($a['days_in_state'] ?? 0));

        return [
            'attention'      => array_slice($attention, 0, $limit),
            'parked'         => $parked,
            'recent_losses'  => $this->recentLosses($userIds, $limit),
        ];
    }

    /** Recent losses across the cohort, with reason + pre-approval value lost, newest first. */
    private function recentLosses(array $userIds, int $limit): array
    {
        return DB::table('buyer_lost_records as blr')
            ->leftJoin('contacts as c', 'c.id', '=', 'blr.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'blr.agent_owner_user_id_at_loss')
            ->select([
                'blr.contact_id', 'blr.reason_label', 'blr.reason_code',
                'blr.preapproval_amount_at_loss', 'blr.recorded_at',
                'c.first_name', 'c.last_name',
                'blr.agent_owner_user_id_at_loss as agent_id', 'u.name as agent_name',
            ])
            ->whereIn('blr.agent_owner_user_id_at_loss', $userIds)
            ->whereNull('blr.recovered_at')
            ->orderByDesc('blr.recorded_at')
            ->limit($limit)
            ->get()
            ->map(fn ($l) => [
                'contact_id'  => (int) $l->contact_id,
                'name'        => trim(($l->first_name ?? '') . ' ' . ($l->last_name ?? '')) ?: 'Unnamed buyer',
                'agent_id'    => (int) $l->agent_id,
                'agent_name'  => $l->agent_name ?? 'Unassigned',
                'reason'      => $l->reason_label ?: ($l->reason_code ?: 'Unspecified'),
                'value'       => (float) $l->preapproval_amount_at_loss,
                'recorded_at' => $l->recorded_at,
            ])
            ->all();
    }

    private function greatest(?string $a, ?string $b): ?string
    {
        if ($a === null) return $b;
        if ($b === null) return $a;
        return strcmp($a, $b) >= 0 ? $a : $b;
    }
}
