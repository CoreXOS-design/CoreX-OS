<?php

namespace Tests\Unit\Syndication;

use App\Models\Property;
use App\Services\Syndication\Property24\Property24ListingMapper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers Property24ListingMapper::buildTags() — the bridge from CoreX feature
 * labels to P24 `Tag` enum values. Pure unit test: buildTags only reads
 * features_json, so no DB / agency resolution is required.
 */
class Property24FeatureTagsTest extends TestCase
{
    private function buildTags(array $features): array
    {
        $property = new Property();
        $property->features_json = $features;

        $method = new ReflectionMethod(Property24ListingMapper::class, 'buildTags');
        $method->setAccessible(true);

        return $method->invoke(new Property24ListingMapper(), $property);
    }

    private function buildPropertyFeatures(array $features): array
    {
        $property = new Property();
        $property->features_json = $features;

        $method = new ReflectionMethod(Property24ListingMapper::class, 'buildPropertyFeatures');
        $method->setAccessible(true);

        return $method->invoke(new Property24ListingMapper(), $property);
    }

    /** The full PropertyFeatures payload for a flat global feature set. */
    private function propertyFeatures(array $features): array
    {
        return $this->buildPropertyFeatures($features);
    }

    /** buildPropertyFeatures for a property with structured spaces. */
    private function featuresWithSpaces(array $spaces): array
    {
        $property = new Property();
        $property->features_json = [];
        $property->spaces_json   = ['spaces' => $spaces];

        $method = new ReflectionMethod(Property24ListingMapper::class, 'buildPropertyFeatures');
        $method->setAccessible(true);

        return $method->invoke(new Property24ListingMapper(), $property);
    }

    public function test_lounge_and_dining_do_not_create_reception_rooms(): void
    {
        // Property 6049 regression: Lounge + Dining Room were aggregated into a
        // "Reception Rooms 2" the agent never entered. They must not feed the
        // receptionRooms count (they surface as their own named rooms instead).
        $features = $this->featuresWithSpaces([
            ['type' => 'Lounge', 'count' => 1],
            ['type' => 'Dining Room', 'count' => 1],
            ['type' => 'TV Room', 'count' => 1],
        ]);

        $this->assertSame(0, $features['receptionRooms']);
    }

    public function test_explicit_reception_room_space_sets_reception_rooms(): void
    {
        $features = $this->featuresWithSpaces([
            ['type' => 'Reception Room', 'count' => 2],
            ['type' => 'Lounge', 'count' => 1],
        ]);

        $this->assertSame(2, $features['receptionRooms']);
    }

    public function test_reception_rooms_always_emitted_so_stale_value_clears(): void
    {
        // Emitted unconditionally (incl. 0) — P24 keeps fields absent from the
        // payload, so 0 is required to clear a stale count from a prior push.
        $features = $this->featuresWithSpaces([
            ['type' => 'Bedroom', 'count' => 3],
        ]);

        $this->assertArrayHasKey('receptionRooms', $features);
        $this->assertSame(0, $features['receptionRooms']);
    }

    public function test_fast_internet_does_not_imply_fibre_on_p24(): void
    {
        // Regression: a listing with "Fast Internet" (and "TV Port") but NOT
        // "Fibre" was showing "Fibre internet" on Property24 because the mapper
        // treated Fast Internet as fibre. Fast Internet has no P24 field, so it
        // must never set fibre.
        $features = $this->buildPropertyFeatures(['Fast Internet', 'TV Port']);

        $this->assertFalse($features['internetAccess']['fibre']);
        $this->assertFalse($features['internetAccess']['adsl']);
        $this->assertFalse($features['internetAccess']['satellite']);
    }

    public function test_internet_access_is_always_sent_to_clear_stale_p24_values(): void
    {
        // The block must be emitted even when NO internet feature is selected,
        // so a re-push deterministically clears a stale fibre/adsl/satellite a
        // previous push set. Omitting it lets P24 retain the old value.
        $features = $this->buildPropertyFeatures(['Sea View']);

        $this->assertArrayHasKey('internetAccess', $features);
        $this->assertFalse($features['internetAccess']['fibre']);
        $this->assertFalse($features['internetAccess']['adsl']);
        $this->assertFalse($features['internetAccess']['satellite']);
    }

    public function test_fibre_feature_maps_to_p24_fibre(): void
    {
        $features = $this->buildPropertyFeatures(['Fibre']);

        $this->assertTrue($features['internetAccess']['fibre']);
        $this->assertFalse($features['internetAccess']['adsl']);
        $this->assertFalse($features['internetAccess']['satellite']);
    }

