<?php

namespace App\Services\Performance;

use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AT-366-E (Q7) — period-scoped per-agent buyer-activity engine.
 *
 * This is NOT a threshold alarm. It presents, for a selected period, the facts an
 * owner reads to judge how an agent is working their buyers (spec §4):
 *   - how many buyers the agent currently holds,
 *   - activity on those buyers in the period — appointments + comms (email & WhatsApp),
 *   - when the pipeline was last worked,
 *   - where each buyer sits in the pipeline and for how long,
 *   - buyers lost in the period, their reasons, and the pre-approval value lost.
 *
 * rollup() mirrors AgencyPerformanceReportService::build() (same cohort, same
 * point-in-time branch bucketing) so the aggregate buyer metrics reconcile up the
 * agent → branch → company hierarchy. agentDetail() adds the per-buyer breakdown.
 *
 * DATA-FLOOR CAVEATS (spec §4): comms capture is match-first / drop-on-no-match and
 * only exists where a WhatsApp device / email mailbox is provisioned; per-agent email
 * attribution is null for shared mailboxes. So the counts are a captured-and-matched
 * FLOOR, not a true activity count — surfaced as a note on the report.
 */
class BuyerActivityService
{
    private const APPOINTMENT_CATEGORIES = ['viewing', 'listing_presentation', 'property_evaluation', 'meeting'];

    /** @return array{key:string,label:string,currency:bool}[] */
    public const METRICS = [
        ['key' => 'buyers',         'label' => 'Buyers held',           'currency' => false],
        ['key' => 'appointments',   'label' => 'Appointments (buyers)', 'currency' => false],
        ['key' => 'comms_email',    'label' => 'Emails (buyers)',       'currency' => false],
        ['key' => 'comms_whatsapp', 'label' => 'WhatsApps (buyers)',    'currency' => false],
        ['key' => 'lost',           'label' => 'Buyers lost',           'currency' => false],
        ['key' => 'lost_value',     'label' => 'Lost value (R)',        'currency' => true],
    ];

    public function __construct(
        private readonly HierarchyResolver $hierarchy,
        private readonly BranchAttributionResolver $branchAttribution,
    ) {}

    /**
     * Company / branch / per-agent aggregate buyer-activity for the scope + period,
     * bucketed identically to the main report so the numbers roll up cleanly.
     */
    public function rollup(PerformanceScope $scope, Period $period): array
    {
        $agents  = $this->hierarchy->agents($scope);
        $userIds = $agents->pluck('id')->map(fn ($i) => (int) $i)->all();

        $perUser     = $this->metricsByUser($scope->agencyId, $userIds, $period);
        $branchNames = $this->hierarchy->branchNames($scope->agencyId);

        $agentRows  = [];
        $branchAgg  = [];
        $companyAgg = $this->zeroVector();

        foreach ($agents as $agent) {
            $uid     = (int) $agent->id;
            $metrics = $perUser[$uid] ?? $this->zeroVector();

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

            $branchAgg[$branchKey]['label'] ??= $branchLabel;
            foreach ($metrics as $key => $value) {
                $branchAgg[$branchKey]['metrics'][$key] = ($branchAgg[$branchKey]['metrics'][$key] ?? 0) + $value;
                $companyAgg[$key] += $value;
            }
        }

        return [
            'metrics'  => self::METRICS,
            'company'  => $companyAgg,
            'branches' => $branchAgg,
            'agents'   => $agentRows,
        ];
    }

