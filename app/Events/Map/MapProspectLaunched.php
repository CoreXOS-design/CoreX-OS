<?php

declare(strict_types=1);

namespace App\Events\Map;

use App\Events\AbstractDomainEvent;
use App\Models\Prospecting\TrackedProperty;

/**
 * Phase A.2.1 — agent clicked "Prospect Now" on a competitor active
 * listing from the map and the client navigated to the Opportunities
 * detail page for the matching TrackedProperty.
 *
 * event_type (derived by LogAgentActivity::eventTypeKey):
 *   `map_prospect.launched`
 *
 * Replaces the A.2 short-term mapping of "Find owner" → MapComparableAdded,
 * which was semantically wrong (a comparable add and a prospecting launch
 * are different activities). MapComparableAdded stays for actual comp-add
 * clicks from sold_comps records.
 */
final class MapProspectLaunched extends AbstractDomainEvent
{
    public function __construct(
        public readonly TrackedProperty $trackedProperty,
        public readonly int $agencyId,
        public readonly int $actingUserId,
        public readonly string $locationKey,
        public readonly string $source,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actingUserId; }

    public function subject(): ?array
    {
        return [TrackedProperty::class, (int) $this->trackedProperty->id];
    }

    public function context(): array
    {
        return [
            'tracked_property_id' => (int) $this->trackedProperty->id,
            'location_key'        => $this->locationKey,
            'source'              => $this->source,
        ];
    }
}
