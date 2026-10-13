<?php

namespace App\Services\RoadToSold\Contracts;

use App\Models\User;

/**
 * Area membership by suburb id and town, not by bare suburb name (spec 5.7). An AreaProvider that also
 * implements this is matched with AreaService::matchSql(); one that does not falls back to the id +
 * name lists, which can merge two towns' suburbs of the same name.
 */
interface AreaMatchProvider
{
    /** @return array<int,array> rows from RoadToSold\AreaService::suburbMatchesFor() */
    public function suburbMatchesFor(User $user, string $track): array;
}
