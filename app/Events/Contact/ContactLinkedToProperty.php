<?php

declare(strict_types=1);

namespace App\Events\Contact;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;
use App\Models\Property;

/**
 * Fires when a Contact is linked to a Property in a given role (owner/buyer/tenant/...)
 */
final class ContactLinkedToProperty extends AbstractDomainEvent
{
    public function __construct(
        public readonly Contact $contact,
        public readonly Property $property,
        public readonly string $role,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->contact->agency_id ?? $this->property->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [Contact::class, $this->contact->id]; }

    public function context(): array
    {
        return ['property_id' => $this->property->id, 'role' => $this->role];
    }
}
