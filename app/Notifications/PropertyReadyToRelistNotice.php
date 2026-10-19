<?php

namespace App\Notifications;

use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Release audit 2026-10-18, C-F3 (Johan's ruling) - when a tenancy ends by itself (the move-out date came and the out-inspection
 * confirms the property is empty) CoreX puts the property back on the market in CoreX but pushes NOTHING to Property24 or Private
 * Property. Putting it back on the portals is the agent's decision, so this in-app note tells the agent who owns the listing that it
 * is free again. Database only. Gateway event: property.ready_to_relist.
 */
class PropertyReadyToRelistNotice extends Notification
{
    use Queueable;

    public function __construct(public Property $property) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $address = $this->property->buildDisplayAddress() ?: ($this->property->title ?: 'Unknown property');

        return [
            'type' => 'property_ready_to_relist',
            'property_id' => $this->property->id,
            'property_address' => $address,
            'url' => route('corex.properties.show', $this->property->id),
            'message' => "{$address} is free again and ready to re-list on the portals - check the rent and re-list it from the listing.",
        ];
    }
}
