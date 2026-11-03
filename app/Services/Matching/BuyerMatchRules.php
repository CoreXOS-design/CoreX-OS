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
 *
 * evaluate() is the single pass over the rules. check() is its fast form (stops at the first failure - what scoring uses); the same pass run
 * to the end ($all) also reports every field's verdict with the wishlist's "wants" and the property's "has" (spec 13.18, "Buyer wants vs
 * property has"), so what the card shows is the very verdict the filters used, never a second calculation.
 */
class BuyerMatchRules
{
    /** A sale price under this is a placeholder or a typo (R4, R1), not a price: treated as "no price captured". */
    public const SALE_PRICE_FLOOR = 10000;

    /** Stamped into every MIC cache row (matched_features.rules). Bump it whenever a matching rule changes: each buyer's stale rows are then rebuilt once, on demand (BuyerMatchFreshness). */
    public const VERSION = '13.15.2';

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
        $e = $this->evaluate($p, $m, $tolerance, false);

        return ['ok' => $e['reason'] === null, 'reason' => $e['reason'], 'unverified' => $e['reason'] === null ? $e['unverified'] : []];
    }

    /**
     * The full pass: every field's verdict. Same rules, same order, same result as check(); it just does not stop at the first miss.
     *
     * @return array{reason: ?string, unverified: list<string>, fields: list<array{key:string,label:string,wants:string,has:string,state:string}>,
     *               must_haves: list<array{label:string,state:string}>, deal_breakers: list<array{label:string,state:string}>}
     */
    public function evaluate(Property $p, ContactMatch $m, ?float $tolerance = null, bool $all = false): array
    {
        $agencyId = $m->agency_id ?: ($p->agency_id ?: null);
        $tolerance ??= self::toleranceFor($agencyId ? (int) $agencyId : null);
        $unverified = [];
        $reason = null;      // the first rule the property failed
        $fields = [];        // key => [label, wants, has, state]
        $mustOut = [];
        $breakOut = [];
        $stop = function () use (&$reason, $all): bool {
            return ! $all && $reason !== null;
        };
        $miss = function (string $r) use (&$reason): void {
            $reason ??= $r;
        };
        $done = function () use (&$reason, &$unverified, &$fields, &$mustOut, &$breakOut): array {
            $list = [];
            foreach ($fields as $k => $f) {
                $list[] = ['key' => $k] + $f;
            }

            return ['reason' => $reason, 'unverified' => array_values(array_unique($unverified)), 'fields' => $list, 'must_haves' => $mustOut, 'deal_breakers' => $breakOut];
        };
        $set = function (string $key, string $label, string $wants, string $has, string $state) use (&$fields): void {
            $fields[$key] = ['label' => $label, 'wants' => $wants, 'has' => $has, 'state' => $state];
        };

        // 1. Listing type: sale vs rental are different markets.
        if ($m->listing_type) {
            if (blank($p->listing_type)) {
                $unverified[] = 'listing type';
            } elseif (strtolower((string) $p->listing_type) !== strtolower((string) $m->listing_type)) {
                $miss('listing-type');
            }
        }
        if ($stop()) {
            return $done();
        }

        // 2. Auctions: a buyer who will not bid at an auction never sees one (open_to_auction is null or true = open).
        if ($m->open_to_auction === false && $this->isAuction($p)) {
            $miss('auction');
        }
        if ($stop()) {
            return $done();
        }

        // 3. Price: the minimum is never relaxed; the maximum is relaxed by the agency's tolerance only.
        $price = (int) $p->effectivePrice();
        if ($m->price_min || $m->price_max) {
            $wants = self::moneyRange($m->price_min, $m->price_max);
            if ($price <= 0 || (! $p->isRental() && $price < self::SALE_PRICE_FLOOR)) {
                $unverified[] = 'price';
                $set('price', 'Price', $wants, '', 'unknown');
            } else {
                $state = 'ok';
                if ($m->price_min && $price < (int) $m->price_min) {
                    $miss('price-below-min');
                    $state = 'miss';
                } elseif ($m->price_max && $price > (int) ceil((int) $m->price_max * (1 + max(0.0, $tolerance)))) {
                    $miss('price-above-max');
                    $state = 'miss';
                }
                $set('price', 'Price', $wants, self::money($price), $state);
            }
        } else {
            $set('price', 'Price', '', $price > 0 ? self::money($price) : '', 'na');
        }
        if ($stop()) {
            return $done();
        }

        // The advert's canonical type (house, apartment, townhouse, land...), mapped the same way MIC maps it everywhere.
        $typeKey = $this->typeKey($p->property_type, $agencyId);

        // 4. Category.
        if ($m->category) {
            $cat = filled($p->category) ? strtolower(trim((string) $p->category)) : (self::CATEGORY_OF_TYPE[$typeKey] ?? null);
            if ($cat === null) {
                $unverified[] = 'category';
            } elseif ($cat !== strtolower(trim((string) $m->category))) {
                $miss('category');
            }
        }
        if ($stop()) {
            return $done();
        }

        // 5. Property type: must be one of the wishlist's types (a Townhouse is not a House). A type the map cannot read falls back to the
        // coarser family (built / land / farm / commercial): a different family still excludes; the same family is kept but unverified.
        $wanted = [];
        $wantedFamilies = [];
        $wantedNames = [];
        foreach ($m->propertyTypeList() as $name) {
            $wantedNames[] = (string) $name;
            $k = $this->typeKey($name, $agencyId);
            if ($k !== null && $k !== 'other') {
                $wanted[$k] = true;
            }
            if ($fam = MatchingService::propertyTypeFamily($name)) {
                $wantedFamilies[$fam] = true;
            }
        }
        $hasType = trim((string) $p->property_type);
        if ($wanted !== [] || $wantedFamilies !== []) {
            $mapped = $typeKey !== null && $typeKey !== 'other';
            $state = 'ok';
            if ($wanted !== [] && $mapped) {
                if (! isset($wanted[$typeKey])) {
                    $miss('property-type');
                    $state = 'miss';
                }
            } else {
                $advertFamily = MatchingService::propertyTypeFamily($p->property_type);
                if ($advertFamily !== null && $wantedFamilies !== [] && ! isset($wantedFamilies[$advertFamily])) {
                    $miss('property-type');
                    $state = 'miss';
                } elseif (! $mapped) {
                    $unverified[] = 'property type';
                    $state = 'unknown';
                }
            }
            $set('type', 'Type', implode(' · ', $wantedNames), $hasType, $state);
        } else {
            $set('type', 'Type', '', $hasType, 'na');
        }
        if ($stop()) {
            return $done();
        }

        // 6. Suburbs: a buyer who named suburbs only sees those (an open wishlist matches anywhere).
        $okSuburb = $this->matcher->suburbCompatible($p, $m);
        if (! $okSuburb) {
            $miss('suburb');
        }
        $suburbNamed = ! empty($m->p24SuburbIdList());
        $set('suburb', 'Suburb', $this->wantedSuburbs($m), (string) ($p->suburb ?? ''), ! $suburbNamed ? 'na' : ($okSuburb ? 'ok' : 'miss'));
        if ($stop()) {
            return $done();
        }

        // 7. Counts and sizes: a value below the minimum (or above a maximum) excludes; a missing value is unverified.
        $count = function (string $key, string $label, mixed $have, ?int $min, ?int $max, string $unit = '') use (&$unverified, $miss, $set): void {
            $fmt = fn (int $n): string => number_format($n);
            $sfx = $unit !== '' ? ' ' . $unit : '';
            if (! $min && ! $max) {
                $set($key, $label, '', $have !== null && (int) $have > 0 ? $fmt((int) $have) . $sfx : '', 'na');

                return;
            }
            $wants = $min && $max ? $fmt($min) . '–' . $fmt($max) . $sfx : ($min ? $fmt($min) . '+' . $sfx : '≤ ' . $fmt($max) . $sfx);
            if ($have === null || (int) $have <= 0) {
                $unverified[] = $label;
                $set($key, $label, $wants, '', 'unknown');

                return;
            }
            $state = 'ok';
            if ($min && (int) $have < $min) {
                $miss($label . '-below-min');
                $state = 'miss';
            } elseif ($max && (int) $have > $max) {
                $miss($label . '-above-max');
                $state = 'miss';
            }
            $set($key, $label, $wants, $fmt((int) $have) . $sfx, $state);
        };
        // the labels double as the "unverified" names (spec 13.15)
        $count('beds', 'bedrooms', $p->beds, $m->beds_min ? (int) $m->beds_min : null, $m->bedrooms_max ? (int) $m->bedrooms_max : null);
        $count('baths', 'bathrooms', $p->baths, $m->baths_min ? (int) $m->baths_min : null, null);
        $count('garages', 'garages', $p->garages, $m->garages_min ? (int) $m->garages_min : null, null);
        $count('parking', 'parking', $p->getAttribute('parking'), $m->parking_min ? (int) $m->parking_min : null, null);
        $count('floor', 'floor size', $p->size_m2, $m->floor_size_min ? (int) $m->floor_size_min : null, $m->floor_size_max ? (int) $m->floor_size_max : null, 'm²');
        $count('erf', 'erf size', $p->erf_size_m2, $m->erf_size_min ? (int) $m->erf_size_min : null, $m->erf_size_max ? (int) $m->erf_size_max : null, 'm²');
        if ($stop()) {
            return $done();
        }

        // 8. Features: a deal-breaker present, or a must-have missing, excludes - but only against an advert that has structured
        // features to read. One without any is "unknown", flagged, never a silent pass or a silent fail.
        $breakers = is_array($m->deal_breakers) ? array_values(array_filter($m->deal_breakers)) : [];
        $must = is_array($m->must_have_features) ? array_values(array_filter($m->must_have_features)) : [];
        $nice = is_array($m->nice_to_have_features) ? array_filter($m->nice_to_have_features) : [];
        if ($breakers || $must || $nice) {
            $known = $this->matcher->featuresKnown($p);
            // Only a deal-breaker or a must-have makes an advert without a feature list "unverified" (and so never strong). A nice-to-have is a wish, not a
            // requirement: adverts almost never carry features, so counting it would cap every such buyer's list at mid.
            if (! $known && ($breakers || $must)) {
                $unverified[] = 'features';
            }
            foreach ($breakers as $b) {
                $state = ! $known ? 'unknown' : ($this->matcher->hasFeature($p, (string) $b) ? 'miss' : 'ok');
                if ($state === 'miss') {
                    $miss('deal-breaker');
                }
                $breakOut[] = ['label' => self::featureLabel((string) $b), 'state' => $state];
            }
            foreach ($must as $f) {
                $state = ! $known ? 'unknown' : ($this->matcher->hasFeature($p, (string) $f) ? 'ok' : 'miss');
                if ($state === 'miss') {
                    $miss('must-have');
                }
                $mustOut[] = ['label' => self::featureLabel((string) $f), 'state' => $state];
            }
        }

        return $done();
    }

    public function isAuction(Property $p): bool
    {
        return strtolower(trim((string) $p->status)) === Property::STATUS_ON_AUCTION;
    }

    private function typeKey(?string $raw, ?int $agencyId): ?string
    {
        return $this->types->keyFor($raw, $agencyId ? (int) $agencyId : null);
    }

    private function wantedSuburbs(ContactMatch $m): string
    {
        $names = is_array($m->suburbs) ? array_values(array_filter(array_map('strval', $m->suburbs))) : [];

        return implode(' · ', $names);
    }

    private static function featureLabel(string $f): string
    {
        return ucfirst(trim(str_replace('_', ' ', $f)));
    }

    /** R950k, R1.0m, R12,500 */
    public static function money(int $n): string
    {
        if ($n >= 1_000_000) {
            return 'R' . number_format($n / 1_000_000, 1, '.', '') . 'm';
        }
        if ($n >= 100_000) {
            return 'R' . number_format((int) round($n / 1000)) . 'k';
        }

        return 'R' . number_format($n);
    }

    /** "≤ R1.0m", "≥ R800k", "R800k – R1.0m" */
    public static function moneyRange(mixed $min, mixed $max): string
    {
        $min = (int) $min;
        $max = (int) $max;

        return $min && $max ? self::money($min) . ' – ' . self::money($max) : ($max ? '≤ ' . self::money($max) : ($min ? '≥ ' . self::money($min) : ''));
    }
}
