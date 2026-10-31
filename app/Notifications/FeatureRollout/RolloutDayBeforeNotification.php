<?php

namespace App\Notifications\FeatureRollout;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AT-478 §12.4 — told to the agency's rollout admins on the release day before a group switches on, as well
 * as the sign-in prompt, so a busy admin still knows. In-app always; email too when the person has email
 * notifications on (same rule as EventDueReminderNotification). Sent once per group.
 */
class RolloutDayBeforeNotification extends Notification
{
    use Queueable;

    /** @param list<string> $featureLabels */
    public function __construct(
        private readonly string $groupTitles,
        private readonly string $dateLabel,
        private readonly array $featureLabels,
    ) {
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (\App\Models\CommandCenter\UserDashboardSetting::getEffective($notifiable)->notify_email) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Switching on {$this->dateLabel}: {$this->groupTitles}")
            ->greeting("Hi {$notifiable->name},")
            ->line("On {$this->dateLabel} these features switch on for your team:")
            ->line(implode(', ', $this->featureLabels) . '.')
            ->line('Before then you can choose which roles get each one — or hold the group if your team needs more time.')
            ->action('Choose who gets access', url('/corex/feature-rollout'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'feature_rollout_day_before',
            'title'      => "Switching on {$this->dateLabel}: {$this->groupTitles}",
            'body'       => implode(', ', $this->featureLabels) . '. Choose who gets access before then.',
            'action_url' => '/corex/feature-rollout',
            'icon'       => 'sparkles',
        ];
    }
}
