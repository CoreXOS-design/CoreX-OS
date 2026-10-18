<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * .ai/specs/rental-inspections.md §45.6 (Build I-4) — the in-app alert the INSPECTOR gets when the copies of a completed
 * inspection report did not all go out (a failed send, a party with no email address, or the whole send failing). Facts
 * only; it points the agent at the "Copies sent" panel where each recipient has its own Resend. In-app only — never an
 * email to anyone, and never to a tenant or landlord.
 */
class RentalInspectionCopiesNotDelivered extends Notification
{
    use Queueable;

    public function __construct(
        public int $inspectionId,
        public string $inspectionType,
        public string $propertyAddress,
        public ?int $problemCount,   // null = the whole send failed before any recipient was tried
        public string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function message(): string
    {
        $label = \App\Models\RentalInspection::typeName($this->inspectionType) . ' report';

        return $this->problemCount === null
            ? "{$label} for {$this->propertyAddress} could not be sent — open the inspection to resend it."
            : "{$label} for {$this->propertyAddress}: {$this->problemCount} " . ($this->problemCount === 1 ? 'copy' : 'copies') . " did not go out — open the inspection to resend.";
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'rental_inspection_copies_not_delivered',
            'inspection_id' => $this->inspectionId,
            'inspection_type' => $this->inspectionType,
            'property_address' => $this->propertyAddress,
            'problem_count' => $this->problemCount,
            'url' => $this->url,
            'message' => $this->message(),
        ];
    }
}
