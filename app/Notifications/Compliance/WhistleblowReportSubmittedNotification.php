<?php

declare(strict_types=1);

namespace App\Notifications\Compliance;

use App\Models\Compliance\WhistleblowComplaint;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A compliance report is waiting for an officer's decision. Rides the AT-235 gateway; via() is
 * only the fallback for a stray ->notify(). Payload keys are the ones the header bell reads
 * (title / body / action_url).
 */
class WhistleblowReportSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(protected WhistleblowComplaint $complaint) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $filer = $this->complaint->reporter?->name ?? 'An agent';
        $ref   = 'CDX-WB-' . $this->complaint->id;

        return [
            'type'         => 'whistleblow_report_submitted',
            'title'        => 'Compliance report waiting for your decision',
            'body'         => $filer . ' filed ' . $ref . ' (' . str_replace('_', ' ', (string) $this->complaint->tier) . ') about ' . ($this->complaint->property_address ?: 'a property') . '.',
            'complaint_id' => $this->complaint->id,
            'action_url'   => route('compliance.whistleblow.show', $this->complaint),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $filer = $this->complaint->reporter?->name ?? 'An agent';
        $ref   = 'CDX-WB-' . $this->complaint->id;

        return (new MailMessage)
            ->subject('Compliance report waiting for you — ' . $ref)
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line('**' . $filer . '** has filed a compliance report that needs an officer\'s decision.')
            ->line('**Reference:** ' . $ref)
            ->line('**Property:** ' . ($this->complaint->property_address ?: 'not given'))
            ->action('Open the report', route('compliance.whistleblow.show', $this->complaint))
            ->line('You can approve and send it onward, request changes, or reject it.');
    }
}
