<?php

namespace Tests\Unit\PrivateProperty;

use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Property 6049 bug — CoreX sent only 8 structural attributes to Private
 * Property, so no amenity feature ever reached the portal. buildAttributes()
 * now maps features_json + spaces_json to the PP `AttributeType` enum
 * (storage/pp-attributetype-enum.txt — 70 verified values from the live WSDL).
 *
 * These are pure assertions on buildAttributes() (no DB, no SOAP).
 */
class PpFeatureAttributesTest extends TestCase
{
    /** @return array<string,string> AttributeType => Value */
    private function attributesFor(Property $p): array
    {
        $m   = new ReflectionMethod(PrivatePropertyListingMapper::class, 'buildAttributes');
        $out = $m->invoke(new PrivatePropertyListingMapper(), $p);

        $flat = [];
        foreach ($out['Attribute'] ?? [] as $a) {
            $flat[$a['AttributeType']] = $a['Value'];
        }

        return $flat;
    }

    public function test_global_amenities_and_room_counts_map_to_pp_attributes(): void
    {
        // Mirrors property 6049: security + connectivity global features, and
        // structured spaces incl. Lounge, Dining Room, Kitchen, Garden, Patio.
        $p = (new Property())->forceFill([
            'beds' => 3, 'baths' => 2, 'garages' => 1, 'property_type' => 'Townhouse',
            'features_json' => [
                'Alarm System', 'Electric Fence', 'Electric Gate', 'Totally Walled',
                'Satellite Dish', 'Armed Response', 'Safe', 'ADSL', 'Fibre',
            ],
            'spaces_json' => ['spaces' => [
                ['type' => 'Lounge', 'count' => 1],
                ['type' => 'Dining Room', 'count' => 1],
                ['type' => 'Kitchen', 'count' => 1],
                ['type' => 'Garden', 'count' => 1],
                ['type' => 'Patio', 'count' => 1],
                ['type' => 'Parking', 'count' => 1],
            ]],
        ]);

        $attrs = $this->attributesFor($p);

        // Feature flags present (PP's exact enum spelling, incl. its misspelling).
        $this->assertSame('true', $attrs['Alarm'] ?? null);
        $this->assertSame('true', $attrs['Electric_Fencing'] ?? null);
        $this->assertSame('true', $attrs['AccessGate'] ?? null);
        $this->assertSame('true', $attrs['Fence'] ?? null);
        $this->assertSame('true', $attrs['Satelite'] ?? null);
        $this->assertSame('true', $attrs['Garden'] ?? null);
        $this->assertSame('true', $attrs['Patio'] ?? null);

        // Room counts from spaces.
        $this->assertSame('1', $attrs['Lounges'] ?? null);
        $this->assertSame('1', $attrs['DiningAreas'] ?? null);
        $this->assertSame('1', $attrs['Kitchen'] ?? null);
        $this->assertSame('1', $attrs['Parking'] ?? null);

        // Structural attributes still present (no regression).
        $this->assertSame('3', $attrs['Bedrooms'] ?? null);
        $this->assertSame('Townhouse', $attrs['HomeType'] ?? null);

        // Features with NO clean PP attribute are skipped, never guessed.
        $this->assertArrayNotHasKey('Safe', $attrs);
        $this->assertArrayNotHasKey('ADSL', $attrs);
        $this->assertArrayNotHasKey('Armed Response', $attrs);
    }

    public function test_absent_features_are_omitted_not_sent_false(): void
    {
        $p = (new Property())->forceFill([
            'beds' => 2, 'baths' => 1, 'property_type' => 'Apartment',
            'features_json' => [], 'spaces_json' => ['spaces' => []],
        ]);

        $attrs = $this->attributesFor($p);

        // Flags are present-only: an absent amenity emits NO attribute at all.
        $this->assertArrayNotHasKey('Pool', $attrs);
        $this->assertArrayNotHasKey('Alarm', $attrs);
        $this->assertArrayNotHasKey('Garden', $attrs);
        // Structural still there.
        $this->assertSame('2', $attrs['Bedrooms'] ?? null);
    }

    public function test_room_only_feature_does_not_flip_a_property_level_flag(): void
    {
        // 'Built-in Cupboards' lives only inside a bedroom unit and is NOT in the
        // explicit property-screen selection → it must not set BuiltInCupboards.
        $p = (new Property())->forceFill([
            'beds' => 1, 'property_type' => 'House',
            'features_json' => ['Built-in Cupboards'],
            'spaces_json' => [
                'features' => ['security' => [], 'connectivity' => []],
                'spaces' => [[
                    'type' => 'Bedroom', 'count' => 1,
                    'units' => [['label' => 'Bedroom 1', 'features' => ['Built-in Cupboards']]],
                ]],
            ],
        ]);

        $attrs = $this->attributesFor($p);

        $this->assertArrayNotHasKey('BuiltInCupboards', $attrs);
    }
}
