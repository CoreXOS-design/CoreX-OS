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

    /**
     * The ONLY P24 call a refresh of an unchanged listing may make: the listing
     * POST itself. See auditRefreshCost — this is a budget, not a description.
     */
    private const REFRESH_BASELINE_ACTIONS = ['submit'];

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

        // Start this submit's cost meter — see auditRefreshCost, the guard that
        // catches a refresh silently getting expensive again.
        $startedAt = microtime(true);
        Property24ApiClient::beginCostWindow();

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

        // Is there anything for this submit to actually DO? Answered BEFORE the work
        // (the signatures below get stamped as we go), so auditRefreshCost can hold
        // the refresh to its cost contract afterwards.
        $expectedNoOp = $this->refreshIsNoOp($property, (int) $p24AgencyId);

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

        $this->auditRefreshCost($property, $expectedNoOp, $startedAt);

        return [
            'success' => true,
            'message' => 'Listing submitted to Property24',
            'status'  => $updateData['p24_syndication_status'],
            'p24_ref' => $updateData['p24_ref'] ?? null,
        ];
    }

    /**
     * True when NOTHING this submit sends has changed since the last successful
     * sync — same gallery, same agent profile(s), same agent photo(s). Such a
     * refresh must cost exactly ONE P24 call: the listing POST.
     *
     * Evaluated BEFORE the submit does any work, because the work stamps the very
     * signatures this reads.
     */
    private function refreshIsNoOp(Property $property, int $p24AgencyId): bool
    {
        if (empty($property->p24_ref)) {
            return false; // never been on the portal — everything is new
        }

        if ($property->p24_image_signature === null
            || $property->p24_image_signature !== $property->p24ImageSignature()) {
            return false; // gallery changed (or was never fingerprinted)
        }

        foreach ($this->submitAgents($property) as $user) {
            if (!$this->agentSyncIsUpToDate($user, $p24AgencyId)) {
                return false;
            }
        }

        return true;
    }

    /** The CoreX users this listing pushes to P24 — the listing agent, plus the co-listing agent. */
    private function submitAgents(Property $property): array
    {
        return array_values(array_filter([
            $property->agent ?? User::find($property->agent_id),
            $property->pp_second_agent_id ? User::find($property->pp_second_agent_id) : null,
        ]));
    }

    /** True when P24 already holds this agent's current profile AND photo. */
    private function agentSyncIsUpToDate(User $user, int $agencyId): bool
    {
        if (empty($user->p24_agent_id) || (int) $user->p24_agent_agency_id !== $agencyId) {
            return false;
        }

        if ($user->p24_profile_signature === null) {
            return false;
        }

        $payload = $this->agentProfilePayload($user, (int) $user->p24_agent_id, $agencyId);
        if ($user->p24_profile_signature !== md5((string) json_encode($payload))) {
            return false;
        }

        $photoSignature = $this->agentPhotoSignature($user);

        return $photoSignature === null || $photoSignature === $user->p24_photo_signature;
    }

    /**
     * THE REFRESH COST GUARD.
     *
     * A refresh of a listing where nothing changed is allowed exactly one P24 call
     * — the listing POST. Every other call means CoreX re-sent something P24
     * already holds.
     *
     * This guard exists because that regression has now happened twice, silently,
     * and both times it was found by an agent noticing Refresh "felt slow" rather
     * than by anything in the code:
     *
     *   1. The whole photo gallery was re-uploaded on every refresh (60s+), fixed
     *      by properties.p24_image_signature.
     *   2. Months later the agent profile + agent photo began being re-pushed on
     *      every refresh — per agent — quietly undoing most of that win.
     *
     * Nothing about a refresh's cost is visible in a unit test's assertions or in a
     * green pipeline, so the runtime says it out loud instead: if an unchanged
     * refresh spends more than its budget, that is logged as a WARNING naming the
     * offending calls. When you see it, fix the caller — do NOT raise the budget.
     * The companion build-time lock is
     * tests/Feature/Syndication/Property24RefreshCostTest.php, which fails the
     * moment an unchanged refresh makes a second call.
     */
    private function auditRefreshCost(Property $property, bool $expectedNoOp, float $startedAt): void
    {
        $cost    = Property24ApiClient::costWindow();
        $calls   = array_sum($cost);
        $seconds = round(microtime(true) - $startedAt, 1);

        $context = [
            'property_id' => $property->id,
            'calls'       => $calls,
            'seconds'     => $seconds,
            'breakdown'   => $cost,
        ];

        if (!$expectedNoOp) {
            $this->log('info', "P24 submit cost for property #{$property->id}: {$calls} call(s) in {$seconds}s", $context);
            return;
        }

        $overBudget = array_diff_key($cost, array_flip(self::REFRESH_BASELINE_ACTIONS));

        if (empty($overBudget) && $calls <= 1) {
            $this->log('info', "P24 refresh cost for property #{$property->id}: 1 call in {$seconds}s (unchanged — gallery and agents skipped)", $context);
            return;
        }

        $this->log('warning', "P24 REFRESH COST REGRESSION on property #{$property->id}: nothing changed since the last sync, "
            . "so this refresh should have cost 1 call (the listing POST) — it cost {$calls} in {$seconds}s. "
            . "Something on the submit path is re-sending data P24 already holds. Offending calls: "
            . json_encode($overBudget ?: $cost) . ". Fix the caller — do not raise the budget.", $context);
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

    /**
     * Ask P24 whether the listing is currently on the portal. This is the ONLY
     * trustworthy answer to "is it still live" — p24_syndication_status is a local
     * cache that has drifted (a Sold push used to write 'deactivated' while the
     * listing stayed up). Returns null when we cannot get an answer, so callers
     * can distinguish "not on portal" from "P24 didn't tell us".
     */
    public function isOnPortal(Property $property): ?bool
    {
        if (empty($property->p24_ref)) {
            return null;
        }

        $this->bindClientForProperty($property);
        $result = $this->client->isOnPortal($property->id, (int) $property->p24_ref);

        if (! ($result['success'] ?? false)) {
            return null;
        }

        // Read the client's normalised answer. This used to cast 'data' directly:
        // the live payload is the ARRAY ['raw' => '1'] or ['raw' => ''], and both
        // are non-empty arrays, so (bool) returned true unconditionally — this
        // "is it still live" guard answered YES for every listing, including the
        // ~10.7k weekly checks where P24 said it was off the portal.
        return $result['on_portal'] ?? null;
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

        // AT-68 audit-truth — P24 acks the Withdrawn push as success while
        // sometimes leaving the listing LIVE on the portal (AT-221: HTTP 200 while
        // rejecting). We must NEVER record a removal that did not occur. Read the
        // portal back and confirm the listing is actually OFF before writing
        // 'deactivated'. Not-confirmed (still live, or read-back unreachable) →
        // leave the prior status, return a retryable failure so the queued
        // DesyndicatePropertyFromPortalsJob (3 tries) re-attempts.
        $confirm = $this->confirmOffPortal($property);
        if (($confirm['confirmed'] ?? false) !== true) {
            $property->update([
                'p24_last_error' => 'Withdrawal not confirmed — ' . $confirm['message'] . '. Listing may still be live; will retry.',
            ]);
            $this->log('warning', "P24 withdrawal UNCONFIRMED for property #{$property->id} — not recorded as removed", ['detail' => $confirm['message']]);
            return ['success' => false, 'unconfirmed' => true, 'message' => 'Withdrawal not confirmed off Property24 — will retry.'];
        }

        $property->update(['p24_syndication_status' => 'deactivated', 'p24_last_error' => null]);
        $this->log('info', "Listing deactivated for property #{$property->id} (confirmed off portal)");
        return ['success' => true, 'message' => 'Listing deactivated on Property24'];
    }

    /**
     * AT-68 audit-truth read-back. After a Withdrawn push, ask the portal whether
     * the listing is still on it and return an unambiguous verdict. Only an
     * explicit "off portal" answer confirms removal — a still-on answer, an
     * inconclusive shape, or an unreachable read-back all return confirmed=false
     * so the caller records nothing and retries. (Client is already bound by the
     * caller's bindClientForProperty().) Mirrors syncActivationStatus()'s
     * is-on-portal interpretation.
     */
    private function confirmOffPortal(Property $property): array
    {
        $check = $this->client->isOnPortal($property->id, (int) $property->p24_ref);
        if (! ($check['success'] ?? false)) {
            return ['confirmed' => false, 'message' => 'read-back failed (' . ($check['message'] ?? 'portal unreachable') . ')'];
        }
        $data = $check['data'] ?? [];
        $isOn = is_array($data)
            ? ($data['raw'] ?? $data['isOnPortal'] ?? $data['IsOnPortal'] ?? null)
            : $data;
        if ($isOn === true || $isOn === 'true' || $isOn === 'True') {
            return ['confirmed' => false, 'message' => 'listing still ON portal after withdraw'];
        }
        if ($isOn === false || $isOn === 'false' || $isOn === 'False') {
            return ['confirmed' => true, 'message' => 'confirmed off portal'];
        }
        return ['confirmed' => false, 'message' => 'read-back inconclusive'];
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

        // The client owns this endpoint's wire format and hands us a normalised
        // true|false|null — never re-parse 'data' here. See
        // Property24ApiClient::interpretOnPortalPayload for the format and for why
        // parsing it in this method left every listing unreconciled.
        $isOnPortal = $result['on_portal'] ?? null;

        if ($isOnPortal === true) {
            if ($property->p24_syndication_status !== 'active') {
                $property->update(['p24_syndication_status' => 'active', 'p24_activated_at' => $property->p24_activated_at ?? now(), 'p24_last_error' => null]);
                $this->log('info', "Property #{$property->id} confirmed active on P24");
            }
        } elseif ($isOnPortal === false) {
            if ($property->p24_syndication_status === 'active') {
                $property->update(['p24_syndication_status' => 'submitted', 'p24_last_error' => 'Listing not currently on portal']);
                $this->log('info', "Property #{$property->id} is no longer on P24 — demoted to 'submitted'");
            }
        } else {
            // Unknown answer — leave the status alone. Guessing here is what turns
            // one odd response into a wrongly-delisted or wrongly-live listing.
            $this->log('warning', "P24 is-on-portal returned an unrecognised payload for property #{$property->id} — status left unchanged", [
                'property_id' => $property->id,
                'data'        => $result['data'] ?? null,
            ]);
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
    public function ensureAgentRegisteredByUser(User $user, ?int $p24AgencyId = null, bool $force = false): string|bool
    {
        $this->bindClientForUser($user);
        $this->log('info', "ensureAgentRegistered for user #{$user->id} ({$user->name}), agent_photo_path=" . ($user->agent_photo_path ?? 'NULL'));

        $agencyId = $p24AgencyId ?? $this->resolveAgencyIdForUser($user);
        if ($agencyId === null) {
            return "User's branch or agency has no Property24 agency ID configured.";
        }
        $agencyId = (int) $agencyId;

        // Already on P24? Answer this WITHOUT P24's ~90s full agent-list scan
        // whenever we can — see resolveRegisteredAgentId. This is the single
        // biggest cost the listing-submit path used to carry.
        $p24AgentId = $this->resolveRegisteredAgentId($user, $agencyId);

        if ($p24AgentId !== null) {
            // Keep the FULL profile (name, contact, jobTitle, active status) and the
            // photo in step with CoreX — but only when either ACTUALLY changed.
            // Best-effort: a profile-push failure must never block the listing this
            // agent is attached to.
            $this->syncAgentIfChanged($user, $p24AgentId, $agencyId, $force);
            return true;
        }

        // Agent opted out of P24 and isn't on the portal yet — never create a
        // fresh record just to immediately unpublish it. The existing-agent
        // branch above already handles the "on P24 but now excluded" case by
        // pushing published=false.
        if ($user->exclude_from_p24) {
            $this->log('info', "Agent #{$user->id} is excluded from P24 and not yet registered — skipping registration");
            return true;
        }

        return $this->registerNewAgent($user, $agencyId);
    }

    /**
     * The P24 agent id for this user UNDER THIS P24 AGENCY, or null when they are
     * not registered there yet.
     *
     * The order is the entire point of this method. The stored
     * (p24_agent_id, p24_agent_agency_id) pair answers the question with ZERO
     * HTTP. Only when that pair is missing do we fall back to scanning P24's
     * agent list — a 610KB response that takes 15–90s (and has timed out at 120s
     * in production). That scan used to sit on the critical path of EVERY listing
     * Refresh; now it is paid at most once per agent per agency, and its result is
     * stamped onto the user so it is never paid again.
     *
     * The pair is agency-scoped because P24 scopes agents per agency: the same
     * CoreX user co-listing under a second branch's P24 agency is a DIFFERENT
     * P24 agent, and a bare id would silently address the wrong one.
     */
    private function resolveRegisteredAgentId(User $user, int $agencyId): ?int
    {
        if (!empty($user->p24_agent_id) && (int) $user->p24_agent_agency_id === $agencyId) {
            return (int) $user->p24_agent_id;
        }

        // Scoping the lookup to the right agency is critical — P24 enforces
        // firstname+lastname uniqueness per agency, so a lookup against the wrong
        // agency would miss the existing agent and trigger a duplicate-name error
        // on create.
        $existingResult = $this->client->getAgents((string) $agencyId);
        if (!($existingResult['success'] ?? false)) {
            return null;
        }

        foreach ($existingResult['data'] ?? [] as $existing) {
            if (($existing['sourceReference'] ?? '') !== 'CoreX-Agent-' . $user->id) {
                continue;
            }
            $p24AgentId = (int) ($existing['id'] ?? 0);
            if ($p24AgentId <= 0) {
                continue;
            }
            $this->log('info', "Agent #{$user->id} already registered on P24 agency {$agencyId} as #{$p24AgentId}");
            $this->rememberAgentId($user, $p24AgentId, $agencyId);

            return $p24AgentId;
        }

        return null;
    }

    /**
     * Record the resolved (P24 agent id, P24 agency) pair so no future submit ever
     * re-scans P24's agent list for this agent.
     *
     * saveQuietly: this is a cache stamp, not a user edit — it must not fire the
     * User observer, which would dispatch SyncAgentToP24Job and push the agent
     * straight back to P24 in a loop.
     */
    private function rememberAgentId(User $user, int $p24AgentId, int $agencyId): void
    {
        $user->forceFill([
            'p24_agent_id'        => $p24AgentId,
            'p24_agent_agency_id' => $agencyId,
        ])->saveQuietly();
    }

    /**
     * Bring an already-registered agent's profile and photo into step with CoreX —
     * sending ONLY what actually changed.
     *
     * This is the agent-side of the contract `properties.p24_image_signature`
     * already gives the photo gallery: CoreX never re-sends bytes P24 already
     * holds. An unchanged agent costs ZERO P24 calls, which is what keeps a
     * routine listing Refresh at one round-trip (the listing POST) instead of the
     * five it grew to — PUT /agents + PUT /agents/{id}/profile-picture, per agent,
     * on every single refresh, re-uploading an identical photo each time.
     *
     * $force (the explicit Users-page "Sync to P24" button) re-pushes regardless.
     * It is the repair path for a profile edited on the P24 portal behind CoreX's
     * back — a change no CoreX-side fingerprint can possibly detect.
     *
     * Returns true, or an error string when the profile push failed.
     */
    private function syncAgentIfChanged(
        User $user,
        int $p24AgentId,
        int $agencyId,
        bool $force = false,
        bool $pushPhoto = true,
    ): bool|string {
        $payload    = $this->agentProfilePayload($user, $p24AgentId, $agencyId);
        $profileSig = md5((string) json_encode($payload));

        if ($force || $profileSig !== $user->p24_profile_signature) {
            $result = $this->client->updateAgent($payload);
            if (!($result['success'] ?? false)) {
                $this->log('error', "Agent profile push failed for #{$user->id}", ['result' => $result]);
                return $result['message'] ?? 'Unknown agent update error';
            }
            $user->forceFill(['p24_profile_signature' => $profileSig])->saveQuietly();
        } else {
            $this->log('info', "Agent #{$user->id} profile unchanged — skipping P24 profile push");
        }

        if ($pushPhoto) {
            $this->pushAgentPhotoIfChanged($user, $p24AgentId, $force);
        }

        return true;
    }

    /**
     * Upload the agent's photo only when the bytes P24 holds are not the bytes we
     * hold. See agentPhotoSignature for how "the bytes P24 holds" is fingerprinted
     * without reading (or re-fetching) the file on every refresh.
     */
    private function pushAgentPhotoIfChanged(User $user, int $p24AgentId, bool $force = false): void
    {
        $signature = $this->agentPhotoSignature($user);

        if ($signature === null) {
            $this->log('info', "Agent #{$user->id} has no profile photo (user_documents or agent_photo_path) — skipping P24 photo upload");
            return;
        }

        if (!$force && $signature === $user->p24_photo_signature) {
            $this->log('info', "Agent #{$user->id} photo unchanged — skipping P24 photo upload");
            return;
        }

        if ($this->uploadAgentPhotoIfAvailable($user, $p24AgentId)) {
            $user->forceFill(['p24_photo_signature' => $signature])->saveQuietly();
        }
    }

    /**
     * Register an agent that is not on P24 yet (create, or adopt a same-named
     * record P24 already has).
     *
     * The new agent's profile signature is deliberately NOT stamped here: the
     * CREATE payload is a subset of the full profile payload (no status /
     * workNumber / faxNumber), so leaving the signature null makes the agent's
     * next submit perform exactly one PUT to bring the full profile across — and
     * stamp it then. One call, once, per newly-registered agent.
     */
    private function registerNewAgent(User $user, int $agencyId): string|bool
    {
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

        $this->log('info', "Registering agent #{$user->id} ({$user->name}) on P24 agency {$agencyId}");
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
                    $this->rememberAgentId($user, (int) $adoptedId, $agencyId);
                    $this->pushAgentPhotoIfChanged($user, (int) $adoptedId);
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
            $this->rememberAgentId($user, (int) $p24AgentId, $agencyId);
            $this->pushAgentPhotoIfChanged($user, (int) $p24AgentId);
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
        if ($agencyId === null) {
            return null;
        }

        // Column first, agent-list scan only as a fallback — see
        // resolveRegisteredAgentId. SyncAgentToP24Job calls this on EVERY user
        // edit purely to ask "is this agent on P24 at all?"; that question must
        // not cost a 90s full-list fetch.
        return $this->resolveRegisteredAgentId($user, (int) $agencyId);
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
        $agencyId = (int) $agencyId;

        $p24AgentId = $this->resolveRegisteredAgentId($user, $agencyId);
        if (!$p24AgentId) {
            // Not registered yet — create them; that flow also uploads the photo.
            return $this->ensureAgentRegisteredByUser($user, $agencyId, force: true);
        }

        // An EXPLICIT sync always re-pushes, changed or not: this is the button an
        // admin hits to repair a profile someone edited on the P24 portal behind
        // CoreX's back — a divergence no CoreX-side fingerprint can see.
        return $this->syncAgentIfChanged($user, $p24AgentId, $agencyId, force: true, pushPhoto: $pushPhoto);
    }

    /**
     * Build the COMPLETE P24 agent payload from the CoreX user.
     * Single source of truth for "a fully-synced P24 agent profile" — shared by
     * the Users-page "Sync to P24" button (updateAgentOnP24) and the
     * listing-submit path (ensureAgentRegisteredByUser), so an agent carries the
     * same name / contact / jobTitle / active-status regardless of which path
     * last touched them.
     *
     * It is also what gets fingerprinted (p24_profile_signature): the payload IS
     * the thing P24 holds, so hashing it is an exact answer to "would this PUT
     * change anything?" — no field-by-field drift to keep in step.
     */
    private function agentProfilePayload(User $user, int $p24AgentId, int $agencyId): array
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

        return $payload;
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
    private function uploadAgentPhotoIfAvailable(User $user, int $p24AgentId): bool
    {
        $photoPath = $this->resolveAgentPhotoPath($user);

        if ($photoPath === null) {
            $this->log('info', "Agent #{$user->id} has no profile photo (user_documents or agent_photo_path) — skipping P24 photo upload");
            return false;
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
            return false;
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

        if ($result['success'] ?? false) {
            $this->log('info', "Agent photo uploaded for #{$user->id} (P24 agent #{$p24AgentId})");
            return true;
        }

        $this->log('warning', "Agent photo upload failed for #{$user->id}: " . ($result['message'] ?? 'Unknown'));
        return false;
    }

    /**
     * The photo file this agent's P24 profile picture should come from, or null
     * when they have no photo at all.
     *
     * Resolved from the SAME canonical source the rest of CoreX uses
     * (User::profilePhotoUrl): a user_documents 'profile_photo' row first, then the
     * legacy agent_photo_path column. The sync once read ONLY agent_photo_path, so
     * every agent whose photo lives in user_documents reached P24 with no photo.
     * Pick the first candidate whose file actually EXISTS on disk — these two
     * records routinely desync (a stale .jpg path recorded while the real
     * normalised file is photo.webp), so trusting the document path blindly would
     * upload nothing. If neither resolves on disk, keep the preferred path so the
     * URL-fallback strategies in uploadAgentPhotoIfAvailable can still try.
     */
    private function resolveAgentPhotoPath(User $user): ?string
    {
        $candidates = array_values(array_filter([
            $this->agentPhotoDocument($user)?->file_path,
            $user->agent_photo_path,
        ], fn ($p) => !empty($p)));

        if (empty($candidates)) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (Storage::disk('public')->exists($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    private function agentPhotoDocument(User $user): ?object
    {
        return $user->documents()
            ->where('document_type', 'profile_photo')
            ->latest()
            ->first();
    }

    /**
     * Fingerprint of the photo bytes P24 should be holding for this agent, or null
     * when the agent has no photo. Compared against the stored
     * `p24_photo_signature` so a routine refresh re-uploads the profile picture
     * only when it actually changed.
     *
     * Two cases, because the file may not live on THIS host:
     *
     *  • On disk (the normal case): path + size + mtime. Exact, and free — no file
     *    read, so it is safe to compute on every submit.
     *  • Not on disk (split-host installs, where uploadAgentPhotoIfAvailable falls
     *    back to fetching the photo over HTTP): we cannot fingerprint bytes we do
     *    not hold without paying that fetch — which is precisely the cost we are
     *    removing. Fingerprint the REFERENCE instead: the path plus the timestamp
     *    of the profile_photo document (or the user row) that points at it. A photo
     *    replacement in CoreX always touches one of those, even though the
     *    normalised filename (agents/{id}/photo.webp) stays the same. The explicit
     *    "Sync to P24" button ($force) re-uploads regardless, so the repair path is
     *    always one click away.
     */
    private function agentPhotoSignature(User $user): ?string
    {
        $path = $this->resolveAgentPhotoPath($user);
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('public');

        if ($disk->exists($path)) {
            return 'disk:' . md5(implode('|', [$path, (string) $disk->size($path), (string) $disk->lastModified($path)]));
        }

        $touchedAt = $this->agentPhotoDocument($user)?->updated_at
            ?? $user->updated_at;

        return 'ref:' . md5($path . '|' . ($touchedAt?->getTimestamp() ?? 0));
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
