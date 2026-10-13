<?php

namespace App\Services\RoadToSold\Selections;

use App\Models\User;
use App\Services\RoadToSold\Contracts\SuggestionGuard;
use App\Services\RoadToSold\Support\BuilderSettings;
use Carbon\CarbonInterface;

/** Everything a selection needs to answer "which properties for this agent on this day". */
final class SelectionContext
{
    /**
     * @param int[]    $areaSuburbIds        the agent's p24_suburb_ids
     * @param string[] $areaSuburbNames      the agent's suburbs in tracked-property normalised form
     * @param string[] $areaSuburbNamesLower the agent's suburbs as lower(trim()) — for listing free text
     * @param int[]    $identityIds          User::dataIdentityIds() — whose stock counts as "mine"
     * @param int[]    $excludeTrackedIds    already on this day's list
     * @param int[]    $excludeListingIds
     * @param int[]    $excludePropertyIds
     */
    public function __construct(
        public readonly User $user,
        public readonly int $agencyId,
        public readonly CarbonInterface $date,
        public readonly string $track,
        public readonly BuilderSettings $settings,
        public readonly SuggestionGuard $guard,
        public readonly array $areaSuburbIds = [],
        public readonly array $areaSuburbNames = [],
        public readonly array $areaSuburbNamesLower = [],
        public readonly array $identityIds = [],
        public readonly array $excludeTrackedIds = [],
        public readonly array $excludeListingIds = [],
        public readonly array $excludePropertyIds = [],
    ) {
    }

    public function hasAreas(): bool
    {
        return $this->areaSuburbIds !== [] || $this->areaSuburbNames !== [] || $this->areaSuburbNamesLower !== [];
    }

    /** "Lock agents to their areas" is on — only the agent's own suburbs. */
    public function locked(): bool
    {
        return $this->settings->lockAgentsToAreas;
    }
}
