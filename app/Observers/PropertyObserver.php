<?php

namespace App\Observers;

use App\Jobs\MatchPropertyJob;
use App\Jobs\SubmitListingToProperty24;
use App\Jobs\SyncPropertyToWebsite;
use App\Models\Property;
use App\Models\User;
use App\Services\CommandCenter\AutoEventService;
use App\Services\Syndication\Property24\Property24ApiClient;
use App\Services\Syndication\Property24\Property24ListingMapper;
use Illuminate\Support\Facades\Log;

class PropertyObserver
{
    /**
     * Reject owner-role users as listing agents. System Owners are
     * platform identities — they don't own properties, they supervise
     * every agency. This observer closes the write side; the read side
     * is `User::scopeAgencyMembers()`.
     */
    public function saving(Property $property): void
    {
        if (!$property->agent_id) {
            return;
        }

        $ownerRoleNames = User::ownerRoleNames();
        if (empty($ownerRoleNames)) {
            return;
        }

        $agentRole = \DB::table('users')->where('id', $property->agent_id)->value('role');
        if ($agentRole && in_array($agentRole, $ownerRoleNames, true)) {
            throw new \RuntimeException('System Owner accounts cannot be assigned as a property agent. Pick an agency member.');
        }
    }

    /**
     * Fired when a property is first created.
     * Auto-generates document expectation tasks via Command Center.
     */
    public function created(Property $property): void
    {
        try {
            app(AutoEventService::class)->onPropertyCreated($property);
        } catch (\Throwable $e) {
            Log::warning("Command Center auto-event failed on property create #{$property->id}: {$e->getMessage()}");
        }
    }

    /**
     * Fired after create or update.
     * Only sync if the property has been published.
     */
    public function saved(Property $property): void
    {
        // Update last_activity_at for Command Center health tracking
        try {
            if ($property->wasRecentlyCreated === false) {
                app(AutoEventService::class)->onPropertyUpdated($property);
            }
        } catch (\Throwable $e) {
            Log::warning("Command Center activity update failed for property #{$property->id}: {$e->getMessage()}");
        }
        if ($property->isPublished()) {
            SyncPropertyToWebsite::dispatchSync($property, 'upsert');
        } elseif ($property->wasChanged('published_at') && $property->getOriginal('published_at')) {
            // Was published, just got unpublished → tell the website to hard-delete the row
            SyncPropertyToWebsite::dispatchSync($property, 'delete');
        }

        // Core Matches — fire on create or on any criteria-affecting change.
        // Re-saves with no relevant change won't trigger duplicate notifications
        // because MatchPropertyJob dedups via contact_match_notifications.
        $matchSignals = [
            'price', 'beds', 'baths', 'garages', 'size_m2', 'erf_size_m2',
            'suburb', 'city', 'category', 'property_type', 'listing_type',
            'status', 'features_json',
        ];
        if ($property->wasRecentlyCreated || array_intersect(array_keys($property->getChanges()), $matchSignals)) {
            try {
                MatchPropertyJob::dispatch($property->id);
            } catch (\Throwable $e) {
                Log::warning("MatchPropertyJob dispatch failed for property #{$property->id}: {$e->getMessage()}");
            }
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
