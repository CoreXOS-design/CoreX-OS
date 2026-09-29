<?php

namespace App\Notifications;

use App\Models\OtherAgencyStockUnlock;
use App\Models\Property;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * .ai/specs/other-agency-stock.md §8a — tells the requesting agent whether
 * their unlock request was approved or declined. In-app (database) ONLY.
 */
class OtherAgencyStockUnlockDecidedNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected OtherAgencyStockUnlock $decision,
        protected Property $property,
        protected User $decidedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $approved = $this->decision->event_type === OtherAgencyStockUnlock::EVENT_APPROVED;
        $address = method_exists($this->property, 'buildDisplayAddress')
            ? $this->property->buildDisplayAddress()
            : ($this->property->title ?? 'Property');

        return [
            'type'        => 'other_agency_stock_unlock_decided',
            'title'       => $approved
                ? "{$this->decidedBy->name} approved your edit request"
                : "{$this->decidedBy->name} declined your edit request",
            'body'        => $address,
            'action_url'  => '/corex/properties/' . $this->property->id . '?tab=overview',
            'icon'        => $approved ? 'lock-open' : 'lock-closed',
            'property_id' => $this->property->id,
            'approved'    => $approved,
        ];
    }
}
