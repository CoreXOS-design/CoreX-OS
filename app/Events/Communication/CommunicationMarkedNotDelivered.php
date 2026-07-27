<?php

declare(strict_types=1);

namespace App\Events\Communication;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;

/**
 * Contact-details Phase 4 — an agent flagged a previously-recorded outbound
 * send as "could not send / not delivered". Auto-audited to domain_event_log
 * by the existing DomainEvent listener (no extra registration needed) — this
 * is the first link in the "created → marked not-delivered → reselected →
 * resent" chain the audit strip on the contact page reads back.
 *
 * subject() is the CONTACT (not the Communication) — same convention as
 * CommsTileMessageSent — so every status-change event for a contact's sends
 * is queryable with one WHERE clause.
 */
final class CommunicationMarkedNotDelivered extends AbstractDomainEvent
{
    public function __construct(
        public readonly Contact $contact,
        public readonly int $communicationId,
        public readonly string $channel,
        public readonly ?int $actorUserId,
        public readonly int $agencyId,
        public readonly ?string $reason = null,
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
            'reason'           => $this->reason,
        ];
    }
}
