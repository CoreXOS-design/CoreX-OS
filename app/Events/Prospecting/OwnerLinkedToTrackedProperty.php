<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;

/**
 * Fires when a tracked property gets its owner (tracked_properties.owner_contact_id goes from empty
 * to a contact) - from the deeds capture, the map scheme owner, the seller-outreach link or a
 * manual pick. Replacing one owner with another does not raise it. Raised by TrackedPropertyObserver (created / updated).
 * Spec: road-to-sold.md step 2 (find owner).
 */
final class OwnerLinkedToTrackedProperty extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $trackedPropertyId,
        public readonly int $agencyId,
        public readonly int $ownerContactId,
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
}
