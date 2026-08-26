<?php

namespace App\Services\BuyersReport;

use App\Models\Contact;
use App\Services\CommandCenter\BuyerPipelineScope;
use App\Services\Performance\Period;
use Illuminate\Support\Facades\DB;

/**
 * "What buyers do we have now" -- the pipeline-state spine Johan asked for
 * (2026-08-20): "this report especially should marry up to the buyers
 * pipeline". snapshot() MUST use the exact same query shape the pipeline
 * board itself uses (Contact::buyers() + BuyerPipelineScope, both go
 * through Eloquent so Layer 1 AgencyScope + Layer 2 ContactScope apply
 * identically) -- this is what makes the report's state counts genuinely
 * equal the board's kanban badges, not just resemble them. Do NOT route
 * this through HierarchyResolver -- that list is deliberately narrower
 * (is_active, agencyMembers(), and on some environments
 * show_in_performance_reports) and is a DIFFERENT question ("who counts
 * toward performance rollups") from "who is on the pipeline board".
 */
class PipelineStateService
{
    /** Confirmed from real data (contacts.buyer_state) -- the exact 5 the
     *  pipeline board itself hardcodes (4 kanban columns + the separate
     *  Won/Success section). A 6th, real but unlabelled bucket exists:
     *  buyer_state IS NULL -- surfaced as 'no_state', never dropped. */
    public const STATES = [
        'new'  => 'New',
        'warm' => 'Warm',
        'cold' => 'Cold',
        'lost' => 'Lost',
        'won'  => 'Won',
    ];

    /** Sentinel user_id for buyers with no assigned agent (contacts.agent_id
     *  IS NULL) in the per-agent breakdowns -- never a real user id. */
    public const AGENT_UNASSIGNED = -1;

    /**
     * Point-in-time snapshot -- current buyer_state counts for the scope,
     * matching BuyerPipelineController::stateCounts() exactly.
     *
     * @return array{states: array<string,int>, no_state:int, total:int}
     */
    public function snapshot(BuyersReportScope $scope): array
    {
        $query = Contact::buyers();
        BuyerPipelineScope::apply($query, $scope->level, $scope->userId, $scope->branchId);

        $rows = $query->selectRaw('buyer_state, count(*) as cnt')->groupBy('buyer_state')->get();

        $states = array_fill_keys(array_keys(self::STATES), 0);
        $noState = 0;
        foreach ($rows as $r) {
            if ($r->buyer_state !== null && array_key_exists($r->buyer_state, $states)) {
                $states[$r->buyer_state] = (int) $r->cnt;
            } else {
                $noState += (int) $r->cnt;
            }
        }

        return [
            'states'   => $states,
            'no_state' => $noState,
            'total'    => array_sum($states) + $noState,
        ];
    }

    /**
     * Same snapshot, broken out per agent -- the summary level of the
     * state drilldown (state -> per-agent -> buyers).
     *
     * @return array<int, array{user_id:int, name:string, states: array<string,int>, no_state:int}>
     */
    public function snapshotByAgent(BuyersReportScope $scope): array
    {
        // LEFT JOIN, not inner -- a buyer with no assigned agent (agent_id
        // IS NULL) is real (15 of 64 "warm" buyers on qa1) and must still be
        // counted, bucketed under the AGENT_UNASSIGNED sentinel below rather
        // than silently dropped. An inner join here previously undercounted
        // this level against snapshot()'s own total -- caught by this
        // feature's own reconciliation check against the pipeline board.
        $query = Contact::buyers()->leftJoin('users', 'users.id', '=', 'contacts.agent_id');
        BuyerPipelineScope::apply($query, $scope->level, $scope->userId, $scope->branchId);

        $rows = $query
            ->selectRaw('contacts.agent_id as uid, users.name as agent_name, contacts.buyer_state, count(*) as cnt')
            ->groupBy('contacts.agent_id', 'users.name', 'contacts.buyer_state')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $uid = $r->uid === null ? self::AGENT_UNASSIGNED : (int) $r->uid;
            if (!isset($out[$uid])) {
                $out[$uid] = ['user_id' => $uid, 'name' => $r->agent_name ?? 'Unassigned', 'states' => array_fill_keys(array_keys(self::STATES), 0), 'no_state' => 0];
            }
            if ($r->buyer_state !== null && array_key_exists($r->buyer_state, self::STATES)) {
                $out[$uid]['states'][$r->buyer_state] = (int) $r->cnt;
            } else {
                $out[$uid]['no_state'] += (int) $r->cnt;
            }
        }