    public function test_adsl_and_satellite_map_without_setting_fibre(): void
    {
        $features = $this->buildPropertyFeatures(['ADSL', 'Satellite Internet']);

        $this->assertTrue($features['internetAccess']['adsl']);
        $this->assertTrue($features['internetAccess']['satellite']);
        $this->assertFalse($features['internetAccess']['fibre']);
    }

    public function test_sea_view_and_communal_braai_area_map_to_p24_tags(): void
    {
        // Neither has a PropertyFeatures field → both land in top-level tags[].
        $tags = $this->buildTags(['Sea View', 'Communal Braai Area']);
        $this->assertContains('Sea', $tags);
        $this->assertContains('Communalbraaiarea', $tags);
    }

    public function test_match_is_case_insensitive(): void
    {
        $tags = $this->buildTags(['sea view', 'COMMUNAL BRAAI AREA']);
        $this->assertContains('Sea', $tags);
        $this->assertContains('Communalbraaiarea', $tags);
    }

    public function test_unknown_features_are_dropped_not_passed_through(): void
    {
        $this->assertSame(['Sea'], $this->buildTags(['Sea View', 'Some Feature P24 Does Not Know']));
    }

    public function test_security_features_map_to_top_level_tags(): void
    {
        // Security amenities have NO PropertyFeatures field → top-level tags[].
        $tags = $this->buildTags(['Alarm System', 'CCTV', 'Fibre Port Typo']);

        $this->assertContains('AlarmSystem', $tags);
        $this->assertContains('ClosedCircuitTV', $tags);
    }

    /**
     * Connectivity-port tags (TVPort/InternetPort/TelephonePort) are room-detail
     * descriptors — at top level P24 renders them as phantom rooms (AT-P24 bug on
     * #1322: a global "TV Port" showed up as a "TV Port" room). They must NOT ride
     * the top-level tags[] array; they belong only inside a room's featureTags[].
     */
    public function test_connectivity_ports_never_emit_as_top_level_tags(): void
    {
        $tags = $this->buildTags(['Alarm System', 'TV Port', 'Internet Port', 'Telephone Port']);

        $this->assertContains('AlarmSystem', $tags);   // real listing feature stays
        $this->assertNotContains('TVPort', $tags);
        $this->assertNotContains('InternetPort', $tags);
        $this->assertNotContains('TelephonePort', $tags);
    }

    public function test_empty_or_missing_features_returns_empty_array(): void
    {
        $this->assertSame([], $this->buildTags([]));
    }

    public function test_duplicate_labels_are_deduped(): void
    {
        $tags = $this->buildTags(['Sea View', 'Sea View']);

        $this->assertSame(['Sea'], $tags);
    }

    // ── AT-102/AT-103 — global-vs-per-room separation + featureTags ──────────

    private function mapper(): Property24ListingMapper
    {
        return new Property24ListingMapper();
    }

    private function invoke(string $method, Property $p): mixed
    {
        $m = new ReflectionMethod(Property24ListingMapper::class, $method);
        $m->setAccessible(true);
        return $m->invoke($this->mapper(), $p);
    }

    /** A room-only feature attaches to its room; global amenities (no PF field) go to tags[]. */
    public function test_room_only_feature_attaches_to_room_global_goes_to_tags(): void
    {
        $p = new Property();
        $p->spaces_json = [
            'features' => ['security' => ['Alarm System']], // global
            'spaces'   => [['type' => 'Bathroom', 'count' => 1, 'units' => [
                ['label' => 'Bathroom 1', 'features' => ['Shower', 'Toilet']], // room-only
            ]]],
        ];
        $p->features_json = ['Alarm System', 'Shower', 'Toilet'];

        $featureTags = $this->invoke('buildFeatureTags', $p);
        $tags = $this->invoke('buildTags', $p);
        $bath = collect($featureTags)->firstWhere('featureType', 'Bathroom')['tags'] ?? [];

        $this->assertContains('AlarmSystem', $tags);   // global, no PF field → top-level tags[]
        $this->assertContains('Shower', $bath);        // room-only → its room
        $this->assertContains('Toilet', $bath);
        $this->assertNotContains('Shower', $tags);     // room-only never in tags[]
        $this->assertNotContains('Toilet', $tags);
    }

