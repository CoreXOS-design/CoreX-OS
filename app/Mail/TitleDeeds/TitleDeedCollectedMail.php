<?php

namespace App\Mail\TitleDeeds;

use App\Mail\Signatures\BaseSignatureMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** .ai/specs/title-deed-register.md §8.4 — every owner is told who collected the deed, when, and how it was verified. */
class TitleDeedCollectedMail extends BaseSignatureMail
{
    public function __construct(
        public string $recipientName,
        public string $propertyAddress,
        public string $collectorName,
        public string $collectedOn,
        public string $methodLabel,
        public ?string $agentName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: 'Your title deed has been collected - ' . $this->propertyAddress,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.title-deeds.collected',
            with: ['agentFooter' => $this->getAgentFooter()],
        );
    }
}
