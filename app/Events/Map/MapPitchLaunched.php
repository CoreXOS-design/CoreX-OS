<?php

declare(strict_types=1);

namespace App\Events\Map;

use App\Events\AbstractDomainEvent;
use App\Models\Property;

/**
 * Phase A.2 — agent clicked "Pitch this property" on the map and the
 * client navigated to the existing generate-presentation endpoint.
 *
 * event_type (derived by LogAgentActivity::eventTypeKey): `map_pitch.launched`
 *
 * Source values: 'single_detail' (right-panel CTA) | 'composite_row' (icon).
 */
final class MapPitchLaunched extends AbstractDomainEvent
{
    public function __construct(
        public readonly Property $property,
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
        return [Property::class, (int) $this->property->id];
    }

    public function context(): array
    {
        return [
            'property_id'  => (int) $this->property->id,
            'location_key' => $this->locationKey,
            'source'       => $this->source,
        ];
    }
}
