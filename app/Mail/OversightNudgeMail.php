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
        return new Content(
            view: 'emails.oversight-nudge',
            with: [
                'nudge'   => $this->nudge,
                'manager' => $this->manager,
            ],
        );
    }
}
