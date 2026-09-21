<?php

namespace App\Services\Properties;

use App\Models\Property;
use App\Models\PropertyRentalDetailsCustomField;

/**
 * .ai/specs/rental-property-tab.md §4, Part 5. Johan's ruling, verbatim:
 * "an intelligent way to add it as a block at the bottom of the marketing
 * description from corex before it goes out to the portals... this will
 * stop agents from forgetting this when they create the ads."
 *
 * This is the ONE assembly point (§4.2) — every consumer that sends a
 * property's description anywhere it's actually seen (both portal
 * mappers, the website API's ListingResource, and eventually the
 * live-preview page, Part 6) calls descriptionForSyndication() instead
 * of reading Property::$description directly, so the block can never
 * appear in one channel and not another by omission.
 *
 * Computed fresh every call, never persisted (§4.2's own reasoning: a
 * written-in block goes stale the moment a source field changes,
 * reintroducing the exact drift this feature exists to eliminate).
 * Returns the stored description completely unchanged, byte-for-byte,
 * whenever the property-level master tick is off — which is every
 * property, including every existing one, until an agent explicitly
 * turns it on (§4.0).
 */
class RentalAdvertBlockService
{
    /**
     * Core fields eligible for the block — deliberately NOT every rental
     * field on Property. `rental_amount` (the advertised price) and
     * `deposit_amount` already reach both portals through their own
     * native structured slots (Property24ListingMapper.php:116-117's
     * `rentalInfo.depositRequirementsComments`; PP sends deposit_amount
     * as its own native numeric field) — putting them in the text block
     * too would duplicate a figure the portal already shows natively
     * (§4.4's own "avoid the double-price bug" reasoning). `admin_fee`
     * and `marketing_fee` reach ZERO syndication targets today on any
     * channel — confirmed by reading both mappers and ListingResource —
     * which is very likely why an agent hand-typed the admin fee into
     * the description in the first place. These two are the real gap
     * this feature closes; nothing else is core-field-eligible for the
     * text block until a real, named need for another one shows up.
     */
    public const CORE_FIELDS = [
        'admin_fee' => 'Once-off admin fee',
        'marketing_fee' => 'Marketing fee',
    ];

    /**
     * What every portal mapper and the website API should call instead of
     * reading $property->description directly.
     */
    public function descriptionForSyndication(Property $property): ?string
    {
        $description = $property->description;

        if (! $property->rental_advert_block_enabled) {
            return $description;
        }

        $block = $this->buildBlock($property);
        if ($block === '') {
            return $description;
        }

        return trim(($description ?? '') . "\n\n" . $block);
    }

    /**
     * The assembled block text alone, with no description prefix — used by
     * the inline preview (Part 6) so an agent sees exactly what will be
     * appended, not the whole description re-rendered.
     */
    public function buildBlock(Property $property): string
    {
        $lines = [];

        $tickedCore = array_intersect((array) ($property->advertise_core_fields ?? []), array_keys(self::CORE_FIELDS));
        foreach ($tickedCore as $key) {
            $value = $property->{$key};
            // A field with no value and a field that isn't ticked both
            // produce nothing — never a blank line, never "R0" (§4.1).
            if ($value === null || (float) $value == 0.0) {
                continue;
            }
            $lines[] = self::CORE_FIELDS[$key] . ': R ' . number_format((float) $value, 0, '.', ' ');
        }

        $customValues = (array) ($property->rental_details_custom_field_values ?? []);
        $advertisableFields = PropertyRentalDetailsCustomField::activeFor($property->agency_id)
            ->where('advertise', true);

        foreach ($advertisableFields as $field) {
            $value = $customValues[$field->key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $lines[] = $field->label . ': ' . $this->formatCustomValue($value, $field->field_type);
        }

        return implode("\n", $lines);
    }

    private function formatCustomValue(mixed $value, string $fieldType): string
    {
        return match ($fieldType) {
            PropertyRentalDetailsCustomField::TYPE_CURRENCY => 'R ' . number_format((float) $value, 0, '.', ' '),
            PropertyRentalDetailsCustomField::TYPE_YES_NO => $value ? 'Yes' : 'No',
            default => (string) $value,
        };
    }
}
