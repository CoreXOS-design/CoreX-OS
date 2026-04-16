<?php

namespace App\Notifications;

use App\Models\P24OnboardingPortal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OnboardingPortalInvitation extends Notification
{
    use Queueable;

    public function __construct(public P24OnboardingPortal $portal) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $agency = $this->portal->agency?->name ?? 'your agency';
        $url = $this->portal->publicUrl();
        $expires = $this->portal->expires_at?->toFormattedDateString() ?? 'soon';

        return (new MailMessage)
            ->subject('Review your CoreX property import — ' . $agency)
            ->greeting('Welcome to CoreX OS')
            ->line("Home Finders Coastal has imported {$agency}'s Property24 stock into CoreX OS.")
            ->line('Please use the secure link below to review each listing and confirm or exclude it. Your changes go live only after you click *Finish review*.')
            ->action('Open review portal', $url)
            ->line("This link expires on {$expires}. Do not share it publicly.")
            ->salutation('— The CoreX OS team');
    }
}
