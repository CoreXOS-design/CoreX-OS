<?php

namespace App\Observers;

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
