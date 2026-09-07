<?php

declare(strict_types=1);

namespace App\Events\Communications;

/**
 * NOTE: named per spec §3.5 for the Situation-B "configured mailbox failed"
 * case, but Johan's override (spec §3.3, 2026-09-07) removed the silent
 * fallback for that situation — a configured-but-broken mailbox now THROWS
 * instead of falling back. This event is retained, dispatched instead for
 * Situation A (no mailbox configured at all, so the shared mailer is used by
 * design, not as a fallback from a failure) — audit-only, same reasoning as
 * OutgoingMailSentViaOwnMailbox: a named, queryable fact for Phase B, no
 * listener today.
 *
 * Spec: .ai/specs/at395-outgoing-mail-per-mailbox-smtp.md §3.5.
 */
class OutgoingMailFellBackToSharedMailer extends \App\Events\AbstractDomainEvent
{
    public function __construct(
        public readonly ?int $agentId,
        public readonly ?int $agencyId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agencyId;
    }

    public function actorUserId(): ?int
    {
        return $this->agentId;
    }
}
