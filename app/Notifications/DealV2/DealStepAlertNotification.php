<?php

namespace App\Notifications\DealV2;

use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\DealV2\DealStepInstance;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AT-158 DR2 WS6 — a deal-pipeline step alert (bell + branded email).
 *
 * Mirrors the calendar reminder pattern (EventDueReminderNotification): the DB
 * channel writes the in-app bell; 'mail' is added only when the recipient's
 * effective preference allows email. One class covers every WS6 alert kind
 * (RAG transition, overdue escalation, BM-approval pending, agent rejection);
 * the caller (NotificationService) supplies the title/body/severity so the
 * routing/idempotency logic lives in one place.
 */
class DealStepAlertNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $kind      rag_transition|escalation|approval|rejection
     * @param  string  $severity  info|warning|overdue (bell + email tone)
     */
    public function __construct(
        public DealStepInstance $step,
        public string $kind,
        public string $title,
        public string $body,
        public string $severity = 'warning',
        public bool $allowEmail = true,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($this->allowEmail) {
            $settings = UserDashboardSetting::getEffective($notifiable);
            if ($settings->notify_email ?? true) {
                $channels[] = 'mail';
            }
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $deal = $this->step->deal;
        $ref  = $deal?->reference ?: ('Deal #' . ($deal?->id ?? '—'));
        $url  = $deal ? route('deals-v2.show', $deal) : url('/deals-v2');

        $msg = (new MailMessage)
            ->subject($this->title)
            ->greeting("Hi {$notifiable->name},")
            ->line($this->body)
            ->line("Deal: **{$ref}**")
            ->line("Step: **{$this->step->name}**");

        if ($this->step->due_date) {
            $msg->line('Due: **' . $this->step->due_date->format('d M Y') . '**');
        }

        return $msg
            ->action('Open Deal', $url)
            ->line('This is an automated CoreX deal-pipeline alert.');
    }

    public function toArray(object $notifiable): array
    {
        $deal = $this->step->deal;

        return [
            'type'        => 'deal_step_' . $this->kind,
            'title'       => $this->title,
            'body'        => $this->body,
            'severity'    => $this->severity,
            'action_url'  => $deal ? route('deals-v2.show', $deal, false) : '/deals-v2',
            'icon'        => 'flag',
            'deal_id'     => $deal?->id,
            'step_id'     => $this->step->id,
        ];
    }
}