    /** No featureType:"Other" entry is ever emitted (it renders as a junk room on P24). */
    public function test_no_other_featuretype_is_ever_emitted(): void
    {
        $p = new Property();
        $p->spaces_json = [
            'features' => ['connectivity' => ['TV Port'], 'security' => ['Alarm System']],
            'spaces'   => [['type' => 'Bedroom', 'count' => 1, 'units' => [
                ['label' => 'Bedroom 1', 'features' => ['TV Port', 'Tiled Floors']],
            ]]],
        ];
        $p->features_json = ['TV Port', 'Alarm System'];

        $types = collect($this->invoke('buildFeatureTags', $p))->pluck('featureType')->all();
        $this->assertNotContains('Other', $types);

        // The global security amenity lands in top-level tags[]; the global TV Port
        // does NOT (it is a room-detail port that would render as a phantom room).
        $tags = $this->invoke('buildTags', $p);
        $this->assertContains('AlarmSystem', $tags);
        $this->assertNotContains('TVPort', $tags);
        // The per-room TV Port still attaches to its room — the only correct home.
        $bed = collect($this->invoke('buildFeatureTags', $p))->firstWhere('featureType', 'Bedroom')['tags'] ?? [];
        $this->assertContains('TVPort', $bed);
    }

    /** The room NAME must never be sent in featureTags description (P24 labels from featureType). */
    public function test_room_name_is_not_sent_as_description(): void
    {
        $p = new Property();
        $p->spaces_json = ['features' => [], 'spaces' => [
            ['type' => 'Bedroom', 'count' => 1, 'units' => [
                ['label' => 'Bedroom 1', 'features' => ['Tiled Floors']],
            ]],
        ]];
        $p->features_json = [];

        $entry = collect($this->invoke('buildFeatureTags', $p))->firstWhere('featureType', 'Bedroom');
        // Per-unit rooms carry no prose field → description omitted entirely.
        $this->assertArrayNotHasKey('description', $entry);
        $this->assertSame(['TiledFloors'], $entry['tags']);
    }

    /** Space-level prose (descriptionAll) IS kept as description (it is genuine prose, not a name). */
    public function test_space_level_prose_is_kept_as_description(): void
    {
        $p = new Property();
        $p->spaces_json = ['features' => [], 'spaces' => [
            ['type' => 'Lounge', 'count' => 1, 'featuresAll' => ['Tiled Floors'], 'descriptionAll' => 'Open-plan, north facing'],
        ]];
        $p->features_json = [];

        $entry = collect($this->invoke('buildFeatureTags', $p))->firstWhere('featureType', 'Lounge');
        $this->assertSame('Open-plan, north facing', $entry['description']);
    }

    /** A room with NO mapped features is suppressed (no "Bedroom 1 = Bedroom 1" noise). */
    public function test_empty_rooms_are_not_emitted(): void
    {
        $p = new Property();
        $p->spaces_json = ['features' => [], 'spaces' => [
            ['type' => 'Bedroom', 'count' => 2, 'units' => [
                ['label' => 'Bedroom 1', 'features' => ['Built-in Cupboards']], // has a feature
                ['label' => 'Bedroom 2', 'features' => []],                     // empty → skip
            ]],
            ['type' => 'EntranceHall', 'count' => 1], // no features → skip
        ]];
        $p->features_json = [];

        $featureTags = $this->invoke('buildFeatureTags', $p);

        // Exactly one entry (Bedroom 1); every entry carries >=1 tag.
        $this->assertCount(1, $featureTags);
        foreach ($featureTags as $entry) {
            $this->assertNotEmpty($entry['tags'] ?? [], 'featureTags entry must have >=1 tag');
        }
    }

    /** featureTags emits named rooms for room-type spaces that carry features. */
    public function test_named_rooms_emitted_as_feature_tags(): void
    {
        $p = new Property();
        $p->spaces_json = ['features' => [], 'spaces' => [
            ['type' => 'Lounge', 'count' => 1, 'featuresAll' => ['Tiled Floors']],
            ['type' => 'Dining Room', 'count' => 1, 'featuresAll' => ['Tiled Floors']],
            ['type' => 'Patio', 'count' => 1, 'featuresAll' => ['Tiled Floors']], // no P24 FeatureType → skipped
        ]];
        $p->features_json = [];

        $types = collect($this->invoke('buildFeatureTags', $p))->pluck('featureType')->all();

        $this->assertContains('Lounge', $types);
        $this->assertContains('DiningRoom', $types);
        $this->assertNotContains('Patio', $types); // unmapped space type skipped
    }

