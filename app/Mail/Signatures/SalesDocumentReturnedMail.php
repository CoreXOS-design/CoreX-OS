<?php

namespace App\Mail\Signatures;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SalesDocumentReturnedMail extends BaseSignatureMail
{
    public function __construct(
        public string $agentName,
        public string $documentName,
        public string $clientName,
        public string $clientRole,
        public ?string $nextRecipientName,
        public string $dashboardUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            subject: "{$this->clientName} has returned signed: {$this->documentName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.sales.returned-notification',
        );
    }
}
