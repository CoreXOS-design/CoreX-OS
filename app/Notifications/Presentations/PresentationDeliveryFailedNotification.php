<?php

declare(strict_types=1);

namespace App\Notifications\Presentations;

use App\Models\PresentationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 6 Part F1 — fired when a presentation delivery fails (queue
 * exception during email send, etc). Goes to the sending user via mail
 * + database so they can react fast.
 */
final class PresentationDeliveryFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $deliveryId) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $delivery = PresentationDelivery::with('presentation.property')->find($this->deliveryId);
        if (!$delivery) {
            return (new MailMessage())->subject('Delivery removed');
        }

        $address = $delivery->presentation->property_address
            ?? ($delivery->presentation->property?->address ?? 'a property');

        return (new MailMessage())
            ->subject('Delivery failed: ' . $delivery->recipient_name . ' — ' . $address)
            ->greeting('A delivery failed to send')
            ->line('The ' . $delivery->channel . ' delivery to ' . $delivery->recipient_name . ' for ' . $address . ' did not go through.')
            ->line('Error: ' . ($delivery->error_message ?: 'unknown'))
            ->action('Open presentation', route('presentations.show', $delivery->presentation_id))
            ->line('You can retry from the presentation\'s Shares & Activity panel.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'presentation_delivery_failed',
            'delivery_id' => $this->deliveryId,
        ];
    }
}
