<?php

namespace App\Services\Matching;

use App\Models\ContactMatch;
use App\Models\MicAgencySetting;
use App\Models\Property;
use App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver;

/**
 * The ONE set of hard rules that decide whether an advert or a property is a match for a buyer's wishlist
 * (.ai/specs/mic-work-tab.md section 13.15). MatchingService::score() asks it first; Core Matches (own stock) and the MIC cache
 * (prospecting_buyer_matches, scored through the same score()) both go through that one method, so a buyer's matches mean the same
 * thing on every screen. A value that fails any rule is NOT a match - it scores 0 and is never shown.
 *
 * An advert that has not captured a value the buyer asked about (no beds, no size, no type, no features...) is not excluded on that
 * field, but the field is returned in `unverified`, and a match with anything unverified cannot score "strong" (see MatchingService::assess).
 */
class BuyerMatchRules
{
    /** A sale price under this is a placeholder or a typo (R4, R1), not a price: treated as "no price captured". */
    public const SALE_PRICE_FLOOR = 10000;

    /** type_key => the wishlist `category` it belongs to; land / farm / other are not mapped, so the category stays unverified. */
    private const CATEGORY_OF_TYPE = [
        'house' => 'residential', 'apartment' => 'residential', 'townhouse' => 'residential',
        'commercial' => 'commercial', 'industrial' => 'commercial',
    ];

    /** @var array<int, float> agency id => price tolerance as a fraction */
    private static array $toleranceMemo = [];

    public function __construct(private readonly MatchingService $matcher, private readonly PropertyTypeResolver $types)
    {
    }

    public static function flush(): void
    {
        self::$toleranceMemo = [];
    }

    /** The agency's "price tolerance above max" as a fraction (0.10 = 10%). */
    public static function toleranceFor(?int $agencyId): float
    {
        if (! $agencyId) {
            return MicAgencySetting::DEFAULTS['price_tolerance_pct'] / 100;
        }

        return self::$toleranceMemo[$agencyId] ??= MicAgencySetting::priceTolerancePctFor($agencyId) / 100;
    }

