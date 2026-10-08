<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * .ai/specs/rental-inspections.md §51 — the reminder the INSPECTING AGENT gets (in-app bell + email) when a report is waiting
 * for signatures and its signing window is about to close (`lead`) or has closed (`passed`). Facts only; it is never sent to a
 * tenant or landlord, and nothing happens to the inspection when it fires — what to do next is the agent's call.
 */
class RentalInspectionSigningReminder extends Notification
{
    use Queueable;

    public function __construct(
        public string $inspectionTypeName,   // RentalInspection::typeName() — "In-inspection", "Routine inspection"…
        public string $milestone,            // lead | passed
        public string $closesOn,             // Y-m-d
        public int $outstanding,
        public string $propertyAddress,
        public int $inspectionId,
        public string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function message(): string
    {
        $date = \Illuminate\Support\Carbon::parse($this->closesOn)->format('j M Y');
        $when = $this->milestone === 'passed' ? "signing window closed on {$date}" : "signing window closes on {$date}";
        $who = $this->outstanding === 1 ? '1 person still to sign' : $this->outstanding . ' people still to sign';

        return "{$this->inspectionTypeName} — {$when}, {$who} — {$this->propertyAddress}";
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'rental_inspection_signing_reminder',
            'milestone' => $this->milestone,
            'closes_on' => $this->closesOn,
            'outstanding' => $this->outstanding,
            'property_address' => $this->propertyAddress,
            'rental_inspection_id' => $this->inspectionId,
            'url' => $this->url,
            'message' => $this->message(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->message())
            ->greeting('Signing reminder')
            ->line($this->message() . '.')
            ->line('Open the inspection to resend a signing link, show the QR code, sign on your device, or record a refusal. Nothing has been changed on the inspection.')
            ->action('Open the inspection', $this->url);
    }
}
