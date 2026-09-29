<?php

namespace App\Notifications;

use App\Models\OtherAgencyStockUnlock;
use App\Models\Property;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * .ai/specs/other-agency-stock.md §8a — an agent asked to edit a locked
 * Other Agency Stock property's advert content. Sent to every authorised
 * user. In-app (database) ONLY, matching NewPropertyMatchNotification's
 * precedent — NEVER mail (Johan: "No outbound mail").
 */
class OtherAgencyStockUnlockRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected OtherAgencyStockUnlock $request,
        protected Property $property,
        protected User $requestedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $address = method_exists($this->property, 'buildDisplayAddress')
            ? $this->property->buildDisplayAddress()
            : ($this->property->title ?? 'Property');

        return [
            'type'        => 'other_agency_stock_unlock_requested',
            'title'       => "{$this->requestedBy->name} wants to edit Other Agency Stock",
            'body'        => $address,
            'action_url'  => '/corex/properties/' . $this->property->id . '?tab=overview',
            'icon'        => 'lock-open',
            'property_id' => $this->property->id,
            'request_id'  => $this->request->id,
            'reason'      => $this->request->reason,
        ];
    }
}
