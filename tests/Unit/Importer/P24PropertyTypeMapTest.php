<?php

namespace Tests\Unit\Importer;

use App\Services\Importer\P24PropertyTypeMap;
use PHPUnit\Framework\TestCase;

/**
 * Locks P24's REAL property-type codes. The importer once used an invented
 * sequential 1..20 map that turned every House into "VacantLand", every
 * Apartment into "Farm", every Vacant Land into "Office" (audit run 10,
 * 2026-07-17). These IDs come from P24's /listing/v53/property-types and must
 * agree with Property24ListingMapper::resolvePropertyTypeId() (the reverse map
 * used when syndicating back).
 */
class P24PropertyTypeMapTest extends TestCase
{
    /** @dataProvider realCodes */
    public function test_maps_real_p24_codes(int $id, string $type, string $category): void
    {
        $r = P24PropertyTypeMap::resolve($id);
        $this->assertTrue($r['known'], "id {$id} should be known");
        $this->assertSame($type, $r['type']);
        $this->assertSame($category, $r['category']);
    }

    public static function realCodes(): array
    {
        return [
            'House'      => [4, 'House', 'Residential'],
            'Apartment'  => [5, 'Apartment', 'Residential'],
            'Townhouse'  => [6, 'Townhouse', 'Residential'],
            'VacantLand' => [8, 'VacantLand', 'Residential'],
            'Farm'       => [10, 'Farm', 'Agricultural'],
            'Commercial' => [11, 'Commercial', 'Commercial'],
            'Industrial' => [12, 'Industrial', 'Industrial'],
        ];
    }

    public function test_the_old_scrambled_codes_are_gone(): void
    {
        // 4 must be House, never VacantLand. This is the exact regression.
        $this->assertSame('House', P24PropertyTypeMap::resolve(4)['type']);
        $this->assertNotSame('VacantLand', P24PropertyTypeMap::resolve(4)['type']);
        $this->assertNotSame('Farm', P24PropertyTypeMap::resolve(5)['type']);
        $this->assertNotSame('Office', P24PropertyTypeMap::resolve(8)['type']);
    }

    public function test_unknown_id_is_flagged_not_silently_mistyped(): void
    {
        foreach ([null, 0, 1, 2, 3, 7, 9, 99] as $id) {
            $r = P24PropertyTypeMap::resolve($id);
            $this->assertFalse($r['known'], "id " . var_export($id, true) . " must be unknown");
            $this->assertSame('Other', $r['type']);
            $this->assertNull($r['category']);
        }
    }
}
