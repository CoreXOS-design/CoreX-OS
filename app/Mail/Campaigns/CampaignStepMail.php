<?php

namespace App\Mail\Campaigns;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * AT-534 — one campaign email, plain text only (.ai/specs/campaigns.md §6.5). Everything is pre-rendered by CampaignSendService and
 * snapshotted on the campaign_sends row; this class only carries it. Our own Message-ID lets replies be matched (§6.6), and the
 * List-Unsubscribe pair gives mail clients RFC 8058 one-click unsubscribe (§6.4).
 */
class CampaignStepMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $fromEmail,
        public string $fromName,
        public string $subjectLine,
        public string $bodyText,
        public string $messageIdValue,
        public string $unsubscribePostUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            replyTo: [new Address($this->fromEmail, $this->fromName)],
            subject: $this->subjectLine,
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageIdValue,
            text: [
                'List-Unsubscribe' => '<' . $this->unsubscribePostUrl . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.campaigns.step-text');
    }
}
