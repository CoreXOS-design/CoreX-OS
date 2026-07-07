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
        $this->assertSame('Yes', $attrs['Alarm'] ?? null);
        $this->assertSame('Yes', $attrs['Electric_Fencing'] ?? null);
        $this->assertSame('Yes', $attrs['AccessGate'] ?? null);
        $this->assertSame('Yes', $attrs['Fence'] ?? null);
        $this->assertSame('Yes', $attrs['Satelite'] ?? null);
        $this->assertSame('Yes', $attrs['Garden'] ?? null);
        $this->assertSame('Yes', $attrs['Patio'] ?? null);

        // Room counts from spaces (PP Appendix A integer-typed attributes).
        $this->assertSame('1', $attrs['Lounges'] ?? null);
        $this->assertSame('1', $attrs['DiningAreas'] ?? null);
        $this->assertSame('1', $attrs['Parking'] ?? null);

        // Kitchen is a PRESENCE flag in PP Appendix A (boolean "Yes"), not an
        // integer count — verified against the 2026-07-05 live read-back. A
        // single Kitchen space surfaces as the Yes flag, not the count 1.
        $this->assertSame('Yes', $attrs['Kitchen'] ?? null);

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

    /**
     * AT-146 — a room-editor amenity DOES reach PP. Previously buildAttributes()
     * sourced flags from globalFeatures(), which strips features entered on a
     * room/space (not the global feature screen), so "almost no features went to
     * PP". A PP amenity flag answers "does the property have X anywhere", so a
     * bedroom's built-in cupboards must set BuiltInCupboards. (This reverses the
     * pre-AT-146 assertion, which encoded the very bug being fixed.)
     */
    public function test_room_level_amenity_reaches_pp(): void
    {
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

        $this->assertSame('Yes', $attrs['BuiltInCupboards'] ?? null);
    }

    /**
     * AT-146 — the full set of room-editor amenities (entered on a space's
     * featuresAll or a unit's features, never the global screen) now reach PP.
     * These were the bulk of what silently vanished before the fix.
     */
    public function test_room_editor_amenities_reach_pp(): void
    {
        $p = (new Property())->forceFill([
            'beds' => 2, 'baths' => 1, 'property_type' => 'House',
            'features_json' => ['Fireplace', 'Built-In Braai', 'Walk in Closet', 'Air Conditioned', 'Main en-suite'],
            'spaces_json' => ['features' => ['security' => [], 'connectivity' => []], 'spaces' => [
                ['type' => 'Lounge', 'count' => 1, 'featuresAll' => ['Fireplace', 'Built-In Braai', 'Air Conditioned'],
                 'units' => [['label' => 'Lounge 1', 'features' => []]]], // empty unit + featuresAll
                ['type' => 'Bedroom', 'count' => 1, 'units' => [['label' => 'Bedroom 1', 'features' => ['Walk in Closet']]]],
                ['type' => 'Bathroom', 'count' => 1, 'units' => [['label' => 'Bathroom 1', 'features' => ['Main en-suite']]]],
            ]],
        ]);

        $attrs = $this->attributesFor($p);

        $this->assertSame('Yes', $attrs['Fireplace'] ?? null);
        $this->assertSame('Yes', $attrs['Built_in_Braai'] ?? null);
        $this->assertSame('Yes', $attrs['WalkInCloset'] ?? null);
        $this->assertSame('Yes', $attrs['Aircon'] ?? null);
        // EnSuite is a COUNT (one en-suite bathroom here), not a flag.
        $this->assertSame('1', $attrs['EnSuite'] ?? null);
    }

    /**
     * AT-146 — En-suite / Main en-suite map to the PP EnSuite attribute (was
     * unmapped). EnSuite is a COUNT in PP's Appendix A (sits among the room-count
     * attributes), so it is emitted as an integer, NOT the boolean 'true' — a
     * 'true' value triggers PP106 "match attribute datatypes" and rejects the
     * whole listing.
     */
    public function test_ensuite_maps_to_pp_ensuite_count(): void
    {
        $p = (new Property())->forceFill([
            'beds' => 2, 'baths' => 2, 'property_type' => 'Apartment',
            'features_json' => ['En-suite', 'Main en-suite'],
            'spaces_json' => ['spaces' => [
                ['type' => 'Bathroom', 'count' => 1, 'units' => [
                    ['label' => 'Bathroom 1', 'features' => ['Main en-suite']],
                    ['label' => 'Bathroom 2', 'features' => ['En-suite']],
                ]],
            ]],
        ]);

        // Two en-suite bathrooms → EnSuite=2 (integer count).
        $this->assertSame('2', $this->attributesFor($p)['EnSuite'] ?? null);
    }

    /** AT-146 — "Single Storey" maps to PP Storeys=1; multi-storey is omitted (no CoreX feature). */
    public function test_single_storey_maps_to_pp_storeys(): void
    {
        $p = (new Property())->forceFill([
            'beds' => 3, 'property_type' => 'House',
            'features_json' => ['Single Storey'],
            'spaces_json' => ['spaces' => []],
        ]);

        $this->assertSame('1', $this->attributesFor($p)['Storeys'] ?? null);
    }

    /**
     * Half bathrooms. PP's Bathrooms attribute is an integer (no fractional
     * baths) and PP has no numeric half-bath field, so the wizard's `half_baths`
     * scalar surfaces through PP's native Guest_Toilet flag — a half bathroom IS
     * a guest toilet / cloakroom.
     */
    public function test_half_baths_scalar_sets_guest_toilet_flag(): void
    {
        $p = (new Property())->forceFill([
            'beds' => 3, 'baths' => 2, 'half_baths' => 1, 'property_type' => 'House',
            'features_json' => [],
            'spaces_json' => ['spaces' => []],
        ]);

        $attrs = $this->attributesFor($p);
        $this->assertSame('Yes', $attrs['Guest_Toilet'] ?? null);
        // Integer Bathrooms stays the full-bath count — the half surfaces only
        // as the Guest_Toilet amenity, not as a fractional bathroom.
        $this->assertSame('2', $attrs['Bathrooms'] ?? null);
    }

    /** No half bath and no explicit guest toilet → Guest_Toilet omitted (not sent false). */
    public function test_no_half_bath_omits_guest_toilet(): void
    {
        $p = (new Property())->forceFill([
            'beds' => 3, 'baths' => 2, 'half_baths' => 0, 'property_type' => 'House',
            'features_json' => [],
            'spaces_json' => ['spaces' => []],
        ]);

        $this->assertArrayNotHasKey('Guest_Toilet', $this->attributesFor($p));
    }
}
