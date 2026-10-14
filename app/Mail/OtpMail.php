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
 * Generic OTP email for the canonical OtpService. Any consumer that does not
 * ship its own Mailable can send the code with this; consumers that need a
 * bespoke email (e.g. client-auth keeps ClientAuthOtpMail for byte-identical
 * existing mail) pass their own via the service's `mail` callback.
 *
 * Sender is the 'otp' mailer's From address (sender-only); the DESTINATION is
 * set by the caller via ->to() in OtpService — never fixed here.
 *
 * CLIENT-FACING codes (an applicant, a tenant, an owner) pass the agency: the email is then the agency's
 * own — its logo/name on top, its name and contact details at the bottom, its name as the sender — and says
 * nothing about CoreX. Without an agency (staff sign-in, platform codes) it stays the CoreX email.
 */
class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $expiresMinutes = 10,
        public string $heading = 'Your verification code',
        public ?string $subjectLine = null,
        public ?Agency $agency = null,
    ) {}

    public function envelope(): Envelope
    {
        $agencyName = $this->agency ? Agency::displayNameFor($this->agency) : null;
        $replyTo = $this->agency && filter_var((string) $this->agency->email, FILTER_VALIDATE_EMAIL)
            ? [new Address((string) $this->agency->email, $agencyName)]
            : [];

        return new Envelope(
            from: new Address(
                env('MAIL_OTP_FROM_ADDRESS', 'Otp@corexos.co.za'),
                $agencyName ?? \App\Support\ClientBrand::NEUTRAL
            ),
            replyTo: $replyTo,
            subject: $this->subjectLine ?? ($agencyName ? ('Your verification code — ' . $agencyName) : ('Your verification code: ' . $this->code)),
        );
    }

    public function content(): Content
    {
        $branding = $this->agency ? Agency::publicBrandingFor((int) $this->agency->id) : null;

        return new Content(view: 'emails.otp', with: [
            'code'           => $this->code,
            'expiresMinutes' => $this->expiresMinutes,
            'heading'        => $this->heading,
            'agency'         => $this->agency,
            'agencyName'     => $this->agency ? Agency::displayNameFor($this->agency) : null,
            'agencyLogoUrl'  => $branding['logoUrl'] ?? null,
            'agencyColor'    => $branding['colors']['default'] ?? '#0b2a4a',
        ]);
    }
}
