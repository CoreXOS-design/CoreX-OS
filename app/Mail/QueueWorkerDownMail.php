<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QueueWorkerDownMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<int,array{group:string,process:string,state:string,detail:string}> $downWorkers */
    public function __construct(
        public array $downWorkers,
        public string $host,
        public string $checkedAt,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->downWorkers);
        $envs = array_unique(array_map(fn (array $w) => strtoupper($w['environment'] ?? 'live'), $this->downWorkers));
        sort($envs);

        return new Envelope(
            subject: sprintf(
                '[CoreX][%s] %d queue worker%s down on %s',
                implode('+', $envs),
                $count,
                $count === 1 ? '' : 's',
                $this->host,
            ),
        );
    }

    public function content(): Content
    {
        // markdown:, not view: — this template uses @component('mail::message'),
        // which only resolves the `mail::` view namespace via the Markdown
        // renderer that `markdown:` routes through (Content::view does plain
        // Blade rendering and never registers that namespace).
        return new Content(
            markdown: 'emails.queue-worker-down',
            with: [
                'downWorkers' => $this->downWorkers,
                'host'        => $this->host,
                'checkedAt'   => $this->checkedAt,
            ],
        );
    }
}
