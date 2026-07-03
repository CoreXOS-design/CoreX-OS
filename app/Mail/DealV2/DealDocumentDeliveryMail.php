<?php

namespace App\Mail\DealV2;

use App\Mail\Signatures\BaseSignatureMail;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * AT-158 DR2 · WS4 (§8.2b) — direct-attachment delivery.
 *
 * Attaches the document to a branded agency email — for low-sensitivity docs /
 * trusted providers where a secure link + OTP is friction. Configured per
 * doc-type × party in the distribution matrix.
 */
class DealDocumentDeliveryMail extends BaseSignatureMail
{
    public function __construct(
        public string $recipientName,
        public string $documentTitle,
        public string $dealReference,
        public ?string $propertyAddress,
        public ?string $pdfPath = null,
        public ?string $pdfFilename = null,
        public ?string $messageLine = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "Document attached: {$this->documentTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.deals.document-delivery',
            with: ['agentFooter' => $this->getAgentFooter()],
        );
    }

    public function attachments(): array
    {
        if ($this->pdfPath && file_exists($this->pdfPath)) {
            return [
                Attachment::fromPath($this->pdfPath)
                    ->as($this->pdfFilename ?? ($this->documentTitle . '.pdf'))
                    ->withMime('application/pdf'),
            ];
        }
        return [];
    }
}
