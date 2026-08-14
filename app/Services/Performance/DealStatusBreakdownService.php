<?php

namespace App\Services\Performance;

use Illuminate\Support\Facades\DB;

/**
 * AT-366 (6) — DEAL breakdown by status bucket, per agent, DR1+DR2 deduped.
 *
 * Five buckets (superset "all" + four lifecycle states). Precedence mirrors the
 * canonical Deal model logic (Declined > Registered > Granted > Pending):
 *
 *   DR1 (deals.accepted_status):  D=Declined · (R OR registration_date)=Registered
 *                                 · (G OR granted_at)=Granted · else(P/null)=Pending
 *   DR2 (deals_v2.status):        declined=Declined · (registered OR actual_registration)=Registered
 *                                 · granted=Granted · else(active/…)=Pending
 *
 * Dedup: DR1 is canonical; only UNLINKED DR2 (legacy_deal_id NULL) is added.
 * Value = deal value (DR1 property_value / DR2 purchase_price); Commission =
 * deal commission (DR1 total_commission / DR2 commission_amount).
 *
 * Returns per user: bucket => ['count'=>int,'value'=>float,'commission'=>float]
 * for all five buckets. Company/branch rollups are computed from DISTINCT deals
 * (a co-agent deal counts once at rollup level; each agent still sees it in their row).
 */
class DealStatusBreakdownService
{
    public const BUCKETS = ['all', 'pending', 'granted', 'registered', 'declined'];

    public static function dr1Bucket(object $d): string
    {
        if (($d->accepted_status ?? null) === 'D') return 'declined';
        if (!empty($d->registration_date) || ($d->accepted_status ?? null) === 'R') return 'registered';
        if (!empty($d->granted_at) || ($d->accepted_status ?? null) === 'G') return 'granted';
        return 'pending';
    }

    public static function dr2Bucket(object $d): string
    {
        $s = $d->status ?? null;
        if ($s === 'declined') return 'declined';
        if ($s === 'registered' || !empty($d->actual_registration)) return 'registered';
        if ($s === 'granted') return 'granted';
        return 'pending';
    }

    private function emptyBuckets(): array
    {
        $z = ['count' => 0, 'value' => 0.0, 'commission' => 0.0];
        return array_fill_keys(self::BUCKETS, $z);
    }

    /**
     * @param int[] $userIds
     * @return array{perAgent: array<int,array>, distinct: array<string,array>}
     *   perAgent[uid][bucket] = {count,value,commission}; distinct[bucket] = rollup (each deal once).
     */
    public function forUsers(array $userIds, Period $period, int $agencyId): array
    {
        $perAgent = [];
        foreach ($userIds as $u) $perAgent[$u] = $this->emptyBuckets();
        $distinctSeen = [];                 // "1:id"/"2:id" => true  (rollup dedup)
        $distinct = $this->emptyBuckets();
        if (empty($userIds)) return ['perAgent' => $perAgent, 'distinct' => $distinct];

        $start = $period->start->toDateString();
        $end   = $period->end->toDateString();

        $add = function (array &$slot, string $bucket, float $val, float $comm) {
            foreach (['all', $bucket] as $b) {
                $slot[$b]['count']++; $slot[$b]['value'] += $val; $slot[$b]['commission'] += $comm;
            }
        };

        // ---- DR1 (canonical) ----
        $dr1 = DB::table('deals')->whereNull('deleted_at')->where('agency_id', $agencyId)
            ->whereNotNull('deal_date')->whereBetween('deal_date', [$start, $end])
            ->get(['id', 'managed_by_user_id', 'accepted_status', 'registration_date', 'granted_at', 'property_value', 'total_commission']);
        $pivot1 = DB::table('deal_user')->whereIn('user_id', $userIds)
            ->whereIn('deal_id', $dr1->pluck('id'))->get()->groupBy('deal_id');
        foreach ($dr1 as $d) {
            $bucket = self::dr1Bucket($d);
            $val = (float) ($d->property_value ?? 0); $comm = (float) ($d->total_commission ?? 0);
            $agents = [];
            if (in_array((int) $d->managed_by_user_id, $userIds, true)) $agents[(int) $d->managed_by_user_id] = true;
            foreach ($pivot1[$d->id] ?? [] as $p) $agents[(int) $p->user_id] = true;
            foreach (array_keys($agents) as $uid) $add($perAgent[$uid], $bucket, $val, $comm);
            if ($agents) { $key = '1:' . $d->id; if (empty($distinctSeen[$key])) { $distinctSeen[$key] = true; $add($distinct, $bucket, $val, $comm); } }
        }

        // ---- DR2 (unlinked only — dedup) ----
        $dr2 = DB::table('deals_v2')->whereNull('deleted_at')->where('agency_id', $agencyId)
            ->whereNull('legacy_deal_id')
            ->whereNotNull('offer_date')->whereBetween('offer_date', [$start, $end])
            ->get(['id', 'listing_agent_id', 'selling_agent_id', 'status', 'actual_registration', 'purchase_price', 'commission_amount']);
        $pivot2 = DB::table('deal_v2_agents')->whereIn('user_id', $userIds)
            ->whereIn('deal_id', $dr2->pluck('id'))->get()->groupBy('deal_id');
        foreach ($dr2 as $d) {
            $bucket = self::dr2Bucket($d);
            $val = (float) ($d->purchase_price ?? 0); $comm = (float) ($d->commission_amount ?? 0);
            $agents = [];
            foreach (['listing_agent_id', 'selling_agent_id'] as $c) if (in_array((int) $d->$c, $userIds, true)) $agents[(int) $d->$c] = true;
            foreach ($pivot2[$d->id] ?? [] as $p) $agents[(int) $p->user_id] = true;
            foreach (array_keys($agents) as $uid) $add($perAgent[$uid], $bucket, $val, $comm);
            if ($agents) { $key = '2:' . $d->id; if (empty($distinctSeen[$key])) { $distinctSeen[$key] = true; $add($distinct, $bucket, $val, $comm); } }
        }

        return ['perAgent' => $perAgent, 'distinct' => $distinct];
    }
}
