<?php

namespace App\Services\RoadToSold\Adapters;

use App\Models\User;
use App\Services\RoadToSold\Contracts\FollowUp;
use App\Services\RoadToSold\Contracts\FollowUpSource;
use Carbon\CarbonInterface;

/**
 * FollowUpSource backed by lane 3's call log: OutcomeService::followUpsDue() — the current outcome on
 * each property the agent worked whose return date has arrived (overdue ones included, dropped and
 * never-again ones excluded). Until that class exists there are none.
 */
final class LaneFollowUpSource implements FollowUpSource
{
    private const OUTCOME_SERVICE = '\\App\\Services\\RoadToSold\\OutcomeService';

    public function dueOn(User $user, CarbonInterface $date, string $track): array
    {
        if (! class_exists(self::OUTCOME_SERVICE)) {
            return [];
        }

        $out = [];
        foreach (app(self::OUTCOME_SERVICE)->followUpsDue($user, $date, $track) as $o) {
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
