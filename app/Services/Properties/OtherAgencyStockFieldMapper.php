<?php

namespace App\Services\Properties;

use App\Models\P24City;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Services\P24\P24LocationResolver;

/**
 * .ai/specs/other-agency-stock.md §3/§4 — 2026-09-29 Pomona field-mapping
 * fix. The extension only ever sends RAW, unprocessed portal signals
 * (schema.org @type, a free-text label hint, PP's descriptive property-type
 * string, P24's own external suburb id straight off the URL) — this class
 * is the ONE place those get turned into CoreX's actual, current taxonomy
 * and location ids. Kept server-side (not in the extension) because it's
 * the only place both portals' quirks can be handled uniformly and tested,
 * and because the "repair an existing property in place" path re-runs this
 * same mapping without needing a browser at all.
 *
 * Found live, 2026-09-29 (property #21094, Pomona): the PRE-Other-Agency-
 * Stock "Pull Property" endpoint (PropertyPullController) never did ANY of
 * this — it stores whatever raw string a caller sends, verbatim, with no
 * taxonomy or location resolution at all. That endpoint is untouched here
 * (it serves a genuinely different, still-valid purpose — pulling the
 * agency's OWN stock) — this class is specific to the Other Agency Stock
 * import path.
 */
class OtherAgencyStockFieldMapper
{
    /**
     * schema.org @type (P24 JSON-LD `about.@type`, lowercased) -> CoreX's
     * current [property_type, category] canonical labels. P24's own free-
     * text `about.description` is tried FIRST (see mapPropertyType()) since
     * it sometimes already IS the exact canonical label (confirmed live on
     * the Pomona sample: "Apartment / Flat") — this map is the fallback for
     * when that hint is absent or doesn't match anything.
     */
    private const P24_TYPE_MAP = [
        'apartment'             => ['Apartment / Flat', 'Residential'],
        'flat'                  => ['Apartment / Flat', 'Residential'],
        'house'                 => ['House', 'Residential'],
        'singlefamilyresidence' => ['House', 'Residential'],
        'townhouse'             => ['Townhouse', 'Residential'],
        'duplex'                => ['Townhouse', 'Residential'],
        'cluster'               => ['Townhouse', 'Residential'],
        'vacantland'            => ['Vacant Land / Plot', 'Residential'],
        'land'                  => ['Vacant Land / Plot', 'Residential'],
        'plot'                  => ['Vacant Land / Plot', 'Residential'],
        'farm'                  => ['Farm', 'Residential'],
        'smallholding'          => ['Farm', 'Residential'],
        'commercialproperty'    => ['Commercial Property', 'Commercial'],
        'office'                => ['Commercial Property', 'Commercial'],
        'retail'                => ['Commercial Property', 'Commercial'],
        'shop'                  => ['Commercial Property', 'Commercial'],
        'industrialproperty'    => ['Industrial Property', 'Industrial'],
        'warehouse'             => ['Industrial Property', 'Industrial'],
        'factory'               => ['Industrial Property', 'Industrial'],
    ];

    /** The exact current CoreX canonical labels — a hint matching one of these (case-insensitive) is used as-is. */
    private const CANONICAL_LABELS = [
        'Apartment / Flat' => 'Residential',
        'Townhouse'        => 'Residential',
        'House'            => 'Residential',
        'Vacant Land / Plot' => 'Residential',
        'Farm'             => 'Residential',
        'Commercial Property' => 'Commercial',
        'Industrial Property' => 'Industrial',
    ];

