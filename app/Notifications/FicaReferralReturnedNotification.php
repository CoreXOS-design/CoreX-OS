<?php

namespace App\Notifications;

use App\Models\FicaSubmission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AT-269 — a referred FICA pack has been RETURNED to the referrer. Either the CO
 * decided to hand it back with comments, or (system-initiated) the Compliance
 * Officer designation changed and no active CO remained, so the pack was returned
 * for re-assignment rather than left orphaned in the CO queue.
 *
 * Rides the AT-235 gateway (NotificationDispatcher::send); via() is only the
 * fallback for a stray ->notify().
 */
class FicaReferralReturnedNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected FicaSubmission $submission,
        protected ?User $actor,
        protected string $comments,
        protected bool $systemInitiated = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    private function contactName(): string
    {
        $contact = optional($this->submission->contact);

        return trim((string) ($contact->full_name
            ?: trim((string) ($contact->first_name . ' ' . $contact->last_name)))) ?: 'a contact';
    }

    public function toArray(object $notifiable): array
    {
        $who = $this->systemInitiated
            ? 'The Compliance Officer designation changed and no active CO remains'
            : (($this->actor?->name ?? 'The Compliance Officer') . ' returned your referral');

        return [
            'type'             => 'fica_referral_returned',
            'title'            => $this->systemInitiated
                ? 'FICA referral returned for re-assignment'
                : 'FICA referral returned to you',
            'body'             => $who . ' — FICA for ' . $this->contactName() . ': ' . $this->comments,
            'submission_id'    => $this->submission->id,
            'system_initiated' => $this->systemInitiated,
            'deep_link'        => '/corex/compliance/fica/' . $this->submission->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('FICA referral returned — ' . $this->contactName())
            ->greeting('Hi ' . $notifiable->name . ',');

        if ($this->systemInitiated) {
            $mail->line('The Compliance Officer designation for your agency changed and no active Compliance Officer '
                . 'remains to decide a FICA you escalated.')
                ->line('It has been returned to you so it is not left waiting on nobody.');
        } else {
            $mail->line('**' . ($this->actor?->name ?? 'The Compliance Officer') . '** has returned a FICA referral to you.');
        }

        return $mail
            ->line('**Contact:** ' . $this->contactName())
            ->line('**Comments:** ' . $this->comments)
            ->action('Open the FICA pack', url('/corex/compliance/fica/' . $this->submission->id))
            ->line('Please review and, once a Compliance Officer is available, re-escalate if a CO decision is still needed.');
    }
}
