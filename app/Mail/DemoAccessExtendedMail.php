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
 * "Your demo access has been extended" - sent to the prospect when an owner adds time.
 *
 * Spec: .ai/specs/demo-access-control.md §9.1
 *
 * Carries NO credential. The access code exists in plaintext exactly once (the
 * invitation); the database holds a bcrypt hash only, so there is nothing to resend
 * and this mail says so in plain words - they sign in with the code they were
 * originally sent.
 *
 * Same house pattern as DemoAccessGrantMail: ShouldQueue (SMTP on the worker, never in
 * the request), sent from PRIMARY over the `corex` mailer - the call site
 * (DemoAccessService::notifyProspect) selects that mailer, and the From below is read
 * from that mailer's own config so From and SMTP account always agree.
 *
 * The end date is formatted by the caller, once, in the app timezone (with its
 * abbreviation) - not re-derived here at send time, when a queued job could run after
 * a further extension and describe a deadline the prospect was never told about.
 *
 * Product wording only - no agency name, branding or address (multi-agency rule).
 */
class DemoAccessExtendedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  ?string  $endsAt       formatted end date/time incl. timezone, or null for a
     *                                trial that has not started yet
     * @param  ?string  $trialLength  human trial length when the clock has not started
     */
    public function __construct(
        public DemoAccessGrant $grant,
        public string $gateUrl,
        public ?string $endsAt,
        public ?string $trialLength = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.mailers.corex.from_address', 'mail@corexos.co.za'),
                config('mail.mailers.corex.from_name', 'CoreX OS'),
            ),
            subject: 'Your CoreX OS demo access has been extended',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.demo-access-extended',
            with: [
                'contactName' => $this->grant->contact_name,
                'loginEmail'  => $this->grant->contact_email,
                'gateUrl'     => $this->gateUrl,
                'endsAt'      => $this->endsAt,
                'trialLength' => $this->trialLength,
            ],
        );
    }
}
