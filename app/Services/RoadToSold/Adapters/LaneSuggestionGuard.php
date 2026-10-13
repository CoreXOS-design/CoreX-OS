<?php

namespace App\Services\RoadToSold\Adapters;

use App\Models\User;
use App\Services\RoadToSold\Contracts\SuggestionGuard;

/**
 * SuggestionGuard backed by lane 3's HoldService (keyed on tracked_property_id):
 *   blocked  = held by another agent  ∪  marked "never suggest" (do-not-contact, for every agent)
 *   own held = what this agent's own outcome hold keeps
 * Until that class exists nothing is blocked (the builder's own exclusions still apply).
 */
final class LaneSuggestionGuard implements SuggestionGuard
{
    private const HOLD_SERVICE = '\\App\\Services\\RoadToSold\\HoldService';

    public function blockedFor(User $user, array $trackedPropertyIds, string $track): array
    {
        $svc = $this->service();

        return $svc ? array_values(array_map('intval', $svc->excludedIdsFor($user, $trackedPropertyIds, $track))) : [];
    }

    public function ownHeld(User $user, array $trackedPropertyIds, string $track): array
    {
        $svc = $this->service();

        return $svc ? array_values(array_map('intval', $svc->ownHeldIds($trackedPropertyIds, $user, $track))) : [];
    }

    private function service(): ?object
    {
        return class_exists(self::HOLD_SERVICE) ? app(self::HOLD_SERVICE) : null;
    }
}
