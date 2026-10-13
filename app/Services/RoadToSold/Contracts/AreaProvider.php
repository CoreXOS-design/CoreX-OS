<?php

namespace App\Services\RoadToSold\Contracts;

use App\Models\User;

/** The agent's assigned suburbs (lane 1 owns the real answer: RoadToSold\AreaService). */
interface AreaProvider
{
    /** @return int[] p24_suburb_id values */
    public function suburbIdsFor(User $user, string $track): array;

    /** @return string[] suburb names in the tracked-property normalised form (tracked_properties.suburb_normalised) */
    public function suburbNamesNormalisedFor(User $user, string $track): array;

    /** @return string[] suburb names as lower(trim()) — the form MIC uses against prospecting_listings.suburb free text */
    public function suburbNamesLowerTrimFor(User $user, string $track): array;

    public function hasAreas(User $user, string $track): bool;
}
