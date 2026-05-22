<?php

declare(strict_types=1);

namespace App\Notifications\Presentations;

use App\Models\PresentationSnapshotLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 4 Part F2 — flagged-access notification.
 *
 * Fires when a fingerprint mismatch is observed. Cooldown enforced upstream
 * (PublicPresentationController checks last_flag_notified_at and only
 * dispatches once per 24h per link).
 */
final class PresentationFlaggedAccessNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $linkId) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $link = PresentationSnapshotLink::with('presentation.property')->find($this->linkId);
        if (!$link) {
            return (new MailMessage())->subject('Unusual access — link removed');
        }

        $address = $link->presentation->property_address ?: ($link->presentation->property?->address ?? 'your property');
        $analyticsUrl = route('presentations.show', $link->presentation_id);

        return (new MailMessage())
            ->subject('Unusual access pattern on shared presentation')
            ->greeting('Heads up')
            ->line('The presentation for ' . $address . ' was opened from a different device than the first recipient. This may be a forwarded link.')
            ->action('Review engagement', $analyticsUrl)
            ->line('You can revoke this link from the presentation\'s Share Links panel.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'presentation_flagged_access',
            'link_id' => $this->linkId,
        ];
    }
}
