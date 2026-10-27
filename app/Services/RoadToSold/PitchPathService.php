<?php

namespace App\Services\RoadToSold;

use App\Models\Contact;
use App\Models\ContactProperty;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Property\PropertyOwnershipGuard;
use App\Services\SellerOutreach\ListingPropertyPromoter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Road to Sold v3 - the pitch path from a row (road-to-sold.md 22.10, R8).
 *
 * Find the owner -> make the property from the MIC address when there is none -> pitch. Every step is an EXISTING CoreX screen opened in the
 * Road to Sold pop-up; this service is the glue that keeps the three steps joined so the agent never reaches "No property or address to pitch
 * about":
 *
 *   - `carry()` is the pop-up context (embed, the prospect, the stage) that every redirect in the chain keeps, so the "Done" bar and the stage
 *     capture still know which prospect they belong to after the form saves and the page moves on;
 *   - `prospectFor()` reads that context back, only for the signed-in person's own prospect (an assistant's agent's) - anything else is ignored,
 *     never an error, so the same screens keep working outside the pop-up;
 *   - `ensureProperty()` gives the prospect a CoreX property: by the LISTING ROUTE (ListingPropertyPromoter, the same code the Pitch Now
 *     capture runs) when the row came from an advert, otherwise by the promote-to-stock path (ProspectPropertyService). Both match an existing
 *     property first and only otherwise create one in the agency's "prospecting" state;
 *   - `ready()` adds the contact as the property's seller and the tracked property's owner, so the composer opens on contact + property;
 *   - `next()` is the decision the row's button asks: the owner is known -> the composer (Pitch directly); nobody yet -> Find owner.
 *
 * Nothing here is agency specific. It never throws to the caller for a property that cannot be made: that answers null and the screens fall
 * back to their own address capture.
 */
class PitchPathService
{
    /** The pop-up context every redirect in the chain keeps. */
    public const CARRY = ['embed', 'rts_prospect', 'rts_stage'];

    public function __construct(
        private readonly ProspectService $prospects,
        private readonly ProspectPropertyService $properties,
        private readonly ListingPropertyPromoter $promoter,
    ) {
    }

