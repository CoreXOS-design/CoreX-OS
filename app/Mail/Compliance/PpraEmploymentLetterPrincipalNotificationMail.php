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
 * Sent to the resolved principal when a letter reaches
 * awaiting_principal_signature, and re-sent on the agency's configured
 * reminder cadence (ppra_employment_letter_reminder_days) by
 * SendPpraEmploymentLetterReminders while it stays unsigned. Internal
 * system notification — not an agent-personalised outward email — mirrors
 * App\Mail\Signatures\PartySignedNotificationMail's shape (plain Mailable,
 * config('mail.from.*')), not BaseSignatureMail's agent-footer branding.
 */
class PpraEmploymentLetterPrincipalNotificationMail extends Mailable
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
            subject: 'Action required: Confirmation of Employment letter awaiting your signature',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.compliance.ppra-employment-letter-principal',
            with: [
                'signUrl' => route('ppra-employment-letters.show', $this->letter),
            ],
        );
    }
}
