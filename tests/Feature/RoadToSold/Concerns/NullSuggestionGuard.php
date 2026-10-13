<?php

namespace Tests\Feature\RoadToSold\Concerns;

use App\Models\User;
use App\Services\RoadToSold\Contracts\SuggestionGuard;

/**
 * Test double: no holds known. Lets a builder test isolate the builder's own exclusions
 * (another agent's stock, MIC claims and pitch locks) from lane 3's holds.
 */
final class NullSuggestionGuard implements SuggestionGuard
{
    public function blockedFor(User $user, array $trackedPropertyIds, string $track, ?\Carbon\CarbonInterface $on = null): array
    {
        return [];
    }

    public function ownHeld(User $user, array $trackedPropertyIds, string $track): array
    {
        return [];
    }
}
