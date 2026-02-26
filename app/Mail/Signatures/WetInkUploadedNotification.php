<?php

namespace App\Mail\Signatures;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Internal notification — sent to agent/BM when wet ink is uploaded.
 * Does NOT extend BaseSignatureMail (no fromAgent needed).
 */
class WetInkUploadedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $signerName,
        public string $documentName,
        public string $inspectUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Wet ink uploaded: {$this->documentName} — review needed",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.signatures.wet-ink-uploaded',
        );
    }
}
