<?php

namespace App\Mail\DealV2;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * AT-158 DR2 WS6 — per-user morning deal-pipeline digest email.
 *
 * @param array{overdue:array,due_today:array,amber_red:array,registered_yesterday:array} $sections
 */
class DealDailyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public array $sections,
    ) {}

    public function envelope(): Envelope
    {
        $counts = collect($this->sections)->map(fn ($s) => count($s))->sum();

        return new Envelope(
            subject: "Your deal pipeline — {$counts} item(s) need attention",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.deals-v2.daily-digest',
            with: [
                'user'     => $this->user,
                'sections' => $this->sections,
            ],
        );
    }
}
