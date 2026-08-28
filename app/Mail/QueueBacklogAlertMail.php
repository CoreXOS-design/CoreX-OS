<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QueueBacklogAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $lane,
        public int $ageSeconds,
        public int $backlog,
        public int $maxAge,
        public string $supervisor,
        public string $host,
        public string $checkedAt,
    ) {}

    public function envelope(): Envelope
    {
        // The lane is in the subject deliberately. Before 2026-08-28 every backlog
        // email read identically no matter which lane was stalled, so the reader had
        // to open it and then still could not tell. The lane IS the actionable fact:
        // it decides whether this is urgent and which worker to restart.
        return new Envelope(
            subject: sprintf(
                '[CoreX][%s] Queue backlog on %s — oldest job %ds old',
                strtoupper(config('app.env')),
                $this->lane,
                $this->ageSeconds
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.queue-backlog-alert',
            with: [
                'lane'       => $this->lane,
                'ageSeconds' => $this->ageSeconds,
                'backlog'    => $this->backlog,
                'maxAge'     => $this->maxAge,
                'supervisor' => $this->supervisor,
                'host'       => $this->host,
                'checkedAt'  => $this->checkedAt,
            ],
        );
    }
}
