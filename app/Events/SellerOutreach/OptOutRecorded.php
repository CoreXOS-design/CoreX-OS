<?php

declare(strict_types=1);

namespace App\Events\SellerOutreach;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;
use App\Models\SellerOutreach\SellerOutreachSend;

/**
 * Fires when an agent records a seller's opt-out request.
 *
 * Agent-initiated (after the seller replies STOP to the agent's WhatsApp).
 * The agent uses the contact page to record it. The send_id is optional —
 * opt-out can also be standalone (seller asks the agent verbally to stop).
 */
final class OptOutRecorded extends AbstractDomainEvent
{
    /** Recorded by an authenticated agent on the contact page (default). */
    public const SOURCE_AGENT = 'agent';

    /** Recorded by the recipient tapping the per-send self-service opt-out link (AT-49). */
    public const SOURCE_SELF_SERVICE_LINK = 'self_service_link';

    public function __construct(
        public readonly Contact $contact,
        public readonly ?SellerOutreachSend $send,
        public readonly string $reason,
        public readonly ?int $actorUserId,
        public readonly int $agencyId,
        public readonly ?string $source = null,
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
        return $this->actorUserId;
    }

    public function subject(): ?array
    {
        return [Contact::class, $this->contact->id];
    }

    public function context(): array
    {
        return [
            'reason' => $this->reason,
            'send_id' => $this->send?->id,
            'tracking_short_code' => $this->send?->tracking_short_code,
            'source' => $this->source,
        ];
    }
}