    /**
     * @return array{ok: bool, reason: ?string, unverified: list<string>}
     */
    public function check(Property $p, ContactMatch $m, ?float $tolerance = null): array
    {
        $agencyId = $m->agency_id ?: ($p->agency_id ?: null);
        $tolerance ??= self::toleranceFor($agencyId ? (int) $agencyId : null);
        $unverified = [];
        $fail = fn (string $reason): array => ['ok' => false, 'reason' => $reason, 'unverified' => []];

        // 1. Listing type: sale vs rental are different markets.
        if ($m->listing_type) {
            if (blank($p->listing_type)) {
                $unverified[] = 'listing type';
            } elseif (strtolower((string) $p->listing_type) !== strtolower((string) $m->listing_type)) {
                return $fail('listing-type');
            }
        }

        // 2. Auctions: a buyer who will not bid at an auction never sees one (open_to_auction is null or true = open).
        if ($m->open_to_auction === false && $this->isAuction($p)) {
            return $fail('auction');
        }

        // 3. Price: the minimum is never relaxed; the maximum is relaxed by the agency's tolerance only.
        if ($m->price_min || $m->price_max) {
            $price = (int) $p->effectivePrice();
            if ($price <= 0 || (! $p->isRental() && $price < self::SALE_PRICE_FLOOR)) {
                $unverified[] = 'price';
            } else {
                if ($m->price_min && $price < (int) $m->price_min) {
                    return $fail('price-below-min');
                }
                if ($m->price_max && $price > (int) ceil((int) $m->price_max * (1 + max(0.0, $tolerance)))) {
                    return $fail('price-above-max');
                }
            }
        }

        // The advert's canonical type (house, apartment, townhouse, land...), mapped the same way MIC maps it everywhere.
        $typeKey = $this->typeKey($p->property_type, $agencyId);

        // 4. Category.
        if ($m->category) {
            $cat = filled($p->category) ? strtolower(trim((string) $p->category)) : (self::CATEGORY_OF_TYPE[$typeKey] ?? null);
            if ($cat === null) {
                $unverified[] = 'category';
            } elseif ($cat !== strtolower(trim((string) $m->category))) {
                return $fail('category');
            }
        }

        // 5. Property type: must be one of the wishlist's types (a Townhouse is not a House). A type the map cannot read falls back to the
        // coarser family (built / land / farm / commercial): a different family still excludes; the same family is kept but unverified.
        $wanted = [];
        $wantedFamilies = [];
        foreach ($m->propertyTypeList() as $name) {
            $k = $this->typeKey($name, $agencyId);
            if ($k !== null && $k !== 'other') {
                $wanted[$k] = true;
            }
            if ($fam = MatchingService::propertyTypeFamily($name)) {
                $wantedFamilies[$fam] = true;
            }
        }
        if ($wanted !== [] || $wantedFamilies !== []) {
            $mapped = $typeKey !== null && $typeKey !== 'other';
            if ($wanted !== [] && $mapped) {
                if (! isset($wanted[$typeKey])) {
                    return $fail('property-type');
                }
            } else {
                $advertFamily = MatchingService::propertyTypeFamily($p->property_type);
                if ($advertFamily !== null && $wantedFamilies !== [] && ! isset($wantedFamilies[$advertFamily])) {
                    return $fail('property-type');
                }
                if (! $mapped) {
                    $unverified[] = 'property type';
                }
            }
        }

        // 6. Suburbs: a buyer who named suburbs only sees those (an open wishlist matches anywhere).
        if (! $this->matcher->suburbCompatible($p, $m)) {
            return $fail('suburb');
        }

        // 7. Counts and sizes: a value below the minimum (or above a maximum) excludes; a missing value is unverified.
        $count = function (string $label, mixed $have, ?int $min, ?int $max = null) use (&$unverified): ?string {
            if (! $min && ! $max) {
                return null;
            }
            if ($have === null || (int) $have <= 0) {
                $unverified[] = $label;

                return null;
            }
            if ($min && (int) $have < $min) {
                return $label . '-below-min';
            }
            if ($max && (int) $have > $max) {
                return $label . '-above-max';
            }

            return null;
        };
        $checks = [
            ['bedrooms', $p->beds, $m->beds_min, $m->bedrooms_max],
            ['bathrooms', $p->baths, $m->baths_min, null],
            ['garages', $p->garages, $m->garages_min, null],
            ['parking', $p->getAttribute('parking'), $m->parking_min, null],
            ['floor size', $p->size_m2, $m->floor_size_min, $m->floor_size_max],
            ['erf size', $p->erf_size_m2, $m->erf_size_min, $m->erf_size_max],
        ];
        foreach ($checks as [$label, $have, $min, $max]) {
            if ($reason = $count($label, $have, $min ? (int) $min : null, $max ? (int) $max : null)) {
                return $fail($reason);
            }
        }

        // 8. Features: a deal-breaker present, or a must-have missing, excludes - but only against an advert that has structured
        // features to read. One without any is "unknown", flagged, never a silent pass or a silent fail.
        $breakers = is_array($m->deal_breakers) ? array_filter($m->deal_breakers) : [];
        $must = is_array($m->must_have_features) ? array_filter($m->must_have_features) : [];
        $nice = is_array($m->nice_to_have_features) ? array_filter($m->nice_to_have_features) : [];
        if ($breakers || $must || $nice) {
            if (! $this->matcher->featuresKnown($p)) {
                $unverified[] = 'features';
            } else {
                foreach ($breakers as $b) {
                    if ($this->matcher->hasFeature($p, (string) $b)) {
                        return $fail('deal-breaker');
                    }
                }
                foreach ($must as $f) {
                    if (! $this->matcher->hasFeature($p, (string) $f)) {
                        return $fail('must-have');
                    }
                }
            }
        }

        return ['ok' => true, 'reason' => null, 'unverified' => array_values(array_unique($unverified))];
    }

    public function isAuction(Property $p): bool
    {
        return strtolower(trim((string) $p->status)) === Property::STATUS_ON_AUCTION;
    }

    private function typeKey(?string $raw, ?int $agencyId): ?string
    {
        return $this->types->keyFor($raw, $agencyId ? (int) $agencyId : null);
    }
}
