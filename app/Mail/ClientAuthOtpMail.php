<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientAuthOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $expiresMinutes = 10,
        /** Rentals front-half D11: the agency this sign-in is for, named in the body when it is exactly one. Null = say nothing. */
        public ?string $agencyName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                env('MAIL_OTP_FROM_ADDRESS', 'Otp@corexos.co.za'),
                env('MAIL_OTP_FROM_NAME', 'CoreX OS')
            ),
            subject: 'Your CoreX sign-in code: ' . $this->code,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.client-auth.otp');
    }
}
