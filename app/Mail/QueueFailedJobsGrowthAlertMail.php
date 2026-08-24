<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * QueueHealthcheck's failed_jobs-growth check (2026-08-23) — a rising
 * failed_jobs count with a shallow `jobs` table means the worker is actively
 * processing but jobs are failing fast, not stalling. The existing
 * oldest-waiting-job check treats that as HEALTHY (nothing is waiting — it
 * already failed and moved tables), which is the blind spot this closes.
 */
class QueueFailedJobsGrowthAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $newFailures,
        public int $windowMinutes,
        public int $totalFailedJobs,
        public string $host,
        public string $checkedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[CoreX][%s] failed_jobs growing — %d new in %d min (total %d)',
                strtoupper(config('app.env')),
                $this->newFailures,
                $this->windowMinutes,
                $this->totalFailedJobs,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.queue-failed-jobs-growth-alert',
            with: [
                'newFailures'     => $this->newFailures,
                'windowMinutes'   => $this->windowMinutes,
                'totalFailedJobs' => $this->totalFailedJobs,
                'host'            => $this->host,
                'checkedAt'       => $this->checkedAt,
            ],
        );
    }
}
