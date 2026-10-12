<?php

namespace App\Mail\TitleDeeds;

use App\Mail\Signatures\BaseSignatureMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/title-deed-register.md §8.1 — "your title deed is at our office". Carries the collection PIN when the agency
 * uses one. Sent from the agent's own mailbox (SignedDocumentDistributionService::sendGenericMail). Neutral wording: the
 * agency's name and the agent's details come from the agent footer, nothing here is specific to one agency.
 */
class TitleDeedNoticeMail extends BaseSignatureMail
{
    public function __construct(
        public string $recipientName,
        public string $propertyAddress,
        public ?string $pin,
        public bool $isReminder = false,
        public ?string $agentName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: ($this->isReminder ? 'Reminder: your title deed is waiting - ' : 'Your title deed is ready for collection - ') . $this->propertyAddress,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.title-deeds.notice',
            with: ['agentFooter' => $this->getAgentFooter()],
        );
    }
}
