<?php

declare(strict_types=1);

namespace App\Events\Property;

use App\Events\AbstractDomainEvent;
use App\Models\Property;

/**
 * Fired when a Property's suburb is linked or re-linked to a P24 suburb
 * (i.e. `p24_suburb_id` changed from null/old → new). Downstream listeners
 * (Listings pillar P24 publish, market analytics, etc.) subscribe to react
 * — see .ai/specs/corex-domain-events-spec.md.
 */
final class PropertySuburbLinked extends AbstractDomainEvent
{
    public function __construct(
        public readonly Property $property,
        public readonly ?int $previousP24SuburbId,
        public readonly int $newP24SuburbId,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->property->agency_id;
    }

    public function actorUserId(): ?int
    {
        return $this->actorUserId;
    }

    public function subject(): ?array
    {
        return [Property::class, $this->property->id];
    }
}
