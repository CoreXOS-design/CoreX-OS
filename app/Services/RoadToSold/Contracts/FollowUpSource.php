<?php

namespace App\Services\RoadToSold\Contracts;

use App\Models\User;
use Carbon\CarbonInterface;

/** Follow-ups that are due (lane 3 owns the real answer: rts_contact_outcomes.next_action_at). */
interface FollowUpSource
{
    /**
     * Follow-ups due on or before $date for $user that have not been actioned — overdue ones carry
     * forward, so a follow-up due on a weekend still lands on Monday's list.
     *
     * @return FollowUp[]
     */
    public function dueOn(User $user, CarbonInterface $date, string $track): array;
}
