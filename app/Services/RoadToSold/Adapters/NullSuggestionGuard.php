<?php

namespace App\Services\RoadToSold\Adapters;

use App\Models\User;
use App\Services\RoadToSold\Contracts\SuggestionGuard;

/**
 * No holds known. Used until lane 3's HoldService / do-not-contact marker exist; the builder's own
 * exclusions (another agent's stock, MIC claims and pitch locks) still apply.
 */
final class NullSuggestionGuard implements SuggestionGuard
{
    public function blockedFor(User $user, array $trackedPropertyIds, string $track): array
    {
        return [];
    }

    public function ownHeld(User $user, array $trackedPropertyIds, string $track): array
    {
        return [];
    }
}
