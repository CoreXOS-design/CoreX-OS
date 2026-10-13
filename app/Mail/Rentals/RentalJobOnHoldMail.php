<?php

namespace App\Mail\Rentals;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** .ai/specs/rentals-faults-work-orders.md section 22.7 - the contractor / crew who were already sent a job are told it is ON HOLD: do not attend until you hear from us. */
class RentalJobOnHoldMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $recipientName, public string $propertyAddress, public string $jobTitle, public string $agencyName)
    {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "On hold - do not attend until you hear from us - {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.job-on-hold');
    }
}
