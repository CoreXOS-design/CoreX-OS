<?php

namespace App\Services\RoadToSold\Adapters;

use App\Models\User;
use App\Services\RoadToSold\Contracts\AreaProvider;

/**
 * AreaProvider backed by lane 1's AreaService. Until that class exists the agent has no areas —
 * with "Lock agents to their areas" off that is a normal state (the whole agency pool is offered).
 */
final class LaneAreaProvider implements AreaProvider
{
    private const AREA_SERVICE = '\\App\\Services\\RoadToSold\\AreaService';

    public function suburbIdsFor(User $user, string $track): array
    {
        $svc = $this->service();

        return $svc ? array_values(array_map('intval', (array) $svc->suburbIdsFor($user, $track))) : [];
    }

    public function suburbNamesNormalisedFor(User $user, string $track): array
    {
        $svc = $this->service();

        return $svc ? array_values(array_map('strval', (array) $svc->suburbNamesNormalisedFor($user, $track))) : [];
    }

    public function suburbNamesLowerTrimFor(User $user, string $track): array
    {
        $svc = $this->service();
        if (! $svc) {
            return [];
        }
        $names = method_exists($svc, 'suburbNamesLowerTrimFor')
            ? (array) $svc->suburbNamesLowerTrimFor($user, $track)
            : array_map(fn ($n) => mb_strtolower(trim((string) $n)), (array) $svc->suburbNamesNormalisedFor($user, $track));

        return array_values(array_map('strval', $names));
    }

    public function hasAreas(User $user, string $track): bool
    {
        return $this->suburbIdsFor($user, $track) !== [] || $this->suburbNamesNormalisedFor($user, $track) !== [];
    }

    private function service(): ?object
    {
        return class_exists(self::AREA_SERVICE) ? app(self::AREA_SERVICE) : null;
    }
}
