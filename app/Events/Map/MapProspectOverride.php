<?php

declare(strict_types=1);

namespace App\Events\Map;

use App\Events\AbstractDomainEvent;
use App\Models\Property;

/**
 * Phase A.2.5 — agent overrode a "coordinate with X" prompt and prospected
 * a property another HFC agent has in draft. The reason text is mandatory
 * (≥ 20 chars, enforced at the controller).
 *
 * event_type: `map_prospect_override.fired`
 *
 * This is a coordination signal — Branch Managers should monitor these
 * events to spot internal collisions early.
 */
final class MapProspectOverride extends AbstractDomainEvent
{
    public function __construct(
        public readonly Property $property,
        public readonly int $agencyId,
        public readonly int $actingUserId,
        public readonly ?int $originalAgentId,
        public readonly ?string $originalAgentName,
        public readonly int $daysInState,
        public readonly string $reason,
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
        return [Property::class, (int) $this->property->id];
    }

    public function context(): array
    {
        return [
            'property_id'         => (int) $this->property->id,
            'original_agent_id'   => $this->originalAgentId,
            'original_agent_name' => $this->originalAgentName,
            'days_in_state'       => $this->daysInState,
            'reason'              => $this->reason,
            'location_key'        => $this->locationKey,
            'source'              => $this->source,
        ];
    }
}
