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
 * The single pre-webinar reminder (§0 A4).
 *
 * Spec: .ai/specs/webinar-registration.md §6.4
 *
 * ══ THIS EMAIL CANNOT CARRY THE ACCESS CODE, AND MUST NOT PRETEND TO ══
 *
 * By the time this sends, the plaintext no longer exists anywhere in CoreX — the
 * database holds bcrypt(code) alone (§0 D6). So the reminder points back at the
 * confirmation email rather than promising a credential it cannot produce. A reminder
 * that said "your access code is below" and then omitted it would generate support
 * mail on the morning of the webinar, which is the one morning nobody has time for it.
 *
 * Sent over the `corex` mailer, on the `default` queue, for the same reasons set out
 * in WebinarConfirmationMail.
 */
class WebinarReminderMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public WebinarRegistration $registration,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.mailers.corex.from_address', 'mail@corexos.co.za'),
                config('mail.mailers.corex.from_name', 'CoreX OS'),
            ),
            subject: 'Reminder: ' . $this->registration->webinar->title,
        );
    }

    public function content(): Content
    {
        $webinar = $this->registration->webinar;

        return new Content(
            view: 'emails.webinars.reminder',
            with: [
                'webinar'      => $webinar,
                'registration' => $this->registration,
                'contactName'  => $this->registration->name,
                'joinUrl'      => $webinar->join_url,
                'accessEndsAt' => $webinar->demoAccessEndsAt(),
            ],
        );
    }
}
