<?php

namespace App\Mail\Signatures;

use Carbon\Carbon;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SalesDocumentMail extends BaseSignatureMail
{
    public function __construct(
        public string $recipientName,
        public string $documentName,
        public string $uploadUrl,
        public ?string $personalMessage,
        public Carbon $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "Please sign and return: {$this->documentName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.sales.document-sent',
            with: [
                'agentFooter' => $this->getAgentFooter(),
            ],
        );
    }
}
