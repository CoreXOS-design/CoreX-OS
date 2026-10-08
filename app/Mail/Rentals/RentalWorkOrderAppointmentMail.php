<?php

namespace App\Mail\Rentals;

use App\Models\RentalWorkOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * W2 (Johan, 8 Oct 2026) - the tenant is told when the appointment for their repair is set or changed. Sent from the
 * WORK ORDER (RentalWorkOrderService::setAppointment): the agent, the owner (portal) and a job-card booking all end
 * here. Says who is doing the work and when; never a price, a quote or an internal job-card detail.
 */
class RentalWorkOrderAppointmentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $tenantName;
    public string $agencyName;
    public string $propertyAddress;
    public string $when;
    public ?string $note;
    public string $who;
    public string $title;
    public string $portalUrl;

    public function __construct(public RentalWorkOrder $workOrder, string $tenantName, public bool $changed = false)
    {
        $this->onQueue('mail');
        $this->tenantName = $tenantName ?: 'there';
        $this->agencyName = $workOrder->property?->agency?->name ?? config('mail.from.name', 'CoreX OS');
        $this->propertyAddress = $workOrder->property?->buildDisplayAddress() ?: ('Property #' . $workOrder->property_id);
        $this->when = $workOrder->appointment_at?->format('l j F Y \a\t H:i') ?? '';
        $this->note = $workOrder->appointment_note;
        $this->title = $workOrder->title;
        $this->portalUrl = url('/portal');

        $name = $workOrder->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL ? null
            : ($workOrder->isOwnerContractor() ? $workOrder->contractor_name : $workOrder->supplier?->name);
        $this->who = match (true) {
            $workOrder->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL => "{$this->agencyName}'s maintenance team",
            $workOrder->isOwnerContractor() => "The owner's contractor" . ($name ? " ({$name})" : ''),
            default => $name ? "Contractor {$name}" : 'A contractor arranged by ' . $this->agencyName,
        };
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->changed ? 'Repair appointment changed' : 'Repair appointment set') . " - {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.work-order-appointment');
    }
}