    /**
     * One agent's full buyer-activity picture for the period: the aggregate vector,
     * a per-buyer breakdown (state + time-in-stage + last worked + in-period
     * appointments/comms), the pipeline-stage summary, and the lost list + reasons.
     */
    public function agentDetail(int $agencyId, int $userId, Period $period): array
    {
        $agg = ($this->metricsByUser($agencyId, [$userId], $period)[$userId]) ?? $this->zeroVector();

        // The agent's current buyers.
        $buyers = DB::table('contacts')
            ->where('agency_id', $agencyId)
            ->where('agent_id', $userId)
            ->where('is_buyer', 1)
            ->whereNull('deleted_at')
            ->get(['id', 'first_name', 'last_name', 'buyer_state', 'buyer_pipeline_entered_at', 'last_contacted_at', 'last_activity_at']);

        $buyerIds = $buyers->pluck('id')->map(fn ($i) => (int) $i)->all();

        $apptByContact  = $this->appointmentsByContact($userId, $buyerIds, $period);
        $commsByContact = $this->commsByContact($userId, $buyerIds, $period);
        $stateEnteredAt = $this->latestStateEntryByContact($buyerIds);

        $now  = CarbonImmutable::now();
        $rows = [];
        $stageSummary = [];

        foreach ($buyers as $b) {
            $state       = $b->buyer_state ?: 'unclassified';
            $lastWorked  = $this->greatest($b->last_contacted_at, $b->last_activity_at);
            $enteredAt   = $b->buyer_pipeline_entered_at ? CarbonImmutable::parse($b->buyer_pipeline_entered_at) : null;
            $stateSince  = $stateEnteredAt[(int) $b->id][$state] ?? null;

            $rows[] = [
                'contact_id'       => (int) $b->id,
                'name'             => trim(($b->first_name ?? '') . ' ' . ($b->last_name ?? '')) ?: 'Unnamed buyer',
                'state'            => $state,
                'days_in_state'    => $stateSince ? CarbonImmutable::parse($stateSince)->diffInDays($now) : null,
                'days_in_pipeline' => $enteredAt ? $enteredAt->diffInDays($now) : null,
                'last_worked_at'   => $lastWorked,
                'appointments'     => $apptByContact[(int) $b->id] ?? 0,
                'comms'            => $commsByContact[(int) $b->id] ?? 0,
            ];

            $stageSummary[$state] = ($stageSummary[$state] ?? 0) + 1;
        }

        // Sort the pipeline summary by count desc for a stable, readable display.
        arsort($stageSummary);

        // Buyers lost in the period (still lost — not recovered), with reasons + value.
        $lostRows = DB::table('buyer_lost_records as blr')
            ->leftJoin('contacts as c', 'c.id', '=', 'blr.contact_id')
            ->where('blr.agency_id', $agencyId)
            ->where('blr.agent_owner_user_id_at_loss', $userId)
            ->whereNull('blr.recovered_at')
            ->whereBetween('blr.recorded_at', [$period->start, $period->end])
            ->orderByDesc('blr.recorded_at')
            ->get([
                'blr.contact_id', 'blr.reason_label', 'blr.reason_code',
                'blr.preapproval_amount_at_loss', 'blr.buyer_state_at_loss',
                'blr.days_in_pipeline_at_loss', 'blr.recorded_at',
                'c.first_name', 'c.last_name',
            ]);

        $lostReasons = [];
        foreach ($lostRows as $l) {
            $label = $l->reason_label ?: ($l->reason_code ?: 'Unspecified');
            $lostReasons[$label]['count'] = ($lostReasons[$label]['count'] ?? 0) + 1;
            $lostReasons[$label]['value'] = ($lostReasons[$label]['value'] ?? 0) + (float) $l->preapproval_amount_at_loss;
        }
        arsort($lostReasons);

        $lost = $lostRows->map(fn ($l) => [
            'contact_id'       => (int) $l->contact_id,
            'name'             => trim(($l->first_name ?? '') . ' ' . ($l->last_name ?? '')) ?: 'Unnamed buyer',
            'reason'           => $l->reason_label ?: ($l->reason_code ?: 'Unspecified'),
            'value'            => (float) $l->preapproval_amount_at_loss,
            'state_at_loss'    => $l->buyer_state_at_loss,
            'days_in_pipeline' => $l->days_in_pipeline_at_loss !== null ? (int) $l->days_in_pipeline_at_loss : null,
            'recorded_at'      => $l->recorded_at,
        ])->all();

        // "Last worked" across the whole book = the most recent buyer touch.
        $lastWorkedAt = collect($rows)->pluck('last_worked_at')->filter()->max();

        return [
            'metrics'        => self::METRICS,
            'aggregate'      => $agg,
            'last_worked_at' => $lastWorkedAt,
            'buyers'         => $rows,
            'stage_summary'  => $stageSummary,
            'lost'           => $lost,
            'lost_reasons'   => $lostReasons,
        ];
    }