    /**
     * @return array{property_type: ?string, category: ?string}
     */
    public static function mapPropertyType(string $portal, ?string $raw, ?string $labelHint = null): array
    {
        // 1. An exact canonical-label hint wins outright (P24's own
        //    about.description sometimes already IS the CoreX label).
        if ($labelHint) {
            foreach (self::CANONICAL_LABELS as $label => $category) {
                if (strcasecmp(trim($labelHint), $label) === 0) {
                    return ['property_type' => $label, 'category' => $category];
                }
            }
        }

        // 2. PP sends a full descriptive string ("5 Bedroom House") — strip
        //    the leading bed count before matching.
        $candidate = $raw;
        if ($portal === 'pp' && $candidate) {
            $candidate = preg_replace('/^\s*\d+\s*Bedroom(s)?\s+/i', '', $candidate);
        }

        if ($candidate) {
            // A stripped PP string might now BE a canonical label too ("House").
            foreach (self::CANONICAL_LABELS as $label => $category) {
                if (strcasecmp(trim($candidate), $label) === 0) {
                    return ['property_type' => $label, 'category' => $category];
                }
            }

            $key = strtolower(preg_replace('/[^a-z]/i', '', $candidate));
            if (isset(self::P24_TYPE_MAP[$key])) {
                [$label, $category] = self::P24_TYPE_MAP[$key];

                return ['property_type' => $label, 'category' => $category];
            }
        }

        // 3. Genuinely unrecognised — never silently guess a category/type
        //    that could be wrong; leave both null. The property still
        //    imports (property_type has a NOT NULL DB default of 'house'
        //    applied by OtherAgencyStockImportService as a last resort),
        //    it just needs a human to pick the right one, exactly as any
        //    other incomplete listing would.
        return ['property_type' => null, 'category' => null];
    }

    /**
     * Resolve P24's OWN external suburb id (straight off the listing URL,
     * e.g. .../gauteng/1350/117485980 -> 1350) to CoreX's internal
     * p24_suburb_id/p24_city_id/p24_province_id chain plus the denormalised
     * suburb/city/province/town text columns — the exact same shape
     * AppliesP24Location writes for the manual property forms (fix the
     * class, not the instance: one resolution, one set of column names).
     *
     * @return array{p24_suburb_id: ?int, p24_city_id: ?int, p24_province_id: ?int, suburb: ?string, city: ?string, province: ?string, town: ?string}
     */
    public static function resolveP24Location(?int $externalSuburbId): array
    {
        $empty = [
            'p24_suburb_id' => null, 'p24_city_id' => null, 'p24_province_id' => null,
            'suburb' => null, 'city' => null, 'province' => null, 'town' => null,
        ];

        if (! $externalSuburbId) {
            return $empty;
        }

        $resolved = P24LocationResolver::resolveByP24Id($externalSuburbId);
        if (! $resolved) {
            return $empty;
        }

        /** @var P24Suburb $suburb */
        $suburb = $resolved['suburb'];
        /** @var P24City $city */
        $city = $resolved['city'];
        /** @var P24Province|null $province */
        $province = $resolved['province'];

        return [
            'p24_suburb_id'   => $suburb->id,
            'p24_city_id'     => $city->id,
            'p24_province_id' => $province?->id,
            'suburb'          => $suburb->name,
            'city'            => $city->name,
            'province'        => $province?->name,
            'town'            => $city->name,
        ];
    }

    /**
     * .ai/specs/other-agency-stock.md §3/§4 — 2026-09-30 field audit
     * (property #21098, Norkem Park). P24's Property Overview table renders
     * currency as "R 1 800" (a literal "R", a space, then space-separated
     * thousands — confirmed live, plain ASCII spaces, not a currency
     * symbol entity or non-breaking space). Strips everything but digits —
     * robust to "R1,800", "R 1 800.00", or any other thousands-separator
     * style a listing happens to use. Absent/unparseable -> null, never 0
     * (0 is a real value — a genuinely free levy — that must never be
     * confused with "not captured").
     */
    public static function parseCurrency(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        // Strip everything except digits and a decimal point FIRST — a naive
        // "strip everything but digits" turns "R 1 800.00" into "180000"
        // (100x too large), because the ".00" decimal digits get absorbed
        // into the integer. Split on the LAST '.' to treat it as a decimal
        // point (rand cents), never a thousands separator — ZA currency
        // never uses '.' for thousands.
        $cleaned = preg_replace('/[^\d.]/', '', $raw);
        if ($cleaned === '' || $cleaned === '.') {
            return null;
        }

        $parts = explode('.', $cleaned);
        $intPart = $parts[0] !== '' ? $parts[0] : (count($parts) > 1 ? '0' : '');

        return $intPart === '' ? null : (int) $intPart;
    }

