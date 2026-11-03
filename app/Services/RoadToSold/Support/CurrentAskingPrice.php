<?php

namespace App\Services\RoadToSold\Support;

use App\Models\ProspectingListing;
use Illuminate\Support\Collection;

/**
 * The one answer to "what is this property asking right now" for Road to Sold (road-to-sold.md 22.23). A tracked property can carry several listings
 * (a relist, a duplicate sighting); Today's intake and My prospects both read the price of the same one: live before gone, then the most recently
 * seen, then the newest record. The tracked property's own last-known price is only the fallback when no listing carries one.
 */
class CurrentAskingPrice
{
    /** @param iterable<ProspectingListing> $listings */
    public static function pick(iterable $listings): ?ProspectingListing
    {
        return Collection::make($listings)
            ->sortByDesc(fn ($l) => [(int) $l->is_active, $l->last_seen_at?->timestamp ?? 0, (int) $l->id])
            ->first();
    }

    /**
     * @param  array<int,int>  $trackedPropertyIds
     * @return array<int,int>  tracked property id => current price (only those that have one)
     */
    public static function forTracked(array $trackedPropertyIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $trackedPropertyIds))));
        if (! $ids) {
            return [];
        }

        $listings = ProspectingListing::query()->whereIn('tracked_property_id', $ids)->get(['id', 'tracked_property_id', 'price', 'is_active', 'last_seen_at']);
        $out = [];
        foreach ($listings->groupBy('tracked_property_id') as $tpId => $group) {
            $price = self::pick($group)?->price;
            if ($price) {
                $out[(int) $tpId] = (int) $price;
            }
        }

        return $out;
    }
}
