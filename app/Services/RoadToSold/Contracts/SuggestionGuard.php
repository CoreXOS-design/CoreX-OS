<?php

namespace App\Services\RoadToSold\Contracts;

use App\Models\User;

/**
 * "Never suggest" — the properties the list must not offer this agent (lane 3 owns the real answer:
 * holds, do-not-contact; all keyed on tracked_property_id).
 */
interface SuggestionGuard
{
    /**
     * Of these tracked property ids, the ones that must NOT be suggested to $user: held by another
     * agent, marked do-not-contact, or otherwise blocked for every agent.
     *
     * Also blocked for THIS agent: what their own latest outcome parked (a future return date, the wrong-number list, a booked valuation,
     * dropped after no-answers) - see HoldService::ownParkedIds(). $on is the day the list is for (default today).
     *
     * @param  int[]  $trackedPropertyIds
     * @return int[]  subset of the input
     */
    public function blockedFor(User $user, array $trackedPropertyIds, string $track, ?\Carbon\CarbonInterface $on = null): array;

    /**
     * Of these tracked property ids, the ones $user already holds (worked recently, still theirs):
     * they are not offered again as "new" — follow-ups bring them back when they are due.
     *
     * @param  int[]  $trackedPropertyIds
     * @return int[]  subset of the input
     */
    public function ownHeld(User $user, array $trackedPropertyIds, string $track): array;
}
