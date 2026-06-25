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
        // must not emit any internetAccess connection type.
        $features = $this->buildPropertyFeatures(['Fast Internet', 'TV Port']);

        $this->assertArrayNotHasKey('internetAccess', $features);
    }

    public function test_fibre_feature_maps_to_p24_fibre(): void
    {
        $features = $this->buildPropertyFeatures(['Fibre']);

        $this->assertArrayHasKey('internetAccess', $features);
        $this->assertTrue($features['internetAccess']['fibre']);
        $this->assertFalse($features['internetAccess']['adsl']);
        $this->assertFalse($features['internetAccess']['satellite']);
    }

    public function test_adsl_and_satellite_map_without_setting_fibre(): void
    {
        $features = $this->buildPropertyFeatures(['ADSL', 'Satellite Internet']);

        $this->assertArrayHasKey('internetAccess', $features);
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
}
