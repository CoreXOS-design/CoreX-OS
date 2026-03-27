<?php

namespace App\Observers;

use App\Jobs\SubmitListingToProperty24;
use App\Jobs\SyncPropertyToWebsite;
use App\Models\Property;

class PropertyObserver
{
    /**
     * Fired after create or update.
     * Only sync if the property has been published.
     */
    public function saved(Property $property): void
    {
        if ($property->isPublished()) {
            SyncPropertyToWebsite::dispatchSync($property, 'upsert');
        }

        // Auto-sync to Property24 if enabled and already submitted
        if ($property->p24_syndication_enabled && $property->p24_ref && $property->isPublished()) {
            $syncFields = [
                'title', 'headline', 'description', 'price', 'price_on_application',
                'beds', 'baths', 'garages', 'size_m2', 'erf_size_m2',
                'street_name', 'street_number', 'suburb', 'city', 'province',
                'property_type', 'listing_type', 'mandate_type', 'status',
                'images_json', 'dawn_images_json', 'noon_images_json',
                'dusk_images_json', 'gallery_images_json',
                'latitude', 'longitude', 'complex_name', 'unit_number',
            ];

            $changed = array_intersect(array_keys($property->getDirty()), $syncFields);

            if (!empty($changed)) {
                SubmitListingToProperty24::dispatch($property);
            }
        }
    }

    /**
     * Fired on soft-delete or force-delete.
     * Always tell the website to remove it if it was ever published.
     */
    public function deleted(Property $property): void
    {
        if ($property->isPublished()) {
            SyncPropertyToWebsite::dispatchSync($property, 'delete');
        }
    }
}
