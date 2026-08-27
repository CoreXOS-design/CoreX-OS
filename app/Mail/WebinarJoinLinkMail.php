<?php

namespace App\Mail;

use App\Models\WebinarRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Here is your joining link" — sent to a cohort that registered before the link existed.
 *
 * Spec: .ai/specs/webinar-registration.md §4.4
 *
 * ══ WHY THIS IS NOT WebinarConfirmationMail ══
 *
 * A webinar goes up as soon as its date is decided; the Zoom link is generated days
 * later. Everyone who registers in that window gets a confirmation carrying no joining
 * link at all. This is the mail that reaches those people — and it is a different mail,
 * not a re-send, for two reasons:
 *
 *   1. Re-sending the confirmation would tell someone they have registered, which they
 *      already know, and re-attach a calendar invite they have already accepted.
 *   2. THE CONFIRMATION CARRIES THE DEMO ACCESS CODE. This one must not, and cannot:
 *      the plaintext exists only inside the transaction that mints it, and the database
 *      holds bcrypt(code) alone (spec §0 D6). A mail implying the code is below — or
 *      offering to re-send it — generates support requests nobody is able to answer.
 *
 * It therefore points back at the original confirmation for credentials, exactly as
 * WebinarReminderMail does, and for exactly the same reason.
 *
 * ══ SENDER AND QUEUE ══
 *
 * Sent over the `corex` mailer, chosen at the CALL SITE (`Mail::mailer('corex')`) —
 * Mailer::queue() stamps the sending mailer onto the mailable on its way to the queue,
 * so the call site always wins whatever a constructor sets. A From header that
 * disagrees with the authenticated SMTP account fails SPF and the recipient's server
 * bins the mail with nothing raised our side.
 *
 * ShouldQueue on the `default` queue. Do NOT pin a queue name — the CoreX workers run
 * `queue:work` with no --queue flag and drain `default` only; anything else is
 * stranded forever.
 */
class WebinarJoinLinkMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * The link is passed in rather than read off the webinar at render time.
     *
     * The send happens inside the same transaction that writes join_url (spec §4.4),
     * and the worker renders this mail long after that transaction closes — by which
     * point the operator may already have pressed the button again with a newer link.
     * Passing the value captures what this particular send promised, so a mail can
     * never render a link the recipient was not actually told about.
     */
    public function __construct(
        public WebinarRegistration $registration,
        public string $joinUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.mailers.corex.from_address', 'mail@corexos.co.za'),
                config('mail.mailers.corex.from_name', 'CoreX OS'),
            ),
            subject: 'Your joining link: ' . $this->registration->webinar->title,
        );
    }

    public function content(): Content
    {
        $webinar = $this->registration->webinar;

        return new Content(
            view: 'emails.webinars.join-link',
            with: [
                'webinar'      => $webinar,
                'registration' => $this->registration,
                'contactName'  => $this->registration->name,
                'joinUrl'      => $this->joinUrl,
                'accessEndsAt' => $webinar->demoAccessEndsAt(),
            ],
        );
    }
}
