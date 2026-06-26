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

    public function test_security_and_connectivity_features_map(): void
    {
        // Security/connectivity amenities have NO PropertyFeatures field → top-level tags[].
        $tags = $this->buildTags(['Alarm System', 'CCTV', 'Fibre Port Typo', 'TV Port']);

        $this->assertContains('AlarmSystem', $tags);
        $this->assertContains('ClosedCircuitTV', $tags);
        $this->assertContains('TVPort', $tags);
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

        // The global amenities land in top-level tags[] instead.
        $tags = $this->invoke('buildTags', $p);
        $this->assertContains('TVPort', $tags);
        $this->assertContains('AlarmSystem', $tags);
        // And the per-room TV Port still attaches to its room.
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
     * config/property-spaces.php) now maps to the verbatim Tag enum member
     * AirConditioningUnit. The Irrigation/Sprinkler pairings are deliberately
     * HELD: the requested "Irrigation System"/"Sprinkler System" strings do not
     * exist in the CoreX vocabulary (real strings are "Irrigation"/"Sprinklers"),
     * so nothing is mapped for them until Johan confirms the CoreX side.
     */
    public function test_air_conditioned_maps_held_irrigation_sprinkler_do_not(): void
    {
        // AirConditioningUnit is a "listing feature" tag → it lands in the
        // featureTags[] "Other" bucket (not top-level tags[]) per the contract.
        $this->assertContains('AirConditioningUnit', $this->otherTags(['Air Conditioned']));
        $this->assertNotContains('AirConditioningUnit', $this->buildTags(['Air Conditioned']));

        // Held — not mapped (neither the requested nor the real CoreX strings),
        // so they appear in neither bucket.
        foreach (['Irrigation System', 'Irrigation', 'Sprinkler System', 'Sprinklers'] as $f) {
            $this->assertNotContains('Irrigationsystem', $this->otherTags([$f]));
            $this->assertNotContains('SprinklerSystem', $this->otherTags([$f]));
            $this->assertNotContains('Irrigationsystem', $this->buildTags([$f]));
            $this->assertNotContains('SprinklerSystem', $this->buildTags([$f]));
        }
    }
}
