<?php

declare(strict_types=1);

namespace App\Events\Marketing;

use App\Events\AbstractDomainEvent;

/**
 * Fires once when an agent presses "generate" for a seller live link (a new link for a property and its seller).
 * Raised by SellerLinkController::generate() only - the links CoreX makes for itself when a seller is linked to a
 * property (PropertySellerLink::ensureExists, which some pages call just by being opened) are NOT the agent's work and
 * raise nothing. userId = the agent the work counts for (the one who generated it). Spec: road-to-sold.md step 8b.
 */
final class SellerLinkCreated extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $linkId,
        public readonly int $propertyId,
        public readonly ?int $contactId,
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
        return ['App\\Models\\PropertySellerLink', $this->linkId];
    }
}
