<?php

namespace App\Mail;

use App\Models\WebinarRegistration;
use App\Support\IcsCalendarInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * The ONE email a webinar registrant receives.
 *
 * Spec: .ai/specs/webinar-registration.md §6.3
 *
 * Johan's decision (§0 A2): one email, not two. It carries the webinar confirmation,
 * the join link, a calendar attachment, AND the demo credentials — which is why
 * WebinarRegistrationService issues the grant with deliver_email = false. Without that
 * suppression the standard demo invitation would arrive alongside this one, carrying
 * the same access code, and a prospect's first impression of CoreX would be a system
 * that mailed them the same secret twice.
 *
 * This is the ONLY delivery of the plaintext access code. The database holds
 * bcrypt(code) alone — nothing can re-send it, which is exactly why re-registering
 * mints a fresh grant rather than resending this mail (§0 D5).
 *
 * ══ SENDER: mail@corexos.co.za, over the `corex` mailer ══
 *
 * Not the default mailer — that authenticates as system@hfcoastal.co.za, the agency's
 * own mailbox. This is a CoreX product email and the first thing a prospect ever sees
 * of CoreX, so it goes out as CoreX over corexos.co.za's own SMTP. The From below is
 * read from the `corex` mailer's config, i.e. the same block holding the credentials
 * it authenticates with: a From header that disagrees with the authenticated account
 * fails SPF and the recipient's server bins the mail, with nothing raised our side.
 *
 * The mailer itself is chosen at the CALL SITE (`Mail::mailer('corex')`), and must be
 * — this Mailable is ShouldQueue, and Mailer::queue() stamps the sending mailer's name
 * onto the mailable on its way to the queue, so the call site always wins whatever the
 * constructor sets. Any NEW call site that sends this must select `corex` too.
 *
 * ShouldQueue, on the `default` queue: SMTP runs on the worker, never inline in a
 * public web request. Do NOT pin a queue name — the CoreX workers run `queue:work`
 * with no --queue flag and drain `default` only; anything else is stranded forever.
 */
class WebinarConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public WebinarRegistration $registration,
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
            subject: 'You are registered: ' . $this->registration->webinar->title,
        );
    }

    public function content(): Content
    {
        $webinar = $this->registration->webinar;

        return new Content(
            view: 'emails.webinars.confirmation',
            with: [
                'webinar'       => $webinar,
                'registration'  => $this->registration,
                'contactName'   => $this->registration->name,
                'loginEmail'    => $this->registration->email,
                'accessCode'    => $this->accessCode,
                'gateUrl'       => $this->gateUrl,
                'joinUrl'       => $webinar->join_url,
                'accessEndsAt'  => $webinar->demoAccessEndsAt(),
            ],
        );
    }

    /**
     * The calendar invite (§0 A6).
     *
     * The UID is derived from the registration id, so a re-issue updates the SAME
     * diary entry instead of leaving the attendee with two copies of one webinar. The
     * sequence rides on updated_at — monotonic, and it has to increase or clients
     * ignore the update and keep the stale time.
     *
     * IT CARRIES THE JOIN LINK, NEVER THE ACCESS CODE. Calendar entries sync to
     * phones, shared team diaries and assistants' calendars; a credential does not
     * belong in one.
     */
    public function attachments(): array
    {
        $webinar = $this->registration->webinar;

        if (! $webinar->starts_at) {
            return [];
        }

        $start = $webinar->starts_at->copy();
        $end   = $start->copy()->addMinutes($webinar->duration_minutes ?: 60);

        $description = trim(
            ($webinar->description ? $webinar->description . "\n\n" : '')
            . ($webinar->join_url ? 'Join: ' . $webinar->join_url : '')
        );

        $ics = IcsCalendarInvite::build(
            uid: 'webinar-' . $webinar->id . '-reg-' . $this->registration->id . '@corexos.co.za',
            summary: $webinar->title,
            start: $start,
            end: $end,
            description: $description !== '' ? $description : null,
            location: $webinar->join_url ?: 'Online',
            url: $webinar->join_url ?: null,
            sequence: (int) ($this->registration->updated_at?->timestamp ?? 0),
            organiserName: config('mail.mailers.corex.from_name', 'CoreX OS'),
            organiserEmail: config('mail.mailers.corex.from_address', 'mail@corexos.co.za'),
        );

        $filename = (Str::slug($webinar->title) ?: 'webinar') . '.ics';

        return [
            Attachment::fromData(fn () => $ics, $filename)->withMime('text/calendar; charset=utf-8'),
        ];
    }
}
