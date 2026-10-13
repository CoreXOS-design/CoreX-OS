<?php

namespace App\Services\RoadToSold\Adapters;

use App\Models\User;
use App\Services\RoadToSold\Contracts\SuggestionGuard;
use App\Services\RoadToSold\HoldService;

/**
 * SuggestionGuard backed by lane 3's HoldService (keyed on tracked_property_id):
 *   blocked  = held by another agent  ∪  marked "never suggest" (do-not-contact, for every agent)  ∪  parked by this agent's own outcome
 *   own held = what this agent's own outcome hold keeps
 */
final class LaneSuggestionGuard implements SuggestionGuard
{
    public function blockedFor(User $user, array $trackedPropertyIds, string $track, ?\Carbon\CarbonInterface $on = null): array
    {
        return array_values(array_map('intval', app(HoldService::class)->excludedIdsFor($user, $trackedPropertyIds, $track, $on)));
    }

    public function ownHeld(User $user, array $trackedPropertyIds, string $track): array
    {
        return array_values(array_map('intval', app(HoldService::class)->ownHeldIds($trackedPropertyIds, $user, $track)));
    }
}