    /** Legacy properties (no spaces_json): global mapped features with no PF field → tags[]. */
    public function test_legacy_without_spaces_json_routes_to_tags(): void
    {
        $p = new Property();
        $p->features_json = ['Sea View', 'Electric Gate']; // no spaces_json, no PF field

        $tags = $this->invoke('buildTags', $p);

        $this->assertContains('Sea', $tags);
        $this->assertContains('ElectricGate', $tags);
    }

    /** Coverage audit — a previously-unmapped CoreX feature now syndicates (top-level tags[]). */
    public function test_newly_mapped_feature_now_syndicates(): void
    {
        $this->assertContains('GraniteTops', $this->buildTags(['Granite Tops']));
        $this->assertContains('TiledFloors', $this->buildTags(['Tiled Floors']));
        $this->assertContains('Shower', $this->buildTags(['Shower']));
        $this->assertContains('AirConditioningUnit', $this->buildTags(['Air Conditioned']));
    }

    /** Amenities WITH a PropertyFeatures field route there, NOT to loose tags[]. */
    public function test_amenities_with_propertyfeatures_field_are_excluded_from_tags(): void
    {
        // Coffee Machine → kitchens.coffeeMachine; Backup Water → hasBackupWater;
        // Generator → hasGenerator; Wheelchair Friendly → isWheelchairAccessible.
        $pf = $this->propertyFeatures(['Coffee Machine', 'Backup Water', 'Generator', 'Wheelchair Friendly']);
        $this->assertTrue($pf['kitchens']['coffeeMachine']);
        $this->assertTrue($pf['hasBackupWater']);
        $this->assertTrue($pf['hasGenerator']);
        $this->assertTrue($pf['isWheelchairAccessible']);

        // None of them leak into top-level tags[].
        $tags = $this->buildTags(['Coffee Machine', 'Backup Water', 'Generator', 'Wheelchair Friendly']);
        $this->assertSame([], $tags);
    }

    /** BUG 3 — syndication photos = the gallery the agent sees (gallery_images_json), not the merged set. */
    public function test_syndication_images_use_gallery_not_merged_set(): void
    {
        $p = new Property();
        $p->gallery_images_json = ['a.jpg', 'b.jpg', 'c.jpg'];      // what the agent sees
        $p->images_json         = ['a.jpg', 'x.jpg', 'y.jpg', 'z.jpg']; // divergent public mirror

        $this->assertSame(['a.jpg', 'b.jpg', 'c.jpg'], $p->syndicationImages());
    }

    /** BUG 3 — empty gallery falls back to allImages() so a legacy-only property never sends zero. */
    public function test_syndication_images_fall_back_when_gallery_empty(): void
    {
        $p = new Property();
        $p->gallery_images_json = [];
        $p->images_json         = ['legacy1.jpg', 'legacy2.jpg'];

        $this->assertSame(['legacy1.jpg', 'legacy2.jpg'], array_values($p->syndicationImages()));
    }

    /**
     * AT-103 follow-up — "Air Conditioned" (the real CoreX feature label per
     * config/property-spaces.php) maps to the verbatim Tag enum member
     * AirConditioningUnit and, having no PropertyFeatures field, lands in
     * top-level tags[].
     */
    public function test_air_conditioned_maps_to_top_level_tag(): void
    {
        $this->assertContains('AirConditioningUnit', $this->buildTags(['Air Conditioned']));
    }

    /**
     * AT-146 — Irrigation / Sprinklers ARE mapped (Johan: send everything with a
     * P24 tag) but they are Garden ROOM fittings, so they ride the Garden room's
     * featureTags[] and are filtered out of the top-level tags[] array (a global
     * copy at listing level would render as a phantom room).
     */
    public function test_garden_room_fittings_ride_room_not_top_level(): void
    {
        // Never at top level.
        $this->assertNotContains('Irrigationsystem', $this->buildTags(['Irrigation']));
        $this->assertNotContains('SprinklerSystem', $this->buildTags(['Sprinklers']));

        // But attached to a Garden space they map onto that room's featureTags[].
        $p = new Property();
        $p->spaces_json = ['features' => [], 'spaces' => [
            ['type' => 'Garden', 'count' => 1, 'featuresAll' => ['Irrigation', 'Sprinklers', 'Zen Garden']],
        ]];
        $p->features_json = [];

        $garden = collect($this->invoke('buildFeatureTags', $p))->firstWhere('featureType', 'Garden')['tags'] ?? [];
        $this->assertContains('Irrigationsystem', $garden);
        $this->assertContains('SprinklerSystem', $garden);
        $this->assertContains('ZenGarden', $garden);
    }

