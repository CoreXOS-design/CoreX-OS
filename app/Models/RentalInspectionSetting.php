<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * .ai/specs/rental-inspections.md §3.5 — the two windows, agency-
 * configurable, never hardcoded (§0.12). Read-time default pattern, matching
 * RentalApplicationQualifyingSetting: a null column resolves to the
 * DEFAULT_* constant, never written on read.
 */
class RentalInspectionSetting extends Model
{
    use BelongsToAgency;

    public const DEFAULT_FAULT_REPORT_WINDOW_DAYS = 7;
    public const DEFAULT_SIGNING_WINDOW_DAYS = 7;

    /**
     * §15.6 — neutral, multi-agency-safe default. 'other' is always present
     * and always last, never agency-removable (§15.5's mandatory-reason
     * guarantee depends on an escape valve existing) — enforced in
     * refusalReasonPresetsFor() below, not left to agency-edited JSON to
     * get right.
     */
    public const DEFAULT_REFUSAL_REASON_PRESETS = [
        ['key' => 'disputes_condition', 'label' => 'Disputes the recorded condition'],
        ['key' => 'not_present', 'label' => 'Not present for the walkthrough'],
        ['key' => 'refused_no_reason', 'label' => 'Refused outright, no reason given'],
        ['key' => 'other', 'label' => 'Other'],
    ];

    /**
     * Johan, 2026-09-20 — a starting point, not a ruling: which of the
     * EXISTING property feature catalog's labels
     * (config('property-spaces.feature_categories')) are physical things an
     * inspector would actually check are present and working, versus a
     * marketing/legal/policy descriptor with nothing to inspect. Every
     * label here must exist verbatim in that catalog — this constant never
     * introduces a label of its own, only selects from the existing one, so
     * it can never drift out of sync with what Property24/Private Property
     * syndication and the property edit screen both already key on.
     *
     * The agency owns this list from here — Johan's own reasoning for why
     * this is a tick rather than an inferred rule: "Built-in Cupboards" is
     * both a feature AND an inspection item, "Investment" is neither, and
     * no rule reliably tells the two apart. This is one agency's starting
     * guess at that split, not a universal one.
     */
    public const DEFAULT_INSPECTION_FEATURE_LABELS = [
        // The Property
        'Air Conditioned', 'Balcony',
        // Security — physical installations, not access policies or area
        // classifications (e.g. "Gated Community", "24 Hour Guard").
        'Alarm System', 'Boomed Area', 'Burglar Bars', 'CCTV', 'Electric Fence',
        'Electric Gate', 'Guard House', 'Indoor Beams', 'Intercom', 'Outdoor Beams',
        'Perimeter Wall', 'Safe', 'Security Gate', 'Automated Garage Doors',
        // Connectivity — the physical outlet/equipment, not the service
        // subscription behind it (e.g. "ADSL", "Cable TV" stay off).
        'Fibre', 'Internet Port', 'Satellite Dish', 'Telephone Port', 'TV Port', 'Wi-Fi',
        // Sustainability — every one of these is a physical installation.
        'Backup Battery', 'Backup Water', 'Borehole', 'Gas Geyser', 'Gas Hob',
        'Gas Oven', 'Generator', 'Inverter', 'Septic Tank', 'Solar Geyser',
        'Solar Heating', 'Solar Panel', 'Water Tank',
    ];

    /**
     * Johan, 2026-09-20: "we should have a setting somewhere on rentals that
     * defines room types and what gets added - ceiling, walls, floors,
     * windows, doors - that should be a std." The generic fallback for any
     * space type below that isn't in DEFAULT_ROOM_TYPE_ITEMS_BY_TYPE — a
     * type Retha's real vocabulary doesn't cover, or a future addition to
     * config('property-spaces.all_space_types') this constant never has to
     * be updated for.
     */
    public const DEFAULT_ROOM_TYPE_ITEMS = ['Ceiling', 'Walls', 'Floors', 'Windows', 'Doors'];

