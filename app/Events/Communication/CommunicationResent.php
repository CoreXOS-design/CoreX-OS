<?php

declare(strict_types=1);

namespace App\Events\Communication;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;

/**
 * Contact-details Phase 4 — an agent reselected a different phone/email and
 * resent, after the original send was flagged not_delivered. Links the
 * ORIGINAL (flagged) communication to the NEW one the resend created — the
 * original is never modified; the resend is a fresh row (append-only audit).
 */
final class CommunicationResent extends AbstractDomainEvent
{
    public function __construct(
        public readonly Contact $contact,
        public readonly int $originalCommunicationId,
        public readonly int $newCommunicationId,
        public readonly string $channel,
        public readonly ?int $actorUserId,
        public readonly int $agencyId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return [Contact::class, (int) $this->contact->id];
    }

    public function context(): array
    {
        return [
            'original_communication_id' => $this->originalCommunicationId,
            'new_communication_id'      => $this->newCommunicationId,
            'contact_id'                => (int) $this->contact->id,
            'channel'                   => $this->channel,
        ];
    }
}
