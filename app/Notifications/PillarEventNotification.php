<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PillarEventNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $eventKey,
        public string $pillar,
        public string $title,
        public string $body,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public ?string $subjectLabel = null,
        public ?string $actionUrl = null,
        public string $severity = 'info', // info | warning | overdue
        public array $payload = [],
        public array $channels = ['database'], // database, mail, fcm
        // Optional named mailer (config/mail.php → mailers.*). When set, the
        // mail channel sends through it instead of the default mailer, and the
        // From header is taken from that mailer's from_address/from_name. Lets a
        // caller guarantee delivery even where the default mailer is a sink
        // (e.g. staging 'log'). Null → unchanged default behaviour.
        public ?string $mailer = null,
    ) {}

    public function via(object $notifiable): array
    {
        // Channels are decided by the dispatcher based on user preference.
        return $this->channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event_key'    => $this->eventKey,
            'pillar'       => $this->pillar,
            'title'        => $this->title,
            'body'         => $this->body,
            'subject_type' => $this->subjectType,
            'subject_id'   => $this->subjectId,
            'subject_label'=> $this->subjectLabel,
            'action_url'   => $this->actionUrl,
            'severity'     => $this->severity,
            'payload'      => $this->payload,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $msg = (new MailMessage)
            ->subject($this->title)
            ->line($this->body);
        if ($this->actionUrl) {
            $msg->action('Open in CoreX', url($this->actionUrl));
        }

        // Route through a dedicated mailer when requested, and set the From to
        // that mailer's configured sender so it matches the authenticated SMTP
        // account (prevents SPF / sender-mismatch rejection).
        if ($this->mailer) {
            $msg->mailer($this->mailer);
            $fromAddress = config("mail.mailers.{$this->mailer}.from_address");
            if ($fromAddress) {
                $msg->from($fromAddress, config("mail.mailers.{$this->mailer}.from_name"));
            }
        }

        return $msg;
    }

    /**
     * FCM payload — used when the FCM channel is enabled and a channel package is installed.
     * Keeping it here means the dispatcher can read this shape without hard-depending on a package.
     */
    public function toFcmPayload(): array
    {
        return [
            'notification' => [
                'title' => $this->title,
                'body'  => $this->body,
            ],
            'data' => [
                'event_key'       => $this->eventKey,
                'pillar'          => $this->pillar,
                'subject_type'    => (string) $this->subjectType,
                'subject_id'      => (string) $this->subjectId,
                'action_url'      => (string) $this->actionUrl,
                'severity'        => $this->severity,
                'notification_id' => (string) $this->id,
            ],
        ];
    }
}
