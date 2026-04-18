<?php

namespace App\Services\Importer;

/**
 * Maps Property24 PropertyTypeId → CoreX property_type string.
 * Unknown IDs map to 'Other' — caller should log in errors_json.
 */
class P24PropertyTypeMap
{
    private const MAP = [
        1  => 'House',
        2  => 'Apartment',
        3  => 'Townhouse',
        4  => 'VacantLand',
        5  => 'Farm',
        6  => 'Commercial',
        7  => 'Industrial',
        8  => 'Office',
        9  => 'Retail',
        10 => 'Warehouse',
        11 => 'Smallholding',
        12 => 'Guesthouse',
        13 => 'Flat',
        14 => 'Duet',
        15 => 'Cluster',
        16 => 'Simplex',
        17 => 'Studio',
        18 => 'Penthouse',
        19 => 'Mixed Use',
        20 => 'Hotel',
    ];

    private const CATEGORY = [
        'House'        => 'Residential',
        'Apartment'    => 'Residential',
        'Townhouse'    => 'Residential',
        'Flat'         => 'Residential',
        'Duet'         => 'Residential',
        'Cluster'      => 'Residential',
        'Simplex'      => 'Residential',
        'Studio'       => 'Residential',
        'Penthouse'    => 'Residential',
        'Guesthouse'   => 'Holiday',
        'Hotel'        => 'Holiday',
        'VacantLand'   => 'Residential',
        'Farm'         => 'Agricultural',
        'Smallholding' => 'Agricultural',
        'Commercial'   => 'Commercial',
        'Office'       => 'Commercial',
        'Retail'       => 'Commercial',
        'Mixed Use'    => 'Commercial',
        'Industrial'   => 'Industrial',
        'Warehouse'    => 'Industrial',
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
