<?php

namespace App\Mail;

use App\Models\RentalApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * AT-392/AT-410b authoriser+agent flow — the applicant-facing decline
 * email. Wired and firing end to end via RentalApplicationReviewController::
 * sendDecline(), the AGENT's own deliberate send action — never automatic
 * on decline() itself.
 *
 * AT-410b, 2026-09-15 — this Mailable no longer resolves its own wording.
 * It used to build subject/body from RentalApplicationDeclineEmailSetting
 * at send time; now it just delivers whatever literal text it's handed.
 * That's deliberate: the authoriser's decline() action drafts the full
 * merged text once (RentalApplicationDeclineEmailSetting::draftFor()) and
 * stores it on the application for the agent to read and EDIT; re-deriving
 * from settings here would silently discard her edits and send the
 * template again instead of what she actually approved.
 */
class RentalApplicationDeclineMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public RentalApplication $application,
        public string $subject_,
        public string $bodyText,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subject_);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rental-application-decline');
    }
}
