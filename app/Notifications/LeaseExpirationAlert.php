<?php

namespace App\Notifications;

use App\Models\Docuperfect\LeaseRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaseExpirationAlert extends Notification
{
    use Queueable;

    public function __construct(
        public LeaseRecord $lease,
        public string $level,
        public int $daysLeft,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $address = $this->lease->property_address ?? 'Unknown property';
        $tenant = $this->lease->tenant_name ?? 'Unknown tenant';
        $rental = number_format((float) $this->lease->rental_amount, 0, '.', ' ');

        $message = match ($this->level) {
            'expired' => "Lease for {$address} has expired. Tenant: {$tenant}. Please arrange renewal or handover.",
            'urgent' => "Lease for {$address} expires in {$this->daysLeft} days. Tenant: {$tenant} | R{$rental}/mo.",
            'warning' => "Lease for {$address} expires in {$this->daysLeft} days. Tenant: {$tenant} | R{$rental}/mo.",
            default => "Lease for {$address} expires in {$this->daysLeft} days. Tenant: {$tenant} | R{$rental}/mo.",
        };

        return [
            'type' => 'lease_expiration_alert',
            'level' => $this->level,
            'lease_id' => $this->lease->id,
            'property_address' => $address,
            'tenant_name' => $tenant,
            'days_left' => $this->daysLeft,
            'rental_amount' => $this->lease->rental_amount,
            'lease_end_date' => $this->lease->lease_end_date?->toDateString(),
            'message' => $message,
        ];
    }
}
