<?php

namespace App\Services\RoadToSold\Adapters;

use App\Models\User;
use App\Services\RoadToSold\Contracts\SuggestionGuard;
use App\Services\RoadToSold\HoldService;

/**
 * AT-454 (lane 3) - the real answer to lane 2's SuggestionGuard contract: a property is blocked
 * when another agent holds it or when it is marked "never suggest"; an agent's own still-held
 * properties are not offered again as new.
 */
final class HoldSuggestionGuard implements SuggestionGuard
{
    public function __construct(private readonly HoldService $holds)
    {
    }

    public function blockedFor(User $user, array $trackedPropertyIds, string $track): array
    {
        return $this->holds->excludedIdsFor($user, $trackedPropertyIds, $track);
    }

    public function ownHeld(User $user, array $trackedPropertyIds, string $track): array
    {
        return $this->holds->ownHeldIds($trackedPropertyIds, $user, $track);
    }
}
