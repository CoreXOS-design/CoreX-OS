<?php

namespace App\Services\RoadToSold\Contracts;

use App\Models\User;

/** The agent's assigned suburbs (lane 1 owns the real answer: RoadToSold\AreaService). */
interface AreaProvider
{
    /** @return int[] p24_suburb_id values */
    public function suburbIdsFor(User $user, string $track): array;

    /** @return string[] lower-case, trimmed suburb names — for matching free-text suburb columns */
    public function suburbNamesNormalisedFor(User $user, string $track): array;

    public function hasAreas(User $user, string $track): bool;
}
