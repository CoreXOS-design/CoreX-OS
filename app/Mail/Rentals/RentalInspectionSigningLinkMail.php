<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/rental-inspections.md §46 — the email that carries one party's personal signing link. Same base and the
 * same send path as RentalInspectionNotificationMail (the agent's own mailbox via
 * SignedDocumentDistributionService::sendGenericMail()); the wording is neutral — the agency's name and the agent's
 * details come from the agent footer, nothing is agency-specific in the template.
 */
class RentalInspectionSigningLinkMail extends BaseSignatureMail
{
    public function __construct(
        public string $recipientName,
        public string $propertyAddress,
        public string $inspectionLabel,
        public string $signingUrl,
        public string $expiresOn,
        public bool $canSign,
        public ?string $agentName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: ($this->canSign ? 'Please review and sign: ' : 'Inspection report: ') . $this->inspectionLabel . ' — ' . $this->propertyAddress,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rentals.inspection-signing-link',
            with: ['agentFooter' => $this->getAgentFooter()],
        );
    }
}
