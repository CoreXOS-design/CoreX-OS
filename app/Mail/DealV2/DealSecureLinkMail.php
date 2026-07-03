<?php

namespace App\Mail\DealV2;

use App\Mail\Signatures\BaseSignatureMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * AT-158 DR2 · WS4 (§8.2a) — secure-link delivery (the DEFAULT mode).
 *
 * Carries a tokened link, NOT the document. Opening the link triggers an OTP
 * identity challenge before the document streams (POPIA). Branded via
 * BaseSignatureMail (agency from/reply-to/footer).
 */
class DealSecureLinkMail extends BaseSignatureMail
{
    public function __construct(
        public string $recipientName,
        public string $documentTitle,
        public string $dealReference,
        public ?string $propertyAddress,
        public string $secureUrl,
        public ?string $messageLine = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "Document for you: {$this->documentTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.deals.secure-link',
            with: ['agentFooter' => $this->getAgentFooter()],
        );
    }
}
