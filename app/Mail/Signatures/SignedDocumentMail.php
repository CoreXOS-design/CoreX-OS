<?php

namespace App\Mail\Signatures;

use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SignedDocumentMail extends BaseSignatureMail
{
    /**
     * @param  array<int,array{path:string,name:string}>  $documents
     *   HD-7 — the signed documents to attach, one entry per DOCUMENT. A pack that was signed as one
     *   ceremony files as many independent documents, and the parties must receive them the same way:
     *   a Mandate and a Disclosure are two documents, not one stapled PDF. When empty, the mail falls
     *   back to the single `pdfPath` (a non-pack signing, or a pack whose split failed) — so this is
     *   additive and every existing caller keeps working.
     */
    public function __construct(
        public string $recipientName,
        public string $documentName,
        public ?string $envelopeUrl = null,
        public array $progress = [],
        public ?string $pdfPath = null,
        public ?string $pdfFilename = null,
        public array $documents = [],
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
        // HD-7 — N documents, each attached under its own name.
        if (! empty($this->documents)) {
            $attachments = [];

            foreach ($this->documents as $doc) {
                $path = $doc['path'] ?? null;
                if (! $path || ! file_exists($path)) {
                    continue; // A missing file is never a reason to fail a completed signing's email.
                }

                $attachments[] = Attachment::fromPath($path)
                    ->as($doc['name'] ?? 'Signed Document.pdf')
                    ->withMime('application/pdf');
            }

            if (! empty($attachments)) {
                return $attachments;
            }
        }

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
