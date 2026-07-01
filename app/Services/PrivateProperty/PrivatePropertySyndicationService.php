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
    /**
     * The pp_syndication_status to record after a SUCCESSFUL UpdateListing.
     *
     * A listing PP has already published (pp_ref present) stays live on an
     * update → 'active'; a first-ever submit, before PP has issued a ref, is
     * 'submitted' (awaiting publish + ref). Regressing an already-published
     * listing to 'submitted' stranded its status forever, because the
     * activation-sync job only reconciles rows with NO pp_ref. Public + static
     * so the decision is unit-testable without the SOAP path (cf. PP-7
     * PrivatePropertyListingMapper::resolveListingType()).
     */
    public static function postSubmitStatus(Property $property): string
    {
        return $property->pp_ref ? 'active' : 'submitted';
    }

    public function submitListing(Property $property): array
    {
        $this->client->forAgency($property->agency);
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

        // Success — update property state. A listing PP has ALREADY published
        // (pp_ref present) stays live when we UpdateListing it, so it must remain
        // 'active' — regressing it to 'submitted' stranded the status permanently:
        // the activation-sync job only reconciles rows with NO pp_ref, so it never
        // promoted a re-pushed listing back to 'active'. Only a first-ever submit,
        // before PP has issued a ref, is 'submitted' (awaiting publish + ref).
        $updateData = [
            'pp_syndication_status'    => self::postSubmitStatus($property),
            'pp_last_submitted_at'     => now(),
            'pp_last_error'            => null,
            'pp_listing_last_synced_at' => now(),
        ];
        if ($property->pp_ref && empty($property->pp_activated_at)) {
            $updateData['pp_activated_at'] = now();
        }

        // Check if images were sent (not skipped)
        $hasImages = isset($payload['PhotoUrls']) && is_array($payload['PhotoUrls']) && !empty($payload['PhotoUrls']);
        if ($hasImages) {
            $updateData['pp_images_last_synced_at'] = now();
        }

        // Extract PP references from response. PHP SoapClient typically wraps
        // the body in `{UpdateListingResult: {...}}`, and PP sometimes embeds
        // the payload as raw XML inside an `any` element (same pattern used by
        // `GetAllAgentsForBranchResult` — see ensureNoDuplicateBeforeUpdateAgent
        // below). Walk all known wrapper shapes before giving up.
        $listingFeedRef = $this->extractFromSoapResponse($result, 'UpdateListing', ['ListingFeedRef']);
        $ppRef          = $this->extractFromSoapResponse($result, 'UpdateListing', ['PPRef']);
        $delayUntil     = $this->extractFromSoapResponse($result, 'UpdateListing', ['DelayListingOnOtherWebsitesUntil']);

        if ($listingFeedRef !== null) {
            $updateData['pp_listing_feed_ref'] = $listingFeedRef;
        }
        if ($ppRef !== null) {
            $updateData['pp_ref'] = $ppRef;
            $updateData['pp_syndication_status'] = 'active';
            $updateData['pp_activated_at'] = now();
        }
        if ($delayUntil !== null) {
            $updateData['pp_delay_until'] = $delayUntil;
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

        // Auto-push YouTube video / Matterport after a successful submit so the
        // "Refresh to portal" button sends everything in one action. PP carries
        // video via a separate method (UpdateListingVideoOrMatterport) that only
        // works post-publish, so this is best-effort: a failure here (e.g. the
        // PP listing UUID has not arrived via the Event Feed yet) must NOT fail
        // the listing submit itself — the video re-pushes on the next refresh
        // or when the Activated event populates pp_listing_feed_ref.
        $videoOutcome = null; // null = nothing to push; otherwise ['success'=>bool,'message'=>string]
        try {
            $fresh = $property->fresh();
            if (!empty($fresh->youtube_video_id) || !empty($fresh->matterport_id)) {
                $videoResult  = $this->pushVideoOrMatterport($fresh);
                $videoOutcome = [
                    'success' => (bool) ($videoResult['success'] ?? false),
                    'message' => $videoResult['message'] ?? '',
                ];
                $this->log(
                    $videoOutcome['success'] ? 'info' : 'warning',
                    "Auto-push video/Matterport for property #{$property->id}: " . $videoOutcome['message']
                );
            }
        } catch (\Throwable $e) {
            $videoOutcome = ['success' => false, 'message' => 'Video push error: ' . $e->getMessage()];
            $this->log('warning', "Video/Matterport auto-push failed for property #{$property->id}: {$e->getMessage()}");
        }

        // Surface a video-push failure to the agent instead of swallowing it.
        // The listing itself submitted successfully, but if the agent added a
        // video and it did NOT reach PP, the Refresh action must say so —
        // otherwise they see a green success and the video silently never
        // appears (the original reported symptom).
        $message = 'Listing submitted to Private Property';
        if ($videoOutcome !== null && !$videoOutcome['success']) {
            $message .= ' — but the video/Matterport did NOT sync: ' . $videoOutcome['message'];
        } elseif ($videoOutcome !== null && $videoOutcome['success']) {
            $message .= ' (video/Matterport synced)';
        }

        return [
            'success'       => true,
            'message'       => $message,
            'status'        => $updateData['pp_syndication_status'],
            'pp_ref'        => $updateData['pp_ref'] ?? null,
            'video_synced'  => $videoOutcome === null ? null : $videoOutcome['success'],
            'video_message' => $videoOutcome['message'] ?? null,
        ];
    }

    /**
     * Deactivate a listing on Private Property.
     */
    public function deactivateListing(Property $property): array
    {
        $this->client->forAgency($property->agency);
        $listingType = PrivatePropertyListingMapper::resolveListingType($property);
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
     *
     * PP returns two distinct response shapes that we have to handle:
     *
     *   GetListingStatus       → {"GetListingStatusResult": "For Sale"}        (scalar string)
     *   GetReferenceNumberByListing → {"GetReferenceNumberByListingResult": "T2870172"}  (scalar string)
     *
     * GetListingStatus by itself does NOT carry the PP ref — only the public
     * status string ("For Sale", "To Let", "Sold", "Pending Offer", "Inactive").
     * If the listing is live but we have no ref yet, we must follow up with a
     * GetReferenceNumberByListing call.
     */
    public function syncActivationStatus(Property $property): array
    {
        // Bind the SOAP client to THIS property's agency (branch GUID + token).
        // Without it the scheduled SyncPrivatePropertyActivations job reuses one
        // singleton client and checks every agency's listings under the first
        // agency's branch — so multi-agency installs never reconcile. (No auth in
        // queue context, so AgencyScope is inert and $property->agency resolves.)
        $this->client->forAgency($property->agency);

        $result = $this->client->getListingStatus((string) $property->id);

        if (isset($result['error']) && $result['error'] === true) {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Status check failed',
                'status'  => $property->pp_syndication_status,
            ];
        }

        // Extract the status string. PP returns it as a scalar in
        // GetListingStatusResult — but some sandbox calls may return a richer
        // shape with an inner Status key, so handle both.
        $rawStatus = $result['GetListingStatusResult'] ?? null;
        $statusString = null;
        $ppRefFromStatus = null;
        if (is_string($rawStatus)) {
            $statusString = trim($rawStatus);
        } elseif (is_array($rawStatus)) {
            $statusString = $rawStatus['Status'] ?? $rawStatus['PropertyStatus'] ?? null;
            $ppRefFromStatus = $rawStatus['PPRef'] ?? $rawStatus['PropertyRef'] ?? $rawStatus['ListingRef'] ?? null;
        }

        // PP's literal "live" status strings — any of these mean the listing
        // is published and we should have a ref for it.
        $liveStatuses = ['For Sale', 'To Let', 'Pending Offer', 'Sold', 'Active'];
        $isLive = $statusString !== null && in_array($statusString, $liveStatuses, true);

        // If live and we don't have a ref yet, pull it via the dedicated
        // GetReferenceNumberByListing method (GetListingStatus does not return one).
        $ppRef = $ppRefFromStatus;
        if ($isLive && empty($property->pp_ref) && empty($ppRef)) {
            $listingType = PrivatePropertyListingMapper::resolveListingType($property);
            $refResult = $this->client->getReferenceNumber((string) $property->id, $listingType);
            if (!(isset($refResult['error']) && $refResult['error'] === true)) {
                $rawRef = $refResult['GetReferenceNumberByListingResult'] ?? null;
                if (is_string($rawRef) && trim($rawRef) !== '') {
                    $ppRef = trim($rawRef);
                } elseif (is_array($rawRef)) {
                    $ppRef = $rawRef['PPRef'] ?? $rawRef['Ref'] ?? null;
                }
            }
        }

        // Compose update data based on what we observed.
        $updateData = [];
        if ($isLive) {
            if ($property->pp_syndication_status !== 'active') {
                $updateData['pp_syndication_status'] = 'active';
            }
            if (empty($property->pp_activated_at)) {
                $updateData['pp_activated_at'] = now();
            }
        }
        if ($ppRef && empty($property->pp_ref)) {
            $updateData['pp_ref'] = $ppRef;
        }

        if (!empty($updateData)) {
            $property->update($updateData);
            $this->log('info', "Property #{$property->id} synced from PP", [
                'pp_status'  => $statusString,
                'pp_ref'     => $ppRef ?: $property->pp_ref,
                'is_live'    => $isLive,
                'changes'    => array_keys($updateData),
            ]);
        }

        return [
            'success' => true,
            'message' => 'Status synced',
            'status'  => $property->fresh()->pp_syndication_status,
            'pp_ref'  => $property->fresh()->pp_ref,
            'pp_activated_at' => $property->fresh()->pp_activated_at?->toDateTimeString(),
            'pp_listing_status' => $statusString,
        ];
    }

    /**
     * Reactivate a previously deactivated listing on PP.
     */
    public function reactivateListing(Property $property): array
    {
        $this->client->forAgency($property->agency);
        $listingType = PrivatePropertyListingMapper::resolveListingType($property);
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
        $this->client->forAgency($property->agency);
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
     *
     * Duplicate-safe: if PP already holds a profile for this user's email
     * under a different External Ref (AgentId), we remap it to $user->id
     * via UpdateUniqueAgentID first. Without that step, calling UpdateAgent
     * with an AgentId PP doesn't recognise creates a brand-new profile —
     * which is how the duplicate Andre/Elize records were generated.
     */
    public function registerAgent(User $user, bool $active = true): array
    {
        $this->client->forAgency($user->agency ?? null);
        $agentData = $this->mapper->buildAgentData($user, $active);

        if (empty($agentData['TelCell'])) {
            return [
                'success' => false,
                'message' => 'Agent has no phone/cell number — required by Private Property',
            ];
        }

        $remapNote = null;
        if ($active) {
            $remap = $this->ensureNoDuplicateBeforeUpdateAgent($user);
            if ($remap !== null) {
                $remapNote = $remap;
            }
        }

        $result = $this->client->updateAgent($agentData);

        if (isset($result['error']) && $result['error'] === true) {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Agent registration failed',
            ];
        }

        $this->log('info', "Agent #{$user->id} ({$user->name}) " . ($active ? 'registered' : 'deactivated') . " on PP");

        $message = $active ? 'Agent registered on PP' : 'Agent deactivated on PP';
        if ($remapNote) {
            $message .= ' (' . $remapNote . ')';
        }

        return [
            'success' => true,
            'message' => $message,
            'result'  => $result,
        ];
    }

    /**
     * Look up the user's email on PP. If a profile exists with a different
     * AgentId, call UpdateUniqueAgentID to remap PP's existing record onto
     * $user->id BEFORE we send UpdateAgent. Returns a human-readable note,
     * or null if no remap was needed.
     */
    private function ensureNoDuplicateBeforeUpdateAgent(User $user): ?string
    {
        $email = strtolower(trim((string) $user->email));
        if ($email === '') return null;

        $resp = $this->client->getAllAgentsForBranch();
        if (isset($resp['error']) && $resp['error'] === true) {
            return null;
        }

        $xml = $resp['GetAllAgentsForBranchResult']['any'] ?? '';
        if (!is_string($xml) || $xml === '') {
            return null;
        }

        if (!preg_match_all('/<Agents\b[^>]*>(.*?)<\/Agents>/s', $xml, $matches)) {
            return null;
        }

        // Canonical AgentId: pp_external_ref takes precedence over user->id.
        $expectedId = (string) ($user->pp_external_ref ?: $user->id);
        $existingForEmail = [];

        foreach ($matches[1] as $inner) {
            preg_match('/<email\b[^>]*>(.*?)<\/email>/s', $inner, $em);
            preg_match('/<AgentId\b[^>]*>(.*?)<\/AgentId>/s', $inner, $am);
            preg_match('/<PrivatePropertyAgentId\b[^>]*>(.*?)<\/PrivatePropertyAgentId>/s', $inner, $pm);

            $rowEmail = strtolower(trim($em[1] ?? ''));
            if ($rowEmail !== $email) continue;

            $existingForEmail[] = [
                'agent_id'        => trim($am[1] ?? ''),
                'pp_encrypted_id' => trim($pm[1] ?? ''),
            ];
        }

        if (empty($existingForEmail)) {
            return null; // No existing profile — UpdateAgent will create the first one.
        }

        // If any existing record already matches user->id, nothing to remap.
        foreach ($existingForEmail as $rec) {
            if ($rec['agent_id'] === $expectedId) {
                return null;
            }
        }

        // Pick the first record and remap it to user->id.
        $rec = $existingForEmail[0];
        if ($rec['pp_encrypted_id'] === '') {
            return 'PP has profile(s) for ' . $email . ' under different External Refs but no encrypted ID was returned — cannot auto-remap';
        }

        $remap = $this->client->updateUniqueAgentId($rec['pp_encrypted_id'], $expectedId);
        if (isset($remap['error']) && $remap['error'] === true) {
            return 'auto-remap UpdateUniqueAgentID failed: ' . ($remap['message'] ?? 'unknown error');
        }

        $user->update(['pp_unique_agent_id' => $rec['pp_encrypted_id']]);

        $this->log('info', "Remapped PP profile for {$email} from External Ref {$rec['agent_id']} to {$expectedId} before UpdateAgent");

        return "remapped PP External Ref {$rec['agent_id']} → {$expectedId} to avoid duplicate";
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
        $this->client->forAgency($property->agency);
        $submitted = [];
        $skipped   = [];
        $errors    = [];

        $agentIds = array_filter([
            $property->agent_id,
            $property->pp_second_agent_id,
        ]);

        $override = PrivatePropertyConfig::forProperty($property)['image_base_url'];
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

        $listingType = PrivatePropertyListingMapper::resolveListingType($property);

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
        $listingType = PrivatePropertyListingMapper::resolveListingType($property);

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

        // PP requires all phone fields in valid format — empty string fails PP107,
        // and so does a formatted one ("076 901 7397"). Canonicalise to PP's
        // digits-only format here so even raw/legacy values reach PP clean.
        $cellPhone = \App\Support\SaPhoneNumber::normalize($user->cell ?? $user->phone) ?? '';
        if (empty($cellPhone)) {
            return 'Agent has no phone/cell number — required by Private Property';
        }
        $workPhone = \App\Support\SaPhoneNumber::normalize($user->phone) ?? $cellPhone;

        $agentData = [
            'AgentId'               => (string) ($user->pp_external_ref ?: $user->id),
            'FirstName'             => $firstName,
            'LastName'              => $lastName,
            'Email'                 => $user->email ?? '',
            'TelCell'               => $cellPhone,
            'TelWork'               => $workPhone,
            'TelHome'               => '', // PP only recognises TelCell + TelWork
            'Active'                => true,
            'BranchId'              => PrivatePropertyConfig::for($user->agency ?? null)['branch_guid'],
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

    /**
     * Extract a value from a PP SOAP response, defensively walking common
     * envelope shapes returned by PHP's SoapClient.
     *
     * Tried, in order, for each candidate key:
     *   1. Top level                                 ($response[$key])
     *   2. <Operation>Result wrapper                 ($response["{$op}Result"][$key])
     *   3. <Operation>Response/<Operation>Result     ($response["{$op}Response"]["{$op}Result"][$key])
     *   4. Generic `return` wrapper                  ($response['return'][$key])
     *   5. Raw XML in `any` element                  (regex <Key>value</Key>)
     *
     * Returns the first non-empty string match, or null.
     */
    private function extractFromSoapResponse(array $response, string $operation, array $keys): ?string
    {
        $candidateContainers = [
            $response,
            $response["{$operation}Result"] ?? null,
            ($response["{$operation}Response"]["{$operation}Result"] ?? null),
            $response['return'] ?? null,
        ];

        foreach ($keys as $key) {
            foreach ($candidateContainers as $container) {
                if (!is_array($container)) continue;
                if (isset($container[$key]) && is_scalar($container[$key])) {
                    $val = trim((string) $container[$key]);
                    if ($val !== '') return $val;
                }
            }
        }

        // Last resort: PP sometimes wraps the payload as raw XML in an `any`
        // element on the *Result envelope (proven shape for GetAllAgents).
        $xmlCandidates = [
            $response["{$operation}Result"]['any'] ?? null,
            ($response["{$operation}Response"]["{$operation}Result"]['any'] ?? null),
            $response['any'] ?? null,
        ];
        foreach ($xmlCandidates as $xml) {
            if (!is_string($xml) || $xml === '') continue;
            foreach ($keys as $key) {
                if (preg_match('/<' . preg_quote($key, '/') . '\b[^>]*>(.*?)<\/' . preg_quote($key, '/') . '>/s', $xml, $m)) {
                    $val = trim($m[1]);
                    if ($val !== '') return $val;
                }
            }
        }

        return null;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('private_property')->{$level}($message, $context);
    }
}
