<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * AI Spend Safety email — the alert-level warning or the cut-out notice
 * (AT-456 §A.2). Sent by AiSpendGuard to the Dev Settings recipient list.
 */
class AiSpendAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public const KIND_ALERT  = 'alert';
    public const KIND_CUTOUT = 'cutout';

    /**
     * @param array<string, float> $topSources source => spend in the window, highest first (max 3)
     * @param bool $isSystem true for the platform bucket (AI no agency owns, e.g. the Article Library)
     */
    public function __construct(
        public string $kind,
        public string $agencyName,
        public float $spendZar,
        public float $thresholdZar,
        public int $windowHours,
        public array $topSources,
        public string $usageUrl,
        public bool $isSystem = false,
    ) {}

    public function envelope(): Envelope
    {
        // The agency and what happened are in the subject — the reader decides
        // from the inbox whether this needs them right now.
        $what = $this->kind === self::KIND_CUTOUT
            ? 'AI PAUSED'
            : 'AI spend alert';

        return new Envelope(
            subject: sprintf(
                '[CoreX][%s] %s — %s spent R %s in %dh',
                strtoupper((string) config('app.env')),
                $what,
                $this->agencyName,
                number_format($this->spendZar, 2),
                $this->windowHours,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.ai-spend-alert',
            with: [
                'isCutout'     => $this->kind === self::KIND_CUTOUT,
                'agencyName'   => $this->agencyName,
                'spendZar'     => $this->spendZar,
                'thresholdZar' => $this->thresholdZar,
                'windowHours'  => $this->windowHours,
                'topSources'   => $this->topSources,
                'usageUrl'     => $this->usageUrl,
                'isSystem'     => $this->isSystem,
            ],
        );
    }
}
