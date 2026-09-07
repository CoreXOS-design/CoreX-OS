<?php

declare(strict_types=1);

namespace App\Events\Communications;

use App\Events\AbstractDomainEvent;
use App\Models\Communications\CommunicationMailbox;

/**
 * An outgoing mail (e-sign invitation, Phase A) was sent through the agent's
 * own resolved mailbox rather than the shared CoreX mailer.
 *
 * Spec: .ai/specs/at395-outgoing-mail-per-mailbox-smtp.md §3.5 ·
 * corex-domain-events-spec.md §5 (catalogue).
 *
 * Audit-only, deliberately no listener — mirrors the Webinar events'
 * reasoning (corex-domain-events-spec.md §5 note 4): this exists so the fact
 * lands in domain_event_log like every other, giving Phase B (OAuth sending)
 * a named contract to react to later, not because anything reacts today.
 */
class OutgoingMailSentViaOwnMailbox extends AbstractDomainEvent
{
    public function __construct(
        public readonly CommunicationMailbox $mailbox,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->mailbox->agency_id;
    }

    public function actorUserId(): ?int
    {
        return $this->mailbox->user_id;
    }

    public function subject(): ?array
    {
        return [CommunicationMailbox::class, $this->mailbox->getKey()];
    }
}
