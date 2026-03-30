<?php

namespace App\Observers;

use App\Jobs\SubmitListingToProperty24;
use App\Jobs\SyncPropertyToWebsite;
use App\Models\Property;
use App\Services\Syndication\Property24\Property24ApiClient;
use App\Services\Syndication\Property24\Property24ListingMapper;
use Illuminate\Support\Facades\Log;

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

        // P24 syndication auto-sync
        if (!$property->p24_syndication_enabled || !$property->p24_ref) {
            return;
        }

        $dirty = $property->getDirty();

        // If status changed, send a lightweight status update to P24
        if (isset($dirty['status'])) {
            $p24Status = Property24ListingMapper::getP24Status($property->status, $property->p24_ref);

            try {
                $client = app(Property24ApiClient::class);
                $client->setListingStatus($property->id, (int) $property->p24_ref, $p24Status);

                Log::channel('property24')->info("Status auto-synced for property #{$property->id}: {$p24Status}");

                // Update local syndication status to reflect terminal states
                if (Property24ListingMapper::isTerminalStatus($p24Status)) {
                    $property->updateQuietly([
                        'p24_syndication_status' => 'deactivated',
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('property24')->error("Status sync failed for property #{$property->id}: {$e->getMessage()}");
            }

            return; // Don't also re-submit the full listing
        }

        // For non-status field changes, re-submit the full listing
        $syncFields = [
            'title', 'headline', 'description', 'price', 'price_on_application',
            'beds', 'baths', 'garages', 'size_m2', 'erf_size_m2',
            'street_name', 'street_number', 'suburb', 'city', 'province',
            'property_type', 'listing_type', 'mandate_type',
            'images_json', 'dawn_images_json', 'noon_images_json',
            'dusk_images_json', 'gallery_images_json',
            'latitude', 'longitude', 'complex_name', 'unit_number',
            'features_json', 'spaces_json',
            'rates_taxes', 'levy', 'special_levy',
            'deposit_amount', 'lease_period',
        ];

        $changed = array_intersect(array_keys($dirty), $syncFields);

        if (!empty($changed)) {
            SubmitListingToProperty24::dispatch($property);
        }
    }

    /**
     * Fired on soft-delete or force-delete.
     * Always tell the website to remove it if it was ever published.
     * Also withdraw the listing from P24.
     */
    public function deleted(Property $property): void
    {
        if ($property->isPublished()) {
            SyncPropertyToWebsite::dispatchSync($property, 'delete');
        }

        // Withdraw from P24 if syndicated
        if ($property->p24_syndication_enabled && $property->p24_ref) {
            try {
                $client = app(Property24ApiClient::class);
                $client->setListingStatus($property->id, (int) $property->p24_ref, 'Withdrawn');
                Log::channel('property24')->info("Property #{$property->id} withdrawn from P24 (deleted)");
            } catch (\Exception $e) {
                Log::channel('property24')->error("P24 withdrawal failed for deleted property #{$property->id}: {$e->getMessage()}");
            }
        }
    }
}