    /**
     * The per-user aggregate vector for a cohort — one grouped query per metric.
     *
     * @param  int[]  $userIds
     * @return array<int, array<string,int|float>>  uid => vector
     */
    private function metricsByUser(int $agencyId, array $userIds, Period $period): array
    {
        $out = [];
        foreach ($userIds as $uid) {
            $out[(int) $uid] = $this->zeroVector();
        }
        if (empty($userIds)) {
            return $out;
        }

        // Buyers currently held (snapshot).
        $buyers = DB::table('contacts')
            ->select('agent_id as uid', DB::raw('COUNT(*) as c'))
            ->where('agency_id', $agencyId)
            ->where('is_buyer', 1)
            ->whereNull('deleted_at')
            ->whereIn('agent_id', $userIds)
            ->groupBy('agent_id')
            ->pluck('c', 'uid');
        foreach ($buyers as $uid => $c) {
            $out[(int) $uid]['buyers'] = (int) $c;
        }

        // Appointments on the agent's buyers in the period.
        $appts = DB::table('calendar_events as ce')
            ->join('contacts as c', 'c.id', '=', 'ce.contact_id')
            ->select('ce.user_id as uid', DB::raw('COUNT(*) as c'))
            ->where('c.agency_id', $agencyId)
            ->where('c.is_buyer', 1)
            ->whereNull('c.deleted_at')
            ->whereColumn('c.agent_id', 'ce.user_id')
            ->whereIn('ce.category', self::APPOINTMENT_CATEGORIES)
            ->whereIn('ce.user_id', $userIds)
            ->whereBetween('ce.event_date', [$period->start, $period->end])
            ->groupBy('ce.user_id')
            ->pluck('c', 'uid');
        foreach ($appts as $uid => $c) {
            $out[(int) $uid]['appointments'] = (int) $c;
        }

        // Comms (email + WhatsApp) ingested on the agent's buyers in the period.
        // COUNT(DISTINCT) so a comm linked to several contacts isn't double-counted.
        $comms = DB::table('communications as m')
            ->join('communication_links as cl', function ($j) {
                $j->on('cl.communication_id', '=', 'm.id')
                  ->where('cl.linkable_type', '=', Contact::class)
                  ->whereNull('cl.deleted_at');
            })
            ->join('contacts as c', 'c.id', '=', 'cl.linkable_id')
            ->select('m.owner_user_id as uid', 'm.channel', DB::raw('COUNT(DISTINCT m.id) as c'))
            ->where('m.agency_id', $agencyId)
            ->where('c.is_buyer', 1)
            ->whereNull('c.deleted_at')
            ->whereColumn('c.agent_id', 'm.owner_user_id')
            ->whereIn('m.owner_user_id', $userIds)
            ->whereIn('m.channel', ['email', 'whatsapp'])
            ->whereBetween('m.occurred_at', [$period->start, $period->end])
            ->groupBy('m.owner_user_id', 'm.channel')
            ->get();
        foreach ($comms as $row) {
            $key = $row->channel === 'whatsapp' ? 'comms_whatsapp' : 'comms_email';
            $out[(int) $row->uid][$key] = (int) $row->c;
        }

        // Buyers lost in the period (still lost), count + pre-approval value.
        $lost = DB::table('buyer_lost_records')
            ->select('agent_owner_user_id_at_loss as uid', DB::raw('COUNT(*) as c'), DB::raw('COALESCE(SUM(preapproval_amount_at_loss),0) as v'))
            ->where('agency_id', $agencyId)
            ->whereIn('agent_owner_user_id_at_loss', $userIds)
            ->whereNull('recovered_at')
            ->whereBetween('recorded_at', [$period->start, $period->end])
            ->groupBy('agent_owner_user_id_at_loss')
            ->get();
        foreach ($lost as $row) {
            $out[(int) $row->uid]['lost']       = (int) $row->c;
            $out[(int) $row->uid]['lost_value'] = (float) $row->v;
        }

        return $out;
    }

    /** @param int[] $buyerIds @return array<int,int> contact_id => appt count */
    private function appointmentsByContact(int $userId, array $buyerIds, Period $period): array
    {
        if (empty($buyerIds)) {
            return [];
        }

        return DB::table('calendar_events')
            ->select('contact_id', DB::raw('COUNT(*) as c'))
            ->where('user_id', $userId)
            ->whereIn('category', self::APPOINTMENT_CATEGORIES)
            ->whereIn('contact_id', $buyerIds)
            ->whereBetween('event_date', [$period->start, $period->end])
            ->groupBy('contact_id')
            ->pluck('c', 'contact_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** @param int[] $buyerIds @return array<int,int> contact_id => distinct comm count */
    private function commsByContact(int $userId, array $buyerIds, Period $period): array
    {
        if (empty($buyerIds)) {
            return [];
        }

        return DB::table('communications as m')
            ->join('communication_links as cl', function ($j) {
                $j->on('cl.communication_id', '=', 'm.id')
                  ->where('cl.linkable_type', '=', Contact::class)
                  ->whereNull('cl.deleted_at');
            })
            ->select('cl.linkable_id as contact_id', DB::raw('COUNT(DISTINCT m.id) as c'))
            ->where('m.owner_user_id', $userId)
            ->whereIn('cl.linkable_id', $buyerIds)
            ->whereIn('m.channel', ['email', 'whatsapp'])
            ->whereBetween('m.occurred_at', [$period->start, $period->end])
            ->groupBy('cl.linkable_id')
            ->pluck('c', 'contact_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * The most recent moment each buyer entered each state, so time-in-current-state
     * = now − entry into the buyer's present state.
     *
     * @param  int[]  $buyerIds
     * @return array<int, array<string,string>>  contact_id => [state => occurred_at]
     */
    private function latestStateEntryByContact(array $buyerIds): array
    {
        if (empty($buyerIds)) {
            return [];
        }

        $rows = DB::table('buyer_state_transitions')
            ->select('contact_id', 'to_state', DB::raw('MAX(occurred_at) as entered_at'))
            ->whereIn('contact_id', $buyerIds)
            ->groupBy('contact_id', 'to_state')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->contact_id][$r->to_state] = $r->entered_at;
        }

        return $out;
    }

    /** @return array<string,int|float> */
    private function zeroVector(): array
    {
        $v = [];
        foreach (self::METRICS as $m) {
            $v[$m['key']] = $m['currency'] ? 0.0 : 0;
        }
        return $v;
    }

    /** The later of two nullable timestamps (as a string), or null. */
    private function greatest(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        return strcmp($a, $b) >= 0 ? $a : $b;
    }
}
