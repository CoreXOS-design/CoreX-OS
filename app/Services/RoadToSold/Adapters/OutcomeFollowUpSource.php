<?php

namespace App\Services\RoadToSold\Adapters;

use App\Models\User;
use App\Services\RoadToSold\Contracts\FollowUp;
use App\Services\RoadToSold\Contracts\FollowUpSource;
use App\Services\RoadToSold\OutcomeService;
use Carbon\CarbonInterface;

/**
 * AT-454 (lane 3) - the real answer to lane 2's FollowUpSource contract: the agent's current
 * outcome on each property whose return date has arrived (overdue ones carry forward).
 */
final class OutcomeFollowUpSource implements FollowUpSource
{
    public function __construct(private readonly OutcomeService $outcomes)
    {
    }

    public function dueOn(User $user, CarbonInterface $date, string $track): array
    {
        return $this->outcomes->followUpsDue($user, $date, $track)
            ->map(fn ($o) => new FollowUp(
                trackedPropertyId: (int) $o->tracked_property_id,
                prospectingListingId: $o->prospecting_listing_id ? (int) $o->prospecting_listing_id : null,
                propertyId: $o->property_id ? (int) $o->property_id : null,
                reason: 'Follow-up due: ' . $o->outcome_label . ' (' . $o->next_action_at->format('j M') . ')',
            ))
            ->all();
    }
}
