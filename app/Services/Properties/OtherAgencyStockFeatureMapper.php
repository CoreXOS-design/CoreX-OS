<?php

namespace App\Services\Properties;

/**
 * .ai/specs/other-agency-stock.md §5d — Property24 "Features" -> the CoreX features an agent
 * would tick on the property page.
 *
 * Property24 spreads a listing's features over the Property Overview, Rooms (or Facilities),
 * External Features, Building and Other Features accordions, plus a row of tags beside the
 * bedroom/bathroom icons. The extension sends every row RAW (section, label, values) and this
 * class — server-side, like the zoning and currency parsing — decides what each one means, so
 * a mapping can be corrected without shipping a new extension.
 *
 * What it produces
 *  - `global`  : the five property-wide groups the property page ticks (The Property, Building, Security,
 *                Connectivity, Sustainability) — `spaces_json.features.*`;
 *  - `spaces`  : extra rooms P24 counts that CoreX also has as a space type (Reception Rooms,
 *                Office, Study …);
 *  - `attributes`: single facts that live in a property column, not a tick (number_of_floors, floor_number,
 *                occupation_date, lease_period) — applied by OtherAgencyStockImportService;
 *  - `unmapped`: rows P24 shows that have NO CoreX equivalent, as "Section / Label = value", so
 *                the gap is visible (reported, never invented into a new CoreX feature).
 *
 * Only labels that exist in CoreX's own picker are ever emitted — {@see catalog()} — so nothing
 * here can create a feature the property page does not offer. Beds, baths, garages, parking,
 * kitchen, garden and pool are NOT handled here (OtherAgencyStockFieldMapper::buildSpacesJson).
 */
final class OtherAgencyStockFeatureMapper
{
    public const CATEGORIES = ['theProperty', 'building', 'security', 'connectivity', 'sustainability'];

    /**
     * Property24's Building section: row label -> the CoreX label prefix ("Wall: Plaster"). The part after the
     * prefix is looked up in the catalog, so the vocabulary lives in ONE place (config/property-spaces.php).
     */
    private const BUILDING_FACETS = [
        'wall' => 'Wall', 'walls' => 'Wall',
        'floor' => 'Floor', 'floors' => 'Floor',
        'roof' => 'Roof',
        'window' => 'Window', 'windows' => 'Window',
        'style' => 'Style',
    ];

    /**
     * Property24's wording of a building material -> CoreX's, per facet (after the facet's own word —
     * "Floors", "Wall", "Roof", "Windows" — has been stripped). Right side is the part after "Wall: " etc.
     */
    private const BUILDING_VALUE_ALIASES = [
        'Wall'   => ['wood' => 'Timber', 'wooden' => 'Timber'],
        'Floor'  => ['carpet' => 'Carpeted', 'wood' => 'Wooden', 'tile' => 'Tiled', 'tiles' => 'Tiled'],
        'Roof'   => ['tile' => 'Tiles', 'iron' => 'Corrugated Iron', 'corrugated' => 'Corrugated Iron', 'galvanised' => 'Zinc', 'galvanized' => 'Zinc', 'aluminum' => 'Aluminium'],
        'Window' => ['aluminum' => 'Aluminium', 'upvc' => 'PVC', 'double glazing' => 'Double Glazed', 'wooden' => 'Wood', 'timber' => 'Wood'],
    ];

    /**
     * In the property page's picker (show.blade.php `_FEATURE_CATEGORIES`) but missing from the
     * config/property-spaces.php mirror of it — reported as drift, not "fixed" here. The test
     * tests/Feature/Properties/OtherAgencyStockFeatureMapperTest.php fails if either list changes
     * out from under this one.
     */
    private const PICKER_ONLY = [
        'theProperty' => ['Communal Braai Area', 'Sea View'],
    ];

