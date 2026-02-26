<?php

namespace App\Mail\Signatures;

use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SignedDocumentMail extends BaseSignatureMail
{
    public function __construct(
        public string $recipientName,
        public string $documentName,
        public string $envelopeUrl,
        public array $progress = [],
        public ?string $pdfPath = null,
        public ?string $pdfFilename = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "Fully signed: {$this->documentName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.signatures.signed-document',
            with: [
                'agentFooter' => $this->getAgentFooter(),
            ],
        );
    }

    public function attachments(): array
    {
        if ($this->pdfPath && file_exists($this->pdfPath)) {
            return [
                Attachment::fromPath($this->pdfPath)
                    ->as($this->pdfFilename ?? 'Signed Document.pdf')
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
