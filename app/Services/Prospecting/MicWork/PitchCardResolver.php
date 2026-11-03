<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use Illuminate\Http\Request;

/**
 * road-to-sold.md 23.23: which advert's card the pitch popup (and the clickable header on the pitch screens) shows.
 *
 * The caller knows one of three things: the advert (`_pitch_card`), the CoreX property (`_pitch_property`, the pitch-ready and outreach screens) or
 * the tracked property (`_pitch_tracked`, the map and Road to Sold). This answers ONE prospecting listing id of the viewer's agency, preferring the
 * live advert and the most recently seen, or null. It never decides who may see the card: the list's own `visibleTo` scope does that afterwards.
 */
final class PitchCardResolver
{
    public function listingId(Request $request, int $agencyId): ?int
    {
        return $this->forIds($agencyId, (int) $request->query('_pitch_card'), (int) $request->query('_pitch_property'), (int) $request->query('_pitch_tracked'));
    }

    /** The listing a header should open, from whatever the screen knows (0 / null = unknown). Null when the property has no advert: the header is then not a link. */
    public function forIds(int $agencyId, ?int $listingId = null, ?int $propertyId = null, ?int $trackedId = null): ?int
    {
        if ($listingId) {
            return ProspectingListing::query()->where('agency_id', $agencyId)->whereKey($listingId)->exists() ? $listingId : null;
        }

        $trackedIds = [];
        if ($trackedId) {
            $trackedIds = [$trackedId];
        } elseif ($propertyId) {
            $trackedIds = TrackedProperty::query()->where('agency_id', $agencyId)->where('promoted_to_property_id', $propertyId)->pluck('id')->all();
            $direct = ProspectingListing::query()->where('agency_id', $agencyId)->where('matched_property_id', $propertyId)
                ->orderByDesc('is_active')->orderByDesc('last_seen_at')->value('id');
            if ($direct) {
                return (int) $direct;
            }
        }
        if ($trackedIds === []) {
            return null;
        }

        $id = ProspectingListing::query()->where('agency_id', $agencyId)->whereIn('tracked_property_id', $trackedIds)
            ->orderByDesc('is_active')->orderByDesc('last_seen_at')->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * The attributes that make a header block open the card (`data-pitch-peek`), or '' when the property has no advert to show (then it is plain text).
     * Escaped here, so a view prints it with {!! !!}.
     */
    public function peekAttributes(?int $listingId = null, ?int $propertyId = null, ?int $trackedId = null): string
    {
        // inside the pitch popup the fold-out is the popup's own header (one component, every step; road-to-sold.md 23.30): the screens print no second one
        if (\App\Support\Embed::on()) {
            return '';
        }
        $agencyId = (int) (auth()->user()?->effectiveAgencyId() ?: 0);
        $id = $agencyId > 0 ? $this->forIds($agencyId, $listingId, $propertyId, $trackedId) : null;

        return $id ? 'data-pitch-peek data-listing="' . $id . '" role="button" tabindex="0" aria-expanded="false" style="cursor:pointer;" title="Show me this property"' : '';
    }

    /** @param iterable<int|string> $trackedIds @return array<int,true> the tracked properties (of this agency) that have an advert to show, by id; one query */
    public function trackedWithCards(int $agencyId, iterable $trackedIds): array
    {
        $ids = collect($trackedIds)->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();
        if ($ids === [] || $agencyId <= 0) {
            return [];
        }

        return ProspectingListing::query()->where('agency_id', $agencyId)->whereIn('tracked_property_id', $ids)->distinct()->pluck('tracked_property_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }
}
