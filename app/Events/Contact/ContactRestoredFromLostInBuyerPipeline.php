<?php

declare(strict_types=1);

namespace App\Events\Contact;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;

/**
 * AT-Core-Matches, Task 6 — "the match is set aside, never deleted, and
 * comes back if the buyer does." Fires when a buyer's Buyer Pipeline state
 * moves OFF 'lost' to anything else.
 */
final class ContactRestoredFromLostInBuyerPipeline extends AbstractDomainEvent
{
    public function __construct(
        public readonly Contact $contact,
        public readonly ?int $triggeredByUserId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->contact->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->triggeredByUserId; }
    public function subject(): ?array { return [Contact::class, $this->contact->id]; }

    public function context(): array
    {
        return ['contact_id' => $this->contact->id];
    }
}