    /**
     * AT-146 — the previously-unmapped room-fabric families (floors, walls,
     * windows, doors, beds) now map to their verbatim P24 Tag enum members and
     * attach to the room that carries them.
     */
    public function test_room_fabric_features_now_map_onto_their_room(): void
    {
        $p = new Property();
        $p->spaces_json = ['features' => [], 'spaces' => [
            ['type' => 'Bedroom', 'count' => 1, 'units' => [
                ['label' => 'Bedroom 1', 'features' => [
                    'King Bed', 'Wood Windows', 'Brick Wall', 'Carpet', 'Sliding Doors', 'Blinds',
                ]],
            ]],
        ]];
        $p->features_json = [];

        $bed = collect($this->invoke('buildFeatureTags', $p))->firstWhere('featureType', 'Bedroom')['tags'] ?? [];
        $this->assertContains('KingBed', $bed);
        $this->assertContains('WoodWindowOptions', $bed);
        $this->assertContains('Brick', $bed);
        $this->assertContains('Carpets', $bed);
        $this->assertContains('SlidingDoors', $bed);
        $this->assertContains('Blinds', $bed);
    }

    /**
     * AT-146 — room-fabric tags are room-detail descriptors: a legacy GLOBAL copy
     * (flat features_json, no room provenance) must NOT leak to top-level tags[]
     * where P24 renders it as a phantom room (the #1322 TVPort class of bug).
     */
    public function test_room_fabric_features_never_leak_to_top_level_tags(): void
    {
        $tags = $this->buildTags([
            'Alarm System',        // real listing feature — stays
            'King Bed', 'Wood Windows', 'Brick Wall', 'Carpet', 'Sliding Doors',
            'Fireplace', 'Open Plan', 'Double Garage', 'Full Bathroom', 'Rock Pool',
        ]);

        $this->assertContains('AlarmSystem', $tags);
        foreach ([
            'KingBed', 'WoodWindowOptions', 'Brick', 'Carpets', 'SlidingDoors',
            'FireplaceRoomOptions', 'OpenPlanRoomOptions', 'Double', 'Full', 'RockPool',
        ] as $roomOnly) {
            $this->assertNotContains($roomOnly, $tags, "$roomOnly must not appear at listing level");
        }
    }

    /**
     * AT-146 — the Johan-approved ambiguous pairings resolve to their room-scoped
     * enum members on the room that carries them; "Automated Garage Doors" is the
     * one that stays top-level (a global Security-category feature).
     */
    public function test_ambiguous_pairings_resolve_to_approved_enums(): void
    {
        $p = new Property();
        $p->spaces_json = ['features' => [], 'spaces' => [
            ['type' => 'Kitchen', 'count' => 1, 'featuresAll' => ['Open Plan', 'Fireplace']],
            ['type' => 'Garage', 'count' => 1, 'featuresAll' => ['Double Garage']],
        ]];
        $p->features_json = [];

        $ft = collect($this->invoke('buildFeatureTags', $p));
        $this->assertContains('OpenPlanRoomOptions', $ft->firstWhere('featureType', 'Kitchen')['tags'] ?? []);
        $this->assertContains('FireplaceRoomOptions', $ft->firstWhere('featureType', 'Kitchen')['tags'] ?? []);
        $this->assertContains('Double', $ft->firstWhere('featureType', 'Garage')['tags'] ?? []);

        // Automated Garage Doors is a global Security-category feature → top-level.
        $this->assertContains('ElectricGarage', $this->buildTags(['Automated Garage Doors']));
    }

    // ── AT-146 (property 6049) — space-level featuresAll drop + PF amenity strip ──

