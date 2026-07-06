<?php

namespace Tests\Unit\PrivateProperty;

use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Property 2391 bug — a Vacant Land plot (property_type = "Vacant Land / Plot")
 * carries CoreX category "Residential" because CoreX's category vocabulary has
 * no Land/Farms value; the land signal lives only in property_type. The mapper
 * used to read category alone, so it sent Category=Residential + HomeType=House
 * + forced Bathrooms=0, and PP rejected it with "PP60 - The attributes are
 * insufficient. Bathrooms is a mandatory attribute for residential listings".
 *
 * resolvePpCategory() now derives the PP category from property_type first, and
 * buildAttributes() uses the SAME resolution so land listings send LandType
 * (PP's spaced "Residential Land" value) and never a phantom Bathrooms=0.
 *
 * Pure assertions — no DB, no SOAP.
 */
class PpLandCategoryTest extends TestCase
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

    public function test_vacant_land_with_residential_category_resolves_to_land(): void
    {
        // Exactly property 2391's shape: land signal in property_type only.
        $p = (new Property())->forceFill([
            'category'      => 'Residential',
            'property_type' => 'Vacant Land / Plot',
            'beds'          => 0,
            'baths'         => 0,
            'garages'       => 0,
            'erf_size_m2'   => 486,
        ]);

        $this->assertSame('Land', PrivatePropertyListingMapper::resolvePpCategory($p));

        $attrs = $this->attributesFor($p);

        // Sends PP's proven spaced LandType, NOT camelCase (PP106 rejected that).
        $this->assertSame('Residential Land', $attrs['LandType'] ?? null);
        $this->assertSame('486', $attrs['LandArea'] ?? null);

        // Never sends residential count attributes on land — the exact fields that
        // triggered the PP60 residential-bathrooms rejection.
        $this->assertArrayNotHasKey('Bathrooms', $attrs);
        $this->assertArrayNotHasKey('Bedrooms', $attrs);
        $this->assertArrayNotHasKey('Garages', $attrs);
        $this->assertArrayNotHasKey('HomeType', $attrs);
    }

    public function test_farm_type_resolves_to_farms_category(): void
    {
        $p = (new Property())->forceFill([
            'category'      => 'Residential',
            'property_type' => 'Farm',
        ]);

        $this->assertSame('Farms', PrivatePropertyListingMapper::resolvePpCategory($p));
        $this->assertSame('Farm', $this->attributesFor($p)['FarmType'] ?? null);
    }

    public function test_residential_house_still_forces_and_sends_count_attributes(): void
    {
        // Regression guard: genuine residential must keep sending Bedrooms/
        // Bathrooms/Garages (PP requires them) with a HomeType.
        $p = (new Property())->forceFill([
            'category'      => 'Residential',
            'property_type' => 'House',
            'beds'          => 3,
            'baths'         => 2,
            'garages'       => 1,
        ]);

        $this->assertSame('Residential', PrivatePropertyListingMapper::resolvePpCategory($p));

        $attrs = $this->attributesFor($p);
        $this->assertSame('3', $attrs['Bedrooms'] ?? null);
        $this->assertSame('2', $attrs['Bathrooms'] ?? null);
        $this->assertSame('House', $attrs['HomeType'] ?? null);
        $this->assertArrayNotHasKey('LandType', $attrs);
    }

    public function test_apartment_flat_vocabulary_maps_to_apartment_not_house(): void
    {
        // The human-facing "Apartment / Flat" used to fall through to "House".
        $p = (new Property())->forceFill([
            'category'      => 'Residential',
            'property_type' => 'Apartment / Flat',
            'beds'          => 2,
            'baths'         => 1,
        ]);

        $this->assertSame('Apartment', $this->attributesFor($p)['HomeType'] ?? null);
    }

    public function test_validate_blocks_residential_listing_with_zero_bathrooms(): void
    {
        $mapper = new PrivatePropertyListingMapper();
        $payload = [
            'Category'   => ['Category' => 'Residential'],
            'Attributes' => ['Attribute' => [
                ['AttributeType' => 'Bathrooms', 'Value' => '0'],
            ]],
        ];

        $errors = $mapper->validate($payload);
        $joined = implode(' | ', $errors);
        $this->assertStringContainsString('at least 1 bathroom', $joined);
    }
}
