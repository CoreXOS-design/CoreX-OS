<?php

namespace App\Services\RoadToSold\Adapters;

use App\Models\User;
use App\Services\RoadToSold\AreaService;
use App\Services\RoadToSold\Contracts\AreaMatchProvider;
use App\Services\RoadToSold\Contracts\AreaProvider;

/** AreaProvider backed by lane 1's AreaService (the agent's assigned suburbs). */
final class LaneAreaProvider implements AreaProvider, AreaMatchProvider
{
    public function suburbMatchesFor(User $user, string $track): array
    {
        return app(AreaService::class)->suburbMatchesFor($user, $track);
    }

    public function suburbIdsFor(User $user, string $track): array
    {
        return array_values(array_map('intval', app(AreaService::class)->suburbIdsFor($user, $track)));
    }

    public function suburbNamesNormalisedFor(User $user, string $track): array
    {
        return array_values(array_map('strval', app(AreaService::class)->suburbNamesNormalisedFor($user, $track)));
    }

    public function suburbNamesLowerTrimFor(User $user, string $track): array
    {
        return array_values(array_map('strval', app(AreaService::class)->suburbNamesLowerTrimFor($user, $track)));
    }

    public function hasAreas(User $user, string $track): bool
    {
        return app(AreaService::class)->hasAreas($user, $track);
    }
}
