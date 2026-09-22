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

    /**
     * Johan, 2026-09-21, from Retha's real paper out-inspection form:
     * "Missing" means should-be-here-and-isn't (a deposit argument); N/A
     * means was-never-here (not an argument at all) — two different
     * meanings the app could previously only say one of. Her form also
     * uses a different vocabulary entirely (Good / OK / Bad) from ours —
     * the SET itself is agency-configurable, this constant is only the
     * starting point for a NEW agency, never forced on Retha's or anyone
     * else's.
     *
     * `requires_notes` generalizes §0.3's old hardcoded "anything but Good
     * needs a reason" rule beyond a literal 'good' key — an agency that
     * reduces or renames this set entirely still expresses which of ITS
     * states need a reason on record.
     *
     * Property 5792 progression-gate build, Johan, explicit ruling on the
     * default: "the conditions that assert something adverse — Damaged,
     * Not working, Missing, Other — require a note; Good, Fair and N/A do
     * not." Corrects 'fair' from true to false — this shipped default had
     * never actually been enforced anywhere until this build, so nothing
     * that already relied on it existed to break; an agency that has
     * already customized its own condition_states is entirely unaffected
     * either way (this constant is only ever read when that column is
     * still null).
     *
     * @var array<int, array{key: string, label: string, requires_notes: bool}>
     */
    public const DEFAULT_CONDITION_STATES = [
        ['key' => 'good', 'label' => 'Good', 'requires_notes' => false],
        ['key' => 'fair', 'label' => 'Fair', 'requires_notes' => false],
        ['key' => 'damaged', 'label' => 'Damaged', 'requires_notes' => true],
        ['key' => 'not_working', 'label' => 'Not working', 'requires_notes' => true],
        ['key' => 'missing', 'label' => 'Missing', 'requires_notes' => true],
        ['key' => 'other', 'label' => 'Other', 'requires_notes' => true],
        // Johan: "not an argument at all" — unlike every state above it,
        // N/A needs no justification on record.
        ['key' => 'n_a', 'label' => 'N/A', 'requires_notes' => false],
    ];

    /**
     * Johan, 2026-09-22 (Inspections-tab rebuild, item 5) — "All Good" bulk-
     * fill must use the agency's own baseline/quick-fill condition, never a
     * hardcoded 'good' string. Defaults to the 'good' key when present in
     * the agency's own vocabulary (DEFAULT_CONDITION_STATES' own baseline);
     * an agency that renamed or removed 'good' entirely still gets a sane
     * fallback via baselineConditionKeyFor()'s own resolution below.
     */
    public const DEFAULT_BASELINE_CONDITION_KEY = 'good';

    protected $fillable = [
        'agency_id',
        'fault_report_window_days',
        'out_inspection_signing_window_days',
        'refusal_reason_presets',
        'inspection_feature_labels',
        'room_type_item_defaults',
        'room_type_walking_order',
        'condition_states',
        'baseline_condition_key',
        'require_notes_blocks_progression',
    ];

    protected $casts = [
        'fault_report_window_days' => 'integer',
        'out_inspection_signing_window_days' => 'integer',
        'refusal_reason_presets' => 'array',
        'inspection_feature_labels' => 'array',
        'room_type_item_defaults' => 'array',
        'room_type_walking_order' => 'array',
        'condition_states' => 'array',
        'require_notes_blocks_progression' => 'boolean',
    ];

    /**
     * Property 5792, Johan: "whether the requirement BLOCKS progression or
     * merely warns must itself be an agency setting, defaulting to
     * block." True (the default) = RentalInspection::startAwaitingSignature()/
     * markCompleted() refuse while a required note is missing. False =
     * those same checks still run but never throw — the agent sees the
     * same "which rooms and items" information, just not as a hard stop.
     */
    public const DEFAULT_REQUIRE_NOTES_BLOCKS_PROGRESSION = true;

    public static function requireNotesBlocksProgressionFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_REQUIRE_NOTES_BLOCKS_PROGRESSION;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('require_notes_blocks_progression');

        return $value !== null ? (bool) $value : self::DEFAULT_REQUIRE_NOTES_BLOCKS_PROGRESSION;
    }

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
     * 2026-09-21, cc1 (found live on property 5792, testing this exact
     * method): the number MUST come from the END of the label, never the
     * first digit anywhere in it. The original `/(\d+)/` matched the "1"
     * inside test data labelled "Bedroom CC1 Verify", colliding it with a
     * real "Bedroom 1" — harmless against Johan's own clean labels today,
     * but a real bug the moment any agent types a label with an incidental
     * digit in it: a unit number, a floor, "Flat 2 Bedroom", "Garage B1".
     * Anchored to the end of the string (`\s*$`) so a genuinely trailing
     * instance number ("Bedroom 1", "Garage B1") is read correctly while an
     * incidental digit earlier in the label ("Flat 2 Bedroom", "Bedroom
     * CC1 Verify") is correctly ignored.
     *
     * The numeric tiebreak is cosmetic ordering only, clamped to 0-999 and
     * never persisted as the room's identity or its `type` — a room with
     * no trailing number in its label (e.g. bare "Study") ties at 0 with
     * every other untrailing-numbered room of the same type; callers that
     * order by this value MUST add `id` as a secondary sort key (already
     * done everywhere this is consumed) so that tie has a stable,
     * predictable resolution rather than flipping between page loads.
     */
    public static function defaultRoomSortOrderFor(?int $agencyId, string $type, string $label): int
    {
        $position = self::roomTypeWalkingPositionFor($agencyId, $type);

        $numeric = 0;
        if (preg_match('/(\d+)\s*$/', $label, $matches)) {
            $numeric = min((int) $matches[1], 999);
        }

        return ($position * 1000) + $numeric;
    }

    /**
     * The agency's own condition-state vocabulary — every state an
     * inspector can grade an item as, in the order they're offered.
     * Read-time default pattern like every other resolver here: an agency
     * that hasn't customized this gets DEFAULT_CONDITION_STATES outright.
     *
     * Deliberately does NOT intersect against any external catalog (unlike
     * inspectionFeatureLabelsFor()/roomTypeWalkingOrderFor()) — this
     * vocabulary belongs entirely to the agency, not to a shared property
     * catalog, so a saved custom set (e.g. Retha's Good/OK/Bad) is trusted
     * as-is once it's shaped correctly. Malformed rows (missing key/label)
     * are dropped rather than crashing a read.
     *
     * @return array<int, array{key: string, label: string, requires_notes: bool}>
     */
    public static function conditionStatesFor(?int $agencyId): array
    {
        $states = null;
        if ($agencyId) {
            $states = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('condition_states');
            $states = is_string($states) ? json_decode($states, true) : $states;
        }

        if (! is_array($states) || $states === []) {
            return self::DEFAULT_CONDITION_STATES;
        }

        return array_values(array_filter($states, fn ($s) => is_array($s) && ! empty($s['key']) && isset($s['label'])));
    }

    /**
     * §0.3 — does picking this condition need a reason on record. Johan on
     * N/A specifically: "not an argument at all" — unlike every problem
     * state, it needs no justification. A condition key absent from the
     * agency's own configured set (shouldn't happen given real UI input,
     * but defends a stale/replayed request) defaults to TRUE — an unknown
     * state is treated as needing an explanation, never silently waved
     * through.
     */
    public static function conditionRequiresNotesFor(?int $agencyId, string $conditionKey): bool
    {
        $state = collect(self::conditionStatesFor($agencyId))->firstWhere('key', $conditionKey);

        return $state === null ? true : (bool) ($state['requires_notes'] ?? true);
    }

    /**
     * Item 5 (2026-09-22) — which of this agency's OWN configured condition
     * states "All Good" bulk-fills unrecorded items to. Never hardcoded to
     * the word "Good": a saved key is honoured only if it still names one of
     * the agency's current condition states; otherwise this prefers a
     * configured state that needs no reason (so a one-tap bulk action can
     * never be blocked waiting on typed notes for every item it touches),
     * falling back to the agency's first configured state if every one of
     * its states requires a reason.
     */
    public static function baselineConditionKeyFor(?int $agencyId): string
    {
        $states = self::conditionStatesFor($agencyId);

        $saved = null;
        if ($agencyId) {
            $saved = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('baseline_condition_key');
        }
        if (is_string($saved) && $saved !== '' && collect($states)->contains('key', $saved)) {
            return $saved;
        }

        if (collect($states)->contains('key', self::DEFAULT_BASELINE_CONDITION_KEY)) {
            return self::DEFAULT_BASELINE_CONDITION_KEY;
        }

        $noReasonNeeded = collect($states)->first(fn ($s) => empty($s['requires_notes']));

        return $noReasonNeeded['key'] ?? ($states[0]['key'] ?? self::DEFAULT_BASELINE_CONDITION_KEY);
    }
}
