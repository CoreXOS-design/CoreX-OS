<?php

declare(strict_types=1);

namespace App\Events\Contact;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;

/**
 * Fires when a Contact's communication consent flips for a given channel.
 */
final class ContactConsentChanged extends AbstractDomainEvent
{
    public function __construct(
        public readonly Contact $contact,
        public readonly string $channel,
        public readonly bool $granted,
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
        return ['channel' => $this->channel, 'granted' => $this->granted];
    }
}
