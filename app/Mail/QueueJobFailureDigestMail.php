<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One digest per (job class, alert window) — never one email per failed job.
 * See AppServiceProvider::boot()'s Queue::failing() handler for the debounce
 * that keeps this from firing once per failure.
 */
class QueueJobFailureDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $jobClass,
        public string $queueName,
        public string $queueConnection,
        public string $exceptionClass,
        public string $exceptionMessage,
        public int $recentCount,
        public string $windowLabel,
        public string $host,
        public string $checkedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[CoreX][%s] Queue job failing: %s (%d in %s)',
                strtoupper(config('app.env')),
                class_basename($this->jobClass),
                $this->recentCount,
                $this->windowLabel,
            ),
        );
    }

    public function content(): Content
    {
        // markdown:, not view: — see OversightNudgeMail/FeedbackReportMail for
        // why this distinction matters (2026-08-23 mail-namespace fix).
        return new Content(
            markdown: 'emails.queue-job-failure-digest',
            with: [
                'jobClass'         => $this->jobClass,
                'queueName'        => $this->queueName,
                'queueConnection'  => $this->queueConnection,
                'exceptionClass'   => $this->exceptionClass,
                'exceptionMessage' => $this->exceptionMessage,
                'recentCount'      => $this->recentCount,
                'windowLabel'      => $this->windowLabel,
                'host'             => $this->host,
                'checkedAt'        => $this->checkedAt,
            ],
        );
    }
}
