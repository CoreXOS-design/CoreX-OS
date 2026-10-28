<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QueueNoWorkerAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $queue,
        public int $waiting,
        public int $oldestAgeSeconds,
        public string $host,
        public string $checkedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf('[CoreX][%s] No worker for queue %s — %d job(s) waiting', strtoupper((string) config('app.env')), $this->queue, $this->waiting));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.queue-no-worker-alert', with: [
            'queue' => $this->queue, 'waiting' => $this->waiting, 'oldestAgeSeconds' => $this->oldestAgeSeconds,
            'host' => $this->host, 'checkedAt' => $this->checkedAt,
        ]);
    }
}
