<?php

namespace App\Mail\Rentals;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Audit C-C4 - the owner is told the work on a repair has been reported finished (by a contractor), when the agency has chosen to tell the owner at that moment (OwnerFinishNotice). */
class RentalOwnerWorkReportedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $ownerName, public string $title, public string $body)
    {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.owner-work-reported');
    }
}
