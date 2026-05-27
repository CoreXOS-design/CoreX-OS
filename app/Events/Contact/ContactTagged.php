<?php

declare(strict_types=1);

namespace App\Events\Contact;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;

/**
 * Fires when a tag is attached to a Contact.
 */
final class ContactTagged extends AbstractDomainEvent
{
    public function __construct(
        public readonly Contact $contact,
        public readonly string $tag,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->contact->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [Contact::class, $this->contact->id]; }

    public function context(): array
    {
        return ['tag' => $this->tag];
    }
}
