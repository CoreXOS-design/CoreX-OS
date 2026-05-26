<?php

declare(strict_types=1);

namespace App\Events\Contact;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;

/**
 * Fires when two Contacts are merged (source merged into target).
 */
final class ContactMergedInto extends AbstractDomainEvent
{
    public function __construct(
        public readonly Contact $sourceContact,
        public readonly Contact $targetContact,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->targetContact->agency_id ?? $this->sourceContact->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [Contact::class, $this->targetContact->id]; }

    public function context(): array
    {
        return [
            'source_contact_id' => $this->sourceContact->id,
            'target_contact_id' => $this->targetContact->id,
        ];
    }
}
