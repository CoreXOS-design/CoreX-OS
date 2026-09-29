<?php

namespace App\Mail\Distribution;

use App\Mail\Signatures\BaseSignatureMail;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * §41, 2026-09-28 — the one Mailable App\Services\Distribution\
 * SignedDocumentDistributionService sends for EVERY consumer (rental
 * inspections today, Inventory next) — generic wording, no module-specific
 * language. Extends BaseSignatureMail (App\Mail\Signatures) directly: that
 * class's own content (fromAgent()/resolvedMailbox()/getFromAddress()/
 * getAgentFooter()) is genuinely generic per-agent-mailbox send
 * infrastructure despite its namespace, not e-sign-specific — this is
 * its first consumer outside Docuperfect.
 */
class SignedDocumentDistributionMail extends BaseSignatureMail
{
    public function __construct(
        public string $recipientName,
        public string $documentLabel,
        public string $propertyAddress,
        public string $emailSubject,
        public ?string $publicUrl = null,
        public ?string $pdfPath = null,
        public ?string $pdfFilename = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.distribution.signed-document',
            with: ['agentFooter' => $this->getAgentFooter()],
        );
    }

    public function attachments(): array
    {
        if ($this->pdfPath && file_exists($this->pdfPath)) {
            return [
                Attachment::fromPath($this->pdfPath)
                    ->as($this->pdfFilename ?? ($this->documentLabel . '.pdf'))
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
