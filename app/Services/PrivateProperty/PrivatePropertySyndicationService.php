<?php

namespace App\Services\PrivateProperty;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PrivatePropertySyndicationService
{
    private PrivatePropertySoapClient $client;
    private PrivatePropertyListingMapper $mapper;

    public function __construct(
        PrivatePropertySoapClient $client,
        PrivatePropertyListingMapper $mapper
    ) {
        $this->client = $client;
        $this->mapper = $mapper;
    }

    /**
     * Submit a property listing to Private Property.
     */
    public function submitListing(Property $property): array
    {
        // Map the property to a PP payload
        $payload = $this->mapper->map($property);

        // Validate before sending
        $errors = $this->mapper->validate($payload);
        if (!empty($errors)) {
            $errorDetail = implode('; ', $errors);
            $property->update([
                'pp_syndication_status' => 'error',
                'pp_last_error'         => 'Validation failed: ' . $errorDetail,
            ]);

            return [
                'success' => false,
                'message' => 'Validation failed: ' . $errorDetail,
                'errors'  => $errors,
            ];
        }

        // Ensure the listing agent is registered on PP before submitting
        $agentResult = $this->ensureAgentRegistered($property);
        if ($agentResult !== true) {
            $property->update([
                'pp_syndication_status' => 'error',
                'pp_last_error'         => 'Agent registration failed: ' . $agentResult,
            ]);

            return [
                'success' => false,
                'message' => 'Agent registration failed: ' . $agentResult,
                'errors'  => [$agentResult],
            ];
        }

        // Register second agent if assigned
        if ($property->pp_second_agent_id) {
            $secondAgent = User::find($property->pp_second_agent_id);
            if ($secondAgent) {
                $this->registerAgent($secondAgent);
            }
        }

        // Send to PP
        $result = $this->client->updateListing($payload);

        if (isset($result['error']) && $result['error'] === true) {
            $property->update([
                'pp_syndication_status' => 'error',
                'pp_last_error'         => $result['message'] ?? 'Unknown SOAP error',
            ]);

            return [
                'success' => false,
                'message' => $result['message'] ?? 'Unknown SOAP error',
            ];
        }

        // Success — update property state
        $updateData = [
            'pp_syndication_status'    => 'submitted',
            'pp_last_submitted_at'     => now(),
            'pp_last_error'            => null,
            'pp_listing_last_synced_at' => now(),
        ];

        // Check if images were sent (not skipped)
        $hasImages = isset($payload['PhotoUrls']) && is_array($payload['PhotoUrls']) && !empty($payload['PhotoUrls']);
        if ($hasImages) {
            $updateData['pp_images_last_synced_at'] = now();
        }

        // Extract PP references from response if present
        if (isset($result['ListingFeedRef'])) {
            $updateData['pp_listing_feed_ref'] = $result['ListingFeedRef'];
        }
        if (isset($result['PPRef'])) {
            $updateData['pp_ref'] = $result['PPRef'];
            $updateData['pp_syndication_status'] = 'active';
            $updateData['pp_activated_at'] = now();
        }

        // Extract delay info from response
        if (isset($result['DelayListingOnOtherWebsitesUntil'])) {
            $updateData['pp_delay_until'] = $result['DelayListingOnOtherWebsitesUntil'];
        }

        $property->update($updateData);

        $this->log('info', "Listing submitted for property #{$property->id}", [
            'pp_status'       => $updateData['pp_syndication_status'],
            'pp_ref'          => $updateData['pp_ref'] ?? null,
            'listing_feed_ref' => $updateData['pp_listing_feed_ref'] ?? null,
        ]);

        // Auto-submit agent images after successful listing submission
        try {
            $imgResult = $this->submitAgentImages($property);
            if (!empty($imgResult['submitted'])) {
                $this->log('info', "Auto-submitted agent images for property #{$property->id}", ['count' => count($imgResult['submitted'])]);
            }
        } catch (\Throwable $e) {
            $this->log('warning', "Agent image auto-submit failed for property #{$property->id}: {$e->getMessage()}");
        }

        return [
            'success' => true,
            'message' => 'Listing submitted to Private Property',
            'status'  => $updateData['pp_syndication_status'],
            'pp_ref'  => $updateData['pp_ref'] ?? null,
        ];
    }

