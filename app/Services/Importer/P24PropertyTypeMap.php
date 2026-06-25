<?php

namespace App\Services\Importer;

/**
 * Maps Property24 PropertyTypeId → CoreX canonical property_type string.
 * Unknown IDs map to 'Other' — caller should log in errors_json.
 *
 * IMPORTANT — this map was rebuilt 2026-06-25 after the original (1-indexed,
 * non-canonical) table mis-classified the entire HFC P24 on-boarding import
 * (4,753 live + 14,518 total listing rows). Two faults were corrected:
 *   1. The PropertyTypeId→type ALIGNMENT was wrong for this feed (e.g. the
 *      old table read id 4 as "VacantLand"/5 as "Farm"/6 as "Commercial"/
 *      8 as "Office", but the real listings — confirmed against P24-authored
 *      titles across 14k+ rows — are 4=House, 5=Apartment, 6=Townhouse,
 *      8=Vacant Land, 10=Farm, 11=Commercial, 12=Industrial).
 *   2. The output strings were NOT canonical CoreX values. The map now emits
 *      the exact `property_setting_items` names (group=property_type) so the
 *      stored value matches the edit-form select verbatim.
 *
 * Every PropertyTypeId observed in real HFC feeds (4,5,6,8,10,11,12) is
 * covered. Any other id resolves to 'Other' + known=false so the parser
 * records "Unknown PropertyTypeId" in errors_json — surfacing it loudly for
 * review rather than silently mis-assigning (the original defect class).
 */
class P24PropertyTypeMap
{
    // PropertyTypeId => [canonical property_type, canonical category|null].
    // Canonical type names per property_setting_items (group=property_type).
    // Canonical categories: Residential, Commercial, Industrial, Retirement,
    // Holiday, Project. There is no canonical "Agricultural" category, so Farm
    // carries a null category (the agent sets it on review).
    private const MAP = [
        4  => ['House',               'Residential'],
        5  => ['Apartment / Flat',    'Residential'],
        6  => ['Townhouse',           'Residential'],
        8  => ['Vacant Land / Plot',  'Residential'],
        10 => ['Farm',                null],
        11 => ['Commercial Property', 'Commercial'],
        12 => ['Industrial Property', 'Industrial'],
    ];

    public static function resolve(?int $id): array
    {
        if ($id === null || !isset(self::MAP[$id])) {
            return ['type' => 'Other', 'category' => null, 'known' => false];
        }
        [$type, $category] = self::MAP[$id];
        return ['type' => $type, 'category' => $category, 'known' => true];
    }
}
