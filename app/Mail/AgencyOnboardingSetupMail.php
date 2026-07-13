<?php

namespace App\Mail;

use App\Models\AgencyOnboardingSetup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The "Welcome to CoreX — set up your agency" email sent to a new agency's
 * Admin, carrying the guided-setup link.
 *
 * Spec: .ai/specs/agency-onboarding-setup.md §3.5
 * Sent via the 'corex' mailer (see CreateAgencySetupPortal) so it delivers
 * even where the default mailer is 'log'.
 */
class AgencyOnboardingSetupMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $setupUrl;
    public string $agencyName;

    public function __construct(public AgencyOnboardingSetup $setup)
    {
        $this->setupUrl   = $setup->publicUrl();
        $this->agencyName = $setup->agency?->name ?? 'your agency';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to CoreX — set up your agency',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.agency-onboarding-setup',
        );
    }
}