    /**
     * P24's wording -> CoreX's label, where they differ. Left side lower-case. The security and
     * response names follow the CoreX -> P24 tag table the syndication mapper already uses
     * (Property24ListingMapper::FEATURE_TAG_MAP), read backwards: ClosedCircuitTV <- CCTV,
     * TwentyFourHourResponse <- Armed Response, Guard <- 24 Hour Guard, Electricfencing <- Electric Fence.
     */
    private const ALIASES = [
        'office'                => ['theProperty', 'Office Building'],
        'closed circuit tv'     => ['security', 'CCTV'],
        '24 hour response'      => ['security', 'Armed Response'],
        'guard'                 => ['security', '24 Hour Guard'],
        'electric fencing'      => ['security', 'Electric Fence'],
        'air conditioning unit' => ['theProperty', 'Air Conditioned'],
        'air conditioning'      => ['theProperty', 'Air Conditioned'],
        'fibre internet'        => ['connectivity', 'Fibre'],
        'satellite'             => ['connectivity', 'Satellite Internet'],
        'wifi'                  => ['connectivity', 'Wi-Fi'],
        'solar panels'          => ['sustainability', 'Solar Panel'],
    ];

    /** Row labels that are Yes/No switches for one CoreX feature: 'yes' ticks it, 'no' ticks nothing. */
    private const FLAGS = [
        'wheelchair accessible' => ['theProperty', 'Wheelchair Friendly'],
        'standalone building'   => ['theProperty', 'Standalone'],
        'generator'             => ['sustainability', 'Generator'],
        'no transfer duty'      => ['theProperty', 'No Transfer Duty'],
    ];

    /**
     * Rows that are single facts stored in a property COLUMN rather than a tick: row label -> attribute key.
     * (floor_number is the unit's own floor; number_of_floors is the building's height.)
     */
    private const ATTRIBUTE_ROWS = [
        'number of floors' => 'number_of_floors',
        'floor number'     => 'floor_number',
        'occupation date'  => 'occupation_date',
        'lease period'     => 'lease_period',
    ];

    /** Rows already read into columns/spaces elsewhere (or plain facts, not features) — not "unmapped". */
    private const HANDLED_ELSEWHERE = [
        'listing number', 'type of property', 'street address', 'listing date', 'floor size', 'erf size',
        'levies', 'rates and taxes', 'zoning', 'price per m²',
        'bedrooms', 'bedroom', 'bathrooms', 'bathroom', 'kitchens', 'kitchen',
        'garage', 'garages', 'parking', 'covered parking', 'carport', 'carports',
        'pool', 'garden', 'gardens',
    ];

    /** Spaces buildSpacesJson() already owns — never added again from a row or tag. */
    private const OWNED_SPACES = ['Bedroom', 'Bathroom', 'Garage', 'Parking', 'Kitchen', 'Garden', 'Pool'];

