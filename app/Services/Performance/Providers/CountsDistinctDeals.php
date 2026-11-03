<?php

namespace App\Services\Performance\Providers;

use App\Services\Performance\DealStatusSelection;
use App\Services\Performance\Period;

/**
 * A deal-COUNT metric that can say WHICH deals it counted for each agent, so a branch or the company can
 * count a joint deal once instead of once per agent on it (the Deal Register lists it once).
 */
interface CountsDistinctDeals
{
    /**
     * @param  int[]  $userIds
     * @return array<int, array<string, true>>  uid => ["1:dealId"|"2:dealId" => true] (every requested uid present)
     */
    public function dealKeysByUser(array $userIds, Period $period, DealStatusSelection $statuses): array;
}