    /**
     * 2026-09-21, conductor (transcribed from Retha's real inspection form) —
     * "we are seeding a skeleton and calling it a checklist" once actual
     * agent numbers showed under 6 items/room against her real 18-line
     * kitchen. These are the SYSTEM default for these five types — used
     * only where an agency has not configured its own list for that type
     * (roomTypeItemDefaultsFor()'s array_merge always lets an agency's own
     * customRoomTypeOverridesFor() entry win outright, unchanged by this).
     * Transcribed exactly as given, not assumed to be a superset of the
     * generic baseline above — her Bedroom has no separate Floors/Windows/
     * Doors lines at all, so it genuinely doesn't get them here either.
     */
    public const DEFAULT_ROOM_TYPE_ITEMS_BY_TYPE = [
        'Kitchen' => [
            'Walls', 'Ceiling', 'Ceiling Fans', 'Aircon', 'Light Fittings', 'Light Switches',
            'Carpet', 'Tiles', 'Blinds', 'Curtain Rails', 'Plug Sockets', 'Stoves',
            'Stove Plates', 'Oven', 'Tops', 'Hinges', 'Cupboard Doors', 'Door Frames',
        ],
        'Bathroom' => [
            'Ceiling', 'Extractor Fan', 'Walls', 'Tiles', 'Light Fittings', 'Light Switches',
            'Bath', 'Shower', 'Basin', 'Toilet', 'Taps', 'Towel Rails', 'Blinds',
            'Curtain Rails', 'Door',
        ],
        'Bedroom' => [
            'Walls', 'Ceilings', 'Ceiling Fans', 'Aircon', 'Light Fittings', 'Light Switches',
            'Carpet', 'Tiles', 'Blinds', 'Curtain Rails', 'Plug Sockets', 'Cupboard Doors',
            'Hinges', 'Mirror',
        ],
        'Garage' => ['Ceiling', 'Doors', 'Floors', 'Lights', 'Light Fittings', 'Walls', 'Windows'],
        'Yard' => ['Fences and Gates', 'Retaining Wall', 'Garden', 'Gutters', 'Downspouts', 'Roof'],
    ];

    /**
     * Johan, 2026-09-21, property 5792: rooms rendered in creation order —
     * "no logical way to line up the rooms as the inspection goes" —
     * despite rooms now carrying a real type (the room-type picker fix).
     * A sensible DEFAULT is a walking order by type: entrance/reception,
     * living/social, kitchen/domestic, bedrooms/private, bathrooms,
     * outside/leisure, then utility/storage/vehicle. Agency-configurable
     * (§16.4, `/corex/settings/rental-inspections`) — this is a starting
     * point for every new agency, never a single order forced on all of
     * them.
     *
     * Verified to cover config('property-spaces.all_space_types') exactly —
     * every one of its 50 types appears here once, no gaps, no extras — so
     * roomTypeWalkingOrderFor() never has to guess a fallback position for
     * a type this constant simply forgot.
     */
    public const DEFAULT_ROOM_TYPE_WALKING_ORDER = [
        // Entrance & Reception
        'Entrance Hall', 'Reception Room',
        // Living & Social
        'Lounge', 'TV Room', 'Dining Room', 'Bar', 'Boardroom', 'Braai Room', 'Lapa',
        // Kitchen & Domestic
        'Kitchen', 'Scullery', 'Laundry Room', 'Domestic Room', 'Domestic Bathroom', 'Linen Room',
        // Bedrooms & Private
        'Bedroom', 'Study', 'Office', 'Loft', 'Flatlet',
        // Bathrooms
        'Bathroom', 'Outside Toilet',
        // Outside & Leisure
        'Garden', 'Pool', 'Pool Shed', 'Jacuzzi', 'Patio', 'Veranda', 'Courtyard', 'Gazebo',
        'Greenhouse', 'Sauna', 'Gym', 'Squash Court', 'Tennis Court', 'Clubhouse', 'Boat Launch',
        'Boathouse', 'Jetty', 'Wendy House',
        // Utility, Storage & Vehicle
        'Garage', 'Parking', 'Storeroom', 'Shed', 'Workshop', 'Cellar', 'Stable',
        'Changing Room', 'Studio', 'Yard',
    ];

