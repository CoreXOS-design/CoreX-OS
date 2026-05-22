<?php

declare(strict_types=1);

namespace App\Notifications\Compliance;

use App\Models\Compliance\Rcr\RcrSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 9d H2 — confirms an RCR was submitted (snapshot taken, exports
 * generated). Sent to principal + system owner alongside the CO.
 */
final class RcrSubmissionCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $submissionId) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $submission = RcrSubmission::with(['questionnaire', 'agency', 'submitter'])->find($this->submissionId);
        if (!$submission) {
            return (new MailMessage())->subject('RCR submission removed');
        }
        $directive = $submission->questionnaire->directive_reference ?: 'FIC Directive 11 of 2026';
        return (new MailMessage())
            ->subject($directive . ' RCR submitted')
            ->greeting('RCR submission confirmed')
            ->line($directive . ' RCR has been submitted by ' . ($submission->submitter?->name ?? 'a compliance officer') . '.')
            ->line('Submission date: ' . $submission->submitted_at?->format('j F Y H:i'))
            ->line('Reporting period: ' . $submission->reporting_period_from->format('j M Y') . ' to ' . $submission->reporting_period_to->format('j M Y'))
            ->line('goAML reference: ' . ($submission->submitted_to_platform_reference ?: '(not yet entered)'))
            ->action('Open submission', route('corex.compliance.rcr.show', $submission->id))
            ->line('An immutable snapshot has been recorded for audit retention.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'          => 'rcr_submission_completed',
            'submission_id' => $this->submissionId,
        ];
    }
}
