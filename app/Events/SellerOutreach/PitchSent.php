<?php

declare(strict_types=1);

namespace App\Events\SellerOutreach;

use App\Events\AbstractDomainEvent;
use App\Models\SellerOutreach\SellerOutreachSend;

/**
 * Fires when a seller-outreach pitch has been recorded as sent.
 *
 * Note: this fires when CoreX has logged the send. The actual WhatsApp /
 * email delivery happens via the agent's own client (wa.me click-through or
 * mailto). The event represents "CoreX has recorded that the agent intended
 * to send this pitch."
 *
 * Subscribers (Phase 1):
 *  - Audit\RecordDomainEvent (wildcard — writes to domain_event_log)
 *  - SellerOutreach\AppendOutreachToContactTimeline (writes timeline entry)
 *
 * Spec: .ai/specs/seller-outreach-spec.md S11.
 */
final class PitchSent extends AbstractDomainEvent
{
    public function __construct(
        public readonly SellerOutreachSend $send,
        public readonly ?int $actorUserId,
        public readonly int $agencyId,
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
        return [SellerOutreachSend::class, $this->send->id];
    }

    public function context(): array
    {
        return [
            'channel' => $this->send->channel,
            'template_id' => $this->send->template_id,
            'tracking_short_code' => $this->send->tracking_short_code,
            'contact_id' => $this->send->contact_id,
            'property_id' => $this->send->property_id,
            'recipient_phone' => $this->send->recipient_phone_snapshot,
            'recipient_email' => $this->send->recipient_email_snapshot,
        ];
    }
}
