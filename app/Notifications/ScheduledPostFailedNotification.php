<?php

namespace App\Notifications;

use App\Models\PropertyMarketingPost;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * AT-456 Part D — tells the agent that a scheduled social post did not go out, and why.
 * In-app (database) ONLY, delivered directly (not queued): QA2 has no queue worker, and a failed
 * post must never be silent.
 */
class ScheduledPostFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected PropertyMarketingPost $post,
        protected string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $property = $this->post->property;
        $address  = $property
            ? (method_exists($property, 'buildDisplayAddress') ? $property->buildDisplayAddress() : ($property->title ?? 'your listing'))
            : 'your listing';

        return [
            'type'        => 'scheduled_post_failed',
            'title'       => 'A scheduled ' . ucfirst((string) $this->post->platform) . ' post did not go out',
            'body'        => $address . ' — ' . $this->reason,
            'action_url'  => '/tools/ad-manager/scheduled',
            'icon'        => 'exclamation-triangle',
            'property_id' => $this->post->property_id,
            'post_id'     => $this->post->id,
        ];
    }
}