    /**
     * Deactivate a listing on Private Property.
     */
    public function deactivateListing(Property $property): array
    {
        $listingType = in_array(strtolower($property->mandate_type ?? ''), ['rental']) ? 'Rental' : 'Sale';
        $result = $this->client->deactivateListing((string) $property->id, $listingType);

        if (isset($result['error']) && $result['error'] === true) {
            $property->update([
                'pp_syndication_status' => 'error',
                'pp_last_error'         => 'Deactivation failed: ' . ($result['message'] ?? 'Unknown error'),
            ]);

            return [
                'success' => false,
                'message' => $result['message'] ?? 'Deactivation failed',
            ];
        }

        $property->update([
            'pp_syndication_status' => 'deactivated',
            'pp_last_error'         => null,
        ]);

        $this->log('info', "Listing deactivated for property #{$property->id}");

        return [
            'success' => true,
            'message' => 'Listing deactivated on Private Property',
        ];
    }

    /**
     * Sync activation status from PP for a property.
     */
    public function syncActivationStatus(Property $property): array
    {
        $result = $this->client->getListingStatus((string) $property->id);

        if (isset($result['error']) && $result['error'] === true) {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Status check failed',
                'status'  => $property->pp_syndication_status,
            ];
        }

        // Check for active status and PP ref
        $ppRef  = $result['PPRef'] ?? $result['PropertyRef'] ?? $result['ListingRef'] ?? null;
        $status = $result['Status'] ?? $result['PropertyStatus'] ?? null;

        if ($status === 'Active' && empty($property->pp_ref) && $ppRef) {
            $property->update([
                'pp_ref'                => $ppRef,
                'pp_activated_at'       => now(),
                'pp_syndication_status' => 'active',
            ]);

            $this->log('info', "Property #{$property->id} activated on PP with ref: {$ppRef}");
        } elseif ($ppRef && empty($property->pp_ref)) {
            $property->update(['pp_ref' => $ppRef]);
        }

