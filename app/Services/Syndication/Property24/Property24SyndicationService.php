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
        $this->log('info', "submitListing called for property #{$property->id}, agent_id={$property->agent_id}");

        // Ensure the listing agent(s) are registered on P24 before submitting
        $agentResult = $this->ensureAgentRegistered($property);
        if ($agentResult !== true) {
            $property->update(['p24_syndication_status' => 'error', 'p24_last_error' => 'Agent registration failed: ' . $agentResult]);
            return ['success' => false, 'message' => 'Agent registration failed: ' . $agentResult];
        }

        // Register second agent if assigned
        if ($property->pp_second_agent_id) {
            $secondAgent = User::find($property->pp_second_agent_id);
            if ($secondAgent) {
                $this->ensureAgentRegisteredByUser($secondAgent);
            }
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

        return $this->ensureAgentRegisteredByUser($user);
    }

    /**
     * Register a specific user as an agent on P24.
     * Returns true on success, or an error string on failure.
     */
    public function ensureAgentRegisteredByUser(User $user): string|bool
    {
        $this->log('info', "ensureAgentRegistered for user #{$user->id} ({$user->name}), agent_photo_path=" . ($user->agent_photo_path ?? 'NULL'));

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
     * Push the latest CoreX user details to P24 (name, contact, photo).
     * If the agent isn't on P24 yet, registers them first.
     * Returns true on success, or an error string.
     */
    public function updateAgentOnP24(User $user, bool $pushPhoto = true): bool|string
    {
        $p24AgentId = $this->getP24AgentId($user);
        if (!$p24AgentId) {
            // Not registered yet — create them; that flow also uploads the photo.
            return $this->ensureAgentRegisteredByUser($user);
        }

        $agencyId = (int) config('services.property24_syndication.agency_id');
        $parts    = explode(' ', trim($user->name), 2);

        $payload = [
            'id'              => $p24AgentId,
            'agencyId'        => $agencyId,
            'firstname'       => $parts[0] ?? '',
            'lastname'        => $parts[1] ?? $parts[0] ?? '',
            'emailAddress'    => $user->email ?? '',
            'mobileNumber'    => $this->normaliseSaPhone($user->cell ?? $user->phone),
            'sourceReference' => 'CoreX-Agent-' . $user->id,
            'published'       => true,
            'receiveStatsMail' => false,
            'countryId'       => 1,
            'jobTitle'        => $user->designation ?: 'Sales Agent',
        ];

        // Only send workNumber if it looks like a SA landline (not mobile).
        // P24 rejects mobile-format numbers in the work field with "Invalid work number".
        if ($landline = $this->extractLandline($user->phone)) {
            $payload['workNumber'] = $landline;
        }
        if (!empty($user->fax)) {
            $fax = $this->normaliseSaPhone($user->fax);
            if ($fax !== '') $payload['faxNumber'] = $fax;
        }

        $result = $this->client->updateAgent($payload);
        if (!($result['success'] ?? false)) {
            $this->log('error', "Agent update failed for #{$user->id}", ['result' => $result]);
            return $result['message'] ?? 'Unknown agent update error';
        }

        if ($pushPhoto) {
            $this->uploadAgentPhotoIfAvailable($user, $p24AgentId);
        }

        return true;
    }

    /**
     * Strip whitespace / punctuation from a SA phone number.
     */
    private function normaliseSaPhone(?string $raw): string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        return $digits ?: '';
    }

    /**
     * Return the number only if it looks like a SA landline (0[1-5]XXXXXXXXX, 10 digits).
     * Returns null for mobile (08X/07X/06X) or anything too short/long —
     * so we don't send invalid work numbers that P24 rejects.
     */
    private function extractLandline(?string $raw): ?string
    {
        $d = $this->normaliseSaPhone($raw);
        if (strlen($d) !== 10) return null;
        if (!preg_match('/^0[1-5]\d{8}$/', $d)) return null;
        return $d;
    }

    /**
     * Upload the agent's profile photo to P24 if they have one in CoreX.
     */
    private function uploadAgentPhotoIfAvailable(User $user, int $p24AgentId): void
    {
        if (empty($user->agent_photo_path)) {
            $this->log('info', "Agent #{$user->id} has no agent_photo_path — skipping P24 photo upload");
            return;
        }

        $photoPath = $user->agent_photo_path;
        $bytes = null;
        $mime = 'image/jpeg';

        // Strategy 1: Read from public storage disk directly
        if (Storage::disk('public')->exists($photoPath)) {
            $this->log('info', "Reading agent photo from disk: {$photoPath}");
            $bytes = Storage::disk('public')->get($photoPath);
            $mime = Storage::disk('public')->mimeType($photoPath) ?: 'image/jpeg';
        }

        // Strategy 2: Fetch via public URL (works when disk path doesn't match or on different server)
        if (empty($bytes)) {
            $baseUrl = config('app.url');
            $url = rtrim($baseUrl, '/') . '/storage/' . ltrim($photoPath, '/');
            $this->log('info', "Agent photo not on disk, fetching URL: {$url}");

            try {
                $response = \Illuminate\Support\Facades\Http::withoutVerifying()->timeout(15)->get($url);
                if ($response->successful() && strlen($response->body()) > 100) {
                    $bytes = $response->body();
                    $contentType = $response->header('Content-Type');
                    if ($contentType && str_starts_with($contentType, 'image/')) {
                        $mime = $contentType;
                    }
                    $this->log('info', "Downloaded agent photo from URL: " . strlen($bytes) . " bytes");
                } else {
                    $this->log('warning', "Agent photo URL returned: HTTP " . $response->status());
                }
            } catch (\Exception $e) {
                $this->log('warning', "Failed to download agent photo: {$e->getMessage()}");
            }
        }

        // Strategy 3: Try the image_base_url (production domain)
        if (empty($bytes)) {
            $imageBaseUrl = config('services.property24_syndication.image_base_url');
            if ($imageBaseUrl) {
                $url = rtrim($imageBaseUrl, '/') . '/storage/' . ltrim($photoPath, '/');
                $this->log('info', "Trying image_base_url: {$url}");

                try {
                    $response = \Illuminate\Support\Facades\Http::withoutVerifying()->timeout(15)->get($url);
                    if ($response->successful() && strlen($response->body()) > 100) {
                        $bytes = $response->body();
                        $contentType = $response->header('Content-Type');
                        if ($contentType && str_starts_with($contentType, 'image/')) {
                            $mime = $contentType;
                        }
                        $this->log('info', "Downloaded agent photo from image_base_url: " . strlen($bytes) . " bytes");
                    }
                } catch (\Exception $e) {
                    $this->log('warning', "Failed from image_base_url: {$e->getMessage()}");
                }
            }
        }

        if (empty($bytes)) {
            $this->log('warning', "Agent #{$user->id} photo could not be read from any source: {$photoPath}");
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
