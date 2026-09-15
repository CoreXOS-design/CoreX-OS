<?php

namespace App\Mail;

use App\Models\RentalApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * AT-392 authoriser flow — notifies the AGENT of an authoriser decision
 * (approved / declined / more info requested). Mirrors
 * RentalApplicationReturnedMail's shape (same agent-notification pattern
 * already proven in this module) rather than inventing a new one; one
 * flexible class for all three outcomes rather than three near-identical
 * ones, since the only real difference between them is the headline and
 * whether a reason/amount is shown.
 */
class RentalApplicationDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $agentName;
    public string $contactName;
    public string $headline;
    public string $reviewUrl;

    public function __construct(
        public RentalApplication $application,
        public string $decision, // 'approved' | 'declined' | 'more_info_requested'
        public ?string $reason = null,
        public bool $isOverride = false,
    ) {
        $this->agentName = $application->createdBy->name ?? 'there';
        $this->contactName = $application->contact->full_name ?: 'A tenant';
        $this->reviewUrl = route('corex.rental-applications.review', $application);

        $this->headline = match ($decision) {
            'approved' => "Approved — {$this->contactName}" . ($isOverride ? ' (overridden)' : ''),
            'declined' => "Declined — {$this->contactName}" . ($isOverride ? ' (overridden)' : ''),
            default => "More information requested — {$this->contactName}",
        };
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->headline);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rental-application-decision');
    }
}
