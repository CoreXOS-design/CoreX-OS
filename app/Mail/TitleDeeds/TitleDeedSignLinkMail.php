<?php

namespace App\Mail\TitleDeeds;

use App\Mail\Signatures\BaseSignatureMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** .ai/specs/title-deed-register.md section 14.10 - the one-time link a collector opens on their own phone to sign for the deed. */
class TitleDeedSignLinkMail extends BaseSignatureMail
{
    public function __construct(
        public string $recipientName,
        public string $propertyAddress,
        public string $signUrl,
        public string $expiresOn,
        public string $agencyName,
        public ?string $agentName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: 'Please sign to collect your title deed - ' . $this->propertyAddress,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.title-deeds.sign-link', with: ['agentFooter' => $this->getAgentFooter()]);
    }
}
