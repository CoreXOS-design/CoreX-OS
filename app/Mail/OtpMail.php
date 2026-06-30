<?php

namespace App\Mail;

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
 */
class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $expiresMinutes = 10,
        public string $heading = 'Your verification code',
        public ?string $subjectLine = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                env('MAIL_OTP_FROM_ADDRESS', 'Otp@corexos.co.za'),
                env('MAIL_OTP_FROM_NAME', 'CoreX OS')
            ),
            subject: $this->subjectLine ?? ('Your CoreX code: ' . $this->code),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp', with: [
            'code'           => $this->code,
            'expiresMinutes' => $this->expiresMinutes,
            'heading'        => $this->heading,
        ]);
    }
}
