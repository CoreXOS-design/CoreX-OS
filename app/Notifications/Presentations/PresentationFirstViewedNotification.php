<?php

declare(strict_types=1);

namespace App\Notifications\Presentations;

use App\Models\PresentationSnapshotLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 4 Part F1 — first-view alert for a snapshot link.
 *
 * Fires once per link (the show controller only dispatches when
 * first_viewed_at was null before this view). Channels: mail + database.
 */
final class PresentationFirstViewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $linkId) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $link = PresentationSnapshotLink::with(['presentation.property'])->find($this->linkId);
        if (!$link) {
            return (new MailMessage())->subject('Presentation viewed')->line('Link removed.');
        }

        $address = $link->presentation->property_address ?: ($link->presentation->property?->address ?? 'your property');
        $recipient = $link->recipient_label
            ?: ($link->recipientContact?->display_name ?? 'A recipient');
        $when = optional($link->first_viewed_at)->diffForHumans() ?? 'just now';
        $analyticsUrl = route('presentations.show', $link->presentation_id);

        return (new MailMessage())
            ->subject('Your seller opened the presentation for ' . $address)
            ->greeting('Good news')
            ->line($recipient . ' opened the presentation you sent ' . $when . '.')
            ->action('View engagement', $analyticsUrl)
            ->line('You\'ll be notified again only if something unusual happens.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'          => 'presentation_first_viewed',
            'link_id'       => $this->linkId,
        ];
    }
}
