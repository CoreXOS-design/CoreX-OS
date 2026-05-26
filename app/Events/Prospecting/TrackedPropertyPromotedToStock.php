<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;

/**
 * Fires when a TrackedProperty is promoted to Agency Stock (a mandate is signed).
 * The original TrackedProperty record stays — its source_chain is preserved as
 * audit. promoted_to_property_id on the TP points at the new Property record.
 */
final class TrackedPropertyPromotedToStock extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $trackedPropertyId,
        public readonly int $propertyId,
        public readonly int $agencyId,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return ['App\\Models\\Prospecting\\TrackedProperty', $this->trackedPropertyId];
    }

    public function context(): array
    {
        return ['property_id' => $this->propertyId];
    }
}
