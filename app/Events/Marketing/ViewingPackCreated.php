<?php

declare(strict_types=1);

namespace App\Events\Marketing;

use App\Events\AbstractDomainEvent;

/**
 * Fires once when a viewing pack is made for a buyer - from the Viewing Packs screen, from a booked viewing on the
 * calendar, or by re-making the pack for a viewing. Raised by ViewingPackObserver::created(), so every path is covered.
 * "Sent" is not recorded anywhere in CoreX (a pack has only draft / ready), so creation is the signal. userId = the pack's
 * agent. Spec: road-to-sold.md step 8c.
 */
final class ViewingPackCreated extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $packId,
        public readonly ?int $contactId,
        public readonly ?int $calendarEventId,
        public readonly ?int $agencyId,
        public readonly ?int $branchId,
        public readonly ?int $userId,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return ['App\\Models\\ViewingPack', $this->packId];
    }
}
