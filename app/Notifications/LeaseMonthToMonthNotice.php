<?php

namespace App\Notifications;

use App\Models\Lease;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * .ai/specs/leases.md §5.3 — the in-app note to the agent when a lease went month-to-month on its own (nothing was
 * recorded when it reached its end date). Database only, like LeaseExpiryAlert; says how to undo it.
 */
class LeaseMonthToMonthNotice extends Notification
{
    use Queueable;

    public function __construct(public Lease $lease, public string $previousEndDate) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $address = $this->lease->property?->buildDisplayAddress() ?? $this->lease->property?->title ?? 'Unknown property';
        $ended = \Carbon\Carbon::parse($this->previousEndDate)->format('j M Y');

        return [
            'type' => 'lease_auto_month_to_month',
            'lease_id' => $this->lease->id,
            'property_address' => $address,
            'tenant_name' => $this->lease->tenantNames(),
            'lease_end_date' => $this->previousEndDate,
            'url' => route('corex.leases.show', $this->lease->id),
            'message' => "Lease for {$address} ended on {$ended} with no notice to vacate and no renewal on record, so it is now month-to-month. "
                . 'If that is not right, reverse it under Lease actions.',
        ];
    }
}
