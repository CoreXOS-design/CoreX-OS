<?php

namespace App\Notifications;

use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * AT-68 — renewal reminder. When an agent extends a lapsed mandate's expiry_date
 * into the future and saves, the listing is (or was) OFF the portals because the
 * mandate had expired. Pulling a listing down is automatic; putting it back on the
 * portals is the AGENT'S decision (Johan's ruling) — so we do NOT auto-relist, we
 * REMIND the agent to syndicate.
 *
 * In-app (database) channel ONLY, delivered directly (not through the CommandCenter
 * dispatcher) so it always reaches the bell — a discrete, must-see reminder must not
 * be dropped by open-hours gating, and it needs no queue worker (QA1 is web-only).
 */
class MandateNeedsResyndicationNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Property $property,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $address = method_exists($this->property, 'buildDisplayAddress')
            ? $this->property->buildDisplayAddress()
            : ($this->property->address ?? $this->property->title ?? 'Property');

        return [
            'type'        => 'mandate_needs_resyndication',
            'title'       => 'Mandate renewed — re-syndicate to advertise',
            'body'        => $address . ' was pulled off the portals when its mandate expired. '
                . 'You extended the mandate — syndicate it again to advertise it.',
            'action_url'  => '/corex/properties/' . $this->property->id,
            'icon'        => 'megaphone',
            'property_id' => $this->property->id,
        ];
    }
}
