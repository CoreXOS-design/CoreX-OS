<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * .ai/specs/rental-inspections.md §45.7 (Build I-5) — the reminder the RESPONSIBLE AGENT gets (in-app bell + email) when a
 * loaded interim date, or a computed In/Out inspection, comes within its lead window, falls due, or goes overdue.
 * Facts only — what is due, when, where — never a conclusion about anyone's rights, and never sent to a tenant or
 * landlord (they are invited when the agent books, through the §43 flow).
 *
 * Plain wording is the build's own; Johan can reword it in one place (the two label maps below).
 */
class RentalInspectionDueReminder extends Notification
{
    use Queueable;

    public function __construct(
        public string $inspectionType,   // in | out | interim
        public string $milestone,        // lead | due | overdue
        public string $dueOn,            // Y-m-d
        public string $propertyAddress,
        public ?int $leaseId,
        public string $source,           // planned_date | due_list
        public string $url,
    ) {}

    private const TYPE_LABEL = [
        'in' => 'Move-in inspection',
        'out' => 'Move-out inspection',
        'interim' => 'Interim inspection',
    ];

    public function via(object $notifiable): array
    {
        return ($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function message(): string
    {
        $label = self::TYPE_LABEL[$this->inspectionType] ?? ucfirst($this->inspectionType) . ' inspection';
        $date = \Illuminate\Support\Carbon::parse($this->dueOn)->format('j M Y');
        $when = $this->milestone === 'overdue' ? "overdue since {$date}" : "due {$date}";

        return "{$label} {$when} — {$this->propertyAddress}";
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'rental_inspection_due_reminder',
            'inspection_type' => $this->inspectionType,
            'milestone' => $this->milestone,
            'due_on' => $this->dueOn,
            'property_address' => $this->propertyAddress,
            'lease_id' => $this->leaseId,
            'source' => $this->source,
            'url' => $this->url,
            'message' => $this->message(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->message())
            ->greeting('Inspection reminder')
            ->line($this->message() . '.')
            ->line($this->source === 'planned_date'
                ? 'This is one of the inspection dates your agency loaded for this tenancy.'
                : 'This inspection is due from the lease dates on file.')
            ->action('Open the Due inspections list', $this->url)
            ->line('The tenant and landlord have not been contacted — they are invited when you book the inspection.');
    }
}
