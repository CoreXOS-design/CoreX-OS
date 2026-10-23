<?php

declare(strict_types=1);

namespace App\Events\Contact;

use App\Events\AbstractDomainEvent;

/**
 * Fires once when a buyer's Core Match (their wishlist, `contact_matches`) is created with something to match on - the same
 * "countable" test the buyer pipeline uses to land a buyer, so an empty wishlist raises nothing. Raised by
 * ContactMatchObserver::created(), whichever path made it (agent screen, mobile app, client portal, or the wishlist CoreX
 * seeds from a portal lead - that one has no actor). userId = the buyer's agent (the match's agent, else the buyer's agent,
 * else whoever made it). Spec: road-to-sold.md step 9.
 */
final class ContactMatchCreated extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $matchId,
        public readonly int $contactId,
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
        return ['App\\Models\\ContactMatch', $this->matchId];
    }
}
