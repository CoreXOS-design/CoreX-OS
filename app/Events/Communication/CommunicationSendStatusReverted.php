<?php

declare(strict_types=1);

namespace App\Events\Communication;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;

/**
 * Contact-details Phase 4 — an agent undid a "could not send" flag, putting a
 * communication back to send_status=sent (they flagged it by mistake, or the
 * contact confirmed they DID receive it after all). The revert is itself
 * audited, same as the flag — nothing here is a silent flip.
 */
final class CommunicationSendStatusReverted extends AbstractDomainEvent
{
    public function __construct(
        public readonly Contact $contact,
        public readonly int $communicationId,
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
            'communication_id' => $this->communicationId,
            'contact_id'       => (int) $this->contact->id,
            'channel'          => $this->channel,
        ];
    }
}
