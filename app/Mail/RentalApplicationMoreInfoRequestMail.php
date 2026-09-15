<?php

namespace App\Mail;

use App\Models\RentalApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * AT-392 authoriser flow — the AGENT'S OWN "request more information from
 * the applicant" action (distinct from anything the authoriser does).
 * Johan: "a way to request more info from applicant?... reuse the existing
 * applicant flow and token rather than inventing a second one." Points back
 * at the SAME online link every invite already uses
 * (rental-applications.public.show) — the applicant can already add
 * documents there at any status (cc4's add-after-submit build), so no new
 * token or route was needed, only this notification.
 */
class RentalApplicationMoreInfoRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $contactName;
    public string $agencyName;
    public string $onlineUrl;
    public string $note;

    public function __construct(public RentalApplication $application, string $note)
    {
        $this->contactName = $application->contact->full_name ?: 'there';
        $this->agencyName  = $application->agency->name ?? config('mail.from.name', 'CoreX OS');
        $this->onlineUrl   = route('rental-applications.public.show', $application->token);
        $this->note        = $note;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "We need a bit more from you — {$this->agencyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rental-application-more-info-request',
        );
    }
}
