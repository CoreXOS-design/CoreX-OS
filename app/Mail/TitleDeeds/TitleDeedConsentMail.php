<?php

namespace App\Mail\TitleDeeds;

use App\Mail\Signatures\BaseSignatureMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/title-deed-register.md §8.3 (c) — asks an owner to approve or decline a named representative collecting the
 * deed. The link in it is personal to that owner: single use, expiring.
 */
class TitleDeedConsentMail extends BaseSignatureMail
{
    public function __construct(
        public string $recipientName,
        public string $consentLine,
        public string $consentUrl,
        public string $expiresOn,
        public ?string $agentName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: 'Please confirm: someone is collecting your title deed',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.title-deeds.consent',
            with: ['agentFooter' => $this->getAgentFooter()],
        );
    }
}
