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