    protected $fillable = [
        'agency_id',
        'fault_report_window_days',
        'out_inspection_signing_window_days',
        'refusal_reason_presets',
        'inspection_feature_labels',
        'room_type_item_defaults',
        'room_type_walking_order',
    ];

    protected $casts = [
        'fault_report_window_days' => 'integer',
        'out_inspection_signing_window_days' => 'integer',
        'refusal_reason_presets' => 'array',
        'inspection_feature_labels' => 'array',
        'room_type_item_defaults' => 'array',
        'room_type_walking_order' => 'array',
    ];

    public static function faultReportWindowDaysFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_FAULT_REPORT_WINDOW_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('fault_report_window_days');

        return $value !== null ? (int) $value : self::DEFAULT_FAULT_REPORT_WINDOW_DAYS;
    }

    public static function signingWindowDaysFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_SIGNING_WINDOW_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('out_inspection_signing_window_days');

        return $value !== null ? (int) $value : self::DEFAULT_SIGNING_WINDOW_DAYS;
    }

    /**
     * §15.6 — 'other' is guaranteed present and last regardless of what an
     * agency has edited/saved, so §15.5's mandatory-reason rule always has
     * an escape valve. Never trusted to agency-edited JSON alone.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function refusalReasonPresetsFor(?int $agencyId): array
    {
        $presets = null;
        if ($agencyId) {
            $presets = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('refusal_reason_presets');
            $presets = is_string($presets) ? json_decode($presets, true) : $presets;
        }

        $presets = is_array($presets) && $presets !== [] ? $presets : self::DEFAULT_REFUSAL_REASON_PRESETS;

        $withoutOther = array_values(array_filter($presets, fn ($p) => ($p['key'] ?? null) !== 'other'));
        $withoutOther[] = ['key' => 'other', 'label' => 'Other'];

        return $withoutOther;
    }

    /**
     * Which of the property feature catalog's labels
     * (config('property-spaces.feature_categories')) count as inspection
     * items when a checklist is seeded from a property's advertising
     * features. Intersected against the LIVE catalog on every read, so an
     * agency's saved list can never resurrect a label the catalog has since
     * dropped, and a catalog addition is simply absent until the agency
     * opts in — never silently included. An explicitly empty saved list
     * (an agency that wants nothing seeded from features) is a real,
     * preserved state, not coerced back to the default.
     *
     * @return array<int, string>
     */
    public static function inspectionFeatureLabelsFor(?int $agencyId): array
    {
        $labels = null;
        if ($agencyId) {
            $labels = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('inspection_feature_labels');
            $labels = is_string($labels) ? json_decode($labels, true) : $labels;
        }

        $labels = is_array($labels) ? $labels : self::DEFAULT_INSPECTION_FEATURE_LABELS;

        $catalog = collect(config('property-spaces.feature_categories', []))
            ->flatMap(fn ($category) => $category['features'] ?? [])
            ->all();

        return array_values(array_intersect($labels, $catalog));
    }

    /**
     * The RAW, sparse per-agency overrides only — exactly what this agency
     * has actually customized, nothing merged in. This is what the settings
     * screen's edit form seeds its editable row list from (a type NOT in
     * this list renders no row and stays on the generic baseline); the
     * merged, everything-covered view lives in roomTypeItemDefaultsFor()
     * below, which is what the seeder calls.
     *
     * @return array<string, array<int, string>>
     */
    public static function customRoomTypeOverridesFor(?int $agencyId): array
    {
        $overrides = null;
        if ($agencyId) {
            $overrides = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('room_type_item_defaults');
            $overrides = is_string($overrides) ? json_decode($overrides, true) : $overrides;
        }

        return is_array($overrides) ? $overrides : [];
    }

    /**
     * Ordered default inspection items for every known space type
     * (config('property-spaces.all_space_types')), agency overrides merged
     * on top. Built at read time from the live space-type list, never a
     * static array of type keys — a future addition to that config is
     * covered automatically, with DEFAULT_ROOM_TYPE_ITEMS, until an agency
     * customizes it.
     *
     * @return array<string, array<int, string>>
     */
    public static function roomTypeItemDefaultsFor(?int $agencyId): array
    {
        $overrides = self::customRoomTypeOverridesFor($agencyId);

        $defaults = [];
        foreach (config('property-spaces.all_space_types', []) as $type) {
            $defaults[$type] = self::DEFAULT_ROOM_TYPE_ITEMS_BY_TYPE[$type] ?? self::DEFAULT_ROOM_TYPE_ITEMS;
        }

        return array_merge($defaults, $overrides);
    }

    /**
     * Single-type convenience lookup for the inspection seeder (cc4,
     * rental-inspections rework) — one call per room instance, keyed by
     * its parent space type. Falls back to the generic baseline for a type
     * this agency hasn't customized AND for a type absent from the
     * space-type catalog entirely, so a future or renamed type never seeds
     * an empty checklist.
     *
     * @return array<int, string>
     */
    public static function roomTypeItemsFor(?int $agencyId, string $spaceType): array
    {
        $defaults = self::roomTypeItemDefaultsFor($agencyId);

        return $defaults[$spaceType] ?? self::DEFAULT_ROOM_TYPE_ITEMS;
    }

    /**
     * The agency's own room-type walking order — every space type, in the
     * order an inspector should walk them. Read-time default pattern like
     * every other resolver here: an agency that hasn't customized this
     * gets DEFAULT_ROOM_TYPE_WALKING_ORDER outright, never a partial or
     * empty list.
     *
     * A saved order can go stale in two directions as the catalog changes
     * over time: a type the agency ordered that the catalog has since
     * dropped is silently excluded (array_intersect — never sorts by a
     * type that no longer exists), and a type the catalog gained after the
     * agency last saved is appended, in the DEFAULT order's own relative
     * position, rather than left with no position to sort by at all.
     *
     * @return array<int, string>
     */
    public static function roomTypeWalkingOrderFor(?int $agencyId): array
    {
        $order = null;
        if ($agencyId) {
            $order = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('room_type_walking_order');
            $order = is_string($order) ? json_decode($order, true) : $order;
        }
        $order = is_array($order) && $order !== [] ? $order : self::DEFAULT_ROOM_TYPE_WALKING_ORDER;

        $catalog = config('property-spaces.all_space_types', []);
        $order = array_values(array_intersect($order, $catalog));

        $missing = array_values(array_diff($catalog, $order));
        if ($missing !== []) {
            $defaultMissing = array_values(array_intersect(self::DEFAULT_ROOM_TYPE_WALKING_ORDER, $missing));
            $order = array_merge($order, $defaultMissing);
        }

        return $order;
    }

    /**
     * Where a room of this $type sits in the agency's walking order. A
     * type absent even after roomTypeWalkingOrderFor()'s own catalog
     * reconciliation (shouldn't happen given a real PropertyRoom.type, but
     * defends against a hand-edited/legacy value) sorts last rather than
     * throwing.
     */
    public static function roomTypeWalkingPositionFor(?int $agencyId, string $type): int
    {
        $order = self::roomTypeWalkingOrderFor($agencyId);
        $position = array_search($type, $order, true);

        return $position === false ? count($order) : $position;
    }

    /**
     * A room's default sort_order: its type's walking-order position,
     * combined with a natural-numeric read of its own label ("Bedroom 2"
     * -> 2) as a tiebreak among same-type rooms — Johan: "natural-numeric,
     * NOT alphabetical: alphabetical gives 1, 10, 2." No sibling-room query
     * needed; the tiebreak comes only from this room's own label text.
     *
     * The numeric tiebreak is cosmetic ordering only, clamped to 0-999 and
     * never persisted as the room's identity or its `type` — a room with
     * no number in its label (e.g. bare "Study") sorts first within its
     * type, which reads correctly for the common case of a single
     * instance of that type.
     */
    public static function defaultRoomSortOrderFor(?int $agencyId, string $type, string $label): int
    {
        $position = self::roomTypeWalkingPositionFor($agencyId, $type);

        $numeric = 0;
        if (preg_match('/(\d+)/', $label, $matches)) {
            $numeric = min((int) $matches[1], 999);
        }

        return ($position * 1000) + $numeric;
    }
}