    /**
     * 2026-10-07 (P24 import 117621889): a count the portal shows as a plain
     * number (beds, garages, parking) as a whole number for CoreX's integer
     * columns. Rounds rather than truncates, so a stray "1.9" is 2 and an
     * absent value stays null (never 0 — the caller decides what absent means).
     */
    public static function toWholeNumber(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return max(0, (int) round((float) $value));
    }

    /**
     * 2026-10-07 (P24 import 117621889): P24 shows 2.5 bathrooms; CoreX keeps
     * whole baths in `baths` and a half bath as `half_baths` (the property
     * page reads "2 + ½", the P24 syndication mapper sends baths + 0.5 per
     * half bath, the spaces editor stores a 2.5 Bathroom count). So 2.5 is
     * baths 2 + half_baths 1 — never baths 25, never 2 with the half thrown away.
     * P24 only ever steps in halves; anything from .5 up counts as the half.
     *
     * @return array{0: int, 1: int}  [whole baths, half baths (0|1)]
     */
    public static function splitBathrooms(mixed $total): array
    {
        if ($total === null || $total === '' || ! is_numeric($total)) {
            return [0, 0];
        }
        $t = max(0.0, (float) $total);
        $whole = (int) floor($t + 1e-9);
        $half = ($t - $whole) >= 0.5 - 1e-9 ? 1 : 0;

        return [$whole, $half];
    }

