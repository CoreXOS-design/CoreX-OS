<?php

namespace App\Notifications\FeatureRollout;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * AT-478 §12.6 — a feature switched on for this person a few days ago and they have not taken its guided
 * tour yet. In-app only, sent once per person per feature (rollout_feature_users.tour_nudged_at).
 */
class RolloutTourNudgeNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $featureKey,
        private readonly string $featureLabel,
        private readonly string $tourUrl,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'feature_rollout_tour_nudge',
            'title'       => "Take the tour: {$this->featureLabel}",
            'body'        => "{$this->featureLabel} switched on for you recently. A short guided tour shows you around.",
            'action_url'  => $this->tourUrl,
            'icon'        => 'academic-cap',
            'feature_key' => $this->featureKey,
        ];
    }
}
