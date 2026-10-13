<?php

namespace App\Services\RoadToSold\Adapters;

use App\Models\User;
use App\Services\RoadToSold\Contracts\FollowUpSource;
use Carbon\CarbonInterface;

/** No follow-ups known. Used until lane 3's call log (rts_contact_outcomes) exists. */
final class NullFollowUpSource implements FollowUpSource
{
    public function dueOn(User $user, CarbonInterface $date, string $track): array
    {
        return [];
    }
}
