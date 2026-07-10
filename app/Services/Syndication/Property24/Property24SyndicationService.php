<?php

namespace App\Services\Syndication\Property24;

use App\Exceptions\Property24ConfigurationException;
use App\Models\Agency;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Rebind $this->client to a fresh ApiClient scoped to the given agency's
     * stored P24 credentials. Without this, the DI-resolved client falls back
     * to .env — which is now empty in multi-tenant mode and yields HTTP 401.
     */
    private function bindClientForAgency(?Agency $agency): void
    {
        $this->client = new Property24ApiClient($agency);
    }

    private function bindClientForProperty(Property $property): void
    {
        $this->bindClientForAgency($property->agency ?? Agency::find($property->agency_id));
    }

    private function bindClientForUser(User $user): void
    {
        $this->bindClientForAgency($user->agency ?? Agency::find($user->agency_id));
    }

    /**
     * Persist the P24 listingNumber as a TrackedProperty external ref so that
     * subsequent ingress paths (e.g. P24 lead pull) can resolve back to this
     * stock Property via TrackedPropertyMatchOrCreateService. Best-effort —
     * any failure here must not break syndication.
     */
    private function writeP24ExternalRef(Property $property, string $listingNumber): void
    {
        try {
            $svc = app(\App\Services\Prospecting\TrackedPropertyMatchOrCreateService::class);
            $facts = array_filter([
                'address'       => $property->address ?? null,
                'suburb'        => $property->suburb ?? null,
                'latitude'      => $property->latitude ?? null,
                'longitude'     => $property->longitude ?? null,
                'property_type' => $property->property_type ?? null,
                'bedrooms'      => $property->bedrooms ?? null,
                'bathrooms'     => $property->bathrooms ?? null,
                'garages'       => $property->garages ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            $tracked = $svc->matchOrCreate(
                agencyId: (int) $property->agency_id,
                facts: $facts,
                source: ['type' => 'property24', 'ref' => $listingNumber, 'payload' => ['property_id' => $property->id]],
                actorUserId: null,
            );

            // Bind the tracked property to this stock property if not already.
            if (empty($tracked->promoted_to_property_id)) {
                $tracked->promoted_to_property_id = $property->id;
                $tracked->save();
            }
        } catch (\Throwable $e) {
            $this->log('warning', "writeP24ExternalRef failed for property #{$property->id}: {$e->getMessage()}");
        }
    }

    public function submitListing(Property $property): array
    {
        // AT-P24 remediation (#4): serialise submits per property so the same
        // listing is never saved to P24 twice concurrently — P24 rejects that
        // with "Cannot call the method simultaneously". The queued job is also
        // ShouldBeUnique (dispatch-level dedupe); this lock additionally covers
        // job retries and any non-queued caller. Lock TTL (240s) exceeds the
        // max job runtime so a crashed job self-releases.
        $lock = Cache::lock("p24:submit:{$property->id}", 240);
        if (! $lock->get()) {
            $this->log('warning', "submitListing skipped for property #{$property->id} — another submission is already in progress");
            return ['success' => false, 'message' => 'A Property24 submission for this property is already in progress — please wait for it to finish.'];
        }

        try {
            return $this->performSubmit($property);
        } finally {
            optional($lock)->release();
        }
    }

    private function performSubmit(Property $property): array
    {
        // Photo payload (up to the per-agency cap, default 150 base64-encoded
        // images — Agency::P24_DEFAULT_MAX_PHOTOS) plus Guzzle's JSON encode
        // buffer can exceed the default 256MB limit. Bump for this request only
        // — restored automatically at request end.
        @ini_set('memory_limit', '512M');

        $this->bindClientForProperty($property);
        $this->log('info', "submitListing called for property #{$property->id}, agent_id={$property->agent_id}");

        // LAYER 2 (AT-221) — run Property24's own content rules BEFORE we send.
        // Belt-and-braces for legacy stock edited before capture-time validation
        // (Layer 1) existed. A content rejection (e.g. a phone number in the
        // description) is recorded as a clear reason and the listing is NEVER
        // sent — no pointless round-trip, no re-submit storm, no false "Active".
        $contentViolations = app(\App\Services\Syndication\PortalContentValidator::class)
            ->violationsFor($property, \App\Services\Syndication\PortalContentValidator::P24);
        if (!empty($contentViolations)) {
            $reason = implode(' ', array_column($contentViolations, 'message'));
            $property->update(['p24_syndication_status' => 'rejected', 'p24_last_error' => $reason]);
            return ['success' => false, 'message' => $reason, 'rejected' => true, 'status' => 'rejected'];
        }

        // Resolve the P24 agency ID up-front so agent registration and the
        // listing payload go to the same profile. If the property's
        // branch/agency is not configured, fail fast with a readable error.
        $p24AgencyId = $property->resolveP24AgencyId();
        if ($p24AgencyId === null || $p24AgencyId === '') {
            $message = "Property's branch or agency has no Property24 agency ID configured.";
            $property->update(['p24_syndication_status' => 'error', 'p24_last_error' => $message]);
            return ['success' => false, 'message' => $message];
        }

        // Ensure the listing agent(s) are registered on P24 before submitting
        $agentResult = $this->ensureAgentRegistered($property, (int) $p24AgencyId);
        if ($agentResult !== true) {
            $property->update(['p24_syndication_status' => 'error', 'p24_last_error' => 'Agent registration failed: ' . $agentResult]);
            return ['success' => false, 'message' => 'Agent registration failed: ' . $agentResult];
        }

        // Register second agent if assigned
        if ($property->pp_second_agent_id) {
            $secondAgent = User::find($property->pp_second_agent_id);
            if ($secondAgent) {
                $this->ensureAgentRegisteredByUser($secondAgent, (int) $p24AgencyId);
            }
        }

        try {
            $payload = $this->mapper->map($property);
        } catch (Property24ConfigurationException $e) {
            $property->update(['p24_syndication_status' => 'error', 'p24_last_error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $errors = $this->mapper->validate($payload);
        if (!empty($errors)) {
            $errorDetail = implode('; ', $errors);
            $property->update(['p24_syndication_status' => 'error', 'p24_last_error' => 'Validation failed: ' . $errorDetail]);
            return ['success' => false, 'message' => 'Validation failed: ' . $errorDetail, 'errors' => $errors];
        }

        $result = $this->client->saveListing($property->id, $payload);

        if (!$result['success']) {
            // AT-P24 remediation (#5) extended to the submit/update path. A
            // TRANSIENT Property24 failure (connection timeout / 5xx / 429 — no
            // definitive HTTP status) must NOT be written as a permanent 'error':
            //  • A read-timeout on POST /listings returns 0 bytes (cURL 28), so we
            //    cannot tell whether P24 accepted the save.
            //  • A listing that already has a p24_ref is STILL LIVE on the portal.
            //    The old path clobbered it to 'error', which (a) mislabels a live
            //    listing as broken, (b) hides every recovery button in the UI
            //    (View/Refresh/Deactivate require active|submitted|submitting), and
            //    (c) strands it out of syncAllActivations() (error rows aren't
            //    reconciled) — exactly the class of bug already fixed for
            //    deactivate/reactivate but never for submit. See handleTransientSubmitFailure.
            if ($this->isTransientFailure($result)) {
                return $this->handleTransientSubmitFailure($property, $result);
            }

            // Permanent failure (4xx validation etc.) — surface the real error.
            $property->update(['p24_syndication_status' => 'error', 'p24_last_error' => $result['message'] ?? 'Unknown API error']);
            return ['success' => false, 'message' => $result['message'] ?? 'Unknown API error'];
        }

        // LAYER 3 (AT-221) — HTTP 200 is NOT "on the portal". P24 accepts the call
        // but the BODY can say isOnPortal:false with a reason (content rule, etc.).
        // The truth is isOnPortal, not the status code — reflect it honestly as
        // 'rejected: <reason>' instead of a false 'Active'. (Absent isOnPortal =
        // older/other responses → unchanged behaviour below.)
        $data = $result['data'] ?? [];
        $isOnPortal = $data['isOnPortal'] ?? $data['IsOnPortal'] ?? null;
        if ($isOnPortal === false || $isOnPortal === 'false' || $isOnPortal === 'False') {
            $reasons = $data['reasons'] ?? $data['Reasons'] ?? [];
            $reason  = is_array($reasons) ? implode(' ', array_map('strval', $reasons)) : (string) $reasons;
            $reason  = trim($reason) !== '' ? $reason : 'Property24 did not publish the listing.';
            $ref     = $data['listingNumber'] ?? $data['ListingNumber'] ?? $property->p24_ref;
            $property->update([
                'p24_syndication_status'     => 'rejected',
                'p24_last_error'             => $reason,
                'p24_last_submitted_at'      => now(),
                'p24_listing_last_synced_at' => now(),
                'p24_ref'                    => $ref ? (string) $ref : $property->p24_ref,
            ]);
            $this->log('warning', "Listing rejected by Property24 for property #{$property->id}", ['reason' => $reason]);
            return ['success' => false, 'message' => $reason, 'rejected' => true, 'status' => 'rejected'];
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

        // AT-P24: record the image signature P24 now holds so the next unchanged
        // refresh can send `photos: null` and skip the upload. Store it whenever
        // the payload carried a `photos` key — an array (photos sent → P24 set
        // now matches current) OR explicit null (was already unchanged → still
        // matches). If the key is absent (photos couldn't be built) we leave the
        // signature stale so it retries the upload next time.
        if (array_key_exists('photos', $payload)) {
            $updateData['p24_image_signature'] = $property->p24ImageSignature();
        }

        if (!empty($payload['photos'])) {
            $updateData['p24_images_last_synced_at'] = now();
        }

        $property->update($updateData);

        // Audit chain (CLAUDE.md rule #10): record the P24 listingNumber as an
        // external ref on the Tracked Property so future ingress paths (e.g.
        // P24 lead pull) can resolve back to this Property without touching
        // syndication code. Best-effort — never break syndication on failure.
        if (!empty($updateData['p24_ref'])) {
            $this->writeP24ExternalRef($property, (string) $updateData['p24_ref']);
        }

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

    /**
     * A TRANSIENT Property24 failure (timeout / 5xx / 429) during submit/update —
     * never a permanent 'error'. See performSubmit for the full rationale.
     *
     *  • Already on the portal (has p24_ref): keep it 'active'. The listing is
     *    still live with its previous content; only THIS update didn't reach P24.
     *    Staying 'active' preserves the UI recovery buttons and keeps the row in
     *    syncAllActivations()'s reconcile set, and the deferred note tells the
     *    agent the latest changes haven't synced yet (tap Refresh to re-push).
     *  • Never submitted (no p24_ref): 'pending' + a retryable note. Nothing is on
     *    the portal to mislabel; the agent (or the observer re-dispatch on the next
     *    save) retries. P24 dedupes by sourceReference, so a retry updates the same
     *    listing rather than creating a duplicate.
     *
     * Either way the caller gets ['transient' => true] so the HTTP layer can show
     * a soft "temporarily unavailable" state instead of a hard failure.
     */
    private function handleTransientSubmitFailure(Property $property, array $result): array
    {
        $code = $result['status_code'] ?? 'timeout';

        if (!empty($property->p24_ref)) {
            $property->update([
                'p24_syndication_status' => 'active',
                'p24_last_error'         => "Sync deferred — Property24 temporarily unavailable ({$code}). Your listing is still live on Property24; the latest changes haven't synced yet — tap Refresh to retry.",
            ]);
            $this->log('warning', "Submit transient-failed for property #{$property->id} — kept live (ref {$property->p24_ref})", ['status_code' => $result['status_code'] ?? null]);
            return ['success' => false, 'transient' => true, 'message' => 'Property24 is temporarily unavailable — your listing is still live and the latest changes will re-sync when you retry.'];
        }

        $property->update([
            'p24_syndication_status' => 'pending',
            'p24_last_error'         => "Submission deferred — Property24 temporarily unavailable ({$code}). Not yet on the portal; please retry shortly.",
        ]);
        $this->log('warning', "First submit transient-failed for property #{$property->id} — marked pending/retryable", ['status_code' => $result['status_code'] ?? null]);
        return ['success' => false, 'transient' => true, 'message' => 'Property24 is temporarily unavailable — please retry the submission shortly.'];
    }

    public function deactivateListing(Property $property): array
    {
        if (empty($property->p24_ref)) {
            return ['success' => false, 'message' => 'No P24 reference — listing was never submitted'];
        }

        $this->bindClientForProperty($property);
        $result = $this->client->setListingStatus($property->id, (int) $property->p24_ref, 'Withdrawn');

        if (!$result['success']) {
            // AT-P24 remediation (#5): a transient P24 outage (HTTP 5xx /
            // timeout / connection failure) must NOT be written as a permanent
            // 'error'. The listing is still live on the portal; clobbering the
            // status to 'error' stranded ~148 listings that were never actually
            // withdrawn. Leave the prior status intact and record a retryable
            // note so the next deactivation attempt (caller / cron) re-tries.
            if ($this->isTransientFailure($result)) {
                $property->update([
                    'p24_last_error' => 'Deactivation deferred — Property24 temporarily unavailable (HTTP ' . ($result['status_code'] ?? 'timeout') . '). Listing is still live; will retry.',
                ]);
                $this->log('warning', "Deactivation transient-failed for property #{$property->id} — left live, marked retryable", ['status_code' => $result['status_code'] ?? null]);
                return ['success' => false, 'transient' => true, 'message' => 'Property24 temporarily unavailable — deactivation will be retried.'];
            }

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

        $this->bindClientForProperty($property);
        $result = $this->client->setListingStatus($property->id, (int) $property->p24_ref, 'BackOnMarket');

        if (!$result['success']) {
            // AT-P24 remediation (#5): same transient/permanent split as
            // deactivateListing — a P24 outage must not be recorded as a
            // permanent error.
            if ($this->isTransientFailure($result)) {
                $property->update([
                    'p24_last_error' => 'Reactivation deferred — Property24 temporarily unavailable (HTTP ' . ($result['status_code'] ?? 'timeout') . '). Will retry.',
                ]);
                $this->log('warning', "Reactivation transient-failed for property #{$property->id} — marked retryable", ['status_code' => $result['status_code'] ?? null]);
                return ['success' => false, 'transient' => true, 'message' => 'Property24 temporarily unavailable — reactivation will be retried.'];
            }
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

        $this->bindClientForProperty($property);
        $result = $this->client->isOnPortal($property->id, (int) $property->p24_ref);

        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message'] ?? 'Status check failed', 'status' => $property->p24_syndication_status];
        }

        // is-on-portal returns a bare boolean. When P24 sends it as
        // Content-Type: application/json, the client decodes it to a PHP bool
        // and wraps it as ['data' => true|false] — so array-accessing $data['raw']
        // on a scalar yields null and the status never reconciled (listings stuck
        // 'submitted' forever). Handle the scalar/bool shape first.
        $data = $result['data'] ?? [];
        $isOnPortal = is_array($data)
            ? ($data['raw'] ?? $data['isOnPortal'] ?? $data['IsOnPortal'] ?? null)
            : $data;

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

    /**
     * Reap listings frozen at 'submitting'. SubmitListingToProperty24::failed()
     * already resolves an exhausted/timed-out job to 'error', but a HARD worker
     * kill (OOM, deploy mid-job, SIGKILL the supervisor itself) never fires
     * failed() — leaving the row stuck and the UI spinning "Syncing…" forever.
     *
     * A row's updated_at is stamped when the controller flips it to 'submitting'
     * and nothing else touches a still-'submitting' row, so updated_at is a
     * reliable "entered submitting at" marker. Max job wall-time is ~11 min
     * (3 tries × 180s timeout + 2 × 60s backoff); 15 min means the job is
     * definitively done and this row will never self-resolve. Flip to 'error'
     * so the agent sees a retryable state instead of an eternal spinner.
     */
    public function reapStuckSubmits(int $staleMinutes = 15): int
    {
        $rows = Property::where('p24_syndication_status', 'submitting')
            ->where('updated_at', '<', now()->subMinutes($staleMinutes))
            ->get();

        foreach ($rows as $property) {
            $property->update([
                'p24_syndication_status' => 'error',
                'p24_last_error'         => 'Sync timed out — the background job did not finish. Please retry.',
            ]);
            $this->log('warning', "Reaped stuck 'submitting' property #{$property->id} (>{$staleMinutes}m)");
        }

        return $rows->count();
    }

    public function syncAllActivations(): array
    {
        // Self-heal any listings the submit job left frozen at 'submitting'
        // before reconciling the live ones. See reapStuckSubmits().
        $reaped = $this->reapStuckSubmits();

        // Include 'active' so live listings are periodically re-verified against
        // is-on-portal — otherwise a P24-side removal (expiry, moderation) leaves
        // CoreX showing 'active' forever. submitted/pending await first activation.
        $properties = Property::where('p24_syndication_enabled', true)
            ->whereIn('p24_syndication_status', ['submitted', 'pending', 'active'])
            ->whereNotNull('p24_ref')->get();

        $synced = 0;
        $errors = 0;
        foreach ($properties as $property) {
            $result = $this->syncActivationStatus($property);
            $result['success'] ? $synced++ : $errors++;
        }

        $this->log('info', "P24 activation sync complete: {$synced} synced, {$errors} errors, {$reaped} reaped");
        return ['synced' => $synced, 'errors' => $errors, 'reaped' => $reaped, 'total' => $properties->count()];
    }

    /**
     * Ensure the property's listing agent is registered on P24 under the given
     * P24 agency ID (resolved by the caller from the property's branch/agency).
     * Returns true on success, or an error string on failure.
     */
    private function ensureAgentRegistered(Property $property, int $p24AgencyId): string|bool
    {
        $user = $property->agent ?? User::find($property->agent_id);
        if (!$user) {
            return 'No agent assigned to this property';
        }

        return $this->ensureAgentRegisteredByUser($user, $p24AgencyId);
    }

    /**
     * Register a specific user as an agent on P24 under the given P24 agency ID.
     * When $p24AgencyId is null, the user's own branch/agency resolves it —
     * used by observer hooks that push user updates without a property context.
     * Returns true on success, or an error string on failure.
     */
    public function ensureAgentRegisteredByUser(User $user, ?int $p24AgencyId = null): string|bool
    {
        $this->bindClientForUser($user);
        $this->log('info', "ensureAgentRegistered for user #{$user->id} ({$user->name}), agent_photo_path=" . ($user->agent_photo_path ?? 'NULL'));

        $agencyId = $p24AgencyId ?? $this->resolveAgencyIdForUser($user);
        if ($agencyId === null) {
            return "User's branch or agency has no Property24 agency ID configured.";
        }
        $agencyIdStr = (string) $agencyId;
        $parts = explode(' ', trim($user->name), 2);

        $agentData = [
            'agencyId'        => $agencyId,
            'firstname'       => $parts[0] ?? '',
            'lastname'        => $parts[1] ?? $parts[0] ?? '',
            'emailAddress'    => $user->email ?? '',
            'mobileNumber'    => $this->normaliseSaPhone($user->cell ?? $user->phone),
            'sourceReference' => 'CoreX-Agent-' . $user->id,
            'published'       => !$user->exclude_from_p24,
            'receiveStatsMail' => false,
            'countryId'       => 1, // South Africa
            // jobTitle on the CREATE payload so an agent first registered via a
            // listing submit carries their designation on P24 from the start —
            // previously this path omitted it, so agents only ever got a title
            // if someone later hit the Users-page "Sync to P24" button. That
            // create-vs-update gap is the "half have titles, half don't" split.
            'jobTitle'        => $user->designation ?: 'Sales Agent',
        ];

        // Check if agent already exists on P24 *under this agency*. Scoping to
        // the right agency is critical — P24 enforces firstname+lastname
        // uniqueness per agency, so a lookup against the wrong agency would
        // miss the existing agent and trigger a duplicate-name error on create.
        $existingResult = $this->client->getAgents($agencyIdStr);
        if ($existingResult['success']) {
            foreach ($existingResult['data'] ?? [] as $existing) {
                $ref = $existing['sourceReference'] ?? '';
                if ($ref === 'CoreX-Agent-' . $user->id) {
                    $p24AgentId = (int) $existing['id'];
                    $this->log('info', "Agent #{$user->id} already registered on P24 agency {$agencyIdStr} as #{$p24AgentId}");
                    // Keep the FULL profile (name, contact, jobTitle, active status)
                    // in step with CoreX — not just the photo. Without this an agent
                    // first registered via a listing submit never gains a jobTitle,
                    // and later CoreX edits never reach P24 unless someone hits the
                    // Users-page button. Best-effort: a profile-push failure must not
                    // block the listing this agent is attached to.
                    $this->pushAgentProfile($user, $p24AgentId, $agencyId);
                    $this->uploadAgentPhotoIfAvailable($user, $p24AgentId);
                    return true;
                }
            }
        }

        // Agent opted out of P24 and isn't on the portal yet — never create a
        // fresh record just to immediately unpublish it. The existing-agent
        // branch above already handles the "on P24 but now excluded" case by
        // pushing published=false.
        if ($user->exclude_from_p24) {
            $this->log('info', "Agent #{$user->id} is excluded from P24 and not yet registered — skipping registration");
            return true;
        }

        // Register new agent
        $this->log('info', "Registering agent #{$user->id} ({$user->name}) on P24 agency {$agencyIdStr}");
        $result = $this->client->createAgent($agentData);

        if (!$result['success']) {
            // P24 enforces firstname+lastname uniqueness per agency and returns
            // "An agent named X already exists (AgentId N)". When this happens
            // under the correct agency, the agent was created earlier (e.g. by
            // the admin UI's direct registration flow) and our sourceReference
            // just isn't set. Adopt that agent by PUT-updating it with our
            // sourceReference so future lookups find it.
            if ($adoptedId = $this->extractExistingAgentId($result['message'] ?? '')) {
                $this->log('info', "Adopting existing P24 agent #{$adoptedId} for CoreX user #{$user->id}");
                $adoptPayload = array_merge($agentData, [
                    'id'     => $adoptedId,
                    'status' => 'Active',
                ]);
                $adoptResult = $this->client->updateAgent($adoptPayload);
                if ($adoptResult['success'] ?? false) {
                    $this->uploadAgentPhotoIfAvailable($user, $adoptedId);
                    return true;
                }
                $this->log('error', "Failed to adopt existing P24 agent #{$adoptedId}", ['result' => $adoptResult]);
                return $adoptResult['message'] ?? 'Failed to adopt existing agent';
            }

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
     * Get the P24 agent ID for a CoreX user. Scopes the lookup to the user's
     * resolved P24 agency so we don't miss agents registered under a
     * non-default agency. Returns null if not found.
     */
    public function getP24AgentId(User $user, ?int $p24AgencyId = null): ?int
    {
        $this->bindClientForUser($user);
        $agencyId = $p24AgencyId ?? $this->resolveAgencyIdForUser($user);
        $result   = $this->client->getAgents($agencyId !== null ? (string) $agencyId : null);
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
        $this->bindClientForUser($user);
        $agencyId = $this->resolveAgencyIdForUser($user);
        if ($agencyId === null) {
            return "User's branch or agency has no Property24 agency ID configured.";
        }

        $p24AgentId = $this->getP24AgentId($user, $agencyId);
        if (!$p24AgentId) {
            // Not registered yet — create them; that flow also uploads the photo.
            return $this->ensureAgentRegisteredByUser($user, $agencyId);
        }

        $pushed = $this->pushAgentProfile($user, $p24AgentId, $agencyId);
        if ($pushed !== true) {
            return $pushed;
        }

        if ($pushPhoto) {
            $this->uploadAgentPhotoIfAvailable($user, $p24AgentId);
        }

        return true;
    }

    /**
     * Build the COMPLETE P24 agent payload from the CoreX user and PUT it.
     * Single source of truth for "a fully-synced P24 agent profile" — shared by
     * the Users-page "Sync to P24" button (updateAgentOnP24) and the
     * listing-submit path (ensureAgentRegisteredByUser), so an agent carries the
     * same name / contact / jobTitle / active-status regardless of which path
     * last touched them. Returns true, or an error string on failure.
     */
    private function pushAgentProfile(User $user, int $p24AgentId, int $agencyId): bool|string
    {
        $parts    = explode(' ', trim($user->name), 2);
        // An agent is shown on P24 only when active, not deleted, AND not
        // explicitly opted out via the per-agent "Exclude from Property24" flag.
        // Toggling exclude_from_p24 on for an already-synced agent therefore PUTs
        // published=false / status=Inactive — actively removing them from the
        // portal rather than just halting future syncs.
        $isActive = (bool) $user->is_active && !$user->trashed() && !$user->exclude_from_p24;

        $payload = [
            'id'               => $p24AgentId,
            'agencyId'         => $agencyId,
            'firstname'        => $parts[0] ?? '',
            'lastname'         => $parts[1] ?? $parts[0] ?? '',
            'emailAddress'     => $user->email ?? '',
            'mobileNumber'     => $this->normaliseSaPhone($user->cell ?? $user->phone),
            'sourceReference'  => 'CoreX-Agent-' . $user->id,
            'published'        => $isActive,   // hides the profile from P24 portal when deactivated or opted out
            'status'           => $isActive ? 'Active' : 'Inactive',
            'receiveStatsMail'  => false,
            'countryId'        => 1,
            'jobTitle'         => $user->designation ?: 'Sales Agent',
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
            $this->log('error', "Agent profile push failed for #{$user->id}", ['result' => $result]);
            return $result['message'] ?? 'Unknown agent update error';
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
        // Resolve the photo from the SAME canonical source the rest of CoreX uses
        // (User::profilePhotoUrl): a user_documents 'profile_photo' row first, then
        // the legacy agent_photo_path column. The sync previously read ONLY
        // agent_photo_path, so every agent whose photo lives in user_documents
        // reached P24 with no photo. Pick the first candidate whose file actually
        // EXISTS on disk — these two records routinely desync (a stale .jpg path
        // recorded while the real normalised file is photo.webp), so trusting the
        // document path blindly would upload nothing. If neither resolves on disk,
        // keep the preferred path so the URL-fallback strategies below can try.
        $profileDoc = $user->documents()
            ->where('document_type', 'profile_photo')
            ->latest()
            ->first();

        $candidates = array_values(array_filter([
            $profileDoc?->file_path,
            $user->agent_photo_path,
        ], fn ($p) => !empty($p)));

        if (empty($candidates)) {
            $this->log('info', "Agent #{$user->id} has no profile photo (user_documents or agent_photo_path) — skipping P24 photo upload");
            return;
        }

        $photoPath = $candidates[0];
        foreach ($candidates as $candidate) {
            if (Storage::disk('public')->exists($candidate)) {
                $photoPath = $candidate;
                break;
            }
        }

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

        // P24's profile-picture endpoint rejects WebP (returns HTTP 500). Our
        // photos are stored as WebP (smaller/sharper for every in-app surface),
        // so transcode to JPEG — which P24 accepts — at this boundary only.
        [$bytes, $mime] = $this->toP24SafeImage($bytes, $mime);

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

    /**
     * AT-P24 remediation (#5): classify a failed API result as transient
     * (Property24-side outage — retry later, keep current state) vs permanent
     * (our payload is wrong — surface a hard error). Transient = no HTTP status
     * (connection failure / timeout), a 5xx server error, or 429 rate-limit.
     * Everything else (4xx validation) is permanent.
     */
    private function isTransientFailure(array $result): bool
    {
        $code = $result['status_code'] ?? null;
        if ($code === null) {
            return true; // connection failure / timeout — no response received
        }
        return $code >= 500 || $code === 429;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('property24')->{$level}($message, $context);
    }

    /**
     * Return image bytes in a format P24's profile-picture endpoint accepts.
     * P24 rejects WebP (HTTP 500); JPEG and PNG are fine. JPEG/PNG pass through
     * untouched; anything else (WebP) is re-encoded to JPEG via GD, flattening
     * any alpha onto white. On any decode failure the original bytes are returned
     * so the upload is still attempted rather than silently skipped.
     *
     * @return array{0: string, 1: string} [bytes, mimeContentType]
     */
    private function toP24SafeImage(string $bytes, string $mime): array
    {
        if ($mime === 'image/jpeg' || $mime === 'image/png') {
            return [$bytes, $mime];
        }

        if (!function_exists('imagecreatefromstring')) {
            $this->log('warning', 'P24 photo transcode skipped: GD not available');
            return [$bytes, $mime];
        }

        $img = @imagecreatefromstring($bytes);
        if (!$img instanceof \GdImage) {
            $this->log('warning', 'P24 photo transcode: GD could not decode source; sending original bytes');
            return [$bytes, $mime];
        }

        // Flatten onto white — P24 profile pictures are opaque JPEGs.
        $w = imagesx($img);
        $h = imagesy($img);
        $canvas = imagecreatetruecolor($w, $h);
        imagefilledrectangle($canvas, 0, 0, $w, $h, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);

        ob_start();
        imagejpeg($canvas, null, 88);
        $jpeg = (string) ob_get_clean();
        imagedestroy($canvas);

        if ($jpeg === '') {
            return [$bytes, $mime];
        }

        return [$jpeg, 'image/jpeg'];
    }

    /**
     * Derive the P24 agency ID for a CoreX user from their branch/agency.
     * Returns null when the user is not linked to a configured branch/agency —
     * caller returns a readable error rather than registering them under
     * the wrong P24 profile.
     */
    /**
     * P24's duplicate-name error carries the existing agent's ID:
     *   "Validation errors — An agent named X already exists (AgentId 77843)"
     * Pull the numeric ID out so we can adopt the record.
     */
    private function extractExistingAgentId(string $errorMessage): ?int
    {
        if (preg_match('/AgentId\s+(\d+)/i', $errorMessage, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function resolveAgencyIdForUser(User $user): ?int
    {
        $branchId = method_exists($user, 'effectiveBranchId') ? $user->effectiveBranchId() : $user->branch_id;
        if ($branchId) {
            $branch = \App\Models\Branch::find($branchId);
            if ($branch) {
                $resolved = $branch->resolveP24AgencyId();
                if ($resolved !== null) return (int) $resolved;
            }
        }
        $agency = $user->agency;
        if ($agency && !empty($agency->p24_agency_id)) {
            return (int) $agency->p24_agency_id;
        }
        return null;
    }
}
