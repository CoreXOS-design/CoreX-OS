<?php

namespace App\Notifications;

use App\Models\Lease;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * AT-439 — the real rentals `Lease` sibling of LeaseExpirationAlert
 * (which stays as-is, unmodified, for the legacy DocuPerfect LeaseRecord
 * expiry path it already serves). A separate class rather than widening
 * the existing one: the two models' shapes genuinely differ (property
 * address and tenant name are relations here, not denormalised columns),
 * and LeaseExpirationAlert's constructor is typed to LeaseRecord — out
 * of scope to touch for this task.
 */
class LeaseExpiryAlert extends Notification
{
    use Queueable;

    public function __construct(
        public Lease $lease,
        public string $level,
        public int $daysLeft,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $address = $this->lease->property?->title ?? 'Unknown property';
        $tenant = $this->lease->tenantNames();
        $rental = number_format((float) $this->lease->rental_amount, 0, '.', ' ');

        $message = match ($this->level) {
            'expired' => "Lease for {$address} has expired. Tenant: {$tenant}. Please arrange renewal or handover.",
            default => "Lease for {$address} expires in {$this->daysLeft} days. Tenant: {$tenant} | R{$rental}/mo.",
        };

        return [
            'type' => 'lease_expiry_alert',
            'level' => $this->level,
            'lease_id' => $this->lease->id,
            'property_address' => $address,
            'tenant_name' => $tenant,
            'days_left' => $this->daysLeft,
            'rental_amount' => $this->lease->rental_amount,
            'lease_end_date' => $this->lease->end_date?->toDateString(),
            'message' => $message,
        ];
    }
}
