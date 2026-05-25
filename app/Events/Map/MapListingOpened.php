<?php

declare(strict_types=1);

namespace App\Events\Map;

use App\Events\AbstractDomainEvent;
use App\Models\Property;

/**
 * Phase A.2.3 Item 4 — agent clicked a portal icon (P24 / PP / HFC) on the
 * map and the client opened the public listing in a new tab.
 *
 * event_type (derived by LogAgentActivity::eventTypeKey):
 *   `map_listing.opened`
 *
 * Separate from MapPitchLaunched because "opening the listing on a portal"
 * and "launching a pitch flow" are distinct activities — the former is
 * intel/QA (does our P24 page render correctly), the latter is sales motion.
 */
final class MapListingOpened extends AbstractDomainEvent
{
    public function __construct(
        public readonly Property $property,
        public readonly int $agencyId,
        public readonly int $actingUserId,
        public readonly string $locationKey,
        public readonly string $source,
        public readonly string $portal, // 'p24' | 'pp' | 'hfc'
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actingUserId; }

    public function subject(): ?array
    {
        return [Property::class, (int) $this->property->id];
    }

    public function context(): array
    {
        return [
            'property_id'  => (int) $this->property->id,
            'portal'       => $this->portal,
            'location_key' => $this->locationKey,
            'source'       => $this->source,
        ];
    }
}
