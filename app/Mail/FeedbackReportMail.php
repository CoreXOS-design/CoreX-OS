<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class FeedbackReportMail extends Mailable
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
