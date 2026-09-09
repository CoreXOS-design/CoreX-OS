<?php

namespace App\Mail;

use App\Support\OutboundMailGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * AT-URGENT-2026-09-09 — the daily nag. "A week later an agency discovers
 * no client has received an e-sign invitation since Tuesday. That failure
 * is silent, slow, and far more damaging than the one it prevented." This
 * mail exists so a forgotten kill switch surfaces itself.
 *
 * MUST bypass the guard it is reporting on, or it would report on itself
 * and never arrive during the exact situation it exists for. Stamped with
 * OutboundMailGuard::REDIRECTED_HEADER — the same exemption
 * OutboundMailGuardServiceProvider already grants its own Mailpit-forward
 * copy — so guard() lets this through unconditionally on every environment.
 */
class MailInterceptStillOnMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $sinceLabel,
        public int $heldCount,
        public ?string $turnedOnBy,
        public ?string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[CoreX][%s] Outbound mail interception is still ON — %s, %d held',
                strtoupper(config('app.env')),
                $this->sinceLabel,
                $this->heldCount,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.mail-intercept-still-on',
            with: [
                'sinceLabel' => $this->sinceLabel,
                'heldCount' => $this->heldCount,
                'turnedOnBy' => $this->turnedOnBy,
                'reason' => $this->reason,
                'host' => parse_url(config('app.url'), PHP_URL_HOST),
                'environment' => config('app.env'),
            ],
        );
    }

    public function build(): static
    {
        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message) {
            $message->getHeaders()->addTextHeader(OutboundMailGuard::REDIRECTED_HEADER, '1');
        });

        return $this;
    }
}
