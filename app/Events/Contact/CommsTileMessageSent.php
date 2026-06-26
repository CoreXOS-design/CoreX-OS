<?php

declare(strict_types=1);

namespace App\Events\Contact;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;

/**
 * Part 4 — fires when an agent logs an outbound message from the contact comms
 * tile (the quick-send that writes a provisional `communications` row via
 * OutboundProvisionalLogger). Until now this canvassing action was invisible:
 * it created no seller-outreach send, fired no event, and never reached any
 * activity surface. This event lets it land in agent_activity_events (via the
 * existing LogAgentActivity writer) so the Outreach & Canvassing board can
 * source-tag it as `comms_tile` — kept SEPARATE from MIC-prospect and
 * direct-contact outreach (never blended).
 *
 * Spec: .ai/audits/2026-06-26-prospected-stock-and-unified-outreach.md §6.
 */
final class CommsTileMessageSent extends AbstractDomainEvent
{
    public function __construct(
        public readonly Contact $contact,
        public readonly string $channel,        // whatsapp | email
        public readonly ?int $actorUserId,
        public readonly int $agencyId,
        public readonly ?int $communicationId = null,
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
            'channel'          => $this->channel,
            'contact_id'       => (int) $this->contact->id,
            'communication_id' => $this->communicationId,
        ];
    }
}
