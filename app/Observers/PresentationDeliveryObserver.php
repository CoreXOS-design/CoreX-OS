<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\Presentation\PresentationPresented;
use App\Models\Presentation;
use App\Models\PresentationDelivery;
use App\Support\Events\SafeRaise;

/**
 * Raises PresentationPresented the FIRST time a presentation is given to someone: the first
 * delivery whose sent_at goes from empty to stamped (email sent, link copied, WhatsApp click-through
 * all stamp it). A second delivery of the same presentation raises nothing. Road-to-sold.md step 5.
 */
final class PresentationDeliveryObserver
{
    public function created(PresentationDelivery $delivery): void
    {
        $this->raiseIfFirst($delivery);
    }

    public function updated(PresentationDelivery $delivery): void
    {
        if ($delivery->wasChanged('sent_at') && $delivery->getOriginal('sent_at') === null) {
            $this->raiseIfFirst($delivery);
        }
    }

    private function raiseIfFirst(PresentationDelivery $delivery): void
    {
        if ($delivery->sent_at === null) {
            return;
        }

        $earlier = PresentationDelivery::withoutGlobalScopes()
            ->where('presentation_id', $delivery->presentation_id)
            ->where('id', '!=', $delivery->id)
            ->whereNotNull('sent_at')
            ->exists();
        if ($earlier) {
            return;
        }

        SafeRaise::dispatch('PresentationPresented', function () use ($delivery) {
            $p = Presentation::withoutGlobalScopes()->findOrFail($delivery->presentation_id);

            return new PresentationPresented(
                presentationId: (int) $p->id,
                deliveryId: (int) $delivery->id,
                agencyId: (int) $delivery->agency_id,
                userId: (int) $delivery->sent_by_user_id,
                channel: (string) $delivery->channel,
                propertyId: $p->property_id ? (int) $p->property_id : null,
                trackedPropertyId: $p->tracked_property_id ? (int) $p->tracked_property_id : null,
                sellerContactId: $p->seller_contact_id ? (int) $p->seller_contact_id : null,
            );
        });
    }
}
