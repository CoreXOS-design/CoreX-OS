<?php

namespace App\Mail;

use App\Models\DemoAccessGrant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
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
 *
 * ══ SENDER: mail@corexos.co.za, over the `corex` mailer ══
 *
 * Not the default mailer. That one authenticates as system@hfcoastal.co.za — the
 * agency's own mailbox. This is a CoreX product invitation to a corexos.co.za
 * demo, and the first thing a prospect ever sees of CoreX, so it goes out as
 * CoreX over corexos.co.za's own SMTP.
 *
 * The From below is read from the `corex` mailer's config, i.e. the same block
 * that holds the SMTP credentials it authenticates with. That is deliberate: a
 * From header that does not match the authenticated account fails SPF and the
 * recipient's server bins the mail, with nothing raised our side.
 *
 * The other half of that pair — the mailer itself — is chosen at the CALL SITE
 * (SendDemoAccessGrantEmail: `Mail::mailer('corex')`), and it has to be. Pinning
 * it here would be theatre: this Mailable is ShouldQueue, and Mailer::queue()
 * stamps the sending mailer's own name onto the mailable on its way to the
 * queue — so the call site's choice always wins, whatever the constructor set.
 * Any NEW call site that mails this must therefore select the `corex` mailer too;
 * DemoAccessGrantTest asserts it does.
 */
class DemoAccessGrantMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public DemoAccessGrant $grant,
        public string $accessCode,
        public string $gateUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.mailers.corex.from_address', 'mail@corexos.co.za'),
                config('mail.mailers.corex.from_name', 'CoreX OS'),
            ),
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
                'gateUrl'      => $this->gateUrl,
                'expiryHours'  => $this->grant->expiry_hours,
                'contactName'  => $this->grant->contact_name,
                'companyName'  => $this->grant->company_name,
                'loginEmail'   => $this->grant->contact_email,
            ],
        );
    }
}
