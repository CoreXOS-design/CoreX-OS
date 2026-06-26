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

    /**
     * The tags of the featureTags[] "Other" entry — where GLOBAL
     * "listing feature" amenities now go so P24 files them under "Other
     * Features" (not top-level tags[], which P24 mis-renders under "Rooms").
     */
    private function otherTags(array $features): array
    {
        $p = new Property();
        $p->features_json = $features;
        $other = collect($this->invoke('buildFeatureTags', $p))->firstWhere('featureType', 'Other');
        return $other['tags'] ?? [];
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
        // Sea = "building options" (feature-description=no) → top-level tags[].
        // Communal Braai Area = "listing feature" → featureTags[] "Other".
        $this->assertContains('Sea', $this->buildTags(['Sea View', 'Communal Braai Area']));
        $this->assertContains('Communalbraaiarea', $this->otherTags(['Sea View', 'Communal Braai Area']));
    }

    public function test_match_is_case_insensitive(): void
    {
        $this->assertContains('Sea', $this->buildTags(['sea view', 'COMMUNAL BRAAI AREA']));
        $this->assertContains('Communalbraaiarea', $this->otherTags(['sea view', 'COMMUNAL BRAAI AREA']));
    }

    public function test_unknown_features_are_dropped_not_passed_through(): void
    {
        // Sea is the only listing-only tag here; the unknown feature is dropped.
        $this->assertSame(['Sea'], $this->buildTags(['Sea View', 'Some Feature P24 Does Not Know']));
        $this->assertSame([], $this->otherTags(['Sea View', 'Some Feature P24 Does Not Know']));
    }

    public function test_security_and_connectivity_features_map(): void
    {
        // All three are "listing feature" type → featureTags[] "Other", NOT tags[].
        $other = $this->otherTags(['Alarm System', 'CCTV', 'Fibre Port Typo', 'TV Port']);

        $this->assertContains('AlarmSystem', $other);
        $this->assertContains('ClosedCircuitTV', $other);
        $this->assertContains('TVPort', $other);
        // …and none of them leak into the top-level tags[] (the "Rooms" bug).
        $tags = $this->buildTags(['Alarm System', 'CCTV', 'TV Port']);
        $this->assertNotContains('TVPort', $tags);
        $this->assertNotContains('AlarmSystem', $tags);
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

    /** A feature ONLY on a room must not appear in tags[] NOR the global "Other" bucket. */
    public function test_room_only_mapped_feature_is_excluded_from_global_buckets(): void
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
        $tags  = $this->invoke('buildTags', $p);
        $other = collect($featureTags)->firstWhere('featureType', 'Other')['tags'] ?? [];
        $bath1 = collect($featureTags)->firstWhere('description', 'Bathroom 1')['tags'] ?? [];

        $this->assertContains('AlarmSystem', $other);     // global listing-feature → Other
        $this->assertNotContains('AlarmSystem', $tags);   // not loose in tags[]
        $this->assertNotContains('Shower', $other);       // room-only never global
        $this->assertNotContains('Toilet', $other);
        $this->assertContains('Shower', $bath1);          // attaches to its room
        $this->assertContains('Toilet', $bath1);
    }

    /** Decision A: a feature set BOTH globally and on a room appears under Other AND its room. */
    public function test_both_global_and_room_feature_appears_in_both_buckets(): void
    {
        $p = new Property();
        $p->spaces_json = [
            'features' => ['connectivity' => ['TV Port']], // global
            'spaces'   => [['type' => 'Bedroom', 'count' => 1, 'units' => [
                ['label' => 'Bedroom 1', 'features' => ['TV Port']], // also per-room
            ]]],
        ];
        $p->features_json = ['TV Port'];

        $tags = $this->invoke('buildTags', $p);
        $featureTags = $this->invoke('buildFeatureTags', $p);
        $other = collect($featureTags)->firstWhere('featureType', 'Other')['tags'] ?? [];
        $bed1  = collect($featureTags)->firstWhere('description', 'Bedroom 1')['tags'] ?? [];

        $this->assertNotContains('TVPort', $tags);  // NOT loose in tags[] (would show under Rooms)
        $this->assertContains('TVPort', $other);    // global → Other Features
        $this->assertContains('TVPort', $bed1);     // and attaches to the room
    }

    /** BUG 1 — a room with NO mapped features is suppressed (no "Bedroom 1 = Bedroom 1" noise). */
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
        $descriptions = collect($featureTags)->pluck('description')->filter()->all();

        $this->assertContains('Bedroom 1', $descriptions);       // kept (has a feature)
        $this->assertNotContains('Bedroom 2', $descriptions);    // suppressed (empty)
        // Every emitted entry carries at least one tag.
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

    /** Legacy properties (no spaces_json): listing-only tags → tags[], the rest → Other. */
    public function test_legacy_without_spaces_json_splits_global_features(): void
    {
        $p = new Property();
        $p->features_json = ['Sea View', 'Electric Gate']; // no spaces_json

        $tags  = $this->invoke('buildTags', $p);
        $other = collect($this->invoke('buildFeatureTags', $p))->firstWhere('featureType', 'Other')['tags'] ?? [];

        $this->assertContains('Sea', $tags);            // building options → tags[]
        $this->assertNotContains('ElectricGate', $tags);
        $this->assertContains('ElectricGate', $other);  // listing feature → Other
    }

    /** Coverage audit — a previously-unmapped CoreX feature now syndicates (under Other). */
    public function test_newly_mapped_feature_now_syndicates(): void
    {
        $this->assertContains('GraniteTops', $this->otherTags(['Granite Tops']));
        $this->assertContains('TiledFloors', $this->otherTags(['Tiled Floors']));
        $this->assertContains('Shower', $this->otherTags(['Shower']));
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
