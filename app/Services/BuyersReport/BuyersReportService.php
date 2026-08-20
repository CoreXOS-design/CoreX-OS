<?php

namespace App\Services\BuyersReport;

use App\Services\Performance\BranchAttributionResolver;
use App\Services\Performance\BuyerActivityService;
use App\Services\Performance\HierarchyResolver;
use App\Services\Performance\Period;
use App\Services\Performance\PeriodComparison;
use App\Services\Performance\PerformanceScope;
use App\Services\Performance\Providers\BuyersAddedProvider;
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
        private readonly BuyersAddedProvider $addedProvider,
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

        // (metric key => uid => value), merged into the existing rollup shape.
        $extra = [
            'buyers_won'   => $this->wonProvider->forUsers($userIds, $period),
            'buyers_added' => $this->addedProvider->forUsers($userIds, $period),
        ];

        $companyExtra = array_fill_keys(array_keys($extra), 0);
        $branchExtra  = [];

        foreach ($rollup['agents'] as &$agentRow) {
            $uid = (int) $agentRow['user_id'];
            $branchKey = $agentRow['branch_id'] !== null ? (string) $agentRow['branch_id'] : 'unassigned';

            foreach ($extra as $key => $byUser) {
                $v = $byUser[$uid] ?? 0;
                $agentRow['metrics'][$key] = $v;
                $companyExtra[$key] += $v;
                $branchExtra[$branchKey][$key] = ($branchExtra[$branchKey][$key] ?? 0) + $v;
            }
        }
        unset($agentRow);

        foreach ($companyExtra as $key => $v) {
            $rollup['company'][$key] = $v;
        }
        foreach ($rollup['branches'] as $key => &$b) {
            foreach (array_keys($extra) as $mk) {
                $b['metrics'][$mk] = $branchExtra[$key][$mk] ?? 0;
            }
        }
        unset($b);

        $rollup['metrics'][] = ['key' => 'buyers_won', 'label' => 'Buyers won', 'currency' => false, 'direction' => 'higher_is_better'];
        $rollup['metrics'][] = ['key' => 'buyers_added', 'label' => 'Buyers added', 'currency' => false, 'direction' => 'higher_is_better'];

        return $rollup;
    }

    /**
     * Period-over-period deltas for company/branch/agent, mirroring
     * AgencyPerformanceReportController::compareBuyerRollup() but generic
     * over $current['metrics'] (already carries key/label/currency/direction
     * for every metric build() produces, including buyers_won/buyers_added,
     * so no separate METRICS const is needed here). $current and $previous
     * must both come from build() for the SAME scope, different periods.
     */
    public function compare(array $current, array $previous): array
    {
        $out = ['company' => [], 'branches' => [], 'agents' => []];

        foreach ($current['metrics'] as $m) {
            $out['company'][$m['key']] = PeriodComparison::compute(
                (float) ($current['company'][$m['key']] ?? 0),
                (float) ($previous['company'][$m['key']] ?? 0),
                $m['direction'],
            );
        }

        $branchKeys = array_unique(array_merge(array_keys($current['branches']), array_keys($previous['branches'])));
        foreach ($branchKeys as $key) {
            $curB  = $current['branches'][$key]['metrics']  ?? [];
            $prevB = $previous['branches'][$key]['metrics'] ?? [];
            $row = [];
            foreach ($current['metrics'] as $m) {
                $row[$m['key']] = PeriodComparison::compute((float) ($curB[$m['key']] ?? 0), (float) ($prevB[$m['key']] ?? 0), $m['direction']);
            }
            $out['branches'][$key] = [
                'label'   => $current['branches'][$key]['label'] ?? $previous['branches'][$key]['label'] ?? (string) $key,
                'metrics' => $row,
            ];
        }

        $prevAgentsByUser = [];
        foreach ($previous['agents'] as $a) {
            $prevAgentsByUser[$a['user_id']] = $a;
        }
        foreach ($current['agents'] as $a) {
            $prevA = $prevAgentsByUser[$a['user_id']] ?? ['metrics' => []];
            $row = [];
            foreach ($current['metrics'] as $m) {
                $row[$m['key']] = PeriodComparison::compute((float) ($a['metrics'][$m['key']] ?? 0), (float) ($prevA['metrics'][$m['key']] ?? 0), $m['direction']);
            }
            $out['agents'][] = ['user_id' => $a['user_id'], 'name' => $a['name'], 'metrics' => $row];
        }

        return $out;
    }

    /**
     * The action list at the top of the report — problems first, longest-
     * neglected first. Four groups: cold/lost buyers needing attention,
     * buyers parked on purpose (shown separately, not mixed into the worry
     * list), viewings held with no feedback captured (Johan's point 5 --
     * "has any feedback been provided ... that was taken out on
     * appointments?" -- a buyer taken out with nothing captured afterward is
     * exactly the gap he's trying to see), and recent losses with reason +
     * value.
     *
     * One grouped query per group for the cohort, not a per-agent loop --
     * same discipline every provider in this codebase already follows.
     */
    public function needsAttention(BuyersReportScope $scope, int $limit = 50): array
    {
        $perfScope = new PerformanceScope($scope->agencyId, $scope->branchId, $scope->userId);
        $agents    = $this->hierarchy->agents($perfScope);
        $userIds   = $agents->pluck('id')->map(fn ($i) => (int) $i)->all();

        if (empty($userIds)) {
            return ['attention' => [], 'parked' => [], 'no_feedback' => [], 'recent_losses' => []];
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
            return [
                'attention'     => [],
                'parked'        => [],
                'no_feedback'   => $this->viewingsWithNoFeedback($userIds, $limit),
                'recent_losses' => $this->recentLosses($userIds, $limit),
            ];
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
            'attention'     => array_slice($attention, 0, $limit),
            'parked'        => $parked,
            'no_feedback'   => $this->viewingsWithNoFeedback($userIds, $limit),
            'recent_losses' => $this->recentLosses($userIds, $limit),
        ];
    }

    /**
     * Viewings already held (event_date in the past) for a buyer, with no
     * calendar_event_feedback row captured. Checks BOTH ways a buyer can be
     * linked to a viewing: the single calendar_events.contact_id column, and
     * calendar_event_links (role=buyer_contact) for a multi-buyer tick-list
     * viewing -- the buyer tick list shipped this week writes there, not to
     * contact_id, for anyone past the first ticked buyer.
     */
    private function viewingsWithNoFeedback(array $userIds, int $limit): array
    {
        $directLinked = DB::table('calendar_events as ce')
            ->select('ce.id as event_id', 'ce.contact_id', 'ce.user_id as agent_id', 'ce.event_date', 'ce.title')
            ->where('ce.category', 'viewing')
            ->whereIn('ce.user_id', $userIds)
            ->whereNotNull('ce.contact_id')
            ->where('ce.event_date', '<', now())
            ->whereNull('ce.deleted_at');

        $tickListLinked = DB::table('calendar_events as ce')
            ->join('calendar_event_links as cel', function ($j) {
                $j->on('cel.calendar_event_id', '=', 'ce.id')
                    ->where('cel.linkable_type', '=', \App\Models\Contact::class)
                    ->where('cel.role', '=', 'buyer_contact')
                    ->whereNull('cel.deleted_at');
            })
            ->select('ce.id as event_id', 'cel.linkable_id as contact_id', 'ce.user_id as agent_id', 'ce.event_date', 'ce.title')
            ->where('ce.category', 'viewing')
            ->whereIn('ce.user_id', $userIds)
            ->where('ce.event_date', '<', now())
            ->whereNull('ce.deleted_at');

        $viewings = $directLinked->unionAll($tickListLinked)->get()
            ->unique(fn ($v) => $v->event_id . ':' . $v->contact_id);

        if ($viewings->isEmpty()) {
            return [];
        }

        $eventIds = $viewings->pluck('event_id')->unique()->map(fn ($i) => (int) $i)->all();
        $fed = DB::table('calendar_event_feedback')
            ->select('calendar_event_id', 'contact_id')
            ->whereIn('calendar_event_id', $eventIds)
            ->whereNull('deleted_at')
            ->get()
            ->map(fn ($f) => $f->calendar_event_id . ':' . $f->contact_id)
            ->flip();

        $agentNames = DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id');
        $contactIds = $viewings->pluck('contact_id')->unique()->map(fn ($i) => (int) $i)->all();
        $contactNames = DB::table('contacts')->whereIn('id', $contactIds)
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        $missing = $viewings->filter(fn ($v) => !$fed->has($v->event_id . ':' . $v->contact_id));

        return $missing->sortBy('event_date')->take($limit)->map(function ($v) use ($agentNames, $contactNames) {
            $contact = $contactNames->get((int) $v->contact_id);
            return [
                'event_id'   => (int) $v->event_id,
                'contact_id' => (int) $v->contact_id,
                'name'       => $contact ? (trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'Unnamed buyer') : 'Unnamed buyer',
                'agent_id'   => (int) $v->agent_id,
                'agent_name' => $agentNames[$v->agent_id] ?? 'Unassigned',
                'title'      => $v->title,
                'event_date' => $v->event_date,
                'days_ago'   => (int) abs(\Carbon\CarbonImmutable::parse($v->event_date)->diffInDays(now())),
            ];
        })->values()->all();
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
