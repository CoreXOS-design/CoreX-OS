<?php

declare(strict_types=1);

namespace App\Mail\SellerOutreach;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Seller-outreach EMAIL channel — the branded HTML send.
 *
 * Reuses the e-sign branded wrapper: extends BaseSignatureMail (agent From /
 * Reply-To, agency-branded agent footer with logo + disclaimer + POPI) and the
 * shared emails.signatures.partials.agent-footer partial. The $body is the SAME
 * rendered/merged text the WhatsApp channel produces, with {opt_out_link} /
 * {opt_in_link} / {tracking_link} ALREADY substituted to real per-send URLs by
 * SellerOutreachSenderService::send() — this Mailable does not re-render merge
 * fields.
 */
final class OutreachEmail extends BaseSignatureMail
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $emailSubject,
        public readonly string $body,
        ?User $agent = null,
    ) {
        if ($agent) {
            $this->fromAgent($agent);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: $this->emailSubject !== '' ? $this->emailSubject : 'A message from your agent',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.seller-outreach.outreach-email',
            with: [
                'recipientName' => $this->recipientName,
                'body'          => $this->body,
                'agentFooter'   => $this->getAgentFooter(),
            ],
        );
    }
}