    /**
     * CoreX's tick-list, by group: label => true. The config mirror plus the picker-only extras.
     *
     * @return array<string, string[]>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::CATEGORIES as $cat) {
            $out[$cat] = array_values(array_unique(array_merge(
                (array) config("property-spaces.feature_categories.{$cat}.features", []),
                self::PICKER_ONLY[$cat] ?? [],
            )));
        }

        return $out;
    }

    /**
     * @param  array<int, array{s?: mixed, k?: mixed, v?: mixed}>  $rows      every accordion row: section, label, values
     * @param  string[]                                            $stripTags  the tags beside the icon strip ("Furnished", "Pet Friendly" …)
     * @return array{global: array<string, string[]>, spaces: array<int, array{type: string, count: int}>, attributes: array<string, int|string>, unmapped: string[]}
     */
    public static function map(array $rows, array $stripTags = []): array
    {
        $global = array_fill_keys(self::CATEGORIES, []);
        $spaces = [];
        $attributes = [];
        $unmapped = [];

        $tick = function (string $cat, string $label) use (&$global): void {
            if (! in_array($label, $global[$cat], true)) {
                $global[$cat][] = $label;
            }
        };

        // A bare P24 word/phrase -> a tick, or false when CoreX has nothing for it.
        $tickValue = function (string $value) use ($tick): bool {
            $hit = self::resolve($value);
            if ($hit === null) {
                return false;
            }
            $tick($hit[0], $hit[1]);

            return true;
        };

        foreach (array_slice($rows, 0, 400) as $row) {
            if (! is_array($row)) {
                continue; // an optional extra must never break the import
            }
            $section = self::clean($row['s'] ?? '');
            $label = self::clean($row['k'] ?? '');
            $key = strtolower($label);
            $values = self::values($row['v'] ?? []);
            if ($key === '' || ! $values) {
                continue;
            }
            $first = strtolower($values[0]);
            $where = ($section !== '' ? "{$section} / " : '') . $label;

            if (in_array($key, self::HANDLED_ELSEWHERE, true)) {
                continue;
            }

            if (isset(self::ATTRIBUTE_ROWS[$key])) {
                $attr = self::ATTRIBUTE_ROWS[$key];
                $value = self::attributeValue($attr, $values[0]);
                if ($value !== null) {
                    $attributes[$attr] = $value;
                } else {
                    $unmapped[] = "{$where} = " . implode(', ', $values);
                }
            } elseif (isset(self::BUILDING_FACETS[$key])) {
                foreach ($values as $v) {
                    $label = self::buildingLabel(self::BUILDING_FACETS[$key], $v);
                    if ($label !== null) {
                        $tick('building', $label);
                    } else {
                        $unmapped[] = "{$where} = {$v}";
                    }
                }
            } elseif ($key === 'furnished') {
                if ($first === 'yes') {
                    $tick('theProperty', 'Furnished');
                } elseif ($first === 'no') {
                    $tick('theProperty', 'Unfurnished');
                }
            } elseif ($key === 'pets allowed') {
                if ($first === 'yes') {
                    $tick('theProperty', 'Pet Friendly');
                } elseif ($first === 'no') {
                    $tick('theProperty', 'Pets Not Allowed');
                }
            } elseif (isset(self::FLAGS[$key])) {
                if ($first === 'yes') {
                    $tick(self::FLAGS[$key][0], self::FLAGS[$key][1]);
                }
            } elseif ($key === 'backup water') {
                // "Backup Water | Water Tank" (or Borehole): the generic Backup Water tick, plus the specific one.
                if ($first !== 'no') {
                    $tick('sustainability', 'Backup Water');
                    foreach ($values as $v) {
                        if (strtolower($v) !== 'yes' && ! $tickValue($v)) {
                            $unmapped[] = "{$where} = {$v}";
                        }
                    }
                }
            } elseif (self::isRoomKey($key, $room)) {
                $count = ctype_digit($values[0]) ? (int) $values[0] : 1;
                if ($count > 0) {
                    $spaces[$room] = ['type' => $room, 'count' => $count];
                }
            } elseif (in_array($key, ['security', 'special feature', 'lifestyle', 'description', 'internet access', 'temperature control'], true)) {
                // A list row: every value is its own candidate tick.
                foreach ($values as $v) {
                    if (! $tickValue($v)) {
                        $unmapped[] = "{$where} = {$v}";
                    }
                }
            } elseif ($first === 'yes' && self::resolve($label) !== null) {
                // "Solar Geyser | Yes", "Borehole | Yes": the row name IS the feature.
                $tickValue($label);
            } elseif ($first === 'no') {
                // An explicit "No" on a feature we don't track — nothing to tick and nothing to report.
                continue;
            } else {
                $unmapped[] = "{$where} = " . implode(', ', $values);
            }
        }

        foreach (array_slice($stripTags, 0, 100) as $tag) {
            if (! is_string($tag)) {
                continue;
            }
            $tag = self::clean($tag);
            $k = strtolower($tag);
            if ($k === '' || in_array($k, ['pool', 'garden'], true)) {
                continue; // flags buildSpacesJson() owns
            }
            if ($k === 'no pets allowed') {
                $tick('theProperty', 'Pets Not Allowed');
            } elseif (self::isRoomKey($k, $room)) {
                $spaces[$room] ??= ['type' => $room, 'count' => 1];
            } elseif (! $tickValue($tag)) {
                $unmapped[] = "Tags / {$tag}";
            }
        }

        return [
            'global'     => $global,
            'spaces'     => array_values($spaces),
            'attributes' => $attributes,
            'unmapped'   => array_values(array_unique($unmapped)),
        ];
    }

