<?php

namespace App\Services\Performance\Providers;

use App\Services\BuyerStateService;
use App\Services\Performance\Period;
use Illuminate\Support\Facades\DB;

/**
 * Buyers report — buyers who converted (buyer_state -> 'won') in the period,
 * attributed to the buyer's ASSIGNED agent (contacts.agent_id), not whoever
 * triggered the transition (buyer_state_transitions.triggered_by_user_id can be
 * null — markWon() is often system-triggered via property-link, not an agent
 * action — same AT-159 rule BuyerActivityService already follows: owner =
 * assigned agent, never the capturer/triggerer).
 */
class BuyersWonProvider implements MetricProvider
{
    public function key(): string { return 'buyers_won'; }

    public function label(): string { return 'Buyers won'; }

    public function direction(): string { return 'higher_is_better'; }

    public function forUsers(array $userIds, Period $period): array
    {
        $out = array_fill_keys($userIds, 0);
        if (empty($userIds)) {
            return $out;
        }

        $rows = DB::table('buyer_state_transitions as t')
            ->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->select('c.agent_id as uid', DB::raw('COUNT(*) as c'))
            ->where('t.to_state', BuyerStateService::WON)
            ->whereIn('c.agent_id', $userIds)
            ->whereNull('c.deleted_at')
            ->whereBetween('t.occurred_at', [$period->start, $period->end])
            ->groupBy('c.agent_id')
            ->pluck('c', 'uid');

        foreach ($rows as $uid => $c) {
            $out[(int) $uid] = (int) $c;
        }

        return $out;
    }
}
