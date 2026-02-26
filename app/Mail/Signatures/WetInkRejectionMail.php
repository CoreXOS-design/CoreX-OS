<?php

namespace App\Mail\Signatures;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WetInkRejectionMail extends BaseSignatureMail
{
    public function __construct(
        public string $signerName,
        public string $documentName,
        public string $signingUrl,
        public ?string $rejectionNote,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "Action needed: {$this->documentName} — signatures missing",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.signatures.wet-ink-rejection',
            with: [
                'agentFooter' => $this->getAgentFooter(),
            ],
        );
    }
}