    /**
     * Property 6049 root cause: a space with an auto-created EMPTY unit plus
     * space-level featuresAll dropped its entire featuresAll set, because the
     * per-unit branch never read featuresAll. The agent's Kitchen (Oven & Hob,
     * Pantry, …), Lounge and Garage features never reached P24.
     */
    public function test_space_level_features_emit_when_units_are_empty(): void
    {
        $p = new Property();
        $p->features_json = [];
        $p->spaces_json = ['features' => [], 'spaces' => [
            ['type' => 'Kitchen', 'count' => 1,
             'featuresAll' => ['Oven and Hob', 'Pantry', 'Extractor Fan'],
             'units' => [['label' => 'Kitchen 1', 'features' => []]]], // auto-created empty unit
            ['type' => 'Garage', 'count' => 1,
             'featuresAll' => ['Single Garage'],
             'units' => [['label' => 'Garage 1', 'features' => []]]],
        ]];

        $ft = collect($this->invoke('buildFeatureTags', $p));
        $kitchen = $ft->firstWhere('featureType', 'Kitchen')['tags'] ?? [];
        $this->assertContains('OvenAndHob', $kitchen);
        $this->assertContains('Pantry', $kitchen);
        $this->assertContains('ExtractorFan', $kitchen);
        // Exactly one Kitchen row (not one per empty unit).
        $this->assertSame(1, $ft->where('featureType', 'Kitchen')->count());
        $this->assertContains('Single', $ft->firstWhere('featureType', 'Garage')['tags'] ?? []);
    }

    /**
     * AT-146 — when a unit carries its own features, the space-level featuresAll
     * is merged into that unit (whole-space features apply to every unit).
     */
    public function test_space_level_features_merge_into_units_with_own_features(): void
    {
        $p = new Property();
        $p->features_json = [];
        $p->spaces_json = ['features' => [], 'spaces' => [
            ['type' => 'Bedroom', 'count' => 1,
             'featuresAll' => ['Air Conditioned'],
             'units' => [['label' => 'Bedroom 1', 'features' => ['King Bed']]]],
        ]];

        $bed = collect($this->invoke('buildFeatureTags', $p))->firstWhere('featureType', 'Bedroom')['tags'] ?? [];
        $this->assertContains('KingBed', $bed);              // unit's own
        $this->assertContains('AirConditioningUnit', $bed); // space-level merged in
    }

    /**
     * AT-146 (property 6049) — a parking amenity entered on the Parking space
     * (globalFeatures() strips it, since parking is not a global-screen category)
     * must still set its structured PropertyFeatures.parking boolean.
     */
    public function test_space_level_parking_amenity_sets_property_feature(): void
    {
        $p = new Property();
        $p->features_json = ['Visitors Parking'];
        $p->spaces_json = ['features' => [], 'spaces' => [
            ['type' => 'Parking', 'count' => 1,
             'featuresAll' => ['Visitors Parking'],
             'units' => [['label' => 'Parking 1', 'features' => []]]],
        ]];

        $features = $this->invoke('buildPropertyFeatures', $p);
        $this->assertTrue($features['parking']['visitorsParking']);
    }

    /**
     * AT-146 — same class as parking: a kitchen fitting on the Kitchen space sets
     * its KitchensInfo boolean instead of being stripped by globalFeatures().
     */
    public function test_space_level_kitchen_fitting_sets_property_feature(): void
    {
        $p = new Property();
        $p->features_json = ['Dishwasher'];
        $p->spaces_json = ['features' => [], 'spaces' => [
            ['type' => 'Kitchen', 'count' => 1,
             'featuresAll' => ['Dishwasher'],
             'units' => [['label' => 'Kitchen 1', 'features' => []]]],
        ]];

        $features = $this->invoke('buildPropertyFeatures', $p);
        $this->assertTrue($features['kitchens']['dishwasher']);
    }

    /**
     * Half bathrooms. P24 has no separate half-bath field — a half bathroom is
     * 0.5 of BathroomsInfo.bathrooms. CoreX stores full `baths` and `half_baths`
     * as distinct additive counts, so the P24 value = baths + 0.5 per half bath.
     */
    public function test_half_baths_add_half_a_bathroom_to_the_p24_count(): void
    {
        $p = new Property();
        $p->baths = 2;
        $p->half_baths = 1;

        $features = $this->invoke('buildPropertyFeatures', $p);
        $this->assertSame(2.5, $features['bathrooms']['bathrooms']);
    }

    /** A half-bath-only property still sends a bathroom count (0.5). */
    public function test_half_bath_only_property_still_sends_a_bathroom_count(): void
    {
        $p = new Property();
        $p->baths = 0;
        $p->half_baths = 1;

        $features = $this->invoke('buildPropertyFeatures', $p);
        $this->assertSame(0.5, $features['bathrooms']['bathrooms']);
    }

    /** No baths at all → no bathrooms key (unchanged behaviour). */
    public function test_no_baths_omits_the_bathrooms_key(): void
    {
        $p = new Property();
        $p->baths = 0;
        $p->half_baths = 0;

        $features = $this->invoke('buildPropertyFeatures', $p);
        $this->assertArrayNotHasKey('bathrooms', $features);
    }
}
