<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

// ShouldQueue: feedback delivery (SMTP) must run on the worker, never inline in
// the submission request. A synchronous send blocks the HTTP request and can
// time out under morning scheduler/queue load (the 08:30 PromptOutcomeCaptureJob
// contention). The report row is already persisted before delivery is dispatched,
// so a slow/failed send never costs the user their feedback.
class FeedbackReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public object $report,
        public ?User $submitter,
        public Collection $attachments,
    ) {}

    public function envelope(): Envelope
    {
        $severity = $this->report->severity ? "[{$this->report->severity}] " : '';

        return new Envelope(
            subject: "Feedback: {$severity}{$this->report->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.feedback-report',
            with: [
                'report' => $this->report,
                'submitter' => $this->submitter,
                'feedbackAttachments' => $this->attachments,
            ],
        );
    }
}
