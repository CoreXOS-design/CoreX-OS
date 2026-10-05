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
 * .ai/specs/rental-work-orders.md §4 — on creation, a plain-language notice
 * naming the issue and (if known) the trade type; on completion,
 * confirmation it's done, who paid, and a link to the completion photos.
 * Skipped entirely (not queued) if the property has no owner attached —
 * see RentalWorkOrderService::notifyOwner()'s own note.
 */
class RentalWorkOrderOwnerMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const STAGE_CREATED = 'created';
    public const STAGE_COMPLETED = 'completed';
    /** §14.21 — a job card's quote was edited and RE-SENT; this one replaces the earlier revision. */
    public const STAGE_QUOTE_REVISED = 'quote_revised';

    public string $ownerName;
    public string $agencyName;
    public string $propertyAddress;

    public function __construct(public RentalWorkOrder $workOrder, public string $stage, string $ownerName)
    {
        $this->onQueue('mail');
        $this->ownerName = $ownerName ?: 'there';
        $this->agencyName = $workOrder->property?->agency?->name ?? config('mail.from.name', 'CoreX OS');
        $this->propertyAddress = $workOrder->property?->buildDisplayAddress() ?: ('Property #' . $workOrder->property_id);
    }

    public function envelope(): Envelope
    {
        $subject = match ($this->stage) {
            self::STAGE_COMPLETED => "Work completed — {$this->propertyAddress}",
            self::STAGE_QUOTE_REVISED => "Revised quote — {$this->propertyAddress}",
            default => "A work order was logged — {$this->propertyAddress}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.work-order-owner');
    }
}
