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
    /**
     * Johan (2026-08-20, live review): "Theres no ways to say - buyer / leads
     * - and I take it all tenants excluded here?" Answer from the data: NOT
     * excluded — contacts.contact_type_id resolves to messy composite labels
     * ("Buyer, Lead", "Lead, Tenant"), and of the is_buyer=1 cohort on real
     * data, ~28% are labelled "Lessee" (a rental-side role, not a buyer at
     * all). These are the real, data-supported buckets — a contact can match
     * more than one, since the labels combine.
     */
    public const TYPES = [
        'buyer'  => 'Buyer',
        'lead'   => 'Lead',
        'tenant' => 'Tenant',
    ];

    /** Shared with BuyersReportDrilldownService — the drilldown row filter
     *  must match the tile's condition exactly, not a re-derived copy. */
    public const TYPE_LIKE = [
        'buyer'  => ['%Buyer%'],
        'lead'   => ['%Lead%'],
        'tenant' => ['%Tenant%', '%Lessee%'],
    ];

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
     *
     * $type narrows 'buyers'/'buyers_added'/'lost'/'lost_value' to a
     * contact-type bucket (see TYPES) — those four are this class's own
     * direct queries, so the filter applies exactly, and the drilldown for
     * each uses the identical condition (never drifts from the tile).
     * Appointments/comms/buyers_won stay unfiltered (BuyerActivityService/
     * BuyersWonProvider don't carry a type dimension) — the view marks them
     * "all types" rather than silently imply they're narrowed too.
     */
    public function build(BuyersReportScope $scope, Period $period, ?string $type = null): array
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

        // Johan (2026-08-20, live review) — "You have a value that is 0 across
        // the board?" It is: every buyer_lost_records row for this cohort has
        // preapproval_amount_at_loss = NULL, never captured, and SUM(NULL)
        // silently reads as R0. A confident zero next to real losses is worse
        // than a blank, so the company/branch/agent rollups also carry WHETHER
        // any value was ever captured, and the auto-transitioned/real-reason
        // split (BuyerStateService::transitionTo() writes reason_code =
        // 'no_activity' for the system timeout, never a human decision) — most
        // "lost buyers" turn out to be housekeeping, not a business outcome,
        // and the report should say so rather than imply otherwise.
        // Johan (2026-08-20, lost-section redesign): "on lost the agent who
        // lost it is critical ... lost - real losses / auto losses." Real
        // losses ARE the business number; auto (reason_code=no_activity, a
        // system timeout) is housekeeping and must never be presented as
        // lost business. 'lost' is now UNCONDITIONALLY the real count —
        // never the auto+real total — and lost_value is unconditionally the
        // real-only sum, both regardless of the type filter, so the primary
        // tile number is always the honest one.
        $lostInsight = $this->lostInsight($userIds, $period, $rollup['agents'], $type);
        $rollup['company']['lost']                = $lostInsight['company']['real'];
        $rollup['company']['lost_value']          = $lostInsight['company']['value'];
        $rollup['company']['lost_value_captured'] = $lostInsight['company']['value_captured'];
        $rollup['company']['lost_auto']           = $lostInsight['company']['auto'];
        $rollup['company']['lost_real']           = $lostInsight['company']['real'];
        foreach ($rollup['branches'] as $key => &$b) {
            $b['metrics']['lost']                = $lostInsight['branches'][$key]['real'] ?? 0;
            $b['metrics']['lost_value']          = $lostInsight['branches'][$key]['value'] ?? 0;
            $b['metrics']['lost_value_captured'] = $lostInsight['branches'][$key]['value_captured'] ?? false;
            $b['metrics']['lost_auto']           = $lostInsight['branches'][$key]['auto'] ?? 0;
            $b['metrics']['lost_real']           = $lostInsight['branches'][$key]['real'] ?? 0;
        }
        unset($b);
        foreach ($rollup['agents'] as &$agentRow) {
            $uid = (int) $agentRow['user_id'];
            $agentRow['metrics']['lost']                = $lostInsight['agents'][$uid]['real'] ?? 0;
            $agentRow['metrics']['lost_value']          = $lostInsight['agents'][$uid]['value'] ?? 0;
            $agentRow['metrics']['lost_value_captured'] = $lostInsight['agents'][$uid]['value_captured'] ?? false;
            $agentRow['metrics']['lost_auto']           = $lostInsight['agents'][$uid]['auto'] ?? 0;
            $agentRow['metrics']['lost_real']           = $lostInsight['agents'][$uid]['real'] ?? 0;
        }
        unset($agentRow);

        // Johan (2026-08-20, live review) — "no ways to say buyer / leads".
        // 'buyers'/'buyers_added' are THIS class's own direct contact
        // queries, so a type filter applies to them exactly, and the
        // drilldown for each uses the identical join/condition — never
        // drifts from the tile. Appointments/comms/buyers_won stay on
        // BuyerActivityService/BuyersWonProvider's full is_buyer=1 cohort
        // (no type dimension there); the view marks those "all types" rather
        // than implying they're narrowed too. lost/lost_value are already
        // type-filtered above via $lostInsight, unconditionally.
        if ($type !== null) {
            $filtered = $this->typeFilteredHeldAndAdded($userIds, $scope->agencyId, $period, $type, $rollup['agents']);
            $rollup['company']['buyers']       = $filtered['company']['held'];
            $rollup['company']['buyers_added'] = $filtered['company']['added'];
            foreach ($rollup['branches'] as $key => &$b) {
                $b['metrics']['buyers']       = $filtered['branches'][$key]['held'] ?? 0;
                $b['metrics']['buyers_added'] = $filtered['branches'][$key]['added'] ?? 0;
            }
            unset($b);
            foreach ($rollup['agents'] as &$agentRow) {
                $uid = (int) $agentRow['user_id'];
                $agentRow['metrics']['buyers']       = $filtered['agents'][$uid]['held'] ?? 0;
                $agentRow['metrics']['buyers_added'] = $filtered['agents'][$uid]['added'] ?? 0;
            }
            unset($agentRow);
        }

        return $rollup;
    }

    /** @return \Illuminate\Database\Query\Builder */
    private function typeFilteredContacts(string $table, string $alias, ?string $type)
    {
        $query = DB::table("{$table} as {$alias}");
        if ($type !== null && isset(self::TYPE_LIKE[$type])) {
            $query->join('contact_types as ct', 'ct.id', '=', "{$alias}.contact_type_id")
                ->where(function ($q) use ($type) {
                    foreach (self::TYPE_LIKE[$type] as $pattern) {
                        $q->orWhere('ct.name', 'like', $pattern);
                    }
                });
        }

        return $query;
    }

    /**
     * @param  int[]  $userIds
     * @param  array<int, array{user_id:int, branch_id:?int}>  $agentRows
     * @return array{company: array{held:int,added:int}, branches: array, agents: array}
     */
    private function typeFilteredHeldAndAdded(array $userIds, int $agencyId, Period $period, string $type, array $agentRows): array
    {
        $branchOf = [];
        foreach ($agentRows as $a) {
            $branchOf[(int) $a['user_id']] = $a['branch_id'] !== null ? (string) $a['branch_id'] : 'unassigned';
        }

        $empty = ['held' => 0, 'added' => 0];
        $out = ['company' => $empty, 'branches' => [], 'agents' => []];

        $held = $this->typeFilteredContacts('contacts', 'c', $type)
            ->select('c.agent_id as uid', DB::raw('COUNT(*) as c'))
            ->where('c.agency_id', $agencyId)
            ->where('c.is_buyer', 1)
            ->whereNull('c.deleted_at')
            ->whereIn('c.agent_id', $userIds)
            ->groupBy('c.agent_id')
            ->pluck('c', 'uid');

        $added = $this->typeFilteredContacts('contacts', 'c', $type)
            ->select('c.agent_id as uid', DB::raw('COUNT(*) as c'))
            ->where('c.agency_id', $agencyId)
            ->where('c.is_buyer', 1)
            ->whereNull('c.deleted_at')
            ->whereIn('c.agent_id', $userIds)
            ->whereBetween('c.buyer_pipeline_entered_at', [$period->start, $period->end])
            ->groupBy('c.agent_id')
            ->pluck('c', 'uid');

        foreach ($userIds as $uid) {
            $branchKey = $branchOf[$uid] ?? 'unassigned';
            $row = ['held' => (int) ($held[$uid] ?? 0), 'added' => (int) ($added[$uid] ?? 0)];
            $out['agents'][$uid] = $row;
            $out['company']['held']  += $row['held'];
            $out['company']['added'] += $row['added'];
            $out['branches'][$branchKey] ??= $empty;
            $out['branches'][$branchKey]['held']  += $row['held'];
            $out['branches'][$branchKey]['added'] += $row['added'];
        }

        return $out;
    }

    /**
     * @param  int[]  $userIds
     * @param  array<int, array{user_id:int, branch_id:?int}>  $agentRows  from BuyerActivityService::rollup()
     * @return array{company: array, branches: array<string, array>, agents: array<int, array>}
     */
    private function lostInsight(array $userIds, Period $period, array $agentRows, ?string $type = null): array
    {
        $branchOf = [];
        foreach ($agentRows as $a) {
            $branchOf[(int) $a['user_id']] = $a['branch_id'] !== null ? (string) $a['branch_id'] : 'unassigned';
        }

        $empty = ['value_captured' => false, 'auto' => 0, 'real' => 0, 'value' => 0.0];
        $out = ['company' => $empty, 'branches' => [], 'agents' => []];

        if (empty($userIds)) {
            return $out;
        }

        $query = DB::table('buyer_lost_records as blr')
            ->select(['blr.agent_owner_user_id_at_loss', 'blr.reason_code', 'blr.preapproval_amount_at_loss'])
            ->whereIn('blr.agent_owner_user_id_at_loss', $userIds)
            ->whereNull('blr.recovered_at')
            ->whereBetween('blr.recorded_at', [$period->start, $period->end]);

        // buyer_lost_records has no contact_type_id of its own — the type
        // lives on the contact it was recorded against.
        if ($type !== null && isset(self::TYPE_LIKE[$type])) {
            $query->join('contacts as c', 'c.id', '=', 'blr.contact_id')
                ->join('contact_types as ct', 'ct.id', '=', 'c.contact_type_id')
                ->where(function ($q) use ($type) {
                    foreach (self::TYPE_LIKE[$type] as $pattern) {
                        $q->orWhere('ct.name', 'like', $pattern);
                    }
                });
        }

        $rows = $query->get();

        // Johan (2026-08-20, lost-section redesign): "Value lost: compute
        // from REAL losses only." An auto-transition is a system timeout,
        // never a captured pre-approval value in the first place — folding
        // it into the sum would understate value_captured's honesty just as
        // badly as the original R0 bug did.
        $tally = function (array $bucket, bool $isAuto, ?float $value): array {
            $bucket[$isAuto ? 'auto' : 'real']++;
            if (!$isAuto) {
                $bucket['value_captured'] = $bucket['value_captured'] || $value !== null;
                $bucket['value'] += $value ?? 0.0;
            }
            return $bucket;
        };

        foreach ($rows as $r) {
            $uid = (int) $r->agent_owner_user_id_at_loss;
            $branchKey = $branchOf[$uid] ?? 'unassigned';
            $isAuto = $r->reason_code === 'no_activity';
            $value = $r->preapproval_amount_at_loss !== null ? (float) $r->preapproval_amount_at_loss : null;

            $out['company'] = $tally($out['company'], $isAuto, $value);
            $out['branches'][$branchKey] = $tally($out['branches'][$branchKey] ?? $empty, $isAuto, $value);
            $out['agents'][$uid] = $tally($out['agents'][$uid] ?? $empty, $isAuto, $value);
        }

        return $out;
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
    public function needsAttention(BuyersReportScope $scope, int $limit = 50, ?string $type = null): array
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
        $buyers = $this->typeFilteredContacts('contacts', 'c', $type)
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
                'no_feedback'   => $this->viewingsWithNoFeedback($userIds, $limit, $type),
                'recent_losses' => $this->recentLosses($userIds, $limit, $type),
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

        // Longest-neglected first. 'parked' was left uncapped originally — on a
        // company-wide cohort that alone rendered 16+ rows inline; capped like
        // every other group now (Johan, 2026-08-20 live review: "a wall of
        // names" before any tile).
        usort($attention, fn ($a, $z) => ($z['days_in_state'] ?? 0) <=> ($a['days_in_state'] ?? 0));
        usort($parked, fn ($a, $z) => ($z['days_in_state'] ?? 0) <=> ($a['days_in_state'] ?? 0));

        return [
            'attention'     => array_slice($attention, 0, $limit),
            'parked'        => array_slice($parked, 0, $limit),
            'no_feedback'   => $this->viewingsWithNoFeedback($userIds, $limit, $type),
            'recent_losses' => $this->recentLosses($userIds, $limit, $type),
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
    private function viewingsWithNoFeedback(array $userIds, int $limit, ?string $type = null): array
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
        $contactNames = DB::table('contacts as c')
            ->leftJoin('contact_types as ct', 'ct.id', '=', 'c.contact_type_id')
            ->whereIn('c.id', $contactIds)
            ->get(['c.id', 'c.first_name', 'c.last_name', 'ct.name as type_name'])
            ->keyBy('id');

        $missing = $viewings->filter(fn ($v) => !$fed->has($v->event_id . ':' . $v->contact_id));
        if ($type !== null && isset(self::TYPE_LIKE[$type])) {
            $missing = $missing->filter(function ($v) use ($contactNames, $type) {
                $typeName = (string) ($contactNames->get((int) $v->contact_id)->type_name ?? '');
                foreach (self::TYPE_LIKE[$type] as $pattern) {
                    if (str_contains(strtolower($typeName), strtolower(trim($pattern, '%')))) {
                        return true;
                    }
                }
                return false;
            });
        }

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
    private function recentLosses(array $userIds, int $limit, ?string $type = null): array
    {
        $query = DB::table('buyer_lost_records as blr')
            ->leftJoin('contacts as c', 'c.id', '=', 'blr.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'blr.agent_owner_user_id_at_loss')
            ->select([
                'blr.contact_id', 'blr.reason_label', 'blr.reason_code',
                'blr.preapproval_amount_at_loss', 'blr.recorded_at',
                'c.first_name', 'c.last_name',
                'blr.agent_owner_user_id_at_loss as agent_id', 'u.name as agent_name',
            ])
            ->whereIn('blr.agent_owner_user_id_at_loss', $userIds)
            ->whereNull('blr.recovered_at');

        if ($type !== null && isset(self::TYPE_LIKE[$type])) {
            $query->join('contact_types as ct', 'ct.id', '=', 'c.contact_type_id')
                ->where(function ($q) use ($type) {
                    foreach (self::TYPE_LIKE[$type] as $pattern) {
                        $q->orWhere('ct.name', 'like', $pattern);
                    }
                });
        }

        $losses = $query->orderByDesc('blr.recorded_at')->limit($limit)->get();

        if ($losses->isEmpty()) {
            return [];
        }

        // Johan (2026-08-20, live review): "Where are comments added on lost
        // leads?" — a lost buyer's note is the only place the real story
        // lives. One buyer can have several notes; only the latest is shown.
        // Self-join on (contact_id, MAX(created_at)) rather than a tuple-IN,
        // which the query builder can't portably bind.
        $contactIds = $losses->pluck('contact_id')->unique()->map(fn ($i) => (int) $i)->all();
        $latest = DB::table('contact_notes')
            ->select('contact_id', DB::raw('MAX(created_at) as latest_at'))
            ->whereIn('contact_id', $contactIds)
            ->whereNull('deleted_at')
            ->groupBy('contact_id');

        $latestNotes = DB::table('contact_notes as cn')
            ->joinSub($latest, 'latest', function ($j) {
                $j->on('cn.contact_id', '=', 'latest.contact_id')
                    ->on('cn.created_at', '=', 'latest.latest_at');
            })
            ->pluck('cn.body', 'cn.contact_id');

        return $losses->map(fn ($l) => [
            'contact_id'      => (int) $l->contact_id,
            'name'            => trim(($l->first_name ?? '') . ' ' . ($l->last_name ?? '')) ?: 'Unnamed buyer',
            'agent_id'        => (int) $l->agent_id,
            'agent_name'      => $l->agent_name ?? 'Unassigned',
            'reason'          => $l->reason_label ?: ($l->reason_code ?: 'Unspecified'),
            'value'           => (float) $l->preapproval_amount_at_loss,
            'value_captured'  => $l->preapproval_amount_at_loss !== null,
            'recorded_at'     => $l->recorded_at,
            'latest_note'     => $latestNotes[$l->contact_id] ?? null,
        ])->all();
    }

    private function greatest(?string $a, ?string $b): ?string
    {
        if ($a === null) return $b;
        if ($b === null) return $a;
        return strcmp($a, $b) >= 0 ? $a : $b;
    }
}
