<?php

declare(strict_types=1);

namespace App\Events\Presentation;

use App\Events\AbstractDomainEvent;

/**
 * Fires ONCE per presentation, the first time it is actually given to someone: the first delivery
 * that reaches 'sent' (emailed, link copied, or the agent's WhatsApp click-through). Further
 * deliveries of the same presentation, to the same or other recipients, do not raise it again.
 * Raised by PresentationDeliveryObserver. `presentations.status` is NOT changed by this.
 * userId = the agent who sent it. Spec: road-to-sold.md step 5.
 */
final class PresentationPresented extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $presentationId,
        public readonly int $deliveryId,
        public readonly int $agencyId,
        public readonly int $userId,
        public readonly string $channel,
        public readonly ?int $propertyId = null,
        public readonly ?int $trackedPropertyId = null,
        public readonly ?int $sellerContactId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->userId; }

    public function subject(): ?array
    {
        return ['App\\Models\\Presentation', $this->presentationId];
    }
}
