<?php

declare(strict_types=1);

namespace App\Events\SellerOutreach;

use App\Events\AbstractDomainEvent;
use App\Models\SellerOutreach\SellerOutreachSend;

/**
 * Fires when an agent updates the outcome on a SellerOutreachSend.
 *
 * Outcomes are agent-settable: replied | booked | no_response | not_interested | bounced
 * (auto-set outcomes 'sent' and 'clicked' come from the system but the
 * controller permits reverts to them too).
 *
 * Wildcard audit listener captures this event automatically. No bespoke
 * listener — the controller writes the timeline row inline (it's
 * transactional with the send.update() so we can't fail one without the
 * other).
 */
final class OutreachOutcomeUpdated extends AbstractDomainEvent
{
    public function __construct(
        public readonly SellerOutreachSend $send,
        public readonly string $previousOutcome,
        public readonly string $newOutcome,
        public readonly ?string $note,
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
            'previous_outcome' => $this->previousOutcome,
            'new_outcome'      => $this->newOutcome,
            'note_present'     => $this->note !== null && trim($this->note) !== '',
            'contact_id'       => $this->send->contact_id,
        ];
    }
}
