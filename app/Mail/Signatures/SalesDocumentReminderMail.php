<?php

namespace App\Mail\Signatures;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SalesDocumentReminderMail extends BaseSignatureMail
{
    public function __construct(
        public string $recipientName,
        public string $documentName,
        public string $uploadUrl,
        public string $level,
        public string $agentEmail,
        public int $daysSinceSent,
    ) {}

    public function envelope(): Envelope
    {
        $prefix = match ($this->level) {
            'final'  => 'Final reminder',
            'firm'   => 'Reminder',
            'manual' => 'Reminder',
            default  => 'Friendly reminder',
        };

        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "{$prefix}: {$this->documentName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.sales.reminder',
            with: [
                'agentFooter' => $this->getAgentFooter(),
            ],
        );
    }
}
