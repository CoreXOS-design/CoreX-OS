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
 * The tenant / owner PORTAL sign-in code (first-time activation and "forgot password" recovery).
 *
 * Agency mail goes out as the agency, never as CoreX: when the sign-in belongs to exactly one agency (ClientAuthService::agencyForSignIn)
 * the email carries that agency's logo (or name), its name and contact details at the bottom, its name as the sender and in the subject,
 * and replies go to the agency. When it cannot be one agency (a person with logins at several, or an address nobody knows) it is a
 * plain "your sign-in code" email - no agency, and no CoreX either. Both shapes are identical in layout, so the mail never hints at
 * whether an address is a client.
 */
class ClientAuthOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    /** The agency's display name, or null for the neutral email. */
    public ?string $agencyName;

    public function __construct(
        public string $code,
        public int $expiresMinutes = 10,
        /** The one agency this sign-in is for; null = neutral wording, nothing guessed. */
        public ?Agency $agency = null,
    ) {
        $this->agencyName = $agency ? Agency::displayNameFor($agency) : null;
    }

    public function envelope(): Envelope
    {
        $replyTo = $this->agency && filter_var((string) $this->agency->email, FILTER_VALIDATE_EMAIL)
            ? [new Address((string) $this->agency->email, $this->agencyName)]
            : [];

        return new Envelope(
            from: new Address(
                env('MAIL_OTP_FROM_ADDRESS', 'Otp@corexos.co.za'),
                $this->agencyName ?? 'Portal sign-in'
            ),
            replyTo: $replyTo,
            subject: ($this->agencyName ? "Your {$this->agencyName} sign-in code" : 'Your sign-in code') . ': ' . $this->code,
        );
    }

    public function content(): Content
    {
        $branding = $this->agency ? Agency::publicBrandingFor((int) $this->agency->id) : null;

        return new Content(view: 'emails.client-auth.otp', with: [
            'agencyLogoUrl' => $branding['logoUrl'] ?? null,
            'agencyColor'   => $branding['colors']['default'] ?? '#0b2a4a',
        ]);
    }
}
