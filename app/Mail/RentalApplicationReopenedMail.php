<?php

namespace App\Mail;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\RentalApplication;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Reopen/resubmit, 2026-09-08 — the agent's "send it back to the applicant"
 * notification. Extends BaseSignatureMail exactly like
 * RentalApplicationInviteMail does — Johan: "email sent to applicant needs
 * the same email format as esign emails - agent details, photo etc," and
 * the reopen-flow investigation flagged this same requirement explicitly
 * ("cc4 owns that template work; coordinate, do not build a second
 * version"). This is not a second template — same base, same From/reply-to
 * routing, same agent footer, only its own subject/body copy and the
 * agent's reopen note.
 */
class RentalApplicationReopenedMail extends BaseSignatureMail
{
    public string $contactName;
    public string $agencyName;
    public string $onlineUrl;
    public string $expiresAt;
    public string $note;

    public function __construct(public RentalApplication $application, string $note)
    {
        $this->contactName = $application->contact->full_name ?: 'there';
        $this->agencyName  = $application->agency->name ?? config('mail.from.name', 'CoreX OS');
        $this->onlineUrl   = route('rental-applications.public.show', $application->token);
        $this->expiresAt   = $application->token_expires_at->format('d M Y');
        $this->note        = $note;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "Your rental application needs a quick update — {$this->agencyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rental-application-reopened',
            with: [
                'agentFooter' => $this->getAgentFooter(),
            ],
        );
    }
}
