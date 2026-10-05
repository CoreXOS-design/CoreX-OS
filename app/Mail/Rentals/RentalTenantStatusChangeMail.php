<?php

namespace App\Mail\Rentals;

use App\Models\RentalFaultReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * .ai/specs/rental-portal-access.md §6 — AT-445. The tenant's own fault
 * report changed status (approved, declined, owner handling, resolved).
 */
class RentalTenantStatusChangeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $recipientName;
    public string $propertyAddress;

    public function __construct(public RentalFaultReport $faultReport, string $recipientName)
    {
        $this->onQueue('mail');
        $this->recipientName = $recipientName ?: 'there';
        $this->propertyAddress = $faultReport->property?->buildDisplayAddress() ?: ('Property #' . $faultReport->property_id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Update on your fault report — {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.tenant-status-change');
    }
}
