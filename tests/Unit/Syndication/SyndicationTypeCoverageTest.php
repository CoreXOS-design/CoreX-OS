<?php

namespace Tests\Unit\Syndication;

use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use App\Services\Syndication\Property24\Property24ListingMapper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Structural regression guard for the syndication type/category bug class.
 *
 * Property 2391 (Vacant Land / Plot) was rejected by Private Property because
 * the PP mapper classified it from CoreX's `category` column (Residential) —
 * which has no Land/Farms value — instead of `property_type`, and sent an
 * attribute VALUE ("VacantLand") outside PP's accepted vocabulary. Audit:
 * .ai/audits/syndication-type-category-audit-2026-07-06.md
 *
 * This test drives the ENTIRE real CoreX property_type input space through both
 * portal mappers and asserts, for every type:
 *   - PP resolves a valid top category (property_type-aware, never category-only)
 *   - PP emits exactly one category-appropriate type attribute
 *   - the PP type VALUE follows PP's spaced Title-Case convention (no camelCase
 *     multi-word value that would PP106)
 *   - Bedrooms/Bathrooms/Garages are forced ONLY for residential
 *   - P24 resolves a valid numeric propertyTypeId
 *
 * If a mapper regresses, or someone adds a property_type option without wiring
 * it through, this fails — mechanically, before it reaches a portal.
 */
class SyndicationTypeCoverageTest extends TestCase
{
    /**
     * The canonical CoreX property_type vocabulary — mirrors the
     * PropertySettingItem 'property_type' group (config-owned). Keep in sync:
     * adding a real property_type option means adding it here.
     *
     * @var array<string,array{ppCategory:string,ppTypeAttr:string,p24Id:int}>
     */
    private const EXPECTATIONS = [
        'House'               => ['ppCategory' => 'Residential', 'ppTypeAttr' => 'HomeType',     'p24Id' => 4],
        'Apartment / Flat'    => ['ppCategory' => 'Residential', 'ppTypeAttr' => 'HomeType',     'p24Id' => 5],
        'Townhouse'           => ['ppCategory' => 'Residential', 'ppTypeAttr' => 'HomeType',     'p24Id' => 6],
        'Vacant Land / Plot'  => ['ppCategory' => 'Land',        'ppTypeAttr' => 'LandType',     'p24Id' => 8],
        'Farm'                => ['ppCategory' => 'Farms',       'ppTypeAttr' => 'FarmType',     'p24Id' => 10],
        'Commercial Property' => ['ppCategory' => 'Commercial',  'ppTypeAttr' => 'BusinessType', 'p24Id' => 11],
        'Industrial Property' => ['ppCategory' => 'Commercial',  'ppTypeAttr' => 'BusinessType', 'p24Id' => 12],
    ];

    private const PP_TYPE_ATTRS = ['HomeType', 'LandType', 'FarmType', 'BusinessType'];

    /** @return array<string,string> AttributeType => Value */
    private function ppAttributes(Property $p): array
    {
        $m   = new ReflectionMethod(PrivatePropertyListingMapper::class, 'buildAttributes');
        $out = $m->invoke(new PrivatePropertyListingMapper(), $p);

        $flat = [];
        foreach ($out['Attribute'] ?? [] as $a) {
            $flat[$a['AttributeType']] = $a['Value'];
        }

        return $flat;
    }

    public function test_every_property_type_maps_cleanly_on_both_portals(): void
    {
        $p24    = new Property24ListingMapper();
        $p24Ref = new ReflectionMethod(Property24ListingMapper::class, 'resolvePropertyTypeId');
        $p24Ref->setAccessible(true);

        foreach (self::EXPECTATIONS as $type => $exp) {
            // beds/baths deliberately 0 — the property-2391 shape.
            $prop = (new Property())->forceFill([
                'property_type' => $type,
                'category'      => 'Residential', // the mismatched column that started the bug
                'beds'          => 0,
                'baths'         => 0,
                'garages'       => 0,
            ]);

            // --- Private Property ---------------------------------------------
            $ppCategory = PrivatePropertyListingMapper::resolvePpCategory($prop);
            $this->assertSame(
                $exp['ppCategory'],
                $ppCategory,
                "PP category for '{$type}' must derive from property_type, not the category column"
            );

            $attrs = $this->ppAttributes($prop);

            // Exactly one type attribute, and it is the category-appropriate one.
            $present = array_values(array_intersect(self::PP_TYPE_ATTRS, array_keys($attrs)));
            $this->assertSame(
                [$exp['ppTypeAttr']],
                $present,
                "PP must send exactly the '{$exp['ppTypeAttr']}' attribute for '{$type}'"
            );

            // The VALUE must follow PP's spaced Title-Case convention — no
            // camelCase multi-word value (the "VacantLand" PP106 trap).
            $value = $attrs[$exp['ppTypeAttr']];
            $this->assertNotSame('', $value, "PP type value for '{$type}' must not be empty");
            $this->assertDoesNotMatchRegularExpression(
                '/[a-z][A-Z]/',
                $value,
                "PP type value '{$value}' for '{$type}' looks camelCase — PP wants spaced Title Case (PP106 risk)"
            );

            // Count attributes are forced (even at 0) ONLY for residential.
            $isResidential = $exp['ppCategory'] === 'Residential';
            foreach (['Bedrooms', 'Bathrooms', 'Garages'] as $count) {
                $this->assertSame(
                    $isResidential,
                    array_key_exists($count, $attrs),
                    "PP {$count} should be sent for '{$type}' only when residential"
                );
            }

            // --- Property24 ---------------------------------------------------
            $p24Id = $p24Ref->invoke($p24, $type);
            $this->assertSame(
                $exp['p24Id'],
                $p24Id,
                "P24 propertyTypeId for '{$type}' must resolve to {$exp['p24Id']}"
            );
        }
    }
}
