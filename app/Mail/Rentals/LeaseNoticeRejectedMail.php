<?php

namespace App\Mail\Rentals;

use App\Models\Agency;
use App\Models\LeaseNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * .ai/specs/lease-notices.md §5.3 - the person who gave a notice from the portal is told when the agent does not accept it, with the
 * reason. The agency's own mail (its name, logo and details, its name as the sender) - never CoreX. The other party is told nothing.
 */
class LeaseNoticeRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $agencyName;
    public string $recipientName;
    public string $propertyAddress;

    public function __construct(public LeaseNotice $notice)
    {
        $this->onQueue('mail');
        $lease = $notice->lease;
        $this->agencyName = Agency::displayNameFor($lease?->agency, 'Your agency');
        $this->recipientName = trim((string) $notice->givenByContact?->first_name) ?: 'there';
        $this->propertyAddress = $lease?->property?->buildDisplayAddress() ?: ('Lease #' . $notice->lease_id);
    }

    public function envelope(): Envelope
    {
        $agency = $this->notice->lease?->agency;
        $replyTo = $agency && filter_var((string) $agency->email, FILTER_VALIDATE_EMAIL) ? [new Address((string) $agency->email, $this->agencyName)] : [];

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->agencyName),
            replyTo: $replyTo,
            subject: "{$this->agencyName} - your notice for {$this->propertyAddress} was not accepted",
        );
    }

    public function content(): Content
    {
        $agency = $this->notice->lease?->agency;
        $branding = $agency ? Agency::publicBrandingFor((int) $agency->id) : null;

        return new Content(view: 'emails.rentals.lease-notice-rejected', with: [
            'agency' => $agency, 'agencyLogoUrl' => $branding['logoUrl'] ?? null, 'agencyColor' => $branding['colors']['default'] ?? '#1a365d',
        ]);
    }
}
