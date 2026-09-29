<?php

namespace App\Services\Properties;

use App\Models\Property;

/**
 * .ai/specs/rental-inspections.md — Stage 2 conversion. Johan: "current
 * rental stock cannot be dumped. its needs to be converted." Normalises a
 * property's old flat spaces_json (e.g. {"studies":1,"kitchens":1,...}) —
 * or a fully null spaces_json carrying only the legacy beds/baths/garages
 * columns — into the current {spaces:[...], features:{...}} shape the
 * property edit screen and RentalInspectionFormSeeder both actually read.
 *
 * One implementation, reused by both the artisan command below and
 * anywhere else that might need to convert a single property on demand
 * (e.g. lazily, the first time an agent opens an old-format property).
 *
 * Mapping table (old -> new), settled across three rounds of Johan's own
 * rulings tonight:
 *   beds column                          -> Bedroom
 *   baths + half_baths columns           -> Bathroom (half_baths>0 adds 0.5)
 *   garages column                       -> Garage
 *   spaces_json.parking_spaces           -> Parking   \_ Johan: "secure
 *   spaces_json.secure_parkings          -> Parking   /  parking sits under
 *                                                         parking" — summed,
 *                                                         not a new type,
 *                                                         not folded into
 *                                                         Garage.
 *   spaces_json.studies (+description)   -> Study
 *   spaces_json.kitchens (+description)  -> Kitchen
 *   spaces_json.domestic_rooms (+desc)   -> Domestic Room
 *   spaces_json.domestic_bathrooms       -> Domestic Bathroom
 *   spaces_json.outside_toilets          -> Outside Toilet
 *   spaces_json.reception_rooms          -> Reception Room
 *   features_json.pool                   -> Pool space (Johan named this
 *   features_json.garden                 -> Garden space directly: these
 *   features_json.flatlet                -> Flatlet space are spaces, not
 *                                            features-within-a-space)
 *   features_json.furnished              -> features.theProperty (marketing
 *                                            fidelity only — not itself an
 *                                            inspection driver; furniture
 *                                            condition/count is Inventory,
 *                                            Stage 6, not this)
 *   features_json.pets_allowed           -> features.theProperty (marketing
 *                                            fidelity only — a lease/policy
 *                                            term, never an inspection item,
 *                                            matches Johan's own "commercial
 *                                            or legal attribute" exclusion)
 *   features_json.listing_visibility     -> NOT mapped. MatchingService and
 *                                            MarketingCopyService already
 *                                            treat this key as noise, never
 *                                            a feature — confirmed, not
 *                                            assumed.
 *
 * Every applicable type gets an entry even at count 0 (matching the
 * existing new-format convention already seen on properties like 6141,
 * where Pool/Garden/Parking appear with count:0 rather than being
 * omitted) — nothing is hidden, the agent sees every type this property's
 * old data ever touched and edits from there.
 */
class LegacySpacesJsonConverter
{
    public const SOURCE_KEY_TO_TYPE = [
        'studies' => 'Study',
        'kitchens' => 'Kitchen',
        'domestic_rooms' => 'Domestic Room',
        'domestic_bathrooms' => 'Domestic Bathroom',
        'outside_toilets' => 'Outside Toilet',
        'reception_rooms' => 'Reception Room',
    ];

    public function needsConversion(Property $property): bool
    {
        if ($property->spaces_json_legacy_backup !== null) {
            return false;
        }

        $sj = $property->spaces_json;

        return ! (is_array($sj) && array_key_exists('spaces', $sj));
    }

    /**
     * @return array{skipped: bool, dry_run?: bool, new?: array, reason?: string}
     */
    public function convert(Property $property, bool $dryRun = false): array
    {
        if (! $this->needsConversion($property)) {
            return ['skipped' => true, 'reason' => 'already new format or already converted'];
        }

        $oldSpaces = is_array($property->spaces_json) ? $property->spaces_json : [];
        $oldFeatures = is_array($property->features_json) ? $property->features_json : [];

        $spaces = [];
        $addSpace = function (string $type, float $count, ?string $description = null) use (&$spaces) {
            $units = [];
            for ($i = 1; $i <= (int) ceil($count); $i++) {
                $units[] = ['label' => "{$type} {$i}", 'features' => []];
            }
            $spaces[] = [
                'type' => $type,
                'count' => $count,
                'units' => $units,
                'featuresAll' => [],
                'descriptionAll' => $description ?? '',
            ];
        };

        // Columns that were never inside spaces_json in the old model at all.
        $addSpace('Bedroom', (float) ($property->beds ?? 0));
        $halfBath = ($property->half_baths ?? 0) > 0 ? 0.5 : 0.0;
        $addSpace('Bathroom', (float) ($property->baths ?? 0) + $halfBath);
        $addSpace('Garage', (float) ($property->garages ?? 0));

        // Johan's final ruling: secure parking sits under Parking, summed — not
        // folded into Garage, not a new type.
        $addSpace('Parking', (float) ($oldSpaces['parking_spaces'] ?? 0) + (float) ($oldSpaces['secure_parkings'] ?? 0));

        foreach (self::SOURCE_KEY_TO_TYPE as $key => $type) {
            $addSpace($type, (float) ($oldSpaces[$key] ?? 0), $oldSpaces["{$key}_description"] ?? null);
        }

        // Old features_json booleans that are actually space types (Johan
        // named these directly), preserved even when false — matches the
        // "every applicable type gets an entry" rule above.
        $addSpace('Pool', ! empty($oldFeatures['pool']) ? 1.0 : 0.0);
        $addSpace('Garden', ! empty($oldFeatures['garden']) ? 1.0 : 0.0);
        $addSpace('Flatlet', ! empty($oldFeatures['flatlet']) ? 1.0 : 0.0);

        // Old features_json booleans that are genuine property-wide marketing
        // features — preserved for marketing fidelity only.
        $theProperty = [];
        if (! empty($oldFeatures['furnished'])) {
            $theProperty[] = 'Furnished';
        }
        if (! empty($oldFeatures['pets_allowed'])) {
            $theProperty[] = 'Pet Friendly';
        }

        $newShape = [
            'spaces' => $spaces,
            'features' => [
                'theProperty' => $theProperty,
                'security' => [],
                'connectivity' => [],
                'sustainability' => [],
            ],
        ];

        if ($dryRun) {
            return ['skipped' => false, 'dry_run' => true, 'new' => $newShape];
        }

        $property->forceFill([
            'spaces_json_legacy_backup' => [
                'spaces_json' => $property->spaces_json,
                'features_json' => $property->features_json,
            ],
            'spaces_json' => $newShape,
        ])->save();

        return ['skipped' => false, 'dry_run' => false, 'new' => $newShape];
    }
}
