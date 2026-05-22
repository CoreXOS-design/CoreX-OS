<?php

declare(strict_types=1);

namespace App\Notifications\Compliance;

use App\Models\Compliance\Rcr\RcrSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 9d H2 — cadence-aware deadline reminder.
 *
 * The job decides the cadence (weekly → 3-daily → daily → critical); this
 * notification simply renders the right tone for the days-remaining value.
 */
final class RcrDeadlineApproachingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $submissionId,
        public readonly int $daysRemaining,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $submission = RcrSubmission::with('questionnaire')->find($this->submissionId);
        if (!$submission) {
            return (new MailMessage())->subject('RCR submission unavailable');
        }

        $tone = $this->daysRemaining < 0
            ? 'OVERDUE'
            : ($this->daysRemaining <= 1
                ? 'CRITICAL'
                : ($this->daysRemaining <= 7 ? 'URGENT' : 'Reminder'));
        $directive = $submission->questionnaire->directive_reference ?: 'FIC Directive 11 of 2026';
        $deadline  = $submission->submission_deadline->format('j F Y');

        $subject = $this->daysRemaining < 0
            ? "[OVERDUE] {$directive} RCR — was due {$deadline}"
            : "[{$tone}] {$directive} RCR due in " . abs($this->daysRemaining) . ' day' . ($this->daysRemaining === 1 ? '' : 's');

        $mail = (new MailMessage())
            ->subject($subject)
            ->greeting('Compliance reminder');

        if ($this->daysRemaining < 0) {
            $mail->line('The ' . $directive . ' RCR submission was due on ' . $deadline . ' — ' . abs($this->daysRemaining) . ' day(s) ago.')
                ->line('Failure to submit the Risk and Compliance Return may trigger administrative sanctions under FICA.');
        } else {
            $mail->line('The ' . $directive . ' RCR is due on ' . $deadline . ' (' . $this->daysRemaining . ' day' . ($this->daysRemaining === 1 ? '' : 's') . ' remaining).')
                ->line('Please complete and submit via the FIC goAML platform. CoreX is your prep tool — open the RCR drafting page to continue.');
        }

        return $mail
            ->action('Open RCR draft', route('corex.compliance.rcr.show', $submission->id))
            ->line('Reporting period: ' . $submission->reporting_period_from->format('j M Y') . ' to ' . $submission->reporting_period_to->format('j M Y'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'           => 'rcr_deadline_approaching',
            'submission_id'  => $this->submissionId,
            'days_remaining' => $this->daysRemaining,
        ];
    }
}
