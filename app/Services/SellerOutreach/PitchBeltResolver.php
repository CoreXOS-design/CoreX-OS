<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * road-to-sold.md 23.23 drop 5: WHICH tracked property the pitch screens judge and log against - one answer for the row and the popup.
 *
 * The belt's gate (OwnerStationService, 23.6) is a question about a tracked property: has it an owner linked (and the deed)? The Road to Sold row asks it
 * about the agent's record. A CoreX property made for the same address can point at ANOTHER tracked property (a duplicate file with no owner), so asking
 * `properties.tracked_property_id` gave the popup a different answer from the row it was opened from (73 Marine Drive: the row had its owner, the popup said
 * "Find the owner first"). The record the agent works is the truth, so it comes first:
 *   1. the record named by the popup (`rts_prospect`), when it is the agent's own and is about this property;
 *   2. the agent's own open record whose property is this property;
 *   3. the property's own tracked property;
 *   4. a tracked property that was promoted to this property.
 */
final class PitchBeltResolver
{
    public function trackedFor(User $actor, ?Property $property, ?int $rtsProspectId = null): ?int
    {
        if (! $property) {
            return null;
        }
        $agencyId = (int) ($actor->effectiveAgencyId() ?: 0);
        $mine = (int) $actor->ownershipUserId();
        $open = fn ($q) => $q->where('agency_id', $agencyId)->where('track', 'sales')->where('user_id', $mine)->whereNull('closed_at')->whereNull('released_at')->whereNull('deleted_at');

        if ($rtsProspectId) {
            $p = RtsProspect::withoutGlobalScopes()->where($open)->whereKey($rtsProspectId)->first();
            if ($p && (int) $p->property_id === (int) $property->id) {
                return (int) $p->tracked_property_id;
            }
        }
        $p = RtsProspect::withoutGlobalScopes()->where($open)->where('property_id', $property->id)->orderByDesc('id')->first();
        if ($p) {
            return (int) $p->tracked_property_id;
        }
        if ($property->tracked_property_id) {
            return (int) $property->tracked_property_id;
        }
        $tp = TrackedProperty::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->where('promoted_to_property_id', $property->id)->orderBy('id')->value('id');

        return $tp ? (int) $tp : null;
    }
}
