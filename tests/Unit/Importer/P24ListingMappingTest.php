<?php

namespace Tests\Unit\Importer;

use App\Services\Importer\P24ListingsCsvParser;
use App\Services\Importer\P24PropertyTypeMap;
use PHPUnit\Framework\TestCase;

/**
 * Locks the 2026-06-25 importer root-cause fix:
 *  - PropertyTypeId → CANONICAL property_type (was mis-aligned + non-canonical)
 *  - P24 Status → CANONICAL property_status slug (was capitalised non-canonical)
 *  - SuburbId carried into mapped_json for downstream location resolution
 * Pure unit test — no DB, no schema bootstrap.
 */
class P24ListingMappingTest extends TestCase
{
    /** Verified feed ids → canonical CoreX property_type + category. */
    public function test_property_type_map_resolves_verified_ids_to_canonical_values(): void
    {
        $cases = [
            4  => ['House',               'Residential'],
            5  => ['Apartment / Flat',    'Residential'],
            6  => ['Townhouse',           'Residential'],
            8  => ['Vacant Land / Plot',  'Residential'],
            10 => ['Farm',                null],
            11 => ['Commercial Property', 'Commercial'],
            12 => ['Industrial Property', 'Industrial'],
        ];
        foreach ($cases as $id => [$type, $category]) {
            $r = P24PropertyTypeMap::resolve($id);
            $this->assertTrue($r['known'], "id {$id} should be known");
            $this->assertSame($type, $r['type'], "id {$id} type");
            $this->assertSame($category, $r['category'], "id {$id} category");
        }
    }

    /** The OLD wrong alignment must NOT come back (regression guard). */
    public function test_old_wrong_alignment_is_gone(): void
    {
        $this->assertNotSame('VacantLand', P24PropertyTypeMap::resolve(4)['type']);
        $this->assertNotSame('Farm', P24PropertyTypeMap::resolve(5)['type']);
        $this->assertNotSame('Office', P24PropertyTypeMap::resolve(8)['type']);
    }

    /** Unknown / null ids fall back to Other + known=false (surfaced as error). */
    public function test_unknown_id_is_other_and_flagged(): void
    {
        foreach ([null, 1, 2, 3, 7, 9, 99] as $id) {
            $r = P24PropertyTypeMap::resolve($id);
            $this->assertSame('Other', $r['type']);
            $this->assertFalse($r['known']);
            $this->assertNull($r['category']);
        }
    }

    /** End-to-end parse: type canonical, status canonical slug, SuburbId carried. */
    public function test_parser_emits_canonical_type_status_and_carries_suburb_id(): void
    {
        $header = ['ListingNumber','ContactAgentIds','ListingType','Status','Price','RentalRate',
                   'PropertyTypeId','ErfSize','FloorArea','StreetNumber','StreetName','SuburbId',
                   'DescriptionHeader','Description','Bedrooms','Bathrooms','Garages'];
        $rows = [
            // apartment, withdrawn, suburb 6360
            ['1001','55','Sale','Withdrawn','1250000','','5','','82','12','Beach Rd','6360','MODERN apartment UVONGO','Lovely flat','2','1','1'],
            // vacant land, sold
            ['1002','55','Sale','Sold','325000','','8','600','','','','6373','Vacant stand','Stand','','',''],
            // rental, rented -> let_out
            ['1003','55','Rental','Rented','','8500','5','','60','3','Sea St','6359','2 Bed to let','Flat','2','1','0'],
            // new listing, active-ish -> for_sale ; unknown type id 99 -> Other + error
            ['1004','55','Sale','NewListing','990000','','99','','120','9','Main Rd','6357','Something','Desc','3','2','2'],
        ];
        $csv = tempnam(sys_get_temp_dir(), 'p24csv');
        $fh = fopen($csv, 'w');
        fputcsv($fh, $header, ',', '"', '');
        foreach ($rows as $r) fputcsv($fh, $r, ',', '"', '');
        fclose($fh);

        $parsed = (new P24ListingsCsvParser())->parse($csv);
        @unlink($csv);

        $this->assertCount(4, $parsed);
        $byId = [];
        foreach ($parsed as $p) $byId[$p['mapped']['external_id']] = $p;

        // Row 1001: typeid 5 -> Apartment / Flat ; Withdrawn -> withdrawn ; SuburbId carried
        $this->assertSame('Apartment / Flat', $byId['1001']['mapped']['property_type']);
        $this->assertSame('Residential', $byId['1001']['mapped']['category']);
        $this->assertSame('withdrawn', $byId['1001']['mapped']['status']);
        $this->assertSame(6360, $byId['1001']['mapped']['p24_suburb_id']);

        // Row 1002: typeid 8 -> Vacant Land / Plot ; Sold -> sold
        $this->assertSame('Vacant Land / Plot', $byId['1002']['mapped']['property_type']);
        $this->assertSame('sold', $byId['1002']['mapped']['status']);

        // Row 1003: Rented -> let_out (new canonical rental-concluded status)
        $this->assertSame('let_out', $byId['1003']['mapped']['status']);

        // Row 1004: NewListing -> new_listing ; unknown type 99 -> Other + recorded error
        $this->assertSame('new_listing', $byId['1004']['mapped']['status']);
        $this->assertSame('Other', $byId['1004']['mapped']['property_type']);
        $this->assertNotEmpty(array_filter(
            $byId['1004']['errors'],
            fn ($e) => str_contains($e, 'Unknown PropertyTypeId')
        ));
    }
}
