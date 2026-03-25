<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SignatureTeamAlert extends Notification
{
    use Queueable;

    public function __construct(
        public string $signerName,
        public string $signerEmail,
        public string $documentName,
        public int $daysSinceSent,
        public string $signerStatus,
        public string $dashboardUrl,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Follow-up needed: {$this->signerName} hasn't signed {$this->documentName}")
            ->view('emails.signatures.team-alert', [
                'agentName' => $notifiable->name,
                'signerName' => $this->signerName,
                'signerEmail' => $this->signerEmail,
                'documentName' => $this->documentName,
                'daysSinceSent' => $this->daysSinceSent,
                'signerStatus' => $this->signerStatus,
                'dashboardUrl' => $this->dashboardUrl,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'signature_team_alert',
            'signer_name' => $this->signerName,
            'signer_email' => $this->signerEmail,
            'document_name' => $this->documentName,
            'days_since_sent' => $this->daysSinceSent,
            'signer_status' => $this->signerStatus,
            'dashboard_url' => $this->dashboardUrl,
            'message' => "{$this->signerName} hasn't signed \"{$this->documentName}\" after {$this->daysSinceSent} days. Consider following up by phone or WhatsApp.",
        ];
    }
}
