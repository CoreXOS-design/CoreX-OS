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
    public string $stepLabel;
    public ?string $stepDate = null;
    public string $portalUrl;

    public function __construct(public RentalFaultReport $faultReport, string $recipientName)
    {
        $this->onQueue('mail');
        $this->recipientName = $recipientName ?: 'there';
        $this->propertyAddress = $faultReport->property?->buildDisplayAddress() ?: ('Property #' . $faultReport->property_id);
        // Where the fault is now - the progress line's own tenant wording (Johan, 8 Oct 2026). For a declined fault that is the
        // neutral "Not approved - your agent will contact you": never the owner's reason, never the agent's notes.
        $progress = app(\App\Services\Rentals\RentalFaultProgressService::class)->forFault($faultReport, \App\Services\Rentals\RentalFaultProgressService::AUDIENCE_TENANT);
        $current = collect($progress['steps'])->firstWhere('current', true);
        $this->stepLabel = $progress['current_label'];
        $this->stepDate = ($current['at'] ?? null) ? \Illuminate\Support\Carbon::parse($current['at'])->format('j M Y') : null;
        $this->portalUrl = url('/portal');
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