    /** @return array<string,string> the pop-up parameters present on this request (empty outside the pop-up) */
    public function carry(Request $request): array
    {
        $out = [];
        foreach (self::CARRY as $key) {
            $value = $request->input($key);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * The viewer's OWN prospect named by the request, or null. $trackedPropertyId narrows it to the property the screen is about, so a
     * stale or forged id from another property is ignored.
     */
    public function prospectFor(Request $request, User $viewer, ?int $trackedPropertyId = null): ?RtsProspect
    {
        $id = (int) $request->input('rts_prospect');
        if ($id <= 0) {
            return null;
        }
        $prospect = RtsProspect::query()->whereKey($id)->where('user_id', (int) $viewer->ownershipUserId())->first();
        if (! $prospect || ($trackedPropertyId !== null && (int) $prospect->tracked_property_id !== $trackedPropertyId)) {
            return null;
        }

        return $prospect;
    }

    /** The prospect's CoreX property, made from the MIC address when it has none. Null only when no property can be made. */
    public function ensureProperty(RtsProspect $prospect, User $actor): ?Property
    {
        if ($prospect->property_id) {
            $existing = Property::query()->withoutGlobalScopes()->whereNull('deleted_at')->find($prospect->property_id);
            if ($existing) {
                return $existing;
            }
        }
        $tp = TrackedProperty::query()->withoutGlobalScopes()->where('agency_id', $prospect->agency_id)->find($prospect->tracked_property_id);
        if (! $tp) {
            return null;
        }

        $property = $prospect->prospecting_listing_id
            ? $this->fromListing($prospect, $tp, $actor)
            : null;
        $property ??= $this->properties->ensureProperty($tp, $actor);
        if (! $property) {
            return null;
        }

        $prospect->forceFill(['property_id' => $property->id])->save();

        return $property;
    }

    /** The listing route (road-to-sold.md 22.10 step 2) for a row that came from an advert. Null when the advert has no usable address. */
    private function fromListing(RtsProspect $prospect, TrackedProperty $tp, User $actor): ?Property
    {
        $listing = DB::table('prospecting_listings')->where('id', $prospect->prospecting_listing_id)
            ->where('agency_id', $prospect->agency_id)->whereNull('deleted_at')->first();
        if (! $listing) {
            return null;
        }
        $property = null;
        if (! empty($listing->matched_property_id)) {
            $property = Property::query()->withoutGlobalScopes()->whereNull('deleted_at')->find((int) $listing->matched_property_id);
        }
        if (! $property) {
            // An advert with no street address borrows the tracked property's; with neither, the screens ask for it (never a placeholder).
            $address = trim((string) ($listing->address ?? '')) !== '' ? null : trim((string) $tp->displayAddress());
            if (trim((string) ($listing->address ?? '')) === '' && ($address === null || $address === '')) {
                return null;
            }
            try {
                $property = $this->promoter->promote((int) $prospect->agency_id, $listing, $actor, $address);
            } catch (Throwable $e) {
                Log::warning('Road to Sold pitch path: the listing route could not make a property (falling back)', [
                    'prospect_id' => $prospect->id, 'message' => $e->getMessage(),
                ]);

                return null;
            }
            DB::table('prospecting_listings')->where('id', $listing->id)->whereNull('matched_property_id')
                ->update(['matched_property_id' => $property->id, 'matched_at' => now(), 'tracked_property_id' => $tp->id, 'updated_at' => now()]);
        }
        if (! $tp->promoted_to_property_id) {
            $tp->forceFill([
                'promoted_to_property_id' => $property->id, 'promoted_at' => now(), 'promoted_by_user_id' => $actor->id,
                'status' => TrackedProperty::STATUS_PROMOTED,
            ])->save();
        }

        return $property;
    }

    /**
     * The contact is the seller of the prospect's property and the tracked property's owner (never overwriting an owner already captured).
     * Returns the property, or null when none can be made - the caller then sends the agent to the address capture instead of the composer.
     */
    public function ready(RtsProspect $prospect, Contact $contact, User $actor): ?Property
    {
        $property = $this->ensureProperty($prospect, $actor);
        if (! $property) {
            return null;
        }
        try {
            self::linkSellerIfNone((int) $contact->id, $property);
        } catch (Throwable $e) {
            // an owner set behind an open deal cannot move; the composer still opens on the contact and the property
            Log::info('Road to Sold pitch path: the contact was not linked to the property', ['property_id' => $property->id, 'message' => $e->getMessage()]);
        }
        TrackedProperty::query()->withoutGlobalScopes()->whereKey($prospect->tracked_property_id)->whereNull('owner_contact_id')
            ->update(['owner_contact_id' => $contact->id]);

        return $property;
    }

    /**
     * The contact becomes the property's seller - unless they already hold ANY role on it (owner, landlord...). One contact holds one role
     * per property and linking again would quietly change it, so an existing link is left exactly as it is.
     */
    public static function linkSellerIfNone(int $contactId, Property $property): void
    {
        $has = ContactProperty::query()->where('contact_id', $contactId)->where('property_id', $property->id)->exists();
        if ($has) {
            return;
        }
        app(PropertyOwnershipGuard::class)->assertCanLink($property, 'seller');
        ContactPropertyLinker::link($contactId, (int) $property->id, 'seller');
    }

    /**
     * What the row's button does next. Owner known -> the composer on that contact and the property (made now if needed). No owner -> Find
     * owner, on the screen that fits the row (the advert's capture when there is one, otherwise the tracked property's).
     *
     * @return array{step:string,label:string,url:string,property_id:?int,contact_id:?int}
     */
    public function next(RtsProspect $prospect, User $actor, array $carry = []): array
    {
        $carry += ['embed' => '1', 'rts_prospect' => (string) $prospect->id, 'rts_stage' => (string) $prospect->stage];
        $ownerId = (int) TrackedProperty::query()->withoutGlobalScopes()->whereKey($prospect->tracked_property_id)->value('owner_contact_id');
        $owner = $ownerId ? Contact::query()->withoutGlobalScopes()->where('agency_id', $prospect->agency_id)->whereNull('deleted_at')->find($ownerId) : null;

        if ($owner) {
            $property = $this->ready($prospect, $owner, $actor);
            if ($property) {
                return [
                    'step' => 'pitch', 'label' => 'Pitch', 'property_id' => (int) $property->id, 'contact_id' => (int) $owner->id,
                    'url' => route('seller-outreach.composer.show', ['contact' => $owner->id, 'property_id' => $property->id, 'channel' => 'whatsapp'] + $carry),
                ];
            }
        }

        return [
            'step' => 'find_owner', 'label' => 'Find owner', 'property_id' => $prospect->property_id ? (int) $prospect->property_id : null, 'contact_id' => null,
            'url' => $prospect->prospecting_listing_id
                ? route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $prospect->prospecting_listing_id] + $carry)
                : route('seller-outreach.entry.from-tracked-property', ['trackedProperty' => $prospect->tracked_property_id] + $carry),
        ];
    }
}
