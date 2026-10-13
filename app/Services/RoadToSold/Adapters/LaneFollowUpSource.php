<?php

namespace App\Services\RoadToSold\Adapters;

use App\Models\User;
use App\Services\RoadToSold\Contracts\FollowUp;
use App\Services\RoadToSold\Contracts\FollowUpSource;
use App\Services\RoadToSold\OutcomeService;
use Carbon\CarbonInterface;

/**
 * FollowUpSource backed by lane 3's call log: OutcomeService::followUpsDue() — the current outcome on
 * each property the agent worked whose return date has arrived (overdue ones included, dropped and
 * never-again ones excluded).
 */
final class LaneFollowUpSource implements FollowUpSource
{
    public function dueOn(User $user, CarbonInterface $date, string $track): array
    {
        $out = [];
        foreach (app(OutcomeService::class)->followUpsDue($user, $date, $track) as $o) {
            $out[] = new FollowUp(
                trackedPropertyId: $o->tracked_property_id ? (int) $o->tracked_property_id : null,
                prospectingListingId: $o->prospecting_listing_id ? (int) $o->prospecting_listing_id : null,
                propertyId: $o->property_id ? (int) $o->property_id : null,
                reason: 'Follow-up: ' . ($o->outcome_label ?: 'call back') . ', due ' . $o->next_action_at->format('j M'),
            );
        }

        return $out;
    }
}
