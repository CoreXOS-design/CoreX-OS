<?php

declare(strict_types=1);

namespace App\Services\AdTemplates;

use App\Models\PropertySettingItem;

/**
 * Property types for the "Create with AI" pop-up (AT-456 §B.10).
 *
 * The LIST of types is always the agency's own (Settings > Property Types, the same active list the property form and the
 * Ad Builder's "Design for" bar use) - nothing here is a taxonomy. hint() only turns an agency's own type NAME into a few
 * sentences of guidance for the AI about which ad facts make sense for that kind of property; a name it does not recognise
 * gets a generic "adapt sensibly" instruction, so an agency with unusual types is never worse off.
 */
final class AdTemplatePropertyTypes
{
    /** Most types one request may adapt the design to (cost and wait grow with each). */
    public const MAX_TYPES = 5;

    /**
     * The agency's active property type names, in the agency's own order.
     * Pinned to the agency in the query itself (an owner-role user with no agency switched in is not limited by the scope).
     *
     * @return array<int, string>
     */
    public static function forAgency(int $agencyId): array
    {
        return PropertySettingItem::group(PropertySettingItem::GROUP_TYPE)
            ->where('agency_id', $agencyId)
            ->where('active', true)
            ->pluck('name')
            ->map(fn ($n) => trim((string) $n))
            ->filter(fn (string $n) => $n !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Match what the user ticked to the agency's list: exact spelling from the list comes back, in the order ticked, each once.
     * Returns the names that are NOT on the list in the second slot.
     *
     * @param  array<int, mixed>  $ticked
     * @param  array<int, string> $allowed the agency's own list
     * @return array{0: array<int, string>, 1: array<int, string>}  [matched (list spelling), unknown]
     */
    public static function match(array $ticked, array $allowed): array
    {
        $byLower = [];
        foreach ($allowed as $name) {
            $byLower[mb_strtolower(trim($name))] = $name;
        }

        $matched = [];
        $unknown = [];
        foreach ($ticked as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            $hit = $byLower[mb_strtolower($t)] ?? null;
            if ($hit === null) {
                $unknown[] = mb_substr($t, 0, 60);
            } elseif (! in_array($hit, $matched, true)) {
                $matched[] = $hit;
            }
        }

        return [$matched, $unknown];
    }

    /**
     * Which kind of property a type NAME is, from whole-word keyword guesses: rental, land, farm, sectional, commercial, family
     * or other. Shared by hint() and the demo photos the previews show (AdTemplateDemoPhotos).
     */
    public static function category(string $typeName): string
    {
        $n = mb_strtolower($typeName);
        // Whole words only (an optional plural s): "Standalone House" must not read as a "stand" (a plot).
        $has = static function (array $words) use ($n): bool {
            return (bool) preg_match('/(?<![\p{L}])(' . implode('|', array_map(static fn ($w) => preg_quote($w, '/'), $words)) . ')s?(?![\p{L}])/u', $n);
        };

        return match (true) {
            $has(['rent', 'rental', 'to let', 'lease', 'letting'])                                            => 'rental',
            $has(['vacant', 'land', 'plot', 'stand', 'erf'])                                                  => 'land',
            $has(['farm', 'smallholding', 'agricultural', 'game'])                                            => 'farm',
            $has(['apartment', 'flat', 'sectional', 'townhouse', 'cluster', 'duplex', 'simplex', 'penthouse']) => 'sectional',
            $has(['commercial', 'industrial', 'office', 'retail', 'warehouse', 'business', 'factory'])        => 'commercial',
            $has(['house', 'home', 'villa', 'estate', 'cottage', 'bungalow', 'residential'])                  => 'family',
            default                                                                                           => 'other',
        };
    }

    /**
     * Plain-language guidance for adapting a design to one of the agency's property types.
     * Built from the type's NAME (keyword guesses); the fact names are the render kernel's own.
     * $pillsAllowed = false (decision 20: the user did not tick "Key features as pills") leaves every mention of pills out.
     */
    public static function hint(string $typeName, bool $pillsAllowed = true): string
    {
        $category = self::category($typeName);

        if ($category === 'rental') {
            return 'Rentals: lead with the rent per month ("rent_per_month") and when it can be moved into ("available_from"); "pet_friendly" suits a rental. '
                . 'Levy, rates and price per square metre are sales facts - do not build the design around "levy", "rates_taxes" or "price_per_m2".';
        }
        if ($category === 'land') {
            return 'Land with nothing built on it: it has NO bedrooms, bathrooms, garages, parking or floor size. Remove "beds", "baths", "garages", "parking", "garages_or_parking", "size_m2" and the "features" line, '
                . 'and show the plot instead ("erf_size", or "size_or_land") with the price (and "price_per_m2" if it suits). Re-balance the layout so it does not look as if something is missing.';
        }
        if ($category === 'farm') {
            return 'Farms and smallholdings: the size of the land is the headline ("erf_size" or "size_or_land"), the house matters less. Keep "beds"/"baths" only as small secondary details, '
                . 'and use wide scenic photos' . ($pillsAllowed ? '; water and power ("feature_pills") are good to show.' : '.');
        }
        if ($category === 'sectional') {
            return 'Sectional-title homes: a monthly levy and rates matter to buyers, so show "levy" (and "rates_taxes" if it fits), beds, baths and parking ("garages_or_parking"). '
                . 'There is little or no garden or plot, so do not feature "erf_size".';
        }
        if ($category === 'commercial') {
            return 'Commercial and industrial property: bedrooms and bathrooms do not apply. Lead with the floor area ("size_m2") and the price per square metre ("price_per_m2"), '
                . 'with the plot ("erf_size") as a secondary fact. Use a businesslike, uncluttered look.';
        }
        if ($category === 'family') {
            return 'Family homes: beds, baths and garages ("garages_or_parking") are the key facts; ' . ($pillsAllowed ? 'the plot ("erf_size") and the key features as pills ("feature_pills") are good supporting details.' : 'the plot ("erf_size") is a good supporting detail.');
        }

        return 'Adapt the design to what buyers of this kind of property care about: decide, from its name, which of the listing facts make sense (and which do not - remove those) and give the right ones room.';
    }
}
