<?php

namespace App\Services\Rentals;

use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rental-inspections.md — Stage 2 of the inspections rework.
 * Johan: "for advertising the agent is building the property, correct?
 * spaces, features etc. So we use the advertising details to build the
 * inspection report as a basic and then agents should be allowed to add
 * the rest." Seeds a property's inspection form ONCE from its own
 * advertising Spaces + ticked Features, then never touches it again —
 * from that point the form is the property's own (RentalInspectionSetting
 * ::roomTypeItemsFor() / inspectionFeatureLabelsFor() supply the agency's
 * defaults, this class is the one place that turns them into rows).
 *
 * Same shared endpoint a mobile client will call — this is a thin caller
 * over RentalInspectionItem::create()/PropertyRoom::create(), not a second
 * implementation of anything the property tab's own Add-item form already
 * does by hand.
 */
class RentalInspectionFormSeeder
{
    /**
     * @throws \LogicException if already seeded, or if there's no
     *         advertising data to seed from yet.
     */
    public function seedFromAdvertising(Property $property, User $by): void
    {
        if ($property->rental_inspection_form_seeded_at !== null) {
            throw new \LogicException('This property\'s inspection form has already been built once and is now its own — seeding never runs a second time.');
        }

        $spacesJson = $property->spaces_json;
        $spaces = is_array($spacesJson) ? ($spacesJson['spaces'] ?? null) : null;

        if (! is_array($spaces) || $spaces === []) {
            throw new \LogicException('This property has no advertising Spaces yet — add them under the Rental tab first, then build the inspection form from here.');
        }

        $tickedFeatures = RentalInspectionSetting::inspectionFeatureLabelsFor($property->agency_id);
        $propertyWideFeatures = is_array($spacesJson['features'] ?? null)
            ? collect($spacesJson['features'])->flatten()->all()
            : [];

        DB::transaction(function () use ($property, $by, $spaces, $tickedFeatures, $propertyWideFeatures) {
            $sortOrder = 0;

            foreach ($spaces as $space) {
                $type = $space['type'] ?? null;
                $units = $space['units'] ?? [];
                if (! $type || ! is_array($units) || $units === []) {
                    continue;
                }

                $facetLabels = RentalInspectionSetting::roomTypeItemsFor($property->agency_id, $type);
                $typeFeatures = array_intersect($space['featuresAll'] ?? [], $tickedFeatures);

                foreach ($units as $unit) {
                    $unitLabel = $unit['label'] ?? null;
                    if (! $unitLabel) {
                        continue;
                    }

                    $room = PropertyRoom::create([
                        'agency_id' => $property->agency_id,
                        'property_id' => $property->id,
                        'type' => $type,
                        'label' => $unitLabel,
                        'source' => 'advertising_space',
                        'sort_order' => $sortOrder++,
                        'created_by_user_id' => $by->id,
                    ]);

                    foreach ($facetLabels as $facetLabel) {
                        RentalInspectionItem::create([
                            'agency_id' => $property->agency_id,
                            'property_id' => $property->id,
                            'property_room_id' => $room->id,
                            'kind' => RentalInspectionItem::KIND_SPACE,
                            'label' => $facetLabel,
                            'space_type' => $type,
                            'source' => 'advertising_space',
                            'created_by_user_id' => $by->id,
                        ]);
                    }

                    // Unit-specific ticked features (e.g. only THIS bedroom has
                    // aircon) plus the type-wide ticked features (every room of
                    // this type has it) both land on this room, deduplicated.
                    $unitFeatures = array_intersect($unit['features'] ?? [], $tickedFeatures);
                    foreach (array_unique(array_merge($typeFeatures, $unitFeatures)) as $featureLabel) {
                        RentalInspectionItem::create([
                            'agency_id' => $property->agency_id,
                            'property_id' => $property->id,
                            'property_room_id' => $room->id,
                            'kind' => RentalInspectionItem::KIND_SPACE,
                            'label' => $featureLabel,
                            'space_type' => $type,
                            'source' => 'advertising_feature',
                            'created_by_user_id' => $by->id,
                        ]);
                    }
                }
            }

            // Property-wide ticked features (Alarm System, Solar Panel, etc.) —
            // no single room owns these, so no PropertyRoom is created for them.
            foreach (array_unique(array_intersect($propertyWideFeatures, $tickedFeatures)) as $featureLabel) {
                RentalInspectionItem::create([
                    'agency_id' => $property->agency_id,
                    'property_id' => $property->id,
                    'property_room_id' => null,
                    'kind' => RentalInspectionItem::KIND_SPACE,
                    'label' => $featureLabel,
                    'space_type' => null,
                    'source' => 'advertising_feature',
                    'created_by_user_id' => $by->id,
                ]);
            }

            $property->forceFill(['rental_inspection_form_seeded_at' => now()])->save();
        });
    }
}
