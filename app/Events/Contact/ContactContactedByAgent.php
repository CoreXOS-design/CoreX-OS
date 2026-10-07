<?php

declare(strict_types=1);

namespace App\Events\Contact;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;
use Carbon\CarbonInterface;

/**
 * Fires when somebody at the agency GENUINELY made first-class contact with a Contact (Johan, 2026-10-07 —
 * lead response time). Only these four moments, nothing else (a note on its own is never a contact):
 *
 *   contacted_action      the explicit "Contacted and note" / "Mark as Now" / "Pick date" action
 *                         (Contact::markContacted())
 *   message               an outbound WhatsApp / email that actually went out (ingested or reconciled)
 *   shared_link           a Core Match / live link share confirmed as sent (ContactMatchShare::confirmSent())
 *   appointment_feedback  feedback captured on a calendar appointment the contact is part of
 *
 * Listener: RecordLeadFirstResponse (stamps the contact's open portal enquiries). $at is when the contact
 * happened; $actorUserId is who did it (a manager answering counts for the agent's lead, shown as responder).
 * Catalogued in .ai/specs/corex-domain-events-spec.md.
 */
final class ContactContactedByAgent extends AbstractDomainEvent
{
    public const CHANNELS = ['contacted_action', 'message', 'shared_link', 'appointment_feedback'];

    public function __construct(
        public readonly Contact $contact,
        public readonly string $channel,
        public readonly ?CarbonInterface $at = null,
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
        return ['channel' => $this->channel, 'at' => ($this->at ?? now())->toIso8601String()];
    }
}
