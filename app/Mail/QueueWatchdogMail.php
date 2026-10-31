<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** AT-523 - the queue watchdog's one email. Plain text; sent only to can.assurance@gmail.com (QueueWatchdogService::EMAIL). */
class QueueWatchdogMail extends Mailable
{
    public function __construct(public string $heading, public string $detail, public string $healthUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.queue-watchdog');
    }
}
