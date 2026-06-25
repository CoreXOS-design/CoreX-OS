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
