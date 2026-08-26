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
        public int $ageSeconds,
        public int $backlog,
        public string $host,
        public string $checkedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('[CoreX][%s] Queue backlog — oldest job %ds old', strtoupper(config('app.env')), $this->ageSeconds),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.queue-backlog-alert',
            with: [
                'ageSeconds' => $this->ageSeconds,
                'backlog'    => $this->backlog,
                'host'       => $this->host,
                'checkedAt'  => $this->checkedAt,
            ],
        );
    }
}
