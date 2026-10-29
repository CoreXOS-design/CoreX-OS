<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\Prospecting\OwnerLinkedToTrackedProperty;
use App\Models\Prospecting\TrackedProperty;
use App\Support\Events\SafeRaise;

/**
 * Raises OwnerLinkedToTrackedProperty the moment a tracked property gets its owner
 * (owner_contact_id empty -> a contact), whichever of the four writers did it: the deeds capture
 * (browser extension and screen), the map scheme owner, or the seller-outreach link.
 * Replacing an existing owner is not "owner found" and raises nothing. Road-to-sold.md step 2.
 */
final class TrackedPropertyObserver
{
    public function created(TrackedProperty $tp): void
    {
        $this->raise($tp);
    }

    public function updated(TrackedProperty $tp): void
    {
        // Lane B: promoting (or un-promoting) a tracked property to the agency's own stock updates the agency's own-stock flag at once.
        if ($tp->wasChanged('promoted_to_property_id')) {
            try {
                app(\App\Services\Prospecting\AgencyWorkService::class)->syncOwnStock((int) $tp->agency_id, (int) $tp->id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Agency work own-stock sync failed on tracked property #{$tp->id}: {$e->getMessage()}");
            }
        }
        if ($tp->wasChanged('owner_contact_id') && ! $tp->getOriginal('owner_contact_id')) {
            $this->raise($tp);
        }
    }

    private function raise(TrackedProperty $tp): void
    {
        if (! $tp->owner_contact_id) {
            return;
        }

        SafeRaise::dispatch('OwnerLinkedToTrackedProperty', fn () => new OwnerLinkedToTrackedProperty(
            trackedPropertyId: (int) $tp->id,
            agencyId: (int) $tp->agency_id,
            ownerContactId: (int) $tp->owner_contact_id,
            actorUserId: auth()->id() ? (int) auth()->id() : null,
        ));
    }
}