        return array_values($out);
    }

    /**
     * Movement WITHIN the period -- how many buyers entered/left each state.
     * A distinct question from snapshot() (point-in-time vs period) -- see
     * BuyersReportService::build()'s docblock on why both are shown, never
     * one silently standing in for the other.
     *
     * @return array<string, array{entered:int, left:int}>
     */
    public function movement(BuyersReportScope $scope, Period $period): array
    {
        $entered = $this->transitionCounts($scope, $period, 'to_state');
        $left = $this->transitionCounts($scope, $period, 'from_state');

        $out = [];
        foreach (self::STATES as $key => $label) {
            $out[$key] = ['entered' => $entered[$key] ?? 0, 'left' => $left[$key] ?? 0];
        }

        return $out;
    }

    /** @return array<string,int> */
    private function transitionCounts(BuyersReportScope $scope, Period $period, string $column): array
    {
        $query = DB::table('buyer_state_transitions as bst')
            ->join('contacts', 'contacts.id', '=', 'bst.contact_id')
            ->whereBetween('bst.occurred_at', [$period->start, $period->end])
            ->whereNotNull("bst.$column");
        BuyerPipelineScope::apply($query, $scope->level, $scope->userId, $scope->branchId);

        return $query->selectRaw("bst.$column as state, count(*) as cnt")
            ->groupBy("bst.$column")
            ->pluck('cnt', 'state')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * Drilldown level 1 for a pipeline state -- per-agent counts, one state
     * (or the 'no_state' bucket when $state is null).
     *
     * @return array{count:int, rows:array[]}
     */
    public function agentSummaryForState(BuyersReportScope $scope, ?string $state): array
    {
        $byAgent = $this->snapshotByAgent($scope);
        $rows = [];
        $total = 0;
        foreach ($byAgent as $a) {
            $c = $state === null ? $a['no_state'] : ($a['states'][$state] ?? 0);
            if ($c > 0) {
                $rows[] = ['agent_id' => $a['user_id'], 'agent' => $a['name'], 'count' => $c];
                $total += $c;
            }
        }
        usort($rows, fn ($a, $z) => $z['count'] <=> $a['count']);

        return ['count' => $total, 'rows' => $rows];
    }

    /**
     * Drilldown level 2 -- the actual buyers one agent has in one state
     * right now. Same row shape BuyersReportDrilldownService::buyersHeld()
     * uses (name/agent/state/days_in_state/last_worked), scoped to a single
     * agent + state so it reconciles exactly with agentSummaryForState().
     *
     * @return array{count:int, rows:array[]}
     */
    public function buyersForAgentInState(int $agentId, ?string $state): array
    {
        $query = DB::table('contacts as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.agent_id')
            ->where('c.is_buyer', 1)
            ->whereNull('c.deleted_at');
        $agentId === self::AGENT_UNASSIGNED ? $query->whereNull('c.agent_id') : $query->where('c.agent_id', $agentId);
        $state === null ? $query->whereNull('c.buyer_state') : $query->where('c.buyer_state', $state);

        $total = (clone $query)->count();
        if ($total === 0) {
            return ['count' => 0, 'rows' => []];
        }

        $buyers = $query
            ->select(['c.id', 'c.first_name', 'c.last_name', 'c.buyer_state', 'c.last_activity_at', 'c.last_contacted_at', 'u.name as agent_name'])
            ->orderBy('c.first_name')
            ->limit(1000)
            ->get();

        $buyerIds = $buyers->pluck('id')->map(fn ($i) => (int) $i)->all();
        $latest = DB::table('buyer_state_transitions')
            ->select('contact_id', 'to_state', DB::raw('MAX(occurred_at) as entered_at'))
            ->whereIn('contact_id', $buyerIds)
            ->groupBy('contact_id', 'to_state')
            ->get()
            ->groupBy('contact_id');

        $now = now();
        $rows = $buyers->map(function ($b) use ($latest, $now) {
            $enteredAt = $latest->get((int) $b->id, collect())->firstWhere('to_state', $b->buyer_state)?->entered_at;
            $lastContacted = $b->last_contacted_at;
            $lastActivity = $b->last_activity_at;
            $lastWorked = $lastContacted === null ? $lastActivity : ($lastActivity === null ? $lastContacted : max($lastContacted, $lastActivity));

            return [
                'name'          => trim(($b->first_name ?? '') . ' ' . ($b->last_name ?? '')) ?: 'Unnamed buyer',
                'agent'         => $b->agent_name ?? 'Unassigned',
                'state'         => $b->buyer_state === null ? 'No state' : (self::STATES[$b->buyer_state] ?? ucfirst((string) $b->buyer_state)),
                'days_in_state' => $enteredAt ? (int) abs(\Carbon\CarbonImmutable::parse($enteredAt)->diffInDays($now)) : null,
                'last_worked'   => $lastWorked,
            ];
        })->values()->all();

        return ['count' => $total, 'rows' => $rows];
    }

    /**
     * "Buyers held" (report tile, HierarchyResolver-scoped) vs the pipeline
     * snapshot total for the SAME scope -- the reconciliation Johan asked
     * for on the existing tiles (2026-08-20): "If held != the sum of the
     * states, say why on the page itself." $reportUserIds is the SAME
     * HierarchyResolver-derived list BuyersReportService::build() already
     * uses for 'buyers held'; this method explains any gap against the
     * pipeline-scoped set by agent status, never leaves it unexplained.
     *
     * @param  int[]  $reportUserIds
     * @return array{report_held:int, pipeline_total:int, gap:int, reasons: array<string,int>}
     */
    public function explainHeldVsSnapshotGap(BuyersReportScope $scope, array $reportUserIds): array
    {
        $pipelineQuery = Contact::buyers();
        BuyerPipelineScope::apply($pipelineQuery, $scope->level, $scope->userId, $scope->branchId);
        $pipelineBuyers = $pipelineQuery->select('contacts.id', 'contacts.agent_id')->get();
        $pipelineTotal = $pipelineBuyers->count();

        $reportSet = array_flip($reportUserIds);
        $gapBuyers = $pipelineBuyers->filter(fn ($b) => $b->agent_id === null || !isset($reportSet[(int) $b->agent_id]));
        $gap = $gapBuyers->count();

        $reasons = ['unassigned' => 0, 'inactive_agent' => 0, 'owner_role_agent' => 0, 'report_excluded_agent' => 0];
        if ($gap > 0) {
            $gapAgentIds = $gapBuyers->pluck('agent_id')->filter()->unique()->values()->all();
            $agents = DB::table('users')->whereIn('id', $gapAgentIds)->get(['id', 'is_active', 'role']);
            $ownerNames = \App\Models\User::ownerRoleNames();
            $agentsById = $agents->keyBy('id');

            foreach ($gapBuyers as $b) {
                if ($b->agent_id === null) {
                    $reasons['unassigned']++;
                    continue;
                }
                $agent = $agentsById->get((int) $b->agent_id);
                if ($agent === null) {
                    $reasons['report_excluded_agent']++;
                } elseif (!$agent->is_active) {
                    $reasons['inactive_agent']++;
                } elseif (in_array($agent->role, $ownerNames, true)) {
                    $reasons['owner_role_agent']++;
                } else {
                    $reasons['report_excluded_agent']++;
                }
            }
        }

        return ['report_held' => $pipelineTotal - $gap, 'pipeline_total' => $pipelineTotal, 'gap' => $gap, 'reasons' => array_filter($reasons)];
    }
}
