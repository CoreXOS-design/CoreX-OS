<?php

namespace App\Mail\Signatures;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartySignedNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $agentName,
        public string $partyRole,
        public string $partyName,
        public string $documentName,
        public string $reviewUrl,
    ) {}

    public function envelope(): Envelope
    {
        $roleLabel = ucfirst($this->partyRole);

        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                config('mail.from.name', 'CoreX OS')
            ),
            subject: "{$roleLabel} has signed: {$this->documentName} — Review Required",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.signatures.party-signed-notification',
        );
    }
}