    /** A row's first value as the column wants it, or null when it can't be read (never guessed). */
    private static function attributeValue(string $attr, string $raw): int|string|null
    {
        switch ($attr) {
            case 'number_of_floors':
                // "1", "12" — a whole number of floors; "Ground + 2" style text is not guessed at.
                return ctype_digit($raw) && (int) $raw <= 300 ? (int) $raw : null;
            case 'occupation_date':
                // "01 October 2026"; "Immediately" and the like are not a date, so nothing is stored.
                $d = \DateTime::createFromFormat('!d F Y', $raw) ?: \DateTime::createFromFormat('!j F Y', $raw);

                return $d ? $d->format('Y-m-d') : null;
            case 'lease_period':
                return mb_substr($raw, 0, 100);
            default: // floor_number
                return mb_substr($raw, 0, 50);
        }
    }

    /** Property24's Building value ("Tiled Floors", "Zinc", "Brick") -> the catalog label ("Floor: Tiled"), or null. */
    private static function buildingLabel(string $facet, string $value): ?string
    {
        $k = strtolower(self::clean($value));
        $k = trim((string) preg_replace('/\s+(floors?|walls?|roofs?|windows?)$/', '', $k));
        $k = self::BUILDING_VALUE_ALIASES[$facet][$k] ?? $k;

        foreach ((array) config('property-spaces.feature_categories.building.features', []) as $label) {
            if (strcasecmp((string) $label, "{$facet}: {$k}") === 0) {
                return (string) $label;
            }
        }

        return null;
    }

    /**
     * The flat `features_json` mirror of a spaces_json — the SAME recipe PropertyController uses
     * when the property page saves (every space's featuresAll + unit features + the global groups).
     * Writing exactly this at import means a later save recomputes an identical list and never
     * looks like an edit to the locked column.
     *
     * @return string[]
     */
    public static function flatFeatures(array $spacesJson): array
    {
        $flat = [];
        foreach ($spacesJson['spaces'] ?? [] as $sp) {
            foreach ($sp['featuresAll'] ?? [] as $f) {
                $flat[] = $f;
            }
            foreach ($sp['units'] ?? [] as $u) {
                foreach ($u['features'] ?? [] as $f) {
                    $flat[] = $f;
                }
            }
        }
        foreach ($spacesJson['features'] ?? [] as $group) {
            if (is_array($group)) {
                foreach ($group as $f) {
                    $flat[] = $f;
                }
            }
        }

        return array_values(array_unique(array_filter($flat)));
    }

    /** @return array{0: string, 1: string}|null [group, CoreX label] */
    private static function resolve(string $value): ?array
    {
        $k = strtolower(self::clean($value));
        if ($k === '' || in_array($k, ['yes', 'no'], true)) {
            return null;
        }
        if (isset(self::ALIASES[$k])) {
            return self::ALIASES[$k];
        }
        foreach (self::catalog() as $cat => $labels) {
            foreach ($labels as $label) {
                if (strtolower($label) === $k) {
                    return [$cat, $label];
                }
            }
        }

        return null;
    }

    /** "Reception Rooms" / "Office" / "Studies" -> the CoreX space type it counts. */
    private static function isRoomKey(string $key, ?string &$type = null): bool
    {
        $type = null;
        $candidates = [$key, rtrim($key, 's'), preg_replace('/ies$/', 'y', $key), preg_replace('/es$/', '', $key)];
        foreach ((array) config('property-spaces.all_space_types', []) as $space) {
            if (in_array($space, self::OWNED_SPACES, true)) {
                continue;
            }
            if (in_array(strtolower($space), $candidates, true)) {
                $type = $space;

                return true;
            }
        }

        return false;
    }

    /** @return string[] one entry per value, comma/newline lists split, blanks dropped */
    private static function values(mixed $v): array
    {
        $out = [];
        foreach ((array) $v as $chunk) {
            if (! is_scalar($chunk)) {
                continue;
            }
            foreach (preg_split('/[\n,]/', (string) $chunk) ?: [] as $part) {
                $part = self::clean($part);
                if ($part !== '') {
                    $out[] = $part;
                }
            }
        }

        return $out;
    }

    private static function clean(mixed $s): string
    {
        return is_scalar($s) ? trim((string) preg_replace('/\s+/u', ' ', (string) $s)) : '';
    }
}
