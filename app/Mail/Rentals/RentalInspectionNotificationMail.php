<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/rental-inspections.md §43 — the one Mailable for every
 * schedule/reschedule/cancel/reminder notification. Extends
 * BaseSignatureMail (App\Mail\Signatures) for the SAME reason
 * SignedDocumentDistributionMail does — that class's per-agent-mailbox
 * send infrastructure (fromAgent()/resolvedMailbox()/getFromAddress()/
 * getAgentFooter()) is generic, not e-sign-specific. Sent via
 * App\Services\Distribution\SignedDocumentDistributionService::
 * sendGenericMail() — the existing path built for exactly this (a
 * Mailable outside the SignedDocumentDistributable contract) — never a
 * third outbound-mail mechanism.
 */
class RentalInspectionNotificationMail extends BaseSignatureMail
{
    public function __construct(
        public string $recipientName,
        public string $eventLabel,
        public string $propertyAddress,
        public string $scheduledLine,
        public ?string $inspectorName,
        public ?string $note,
        public ?string $reason,
        public string $emailSubject,
        public string $inspectionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rentals.inspection-notification',
            with: ['agentFooter' => $this->getAgentFooter()],
        );
    }
}
