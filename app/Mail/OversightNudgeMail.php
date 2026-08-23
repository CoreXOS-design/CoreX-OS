<?php

namespace App\Mail;

use App\Models\OversightNudge;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OversightNudgeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public OversightNudge $nudge,
        public User $manager,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Action required: ' . str_replace('_', ' ', $this->nudge->category),
            replyTo: $this->manager->email ? [$this->manager->email] : [],
        );
    }

    public function content(): Content
    {
        // markdown:, not view: — this template uses @component('mail::message'),
        // which only resolves the `mail::` view namespace via the Markdown
        // renderer that `markdown:` routes through (Content::view does plain
        // Blade rendering and never registers that namespace). Every send of
        // this mailable failed with "No hint path defined for [mail]" since it
        // shipped (10,356 rows in failed_jobs) — same fix already applied
        // correctly in QueueWorkerDownMail/QueueBacklogAlertMail.
        return new Content(
            markdown: 'emails.oversight-nudge',
            with: [
                'nudge'   => $this->nudge,
                'manager' => $this->manager,
            ],
        );
    }
}
