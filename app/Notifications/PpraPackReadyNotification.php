<?php

namespace App\Notifications;

use App\Models\Compliance\PpraInspectionPack;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * PPRA Inspection Pack Phase J — .ai/specs/ppra-inspection-pack.md §6.9/§4.7.
 * Rides the AT-235 gateway (NotificationDispatcher::send()), same shape as
 * WhistleblowSubmittedNotification/FicaReferralReturnedNotification —
 * via() is the fallback for a stray ->notify(); the real database+mail
 * channel selection is driven by the notification_event_types row's own
 * supports_in_app/supports_email flags inside the dispatcher.
 */
class PpraPackReadyNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected PpraInspectionPack $pack,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'      => 'ppra_pack_generation_complete',
            'title'     => 'PPRA inspection pack ready',
            'body'      => 'Your requested PPRA inspection pack has finished generating and is ready to download.',
            'pack_id'   => $this->pack->id,
            'deep_link' => '/admin/ppra-inspection-pack/' . $this->pack->id . '/download',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your PPRA inspection pack is ready')
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line('The PPRA inspection pack you requested has finished generating.')
            ->action('Download the pack', url('/admin/ppra-inspection-pack/' . $this->pack->id . '/download'))
            ->line('It contains the Inspection Report plus every source document sampled for items k, l, and m.');
    }
}