        return [
            'success' => true,
            'message' => 'Status synced',
            'status'  => $property->fresh()->pp_syndication_status,
            'pp_ref'  => $property->fresh()->pp_ref,
            'pp_activated_at' => $property->fresh()->pp_activated_at?->toDateTimeString(),
        ];
    }

    /**
     * Reactivate a previously deactivated listing on PP.
     */
    public function reactivateListing(Property $property): array
    {
        $listingType = in_array(strtolower($property->mandate_type ?? ''), ['rental']) ? 'Rental' : 'Sale';
        $result = $this->client->reactivateListing((string) $property->id, $listingType);

        if (isset($result['error']) && $result['error'] === true) {
            $property->update([
                'pp_syndication_status' => 'error',
                'pp_last_error'         => 'Reactivation failed: ' . ($result['message'] ?? 'Unknown error'),
            ]);

            return [
                'success' => false,
                'message' => $result['message'] ?? 'Reactivation failed',
            ];
        }

        $property->update([
            'pp_syndication_status' => 'submitted',
            'pp_last_error'         => null,
        ]);

        $this->log('info', "Listing reactivated for property #{$property->id}");

        return [
            'success' => true,
            'message' => 'Listing reactivated on Private Property',
        ];
    }

    /**
     * Submit a showday event for a listing on PP.
     */
    public function submitShowday(Property $property, array $showdayData): array
    {
        $event = $this->mapper->buildShowdayEvent($property, $showdayData);
        $result = $this->client->updateShowday($event);

        if (isset($result['error']) && $result['error'] === true) {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Showday submission failed',
            ];
        }

        $this->log('info', "Showday submitted for property #{$property->id}", ['event' => $event]);

        return [
            'success' => true,
            'message' => 'Showday event submitted to Private Property',
            'result'  => $result,
        ];
    }

    /**
     * Register or update an agent on PP. Public method for controller use.
     */
    public function registerAgent(User $user, bool $active = true): array
    {
        $agentData = $this->mapper->buildAgentData($user, $active);

        if (empty($agentData['TelCell'])) {
            return [
                'success' => false,
                'message' => 'Agent has no phone/cell number — required by Private Property',
            ];
        }

        $result = $this->client->updateAgent($agentData);

        if (isset($result['error']) && $result['error'] === true) {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Agent registration failed',
            ];
        }

        $this->log('info', "Agent #{$user->id} ({$user->name}) " . ($active ? 'registered' : 'deactivated') . " on PP");

        return [
            'success' => true,
            'message' => $active ? 'Agent registered on PP' : 'Agent deactivated on PP',
            'result'  => $result,
        ];
    }

    /**
     * Upload an agent's profile image to PP.
     */
    public function uploadAgentImage(User $user, string $imageUrl): array
    {
        $agentData = $this->mapper->buildAgentData($user);

        $result = $this->client->updateAgentImage($agentData, $imageUrl);

        if (isset($result['error']) && $result['error'] === true) {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Agent image upload failed',
            ];
        }

        $this->log('info', "Agent image uploaded for #{$user->id}", ['url' => $imageUrl]);

        return [
            'success' => true,
            'message' => 'Agent image uploaded to Private Property',
            'result'  => $result,
        ];
    }

    /**
     * Submit agent images for all agents assigned to a property.
     * Uses agent_photo_path from the User model (profile photo).
     */
    public function submitAgentImages(Property $property): array
    {
        $submitted = [];
        $skipped   = [];
        $errors    = [];

        $agentIds = array_filter([
            $property->agent_id,
            $property->pp_second_agent_id,
        ]);

        $override = config('services.private_property.image_base_url');
        $baseUrl  = rtrim(!empty($override) ? $override : config('app.url'), '/');

        foreach ($agentIds as $agentId) {
            $user = User::find($agentId);
            if (!$user) {
                $skipped[] = ['user_id' => $agentId, 'name' => '(not found)', 'reason' => 'User not found'];
                continue;
            }

            if (empty($user->agent_photo_path)) {
                $skipped[] = ['user_id' => $user->id, 'name' => $user->name, 'reason' => 'No agent_photo_path set — upload a photo in CoreX first'];
                continue;
            }

            $imageUrl = $baseUrl . '/storage/' . ltrim($user->agent_photo_path, '/');

            if (!str_starts_with($imageUrl, 'https://')) {
                $skipped[] = ['user_id' => $user->id, 'name' => $user->name, 'reason' => "Image URL is not HTTPS: {$imageUrl}"];
                $this->log('warning', "Skipping agent image for #{$user->id} — not HTTPS: {$imageUrl}");
                continue;
            }

            // Check file size if stored locally.
            // Note: PP also requires minimum 160x120px. We do not validate
            // dimensions server-side (would need GD/Imagick); ensure agent
            // photos uploaded through CoreX meet this minimum.
            $localPath = storage_path('app/public/' . $user->agent_photo_path);
            if (file_exists($localPath) && filesize($localPath) > 1048576) {
                $skipped[] = ['user_id' => $user->id, 'name' => $user->name, 'reason' => 'Image exceeds 1MB limit'];
                $this->log('warning', "Skipping agent image for #{$user->id} — exceeds 1MB");
                continue;
            }
            $this->log('info', "Agent image push for #{$user->id}: ensure source image is ≥160x120px (PP minimum).");

            $result = $this->uploadAgentImage($user, $imageUrl);

            if ($result['success']) {
                $submitted[] = ['user_id' => $user->id, 'name' => $user->name, 'url' => $imageUrl];
            } else {
                $errors[] = ['user_id' => $user->id, 'name' => $user->name, 'message' => $result['message']];
            }
        }

        return compact('submitted', 'skipped', 'errors');
    }

    /**
     * Push YouTube video ID and/or Matterport ID to PP for an active listing.
     */
    public function pushVideoOrMatterport(Property $property): array
    {
        if (empty($property->pp_listing_feed_ref)) {
            return [
                'success' => false,
                'message' => 'Cannot push video — PP internal listing UUID (pp_listing_feed_ref) is not set on this property. '
                           . 'This UUID is provided by PP via the Listing Event Feed when the listing activates. '
                           . 'Ensure the Event Feed consumer is running, or contact PP to supply the UUID manually '
                           . 'and store it via: php artisan pp:manage set-listing-uuid --property=ID --uuid=UUID',
            ];
        }

        $youtube    = $property->youtube_video_id ?? null;
        $matterport = $property->matterport_id ?? null;

        if (empty($youtube) && empty($matterport)) {
            return [
                'success' => false,
                'message' => 'No YouTube ID or Matterport ID stored on this property. Add one first.',
            ];
        }

        $listingType = in_array(strtolower($property->listing_type ?? ''), ['rental']) ? 'Rental' : 'Sale';

        $result = $this->client->updateListingVideoOrMatterport(
            $property->pp_listing_feed_ref,
            $listingType,
            $youtube,
            $matterport
        );

        if (isset($result['error']) && $result['error'] === true) {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Video/Matterport update failed',
            ];
        }

        $this->log('info', "Video/Matterport pushed for property #{$property->id}", [
            'pp_ref'    => $property->pp_ref,
            'youtube'   => $youtube,
            'matterport' => $matterport,
        ]);

        return [
            'success' => true,
            'message' => 'Video/Matterport updated on Private Property',
            'result'  => $result,
        ];
    }

    /**
     * Claim PP ownership of an agent by updating their unique agent ID.
     */
    public function updateUniqueAgentId(User $user, string $ppAgentId): array
    {
        $result = $this->client->updateUniqueAgentId($ppAgentId, (string) $user->id);

        if (isset($result['error']) && $result['error'] === true) {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'UpdateUniqueAgentID failed',
            ];
        }

        $user->update(['pp_unique_agent_id' => $ppAgentId]);

        $this->log('info', "PP ownership claimed for agent #{$user->id}", [
            'pp_unique_agent_id' => $ppAgentId,
        ]);

        return [
            'success'            => true,
            'message'            => 'PP agent ownership updated',
            'pp_unique_agent_id' => $ppAgentId,
            'result'             => $result,
        ];
    }

    /**
     * Claim PP ownership of a listing by updating its unique listing ID.
     */
    public function updateUniqueListingId(Property $property, string $ppListingId): array
    {
        $listingType = in_array(strtolower($property->listing_type ?? ''), ['rental']) ? 'Rental' : 'Sale';

        $result = $this->client->updateUniqueListingId($ppListingId, (string) $property->id, $listingType);

        if (isset($result['error']) && $result['error'] === true) {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'UpdateUniqueListingID failed',
            ];
        }

        $this->log('info', "PP listing ownership claimed for property #{$property->id}", [
            'pp_listing_id' => $ppListingId,
        ]);

        return [
            'success' => true,
            'message' => 'PP listing ownership updated',
            'result'  => $result,
        ];
    }

    /**
     * Register the property's agent on PP if not already done.
     * Returns true on success, or an error string on failure.
     */
    private function ensureAgentRegistered(Property $property): string|bool
    {
        $user = $property->agent ?? User::find($property->agent_id);
        if (!$user) {
            return 'No agent assigned to this property';
        }

        // Split "name" into first/last (User model stores a single name field)
        $parts     = explode(' ', trim($user->name), 2);
        $firstName = $parts[0] ?? '';
        $lastName  = $parts[1] ?? $parts[0] ?? '';

        // PP requires all phone fields in valid format — empty string fails PP107
        $cellPhone = $user->cell ?? $user->phone ?? '';
        if (empty($cellPhone)) {
            return 'Agent has no phone/cell number — required by Private Property';
        }

        $agentData = [
            'AgentId'               => (string) $user->id,
            'FirstName'             => $firstName,
            'LastName'              => $lastName,
            'Email'                 => $user->email ?? '',
            'TelCell'               => $cellPhone,
            'TelWork'               => $user->phone ?? $cellPhone,
            'TelHome'               => $cellPhone,
            'Active'                => true,
            'BranchId'              => config('services.private_property.branch_guid'),
            'PrivatePropertyAgentId' => '',
            'PrivysealAlias'        => '',
        ];

        $this->log('info', "Registering agent #{$user->id} ({$user->name}) on PP");

        $result = $this->client->updateAgent($agentData);

        if (isset($result['error']) && $result['error'] === true) {
            $this->log('error', "Agent registration failed for #{$user->id}", ['result' => $result]);
            return $result['message'] ?? 'Unknown agent registration error';
        }

        $this->log('info', "Agent #{$user->id} registered on PP", ['result' => $result]);
        return true;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('private_property')->{$level}($message, $context);
    }
}
