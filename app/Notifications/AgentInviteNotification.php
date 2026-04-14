<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

class AgentInviteNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    public static function createFor($notifiable): self
    {
        $token = Password::broker()->createToken($notifiable);
        return new self($token);
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Welcome to CoreX OS — set your password')
            ->greeting('Welcome to CoreX OS')
            ->line('Your agency has set up a CoreX OS account for you.')
            ->line('Click the button below to set your password and activate your account.')
            ->action('Set my password', $url)
            ->line('This link expires in 60 minutes. If you did not expect this email, please ignore it.');
    }
}
