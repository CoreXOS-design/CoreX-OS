<?php

namespace App\Mail\Signatures;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SalesDocumentAllReturnedMail extends BaseSignatureMail
{
    public function __construct(
        public string $agentName,
        public string $documentName,
        public string $dashboardUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            subject: "All signed: {$this->documentName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.sales.all-returned-notification',
        );
    }
}
