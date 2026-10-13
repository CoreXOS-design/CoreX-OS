<?php

namespace App\Services\RoadToSold\Selections;

use App\Models\User;
use App\Services\RoadToSold\Contracts\SuggestionGuard;
use App\Services\RoadToSold\Support\BuilderSettings;
use App\Services\RoadToSold\Support\OtherAgentStock;
use App\Services\RoadToSold\Support\TrackedPropertyResolver;
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
     * @param array    $areaMatches          the agent's suburbs with city/town, for AreaService::matchSql() (id + town aware)
     * @param array<string,int> $countsBySelection how many of each selection are already on the day (every origin)
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
        public readonly array $areaMatches = [],
        public readonly array $countsBySelection = [],
        private ?OtherAgentStock $otherStock = null,
        private ?TrackedPropertyResolver $resolver = null,
    ) {
    }

    /** The "never another agent's stock" check for this agent (built on first use when the builder did not supply one). */
    public function otherStock(): OtherAgentStock
    {
        return $this->otherStock ??= new OtherAgentStock($this->agencyId, $this->identityIds ?: [$this->user->id]);
    }

    public function resolver(): TrackedPropertyResolver
    {
        return $this->resolver ??= new TrackedPropertyResolver();
    }

    public function countOnDay(string $selectionKey): int
    {
        return (int) ($this->countsBySelection[$selectionKey] ?? 0);
    }

    public function hasAreas(): bool
    {
        return $this->areaMatches !== [] || $this->areaSuburbIds !== [] || $this->areaSuburbNames !== [] || $this->areaSuburbNamesLower !== [];
    }

    /** "Lock agents to their areas" is on — only the agent's own suburbs. */
    public function locked(): bool
    {
        return $this->settings->lockAgentsToAreas;
    }
}
