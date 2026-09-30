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
     * Bedroom/Bathroom/Garage are deliberately NOT written here — those
     * tiles already read the dedicated beds/baths/garages columns (already
     * correctly mapped elsewhere), and duplicating them into spaces_json
     * too would be a second, driftable source of the same number. This is
     * ONLY for the space types that have no dedicated column: Parking,
     * Pool, Kitchen, Garden — plus the property-wide Security feature list,
     * which is not a "space" at all (matches poolTokens()'s own documented
     * spaces_json shape).
     *
     * Every input is optional and independently absent-safe (2026-09-30
     * field audit, input-space rule) — a listing missing Kitchen detail
     * still imports fine with an empty Kitchen space, never a partial
     * failure over one missing overview row.
     *
     * @param  array{parking_count?: ?int, pool?: bool, kitchen_features?: string[], garden_features?: string[]}  $signals
     */
    public static function buildSpacesJson(array $signals): array
    {
        $spaces = [];

        $addSpace = function (string $type, int $count, array $features) use (&$spaces) {
            $units = [];
            for ($i = 1; $i <= $count; $i++) {
                $units[] = ['label' => "{$type} {$i}", 'features' => $features];
            }
            $spaces[] = [
                'type'           => $type,
                'count'          => $count,
                'units'          => $units,
                'featuresAll'    => [],
                'descriptionAll' => '',
            ];
        };

        $parkingCount = (int) ($signals['parking_count'] ?? 0);
        if ($parkingCount > 0) {
            $addSpace('Parking', $parkingCount, []);
        }

        if (! empty($signals['pool'])) {
            $addSpace('Pool', 1, []);
        }

        $kitchenFeatures = array_values(array_filter($signals['kitchen_features'] ?? []));
        if (! empty($kitchenFeatures)) {
            $addSpace('Kitchen', 1, $kitchenFeatures);
        }

        $gardenFeatures = array_values(array_filter($signals['garden_features'] ?? []));
        if (! empty($gardenFeatures)) {
            $addSpace('Garden', 1, $gardenFeatures);
        }

        $securityFeatures = array_values(array_filter($signals['security_features'] ?? []));

        return [
            'spaces'   => $spaces,
            'features' => [
                'security'       => $securityFeatures,
                'theProperty'    => [],
                'connectivity'   => [],
                'sustainability' => [],
            ],
        ];
    }
}
