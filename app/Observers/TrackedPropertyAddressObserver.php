<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\Prospecting\TrackedPropertyAddressAdded;
use App\Events\Prospecting\TrackedPropertyAddressPrimaryChanged;
use App\Events\Prospecting\TrackedPropertyAddressVerified;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAddress;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `tracked_properties` address cache in sync with the primary
 * `tracked_property_addresses` row (spec §3.2.1).
 *
 * Invariant: at most one address per TP has is_primary = true. The observer
 * enforces it on create + update + delete. Demotion uses updateQuietly() so
 * we don't trigger the observer recursively when flipping siblings off.
 *
 * Domain events fired:
 *   - TrackedPropertyAddressAdded     (every create)
 *   - TrackedPropertyAddressVerified  (verified_at first set)
 *   - TrackedPropertyAddressPrimaryChanged (primary row changes; both old + new IDs in context)
 */
final class TrackedPropertyAddressObserver
{
    public function created(TrackedPropertyAddress $address): void
    {
        // Domain signal for the activity log + any downstream listeners.
        event(new TrackedPropertyAddressAdded($address));

        if ($address->is_primary) {
            $this->promoteAsPrimary($address, previousPrimaryId: null);
        }
    }

    public function updated(TrackedPropertyAddress $address): void
    {
        // is_primary flipped to true (from any other state).
        if ($address->wasChanged('is_primary') && $address->is_primary) {
            $previousPrimaryId = $this->findPreviousPrimaryId($address);
            $this->promoteAsPrimary($address, previousPrimaryId: $previousPrimaryId);
        }

        // verified_at first set (i.e. it was null pre-update and isn't null now).
        if ($address->wasChanged('verified_at')
            && $address->getOriginal('verified_at') === null
            && $address->verified_at !== null
        ) {
            event(new TrackedPropertyAddressVerified($address));
        }
    }

    public function deleted(TrackedPropertyAddress $address): void
    {
        if (!$address->is_primary) {
            return;
        }

        // Promote the next-highest-confidence active address to primary.
        // Order: verified > high > medium > low, then by last_seen_at DESC.
        $next = TrackedPropertyAddress::query()
            ->where('tracked_property_id', $address->tracked_property_id)
            ->whereNull('deleted_at')
            ->where('id', '!=', $address->id)
            ->orderByRaw("FIELD(confidence, 'verified','high','medium','low')")
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->first();

        if (!$next) {
            // No alternative active address. Leave the parent TP's cache
            // as-is — destructive blanking on last-address-delete is worse
            // UX than a stale cached address with no live row backing it.
            return;
        }

        // Promote without re-entering this observer for the demotion step
        // (there is no other primary to demote — we just removed it).
        $next->updateQuietly(['is_primary' => true]);
        $parent = TrackedProperty::withoutGlobalScopes()->find($next->tracked_property_id);
        if ($parent) {
            $this->refreshParentCache($parent, $next);
        }

        event(new TrackedPropertyAddressPrimaryChanged(
            trackedPropertyId: (int) $address->tracked_property_id,
            previousPrimaryAddressId: (int) $address->id,
            newPrimaryAddressId: (int) $next->id,
            agencyId: (int) $address->agency_id,
        ));
    }

    /**
     * Make this row primary: demote any other primary on the same TP, then
     * refresh the parent cache.
     */
    private function promoteAsPrimary(TrackedPropertyAddress $address, ?int $previousPrimaryId): void
    {
        // updateQuietly + DB::table to avoid Eloquent events on the demotion
        // (we don't want the observer to re-fire for the demote step).
        $demoted = DB::table('tracked_property_addresses')
            ->where('tracked_property_id', $address->tracked_property_id)
            ->where('id', '!=', $address->id)
            ->where('is_primary', true)
            ->update(['is_primary' => false, 'updated_at' => now()]);

        // Bypass BelongsToAgency global scope — the observer runs in observer
        // / queue / system contexts where Auth::user() may be null. The
        // explicit ->where('agency_id') in TrackedProperty::queryWithoutAgencyScope()
        // would be incorrect here too (we own this record by definition; the
        // address row's agency_id IS the parent's). Use plain
        // withoutGlobalScopes() + find by id.
        $parent = TrackedProperty::withoutGlobalScopes()->find($address->tracked_property_id);
        if ($parent) {
            $this->refreshParentCache($parent, $address);
        }

        if ($demoted > 0 || $previousPrimaryId !== null) {
            event(new TrackedPropertyAddressPrimaryChanged(
                trackedPropertyId: (int) $address->tracked_property_id,
                previousPrimaryAddressId: $previousPrimaryId,
                newPrimaryAddressId: (int) $address->id,
                agencyId: (int) $address->agency_id,
            ));
        }
    }

    private function findPreviousPrimaryId(TrackedPropertyAddress $address): ?int
    {
        $row = DB::table('tracked_property_addresses')
            ->where('tracked_property_id', $address->tracked_property_id)
            ->where('id', '!=', $address->id)
            ->where('is_primary', true)
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at')
            ->first(['id']);

        return $row ? (int) $row->id : null;
    }

    /**
     * Mirror the cached address fields from the primary address onto the
     * parent tracked_properties row. updateQuietly to avoid firing
     * TrackedProperty's own updated() observer (this is just cache sync,
     * not a real edit of the parent).
     */
    private function refreshParentCache(TrackedProperty $parent, TrackedPropertyAddress $primary): void
    {
        $fields = TrackedPropertyAddress::cachedFields();
        $update = [];
        foreach ($fields as $field) {
            $update[$field] = $primary->{$field};
        }
        $parent->updateQuietly($update);
    }
}
