<?php

namespace App\Services\Importer;

/**
 * Maps Property24 PropertyTypeId → CoreX property_type string.
 *
 * IDs are P24's REAL property-type codes, sourced from GET /listing/v53/property-types
 * (the same table Property24ListingMapper::resolvePropertyTypeId() maps CoreX types
 * BACK to when syndicating — so import and syndication now agree). The codes are
 * NOT a dense 1..N sequence; they are exactly:
 *   4 = House, 5 = Apartment/Flat, 6 = Townhouse, 8 = Vacant Land/Plot,
 *   10 = Farm, 11 = Commercial, 12 = Industrial.
 *
 * The previous version invented a sequential 1..20 map (1=House, 4=VacantLand,
 * 5=Farm, 8=Office …) that did not match P24 at all — it turned every imported
 * House into "VacantLand", every Apartment into "Farm", every Vacant Land into
 * "Office" (audit run 10, 2026-07-17). This is the fix.
 *
 * Unknown IDs map to 'Other' with known=false — the parser records that in
 * errors_json so a code P24 adds later surfaces loudly instead of being
 * silently mis-typed. If a real export flags unknown IDs, extend this table
 * from P24's property-types endpoint rather than guessing.
 */
class P24PropertyTypeMap
{
    private const MAP = [
        4  => 'House',
        5  => 'Apartment',
        6  => 'Townhouse',
        8  => 'VacantLand',
        10 => 'Farm',
        11 => 'Commercial',
        12 => 'Industrial',
    ];

    private const CATEGORY = [
        'House'      => 'Residential',
        'Apartment'  => 'Residential',
        'Townhouse'  => 'Residential',
        'VacantLand' => 'Residential',
        'Farm'       => 'Agricultural',
        'Commercial' => 'Commercial',
        'Industrial' => 'Industrial',
    ];

    public static function resolve(?int $id): array
    {
        if ($id === null || !isset(self::MAP[$id])) {
            return ['type' => 'Other', 'category' => null, 'known' => false];
        }
        $type = self::MAP[$id];
        return ['type' => $type, 'category' => self::CATEGORY[$type] ?? null, 'known' => true];
    }
}
