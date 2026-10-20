<?php

namespace App\Mail\Rentals;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Audit fix C-A4 (19 Oct 2026) - rentals-faults-work-orders.md section 29: the people who were sent a job (the contractor, the crew) and the tenant who was told of an
 * appointment are told, once, that the work order was CANCELLED. $audience = 'worker' (contractor / crew: do not attend) or 'tenant' (the appointment will not go ahead).
 * Plain, agency-neutral wording; the agency's cancel reason is internal and is never in it.
 */
class RentalJobCancelledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $recipientName, public string $propertyAddress, public string $jobTitle, public string $agencyName, public string $audience = 'worker')
    {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->audience === 'tenant' ? 'Repair cancelled' : 'Job cancelled - do not attend') . " - {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.job-cancelled');
    }
}
