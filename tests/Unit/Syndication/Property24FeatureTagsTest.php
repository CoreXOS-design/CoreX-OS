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
        $tags = $this->buildTags(['Sea View', 'Some Feature P24 Does Not Know']);

        $this->assertSame(['Sea'], $tags);
    }

    public function test_security_and_connectivity_features_map(): void
    {
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

    /** A feature that exists ONLY on a room must not appear in the property tags[]. */
    public function test_room_only_mapped_feature_is_excluded_from_tags(): void
    {
        $p = new Property();
        $p->spaces_json = [
            'features' => ['security' => ['Alarm System']], // global
            'spaces'   => [['type' => 'Bathroom', 'count' => 1, 'units' => [
                ['label' => 'Bathroom 1', 'features' => ['Shower', 'Toilet']], // room-only
            ]]],
        ];
        $p->features_json = ['Alarm System', 'Shower', 'Toilet'];

        $tags = $this->invoke('buildTags', $p);

        $this->assertContains('AlarmSystem', $tags);          // global stays
        $this->assertNotContains('Shower', $tags);            // room-only excluded
        $this->assertNotContains('Toilet', $tags);
    }

    /** Decision A: a feature set BOTH globally and on a room appears in both buckets. */
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

        $this->assertContains('TVPort', $tags); // stays in tags[] (global)
        $bed1 = collect($featureTags)->firstWhere('description', 'Bedroom 1');
        $this->assertContains('TVPort', $bed1['tags'] ?? []); // and attaches to the room
    }

    /** featureTags emits named rooms for room-type spaces (Lounge, Dining Room). */
    public function test_named_rooms_emitted_as_feature_tags(): void
    {
        $p = new Property();
        $p->spaces_json = ['features' => [], 'spaces' => [
            ['type' => 'Lounge', 'count' => 1],
            ['type' => 'Dining Room', 'count' => 1],
            ['type' => 'Patio', 'count' => 1], // no P24 FeatureType → skipped
        ]];
        $p->features_json = [];

        $types = collect($this->invoke('buildFeatureTags', $p))->pluck('featureType')->all();

        $this->assertContains('Lounge', $types);
        $this->assertContains('DiningRoom', $types);
        $this->assertNotContains('Patio', $types); // unmapped space type skipped
    }

    /** Legacy properties with no structured spaces_json keep features_json as global. */
    public function test_legacy_without_spaces_json_falls_back_to_features_json(): void
    {
        $p = new Property();
        $p->features_json = ['Sea View', 'Electric Gate'];
        // no spaces_json

        $tags = $this->invoke('buildTags', $p);

        $this->assertContains('Sea', $tags);
        $this->assertContains('ElectricGate', $tags);
    }

    /** Coverage audit — a previously-unmapped CoreX feature now emits its P24 tag. */
    public function test_newly_mapped_feature_now_syndicates(): void
    {
        $this->assertContains('GraniteTops', $this->buildTags(['Granite Tops']));
        $this->assertContains('TiledFloors', $this->buildTags(['Tiled Floors']));
        $this->assertContains('Shower', $this->buildTags(['Shower']));
    }
}
