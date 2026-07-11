<?php

namespace App\Mail;

use App\Models\DemoAccessGrant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invitation email — the ONLY delivery of the plaintext access code.
 *
 * Spec: .ai/specs/demo-access-control.md §6.1
 *
 * ShouldQueue, per the house pattern: SMTP must run on the worker, never inline
 * in the issue request. The grant row is already persisted before delivery is
 * dispatched, so a slow or failed send never costs us the grant.
 *
 * SENT FROM PRIMARY. Never from the demo host — its mailer points at Mailpit
 * (the demo bar links to it), so a grant email sent from there would land in a
 * local catcher and the prospect would never receive it, with no error raised.
 * That failure is silent, which is what makes it dangerous.
 */
class DemoAccessGrantMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public DemoAccessGrant $grant,
        public string $accessCode,
        public string $demoUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your CoreX OS demo access',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.demo-access-grant',
            with: [
                'grant'        => $this->grant,
                'accessCode'   => $this->accessCode,
                'demoUrl'      => $this->demoUrl,
                'expiryHours'  => $this->grant->expiry_hours,
                'contactName'  => $this->grant->contact_name,
                'companyName'  => $this->grant->company_name,
                'loginEmail'   => $this->grant->contact_email,
            ],
        );
    }
}
