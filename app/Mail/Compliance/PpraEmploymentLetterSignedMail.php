<?php

declare(strict_types=1);

namespace App\Mail\Compliance;

use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * PPRA FFC renewal — Confirmation of Employment letter.
 * .ai/specs/ppra-ffc-employment-letter.md
 *
 * Sent to the agent the moment the principal signs (alongside the in-app
 * notification — Johan's ruling: both, always).
 */
class PpraEmploymentLetterSignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PpraEmploymentLetter $letter,
        public User $agent,
        public User $principal,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name', 'CoreX OS')),
            subject: 'Your Confirmation of Employment letter is signed',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.compliance.ppra-employment-letter-signed',
            with: [
                'downloadUrl' => route('ppra-employment-letters.show', $this->letter),
            ],
        );
    }
}
