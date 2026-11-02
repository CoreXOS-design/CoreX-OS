<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

/**
 * mic-work-tab.md 13.16b item 5: the "Property features" of the full ad, grouped the way a buyer reads them on P24.
 *
 * Input: the captured Property Overview rows (label => value, as the portal wrote them) and the file record. Output: four groups of [label, value] pairs
 * (Rooms, Outside, Building, Extras) and the per-room detail lines kept apart for a collapsed "Room details". Every captured key lands in exactly one place;
 * a key this class does not know goes to Extras. Nothing is dropped except the street address (it is the header) and values that say "No".
 */
final class FullAdFeatures
{
    /** canonical label => the lower-cased keys that mean it (order inside a group = the order of this table) */
    private const ROOMS = [
        'Bedrooms' => ['bedrooms'], 'Bathrooms' => ['bathrooms'], 'Dining room' => ['dining room', 'dining'], 'Study' => ['study'], 'Family room' => ['family room', 'family tv room', 'tv room'],
        'Scullery' => ['scullery'], 'Pantry' => ['pantry'], 'Laundry' => ['laundry'], 'Entrance hall' => ['entrance hall'], 'Other rooms' => ['other', 'other rooms'],
    ];
    private const OUTSIDE = [
        'Garages' => ['garages'], 'Garage' => ['garage'], 'Parking' => ['parking'], 'Garden' => ['garden'], 'Pool' => ['pool', 'swimming pool'], 'Security' => ['security'],
        'Balcony' => ['balcony'], 'Patio' => ['patio'], 'Braai' => ['braai'], 'Fenced' => ['fenced', 'fencing'], 'View' => ['view'], 'Flatlet' => ['flatlet'], 'Outbuildings' => ['outbuildings'],
    ];
    private const BUILDING = [
        'Type' => ['type of property', 'property type', 'type'], 'Floor size' => ['floor size', 'floor area'], 'Erf size' => ['erf size', 'land size', 'stand size'],
        'Rates' => ['rates and taxes', 'rates & taxes', 'rates', 'municipal rates'], 'Levies' => ['levies', 'levy'], 'Listing date' => ['listing date', 'listed date'],
        'Listing number' => ['listing number', 'listing ref', 'listing reference'], 'Age' => ['building age', 'age'], 'Storeys' => ['storeys', 'floors'],
    ];
    private const EXTRAS = [
        'Pets allowed' => ['pets allowed', 'pets'], 'Internet' => ['internet access', 'internet'], 'Temperature control' => ['temperature control'], 'Backup water' => ['backup water'],
        'Backup power' => ['backup power'], 'Wheelchair accessible' => ['wheelchair accessible', 'wheelchair'], 'Special features' => ['special features'], 'Furnished' => ['furnished'],
        'Solar' => ['solar', 'solar panels'], 'Borehole' => ['borehole'],
    ];
    /** dropped: the street address is the header of the ad */
    private const DROP = ['street address', 'address'];

    /**
     * @param  array<string,mixed>  $features  captured rows, label => value
     * @param  array<string,mixed>  $record    the file record (MicWorkList::forFiles): floor_size_m2, erf_size_m2, rates_amount, levies_amount, listed_date
     * @return array{groups: array<string, list<array{0:string,1:string}>>, room_details: list<array{0:string,1:string}>}
     */
    public static function group(array $features, array $record = []): array
    {
        $groups = ['Rooms' => [], 'Outside' => [], 'Building' => [], 'Extras' => []];
        $details = [];
        $seen = [];
        $rank = ['Rooms' => array_keys(self::ROOMS), 'Outside' => array_keys(self::OUTSIDE), 'Building' => array_keys(self::BUILDING), 'Extras' => array_keys(self::EXTRAS)];

        foreach ($features as $rawKey => $rawVal) {
            $key = trim((string) $rawKey);
            $val = trim(is_scalar($rawVal) ? (string) $rawVal : '');
            $lk = mb_strtolower($key);
            if ($key === '' || $val === '' || in_array($lk, self::DROP, true) || in_array(mb_strtolower($val), ['no', 'none', 'false', '0'], true)) {
                continue;
            }
            // per-room detail lists: "Bedroom 1", "Bathroom 2", and Kitchen / Lounge when the value is a list of fittings
            if (preg_match('/^(bedroom|bathroom|en.?suite|toilet|wc)\s*\d*$/i', $key) && ! in_array($lk, ['bedrooms', 'bathrooms'], true)) {
                $details[] = [$key, $val];
                continue;
            }
            if (in_array($lk, ['kitchen', 'lounge', 'living room', 'dining room', 'study', 'family room', 'scullery'], true) && str_contains($val, ',')) {
                $details[] = [$key, $val];
                continue;
            }
            [$group, $label] = self::classify($lk, $key);
            if (isset($seen[$group . '|' . $label])) {
                continue;
            }
            $seen[$group . '|' . $label] = true;
            $groups[$group][] = [$label, self::tidyValue($val)];
        }

        // what the record holds and the page rows did not: Building is filled once, never twice
        $fill = [
            'Floor size' => ! empty($record['floor_size_m2']) ? number_format((float) $record['floor_size_m2']) . ' m²' : null,
            'Erf size' => ! empty($record['erf_size_m2']) ? number_format((float) $record['erf_size_m2']) . ' m²' : null,
            'Rates' => ! empty($record['rates_amount']) ? 'R ' . number_format((float) $record['rates_amount'], 0, '.', ' ') : null,
            'Levies' => ! empty($record['levies_amount']) ? 'R ' . number_format((float) $record['levies_amount'], 0, '.', ' ') : null,
            'Listing date' => ! empty($record['listed_date']) ? \Carbon\Carbon::parse($record['listed_date'])->format('d M Y') : null,
        ];
        foreach ($fill as $label => $v) {
            if ($v !== null && ! isset($seen['Building|' . $label])) {
                $seen['Building|' . $label] = true;
                $groups['Building'][] = [$label, $v];
            }
        }

        // known labels in the order the tables above give them, unknown ones after, in the order captured
        foreach ($groups as $g => $rows) {
            $order = array_flip($rank[$g]);
            $i = 0;
            $keyed = array_map(fn ($row) => [$order[$row[0]] ?? (1000 + $i++), $row], $rows);
            usort($keyed, fn ($a, $b) => $a[0] <=> $b[0]);
            $groups[$g] = array_map(fn ($k) => $k[1], $keyed);
        }

        return ['groups' => array_filter($groups, fn ($rows) => $rows !== []), 'room_details' => $details];
    }

    /** @return array{0:string,1:string} [group, canonical label] */
    private static function classify(string $lk, string $key): array
    {
        foreach ([['Rooms', self::ROOMS], ['Outside', self::OUTSIDE], ['Building', self::BUILDING], ['Extras', self::EXTRAS]] as [$g, $table]) {
            foreach ($table as $label => $keys) {
                if (in_array($lk, $keys, true)) {
                    return [$g, $label];
                }
            }
        }

        return ['Extras', mb_strtoupper(mb_substr($key, 0, 1)) . mb_substr($key, 1)];
    }

    private static function tidyValue(string $v): string
    {
        return in_array(mb_strtolower($v), ['yes', 'true'], true) ? 'Yes' : $v;
    }
}
