<?php

namespace App\Services\Syndication\Property24;

use App\Models\Property;
use Illuminate\Support\Facades\Log;

class Property24SyndicationService
{
    private Property24ApiClient $client;
    private Property24ListingMapper $mapper;

    public function __construct(Property24ApiClient $client, Property24ListingMapper $mapper)
    {
        $this->client = $client;
        $this->mapper = $mapper;
    }

    public function submitListing(Property $property): array
    {
        $payload = $this->mapper->map($property);

        $errors = $this->mapper->validate($payload);
        if (!empty($errors)) {
            $errorDetail = implode('; ', $errors);
            $property->update(['p24_syndication_status' => 'error', 'p24_last_error' => 'Validation failed: ' . $errorDetail]);
            return ['success' => false, 'message' => 'Validation failed: ' . $errorDetail, 'errors' => $errors];
        }

        $result = $this->client->saveListing($property->id, $payload);

        if (!$result['success']) {
            $property->update(['p24_syndication_status' => 'error', 'p24_last_error' => $result['message'] ?? 'Unknown API error']);
            return ['success' => false, 'message' => $result['message'] ?? 'Unknown API error'];
        }

        $updateData = [
            'p24_syndication_status'     => 'submitted',
            'p24_last_submitted_at'      => now(),
            'p24_last_error'             => null,
            'p24_listing_last_synced_at' => now(),
        ];

        $data = $result['data'] ?? [];
        if (isset($data['listingNumber'])) {
            $updateData['p24_ref'] = (string) $data['listingNumber'];
        } elseif (isset($data['ListingNumber'])) {
            $updateData['p24_ref'] = (string) $data['ListingNumber'];
        } elseif (is_numeric($data['raw'] ?? null)) {
            $updateData['p24_ref'] = (string) $data['raw'];
        }

        if (!empty($updateData['p24_ref'])) {
            $updateData['p24_syndication_status'] = 'active';
            $updateData['p24_activated_at'] = now();
        }

        if (!empty($payload['photos'])) {
            $updateData['p24_images_last_synced_at'] = now();
        }

        $property->update($updateData);

        $this->log('info', "Listing submitted for property #{$property->id}", [
            'p24_status' => $updateData['p24_syndication_status'],
            'p24_ref'    => $updateData['p24_ref'] ?? null,
        ]);

        return [
            'success' => true,
            'message' => 'Listing submitted to Property24',
            'status'  => $updateData['p24_syndication_status'],
            'p24_ref' => $updateData['p24_ref'] ?? null,
        ];
    }

    public function deactivateListing(Property $property): array
    {
        if (empty($property->p24_ref)) {
            return ['success' => false, 'message' => 'No P24 reference — listing was never submitted'];
        }

        $result = $this->client->setListingStatus($property->id, (int) $property->p24_ref, 'Withdrawn');

        if (!$result['success']) {
            $property->update(['p24_syndication_status' => 'error', 'p24_last_error' => 'Deactivation failed: ' . ($result['message'] ?? 'Unknown error')]);
            return ['success' => false, 'message' => $result['message'] ?? 'Deactivation failed'];
        }

        $property->update(['p24_syndication_status' => 'deactivated', 'p24_last_error' => null]);
        $this->log('info', "Listing deactivated for property #{$property->id}");
        return ['success' => true, 'message' => 'Listing deactivated on Property24'];
    }

    public function reactivateListing(Property $property): array
    {
        if (empty($property->p24_ref)) {
            return ['success' => false, 'message' => 'No P24 reference — listing was never submitted'];
        }

        $result = $this->client->setListingStatus($property->id, (int) $property->p24_ref, 'BackOnMarket');

        if (!$result['success']) {
            $property->update(['p24_syndication_status' => 'error', 'p24_last_error' => 'Reactivation failed: ' . ($result['message'] ?? 'Unknown error')]);
            return ['success' => false, 'message' => $result['message'] ?? 'Reactivation failed'];
        }

        $property->update(['p24_syndication_status' => 'submitted', 'p24_last_error' => null]);
        $this->log('info', "Listing reactivated for property #{$property->id}");
        return ['success' => true, 'message' => 'Listing reactivated on Property24'];
    }

    public function syncActivationStatus(Property $property): array
    {
        if (empty($property->p24_ref)) {
            return ['success' => false, 'message' => 'No P24 reference — cannot check status'];
        }

        $result = $this->client->isOnPortal($property->id, (int) $property->p24_ref);

        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message'] ?? 'Status check failed', 'status' => $property->p24_syndication_status];
        }

        $data = $result['data'] ?? [];
        $isOnPortal = $data['raw'] ?? $data['isOnPortal'] ?? $data['IsOnPortal'] ?? null;

        if ($isOnPortal === true || $isOnPortal === 'true' || $isOnPortal === 'True') {
            if ($property->p24_syndication_status !== 'active') {
                $property->update(['p24_syndication_status' => 'active', 'p24_activated_at' => $property->p24_activated_at ?? now(), 'p24_last_error' => null]);
                $this->log('info', "Property #{$property->id} confirmed active on P24");
            }
        } elseif ($isOnPortal === false || $isOnPortal === 'false' || $isOnPortal === 'False') {
            if ($property->p24_syndication_status === 'active') {
                $property->update(['p24_syndication_status' => 'submitted', 'p24_last_error' => 'Listing not currently on portal']);
            }
        }

        return [
            'success' => true, 'message' => 'Status synced',
            'status' => $property->fresh()->p24_syndication_status,
            'p24_ref' => $property->p24_ref,
            'activated_at' => $property->fresh()->p24_activated_at?->toDateTimeString(),
        ];
    }

    public function syncAllActivations(): array
    {
        $properties = Property::where('p24_syndication_enabled', true)
            ->whereIn('p24_syndication_status', ['submitted', 'pending'])
            ->whereNotNull('p24_ref')->get();

        $synced = 0;
        $errors = 0;
        foreach ($properties as $property) {
            $result = $this->syncActivationStatus($property);
            $result['success'] ? $synced++ : $errors++;
        }

        $this->log('info', "P24 activation sync complete: {$synced} synced, {$errors} errors");
        return ['synced' => $synced, 'errors' => $errors, 'total' => $properties->count()];
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('property24')->{$level}($message, $context);
    }
}
