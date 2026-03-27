<?php

namespace App\Services\Syndication\Property24;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
        // Ensure the listing agent is registered on P24 before submitting
        $agentResult = $this->ensureAgentRegistered($property);
        if ($agentResult !== true) {
            $property->update(['p24_syndication_status' => 'error', 'p24_last_error' => 'Agent registration failed: ' . $agentResult]);
            return ['success' => false, 'message' => 'Agent registration failed: ' . $agentResult];
        }

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

    /**
     * Ensure the property's listing agent is registered on P24.
     * Returns true on success, or an error string on failure.
     */
    private function ensureAgentRegistered(Property $property): string|bool
    {
        $user = $property->agent ?? User::find($property->agent_id);
        if (!$user) {
            return 'No agent assigned to this property';
        }

        $agencyId = (int) config('services.property24_syndication.agency_id');
        $parts    = explode(' ', trim($user->name), 2);

        $agentData = [
            'agencyId'        => $agencyId,
            'firstname'       => $parts[0] ?? '',
            'lastname'        => $parts[1] ?? $parts[0] ?? '',
            'emailAddress'    => $user->email ?? '',
            'mobileNumber'    => $user->cell ?? $user->phone ?? '',
            'sourceReference' => 'CoreX-Agent-' . $user->id,
            'published'       => true,
            'receiveStatsMail' => false,
            'countryId'       => 1, // South Africa
        ];

        // Check if agent already exists on P24
        $existingResult = $this->client->getAgents();
        if ($existingResult['success']) {
            foreach ($existingResult['data'] ?? [] as $existing) {
                $ref = $existing['sourceReference'] ?? '';
                if ($ref === 'CoreX-Agent-' . $user->id) {
                    $p24AgentId = (int) $existing['id'];
                    $this->log('info', "Agent #{$user->id} already registered on P24 as #{$p24AgentId}");
                    // Upload photo if agent has one and P24 might not
                    $this->uploadAgentPhotoIfAvailable($user, $p24AgentId);
                    return true;
                }
            }
        }

        // Register new agent
        $this->log('info', "Registering agent #{$user->id} ({$user->name}) on P24");
        $result = $this->client->createAgent($agentData);

        if (!$result['success']) {
            $this->log('error', "Agent registration failed for #{$user->id}", ['result' => $result]);
            return $result['message'] ?? 'Unknown agent registration error';
        }

        // Upload agent photo after successful registration
        $p24AgentId = $result['data']['id'] ?? $result['data']['Id'] ?? null;
        if ($p24AgentId) {
            $this->uploadAgentPhotoIfAvailable($user, (int) $p24AgentId);
        }

        $this->log('info', "Agent #{$user->id} registered on P24", ['result' => $result['data'] ?? []]);
        return true;
    }

    /**
     * Get the P24 agent ID for a CoreX user. Returns null if not found.
     */
    public function getP24AgentId(User $user): ?int
    {
        $result = $this->client->getAgents();
        if (!$result['success']) return null;

        foreach ($result['data'] ?? [] as $agent) {
            if (($agent['sourceReference'] ?? '') === 'CoreX-Agent-' . $user->id) {
                return (int) $agent['id'];
            }
        }

        return null;
    }

    /**
     * Upload the agent's profile photo to P24 if they have one in CoreX.
     */
    private function uploadAgentPhotoIfAvailable(User $user, int $p24AgentId): void
    {
        if (empty($user->agent_photo_path)) {
            $this->log('info', "Agent #{$user->id} has no photo in CoreX — skipping P24 upload");
            return;
        }

        $photoPath = $user->agent_photo_path;
        $bytes = null;
        $mime = 'image/jpeg';

        // Try reading from public disk first (works on server)
        if (Storage::disk('public')->exists($photoPath)) {
            $bytes = Storage::disk('public')->get($photoPath);
            $mime = Storage::disk('public')->mimeType($photoPath) ?: 'image/jpeg';
        } else {
            // Fallback: try fetching from the public URL (works for local dev pointing at server)
            $imageBaseUrl = config('services.property24_syndication.image_base_url') ?: config('app.url');
            $url = rtrim($imageBaseUrl, '/') . '/storage/' . ltrim($photoPath, '/');

            $this->log('info', "Agent photo not on local disk, trying URL: {$url}");

            try {
                $response = \Illuminate\Support\Facades\Http::timeout(15)->get($url);
                if ($response->successful()) {
                    $bytes = $response->body();
                    $contentType = $response->header('Content-Type');
                    if ($contentType && str_starts_with($contentType, 'image/')) {
                        $mime = $contentType;
                    }
                }
            } catch (\Exception $e) {
                $this->log('warning', "Failed to download agent photo from URL: {$e->getMessage()}");
            }
        }

        if (empty($bytes)) {
            $this->log('warning', "Agent #{$user->id} photo could not be read: {$photoPath}");
            return;
        }

        $imageData = [
            'bytes'           => base64_encode($bytes),
            'mimeContentType' => $mime,
        ];

        $result = $this->client->uploadAgentPhoto($p24AgentId, $imageData);

        if ($result['success']) {
            $this->log('info', "Agent photo uploaded for #{$user->id} (P24 agent #{$p24AgentId})");
        } else {
            $this->log('warning', "Agent photo upload failed for #{$user->id}: " . ($result['message'] ?? 'Unknown'));
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('property24')->{$level}($message, $context);
    }
}
