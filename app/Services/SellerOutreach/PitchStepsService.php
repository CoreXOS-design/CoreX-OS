<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Models\Contact;
use App\Models\Property;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsProspect;
use App\Models\User;

/**
 * road-to-sold.md 23.23 drop 5: the screen behind each pill of the pitch popup, for THIS record.
 *
 * A pill is a control only when its screen exists for the record, so the popup asks once, when it opens, and every pill whose answer is null stays a plain
 * label. The popup knows one of: the advert (listing), the agent's Road to Sold record (prospect) or the tracked property. Property is the MIC card (when
 * the record has an advert); the rest:
 *   owner       the compose screen with the live deed picker (the advert's capture, or the tracked property's when the advert has already been pitched)
 *   pitch       "pitch to sellers" - only when the advert has its CoreX property
 *   contact     the outreach screen on the owner and the property
 *   evaluation  the calendar's own form (PitchBookService)
 * Nothing is created here (no property, no claim, no lock): it only names screens that already exist.
 */
final class PitchStepsService
{
    public function __construct(private readonly PitchBeltResolver $belt, private readonly PitchBookService $book)
    {
    }

    /**
     * @param array<string,string> $carry the popup parameters to keep (embed, rts_prospect, rts_stage)
     * @return array<string,?string> property|owner|pitch|contact|evaluation => address or null
     */
    public function plan(User $actor, ?int $listingId, ?int $prospectId, ?int $trackedId, array $carry = []): array
    {
        $agencyId = (int) ($actor->effectiveAgencyId() ?: 0);
        $out = ['property' => null, 'owner' => null, 'pitch' => null, 'contact' => null, 'evaluation' => null];
        if ($agencyId <= 0) {
            return $out;
        }
        $carry = ['embed' => '1'] + array_filter($carry, fn ($v) => is_string($v) && $v !== '');

        $prospect = $prospectId
            ? RtsProspect::withoutGlobalScopes()->where('agency_id', $agencyId)->where('user_id', (int) $actor->ownershipUserId())->whereNull('deleted_at')->find($prospectId)
            : null;
        $listing = null;
        $lid = $listingId ?: ($prospect?->prospecting_listing_id ? (int) $prospect->prospecting_listing_id : null);
        if ($lid) {
            $listing = ProspectingListing::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find($lid);
        }
        $tpId = $prospect ? (int) $prospect->tracked_property_id : ($trackedId ?: ($listing?->tracked_property_id ? (int) $listing->tracked_property_id : null));
        $tp = $tpId ? TrackedProperty::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->find($tpId) : null;
        if ($prospect && ! isset($carry['rts_prospect'])) {
            $carry['rts_prospect'] = (string) $prospect->id;
        }

        // the MIC card (the same one-card page the popup builds for itself when it knows the advert)
        if ($listing) {
            $out['property'] = route('market-intelligence.work', ['_pitch_card' => $listing->id, 'embed' => 1]);
        }

        // the screen where the owner and the deed are found
        if ($listing && empty($listing->pitched_at)) {
            $out['owner'] = route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $listing->id] + $carry);
        } elseif ($tp) {
            $out['owner'] = route('seller-outreach.entry.from-tracked-property', ['trackedProperty' => $tp->id] + $carry);
        }

        $propertyId = $prospect?->property_id ?: ($listing?->matched_property_id ?: ($tp?->promoted_to_property_id));
        $property = $propertyId ? Property::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find((int) $propertyId) : null;

        if ($listing && $listing->matched_property_id && $property) {
            $out['pitch'] = route('seller-outreach.entry.pitch-ready-prospecting', ['prospectingListingId' => $listing->id] + $carry);
        }

        $ownerId = $tp?->owner_contact_id;
        $owner = $ownerId ? Contact::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find((int) $ownerId) : null;
        if ($owner && $property) {
            $out['contact'] = route('seller-outreach.composer.show', ['contact' => $owner->id, 'property_id' => $property->id, 'channel' => 'whatsapp'] + $carry);
            $out['evaluation'] = $this->book->url($property, $owner, $carry);
        }

        return $out;
    }
}
