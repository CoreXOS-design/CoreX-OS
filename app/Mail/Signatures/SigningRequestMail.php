<?php

namespace App\Mail\Signatures;

use Carbon\Carbon;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SigningRequestMail extends BaseSignatureMail
{
    public function __construct(
        public string $signerName,
        public string $documentName,
        public string $signingUrl,
        public ?string $message,
        public Carbon $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "Please sign: {$this->documentName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.signatures.signing-request',
            with: [
                'agentFooter' => $this->getAgentFooter(),
            ],
        );
    }
}
