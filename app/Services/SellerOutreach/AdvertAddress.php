<?php

namespace App\Services\SellerOutreach;

use App\Models\Prospecting\TrackedProperty;
use App\Models\ProspectingListing;
use App\Models\Property;
use App\Models\RoadToSold\RtsProspect;

/**
 * road-to-sold.md 23.30: inside the pitch popup the address is the ADVERT's - the one in the header - on every step. The CoreX property linked through the deed can
 * carry the deed's wording ("26 Windsor Avenue" for an advert at "3541 James Street"); that one is shown once, as a small "Deed: ..." line under the header, and only
 * when it really differs.
 */
final class AdvertAddress
{
    /** The advert's own address (its listing, else the file's tracked property); null when neither is known. */
    public static function of(?ProspectingListing $listing, ?TrackedProperty $tp): ?string
    {
        $a = trim((string) ($listing?->address ?? ''));

        return $a !== '' ? $a : (trim((string) $tp?->displayAddress()) ?: null);
    }

    /** The advert's address for a Road to Sold record, null when the record has no advert or file to read it from. */
    public static function forProspect(RtsProspect $p): ?string
    {
        $listing = $p->prospecting_listing_id
            ? ProspectingListing::withoutGlobalScopes()->where('agency_id', $p->agency_id)->whereNull('deleted_at')->find((int) $p->prospecting_listing_id) : null;
        $tp = $p->tracked_property_id ? TrackedProperty::query()->withoutGlobalScopes()->where('agency_id', $p->agency_id)->find((int) $p->tracked_property_id) : null;

        return self::of($listing, $tp);
    }

    /** The linked property's own address wording, for the "Deed:" line - null when it is the same address as the advert's. */
    public static function deedLine(?Property $property, ?string $advert): ?string
    {
        $deed = trim((string) ($property?->address ?? ''));
        if ($deed === '' || $advert === null || $advert === '') {
            return null;
        }

        return self::same($deed, $advert) ? null : $deed;
    }

    /** Same street address ignoring case, punctuation and a trailing suburb / town ("3541 James Street" = "3541 James St, Uvongo"). */
    public static function same(string $a, string $b): bool
    {
        $n = function (string $s): string {
            $s = mb_strtolower(trim(explode(',', $s)[0]));
            $s = preg_replace(['/\b(street)\b/', '/\b(avenue)\b/', '/\b(road)\b/', '/\b(drive)\b/', '/\b(crescent)\b/', '/\b(lane)\b/'], ['st', 'ave', 'rd', 'dr', 'cres', 'ln'], $s);

            return trim(preg_replace('/[^a-z0-9]+/', ' ', (string) $s));
        };

        return $n($a) === $n($b);
    }
}
