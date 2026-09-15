<?php

namespace App\Mail;

use App\Models\RentalApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * AT-392, Johan 2026-09-07 — "the agent must be notified the application
 * came back." Sent from RentalApplicationSigningController::submit() once
 * the applicant's submission is saved. Distinct from RentalApplicationMailer
 * /RentalApplicationInviteMail (the OUTBOUND invite to the applicant) —
 * a separate, new file, kept isolated from the agent-side lane's own
 * mailer to avoid touching shared ground.
 */
class RentalApplicationReturnedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $agentName;
    public string $contactName;
    public string $agencyName;
    public string $reviewUrl;
    public string $submittedAt;

    public function __construct(public RentalApplication $application)
    {
        $this->agentName = $application->createdBy->name ?? 'there';
        $this->contactName = $application->contact->full_name ?: 'A tenant';
        $this->agencyName = $application->agency->name ?? config('mail.from.name', 'CoreX OS');
        $this->reviewUrl = route('corex.rental-applications.show', $application);
        $this->submittedAt = $application->submitted_at?->format('d M Y \a\t H:i') ?? '';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Rental application returned — {$this->contactName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rental-application-returned',
        );
    }
}
