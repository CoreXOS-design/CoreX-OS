<?php

namespace App\Mail;

use App\Models\RentalApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * AT-392 spec §4 — one send, two return routes. Both links are always in the
 * one email; the applicant picks whichever suits them.
 */
class RentalApplicationInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $contactName;
    public string $agencyName;
    public string $onlineUrl;
    public string $downloadUrl;
    public string $expiresAt;

    public function __construct(public RentalApplication $application)
    {
        $this->contactName = $application->contact->full_name ?: 'there';
        $this->agencyName  = $application->agency->name ?? config('mail.from.name', 'CoreX OS');
        $this->onlineUrl   = route('rental-applications.public.show', $application->token);
        $this->downloadUrl = route('rental-applications.public.pdf', $application->token);
        $this->expiresAt   = $application->token_expires_at->format('d M Y');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Rental Application — {$this->agencyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rental-application-invite',
        );
    }
}