    /**
     * 2026-10-07 (P24 import 117621889): the portal's street line ("42
     * Springwood", "1750 Argus Drive", "Unit 5, 12 Marine Drive") into the
     * structured columns. Same conventions the rest of CoreX already uses for
     * a free-text address ("[complex/unit], [street]", the street is the LAST
     * segment — ProspectingListing::parseStreetNumber, EntryPointController::
     * parseStreet): a leading "12", "12A", "1/3" or "12-14" is the street
     * number, the rest is the street name; any segment that is just the
     * suburb/city/province ($knownPlaces — P24's "42 Springwood, Umhlali Golf
     * Estate" repeats the suburb) is dropped; a leading "Unit 5"/"Flat 3" is
     * the unit number; any other segment before the street is the complex.
     * A line with no leading number is a street name only (never a guessed
     * number). Nothing readable -> every part null; nothing is invented.
     *
     * @param  string[]  $knownPlaces  suburb / city / province texts to ignore
     * @return array{street_number: ?string, street_name: ?string, complex_name: ?string, unit_number: ?string}
     */
    public static function parseStreetAddress(?string $raw, array $knownPlaces = []): array
    {
        $empty = ['street_number' => null, 'street_name' => null, 'complex_name' => null, 'unit_number' => null];
        $raw = trim((string) preg_replace('/\s+/u', ' ', (string) $raw));
        if ($raw === '') {
            return $empty;
        }

        $known = array_values(array_filter(array_map(
            fn ($p) => mb_strtolower(trim((string) $p)),
            $knownPlaces
        )));
        $segments = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn ($seg) => $seg !== '' && ! in_array(mb_strtolower($seg), $known, true)
        ));
        if (empty($segments)) {
            return $empty;
        }

        $streetLine = array_pop($segments);
        $unit = null;
        $complexParts = [];
        foreach ($segments as $seg) {
            if ($unit === null && preg_match('/^(?:unit|flat|apartment|apt|door)\s*#?\s*([\w\/-]+)$/iu', $seg, $m)) {
                $unit = $m[1];
            } else {
                $complexParts[] = $seg;
            }
        }

        $number = null;
        $name = $streetLine;
        if (preg_match('/^\s*(\d+[A-Za-z]?(?:\s*[\/-]\s*\d+[A-Za-z]?)?)\s+(.+)$/u', $streetLine, $m)) {
            $number = trim($m[1]);
            $name = trim($m[2]);
        }

        return [
            'street_number' => $number,
            'street_name'   => $name !== '' ? $name : null,
            'complex_name'  => $complexParts ? implode(', ', $complexParts) : null,
            'unit_number'   => $unit,
        ];
    }

    /**
     * P24's free-text "Zoning" value -> one of CoreX's fixed zone_type
     * dropdown options (resources/views/corex/properties/show.blade.php:
     * Residential/Commercial/Industrial/Agricultural/Mixed Use). Storing
     * P24's raw text ("General Residential") verbatim would silently show
     * as "-- None --" in that dropdown forever — same class of bug as
     * mapPropertyType() below, so it gets the same treatment: try an exact
     * match, then a keyword match, then leave it null rather than storing
     * an option the UI can't select (never guess wrong).
     */
    private const ZONING_KEYWORDS = [
        'residential'  => 'Residential',
        'business'     => 'Commercial',
        'commercial'   => 'Commercial',
        'retail'       => 'Commercial',
        'office'       => 'Commercial',
        'industrial'   => 'Industrial',
        'agricultural' => 'Agricultural',
        'agriculture'  => 'Agricultural',
        'farming'      => 'Agricultural',
        'mixed'        => 'Mixed Use',
    ];

    public static function mapZoning(?string $raw): ?string
    {
        if (! $raw || trim($raw) === '') {
            return null;
        }

        $trimmed = trim($raw);
        foreach (['Residential', 'Commercial', 'Industrial', 'Agricultural', 'Mixed Use'] as $option) {
            if (strcasecmp($trimmed, $option) === 0) {
                return $option;
            }
        }

        $lower = strtolower($trimmed);
        foreach (self::ZONING_KEYWORDS as $keyword => $option) {
            if (str_contains($lower, $keyword)) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Builds the `spaces_json` shape the Spaces editor/property page tiles
     * read (`{spaces: [{type, count, units: [{label, features}], featuresAll,
     * descriptionAll}], features: {security, theProperty, connectivity,
     * sustainability}}` — confirmed live against a real captured property).
     *
     * 2026-09-30 REGRESSION FIX (property #21098): the first version of this
     * method deliberately left Bedroom/Bathroom/Garage out, reasoning those
     * tiles read the dedicated beds/baths/garages columns directly. Wrong —
     * confirmed live: once spaces_json is non-empty, the Spaces tiles show
     * ONLY what spaces_json lists; there is no per-type fallback to the
     * columns. Writing a spaces_json with just Parking/Pool/Kitchen/Garden
     * made the Bedroom x2/Bathroom x1 tiles that were showing (via the
     * EMPTY-spaces_json fallback, before this method ever ran) vanish
     * outright. Bedroom/Bathroom/Garage are now written here too, from the
     * SAME beds/baths/garages values already going into the property
     * columns in the same fill() call — one source, both places, never two
     * numbers to drift apart.
     *
     * $existingSpacesJson (the property's spaces_json BEFORE this write) is
     * merged from, keyed by `type` — any space type this method doesn't
     * know about (a custom one an agent added, or a future addition) passes
     * through untouched; only the types this method actually has a fresh
     * signal for are replaced.
     *
     * Every input is optional and independently absent-safe (2026-09-30
     * field audit, input-space rule) — a listing missing Kitchen detail
     * still imports fine with an empty Kitchen space, never a partial
     * failure over one missing overview row.
     *
     * 2026-10-07 (P24 import 117580701): two more optional signals from OtherAgencyStockFeatureMapper —
     * `global_features` (the four property-wide tick groups; when present it REPLACES the stored groups,
     * the way a re-import replaces every other imported advert field) and `extra_spaces` (rooms P24 counts
     * that CoreX also has as a space type: Reception Room, Office, Study …).
     *
     * @param  array{beds?: ?int, baths?: int|float|null (total, 2.5 = 2 + half), garages?: ?int, bathroom_features?: string[], parking_count?: ?int, parking_features?: string[], pool?: bool, garden?: bool, kitchen_features?: string[], garden_features?: string[], security_features?: string[], global_features?: ?array<string, string[]>, extra_spaces?: array<int, array{type: string, count: int}>}  $signals
     */
    public static function buildSpacesJson(array $signals, ?array $existingSpacesJson = null): array
    {
        $byType = [];
        foreach (($existingSpacesJson['spaces'] ?? []) as $space) {
            if (! empty($space['type'])) {
                $byType[$space['type']] = $space;
            }
        }

        // $count may be fractional (Bathroom 2.5 = 2 baths + a half bath — the
        // spaces editor's own shape: count 2.5, one unit per started space).
        $setSpace = function (string $type, int|float $count, array $features) use (&$byType) {
            if ($count <= 0) {
                unset($byType[$type]);

                return;
            }
            $units = [];
            for ($i = 1; $i <= (int) ceil($count); $i++) {
                $units[] = ['label' => "{$type} {$i}", 'features' => $features];
            }
            $byType[$type] = [
                'type'           => $type,
                'count'          => floor($count) == $count ? (int) $count : (float) $count,
                'units'          => $units,
                'featuresAll'    => $features,
                'descriptionAll' => '',
            ];
        };

        if (array_key_exists('beds', $signals) && $signals['beds'] !== null) {
            $setSpace('Bedroom', (int) $signals['beds'], []);
        }
        if (array_key_exists('baths', $signals) && $signals['baths'] !== null) {
            $setSpace('Bathroom', (float) $signals['baths'], array_values(array_filter($signals['bathroom_features'] ?? [])));
        }
        if (array_key_exists('garages', $signals) && $signals['garages'] !== null) {
            $setSpace('Garage', (int) $signals['garages'], []);
        }

        $parkingCount = (int) ($signals['parking_count'] ?? 0);
        if ($parkingCount > 0) {
            $setSpace('Parking', $parkingCount, array_values(array_filter($signals['parking_features'] ?? [])));
        }

        if (! empty($signals['pool'])) {
            $setSpace('Pool', 1, []);
        }

        $kitchenFeatures = array_values(array_filter($signals['kitchen_features'] ?? []));
        if (! empty($kitchenFeatures)) {
            $setSpace('Kitchen', 1, $kitchenFeatures);
        }

        $gardenFeatures = array_values(array_filter($signals['garden_features'] ?? []));
        // "Garden | Yes" is a flag, not a feature called "Yes" — the space exists, the list stays empty.
        if (! empty($gardenFeatures) || ! empty($signals['garden'])) {
            $setSpace('Garden', 1, $gardenFeatures);
        }

        foreach (($signals['extra_spaces'] ?? []) as $extra) {
            if (! empty($extra['type']) && (int) ($extra['count'] ?? 0) > 0) {
                $setSpace((string) $extra['type'], (int) $extra['count'], []);
            }
        }

        $securityFeatures = array_values(array_filter($signals['security_features'] ?? []));
        $existingFeatures = $existingSpacesJson['features'] ?? [];

        // Mapped P24 features (catalog labels the property page can tick) are authoritative when
        // the extension sent the page's rows; otherwise (Pull flow, PP, an older extension) the
        // groups stay as they were, with the raw security list as before.
        $mapped = $signals['global_features'] ?? null;
        if (is_array($mapped)) {
            return [
                'spaces'   => array_values($byType),
                'features' => [
                    'security'       => array_values($mapped['security'] ?? []),
                    'theProperty'    => array_values($mapped['theProperty'] ?? []),
                    'connectivity'   => array_values($mapped['connectivity'] ?? []),
                    'sustainability' => array_values($mapped['sustainability'] ?? []),
                ],
            ];
        }

        return [
            'spaces'   => array_values($byType),
            'features' => [
                'security'       => $securityFeatures ?: ($existingFeatures['security'] ?? []),
                'theProperty'    => $existingFeatures['theProperty'] ?? [],
                'connectivity'   => $existingFeatures['connectivity'] ?? [],
                'sustainability' => $existingFeatures['sustainability'] ?? [],
            ],
        ];
    }
}
