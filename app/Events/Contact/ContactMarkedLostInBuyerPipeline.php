<?php

declare(strict_types=1);

namespace App\Events\Contact;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;

/**
 * AT-Core-Matches, Task 6 — fires when a buyer transitions to 'lost' in the
 * Buyer Pipeline, whether by the nightly auto-recompute or a manual
 * override. `BuyerStateService::transitionTo()` writes via
 * `updateQuietly()`, which suppresses Eloquent model events, so this is
 * dispatched explicitly at the point of transition rather than relying on
 * an observer that would never fire.
 */
final class ContactMarkedLostInBuyerPipeline extends AbstractDomainEvent
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
