<?php

namespace App\Mail;

use App\Models\Agency;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * .ai/specs/attorney-portal.md §10 - the personal link to an attorney's deals. The agency's own email (name, logo,
 * reply-to); it says nothing about CoreX. Sent through the sender-only 'otp' envelope like the portal codes.
 */
class AttorneyPortalInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public ?Agency $agency,
        public string $url,
        public int $validDays,
    ) {}

    public function envelope(): Envelope
    {
        $agencyName = $this->agency ? Agency::displayNameFor($this->agency) : null;
        $replyTo = $this->agency && filter_var((string) $this->agency->email, FILTER_VALIDATE_EMAIL)
            ? [new Address((string) $this->agency->email, $agencyName)]
            : [];

        return new Envelope(
            from: new Address(env('MAIL_OTP_FROM_ADDRESS', 'Otp@corexos.co.za'), $agencyName ?? \App\Support\ClientBrand::NEUTRAL),
            replyTo: $replyTo,
            subject: ($agencyName ?: 'Your agent') . ' has shared your deals with you',
        );
    }

    public function content(): Content
    {
        $branding = $this->agency ? Agency::publicBrandingFor((int) $this->agency->id) : null;

        return new Content(view: 'emails.attorney-portal-invite', with: [
            'recipientName' => $this->recipientName,
            'agency'        => $this->agency,
            'agencyName'    => $this->agency ? Agency::displayNameFor($this->agency) : null,
            'agencyLogoUrl' => $branding['logoUrl'] ?? null,
            'agencyColor'   => $branding['colors']['default'] ?? '#0b2a4a',
            'url'           => $this->url,
            'validDays'     => $this->validDays,
        ]);
    }
}
