<?php

namespace App\Services\Performance\Providers;

use App\Services\Performance\DealStatusSelection;
use App\Services\Performance\Period;

/**
 * A metric derived from deals. The report hands it the deal-status ticks so it counts
 * only deals in the ticked buckets (same bucket rule as DealStatusBreakdownService);
 * forUsers() on the same class stays "every status" for any caller that has no ticks.
 */
interface DealStatusAware
{
    /**
     * @param  int[]  $userIds
     * @return array<int, int|float>  uid => value (every requested uid present, 0 if none)
     */
    public function forUsersWithStatuses(array $userIds, Period $period, DealStatusSelection $statuses): array;
}

