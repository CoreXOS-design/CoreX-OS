<?php

namespace App\Notifications;

use App\Models\FicaSubmission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AT-236 — a FICA pack has been REFERRED to the Compliance Officer for a decision.
 *
 * Rides the AT-235 gateway (NotificationDispatcher::send) like every other CoreX
 * alert — channel selection lives in the gateway, not here (via() is only a
 * fallback for a stray ->notify()). Carries the referrer + the mandatory reason so
 * the CO sees WHY without opening the pack.
 */
class FicaReferredToCoNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected FicaSubmission $submission,
        protected User $referrer,
        protected string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $contactName = trim((string) optional($this->submission->contact)->full_name)
            ?: (trim((string) (optional($this->submission->contact)->first_name . ' ' . optional($this->submission->contact)->last_name)) ?: 'a contact');

        return [
            'type'          => 'fica_referred_to_co',
            'title'         => 'FICA referred to you for review',
            'body'          => $this->referrer->name . ' referred a FICA pack for ' . $contactName . ' — reason: ' . $this->reason,
            'submission_id' => $this->submission->id,
            'referrer_id'   => $this->referrer->id,
            'deep_link'     => '/corex/compliance/fica/' . $this->submission->id . '/compliance-review',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $contact = optional($this->submission->contact);
        $contactName = trim((string) ($contact->first_name . ' ' . $contact->last_name)) ?: 'a contact';

        return (new MailMessage)
            ->subject('FICA referred to you — ' . $contactName)
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line('**' . $this->referrer->name . '** has referred a FICA verification to you for a compliance decision.')
            ->line('**Contact:** ' . $contactName)
            ->line('**Reason for referral:** ' . $this->reason)
            ->action('Review the FICA pack', url('/corex/compliance/fica/' . $this->submission->id . '/compliance-review'))
            ->line('You can approve, reject, or return it to the referrer with comments.');
    }
}
