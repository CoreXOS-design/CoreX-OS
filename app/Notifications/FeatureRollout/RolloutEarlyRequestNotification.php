<?php

namespace App\Notifications\FeatureRollout;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * AT-478 §12.5 — someone in the agency asked for a feature before its group switches on ("Ask for this
 * early" on the New and coming page). In-app only, to the agency's rollout admins.
 */
class RolloutEarlyRequestNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $requesterName,
        private readonly string $featureKey,
        private readonly string $featureLabel,
        private readonly ?string $dateLabel,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'feature_rollout_early_request',
            'title'       => "{$this->requesterName} asked for {$this->featureLabel} early",
            'body'        => $this->dateLabel
                ? "It is due to switch on {$this->dateLabel}. You can switch it on sooner from Feature rollout."
                : 'You can switch it on sooner from Feature rollout.',
            'action_url'  => '/corex/feature-rollout',
            'icon'        => 'hand-raised',
            'feature_key' => $this->featureKey,
        ];
    }
}
