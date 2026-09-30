<?php

namespace App\Http\Controllers\Property24;

use App\Http\Controllers\Controller;
use App\Jobs\SubmitListingToProperty24;
use App\Models\Property;
use App\Services\PermissionService;
use App\Services\Syndication\PortalAgentGuard;
use App\Services\Syndication\Property24\Property24ListingMapper;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class P24SyndicationController extends Controller
{
    use \App\Http\Controllers\Concerns\EnforcesMarketingReadiness;
    // Layer 3 — a chosen person must approve before this listing is syndicated.
    // .ai/specs/syndication-approval-gate.md §6.3. Inert unless the agency
    // switched it on.
    use \App\Http\Controllers\Concerns\EnforcesSyndicationApproval;

    private Property24SyndicationService $syndicationService;
    private Property24ListingMapper $mapper;

    public function __construct(Property24SyndicationService $syndicationService, Property24ListingMapper $mapper)
    {
        $this->syndicationService = $syndicationService;
        $this->mapper = $mapper;
    }

    public function toggle(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);
        $nowEnabled = !((bool) $property->p24_syndication_enabled);

        // AT-369 — turning P24 ON while Private Property holds this listing
        // exclusive is refused outright. Turning OFF is never blocked — pulling
        // a listing off a portal only reduces exposure.
        if ($nowEnabled && $property->isPpExclusiveActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot enable Property24 — Private Property exclusivity is active until ' . $property->pp_delay_until->format('d M Y') . '.',
            ], 422);
        }

        if ($nowEnabled) { $this->enforceListingNotDraft($property, 'Property24'); $this->enforceMarketingReadiness($property); $this->enforceSyndicationApproval($property, 'Property24'); }
        $updateData = ['p24_syndication_enabled' => $nowEnabled];

        if ($nowEnabled && $property->p24_syndication_status === null) {
            $updateData['p24_syndication_status'] = 'pending';
        }

        // Switching syndication off must take the listing OFF the portal. Guard on
        // "may still be live" (a p24_ref and no 'deactivated' marker), never on a
        // whitelist of statuses: the old ['submitted','active'] check silently
        // skipped the delist for 'sold'/'rented' (still on the portal), 'pending'
        // and 'error' — leaving the listing live while CoreX reported it removed.
        if (!$nowEnabled && $property->mayBeLiveOnP24()) {
            $result = $this->syndicationService->deactivateListing($property);
            if (!$result['success']) {
                return response()->json(['success' => false, 'message' => 'Failed to deactivate on P24: ' . ($result['message'] ?? 'Unknown error')], 422);
            }
        }

        $property->update($updateData);

        return response()->json([
            'success' => true,
            'p24_syndication_enabled' => $nowEnabled,
            'p24_syndication_status'  => $property->fresh()->p24_syndication_status,
        ]);
    }

    public function submit(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);
        $this->enforceListingNotDraft($property, 'Property24');
        $this->enforceMarketingReadiness($property);
        $this->enforceSyndicationApproval($property, 'Property24');

        // AT-369 — fail fast, before ever queuing the job. The service-layer
        // guard (Property24SyndicationService::submitListing) is the real
        // backstop, but rejecting here means the agent sees the reason
        // immediately instead of watching "submitting…" sit and then error out
        // once the queued job reaches the same check.
        if ($property->isPpExclusiveActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot submit — Private Property exclusivity is active until ' . $property->pp_delay_until->format('d M Y') . '.',
                'p24_syndication_status' => $property->p24_syndication_status,
                'p24_ref' => $property->p24_ref,
            ], 422);
        }

        $missing = $this->mapper->checkReadiness($property);
        if (!empty($missing)) {
            $labels = array_map(fn($m) => $m['label'], $missing);
            $property->update(['p24_syndication_status' => 'error', 'p24_last_error' => 'Missing required fields: ' . implode(', ', $labels)]);
            return response()->json(['success' => false, 'message' => 'Cannot submit — required fields are missing', 'p24_syndication_status' => 'error', 'p24_ref' => $property->p24_ref, 'errors' => $labels, 'missing_fields' => $missing], 422);
        }

        // Queue the push instead of running it synchronously. P24's saveListing
        // takes 1-2 minutes to ingest a photo-heavy listing, which would
        // otherwise block the browser for the whole request. Mark 'submitting'
        // so the UI shows a syncing state and polls sync-state until the queued
        // SubmitListingToProperty24 job flips the status to active/error.
        // Portal Agent Mismatch Guard — ask BEFORE queuing, while the user is here.
        $confirmSwitch = $request->boolean('confirm_agent_switch');
        if ($blocked = $this->agentConflictResponse($property, $confirmSwitch)) {
            return $blocked;
        }

        $property->update(['p24_syndication_status' => 'submitting', 'p24_last_error' => null]);
        SubmitListingToProperty24::dispatch($property, $confirmSwitch);

        return response()->json([
            'success' => true,
            'queued'  => true,
            'message' => 'Syncing to Property24… this can take up to a minute.',
            'p24_syndication_status' => 'submitting',
            'p24_ref' => $property->p24_ref,
        ], 202);
    }

    /**
     * Lightweight DB-only status read for the frontend to poll after a queued
     * submit. Unlike status(), this does NOT call P24 — it just reflects the
     * columns the SubmitListingToProperty24 job writes when it finishes, so it
     * is cheap enough to hit every few seconds.
     */
    public function syncState(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);
        $fresh = $property->fresh();
        return response()->json([
            'p24_syndication_status' => $fresh->p24_syndication_status,
            'p24_ref'                => $fresh->p24_ref,
            'p24_last_error'         => $fresh->p24_last_error,
            'p24_last_submitted_at'  => $fresh->p24_last_submitted_at?->format('d M Y H:i'),
            'agent_conflict'         => app(PortalAgentGuard::class)->current($fresh, PortalAgentGuard::P24),
        ]);
    }

    public function readiness(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);
        $missing = $this->mapper->checkReadiness($property);
        return response()->json(['ready' => empty($missing), 'missing_fields' => $missing]);
    }

    public function deactivate(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);
        $result = $this->syndicationService->deactivateListing($property);
        return response()->json(['success' => $result['success'], 'message' => $result['message'], 'p24_syndication_status' => $property->fresh()->p24_syndication_status], $result['success'] ? 200 : 422);
    }

    public function reactivate(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);
        $this->enforceListingNotDraft($property, 'Property24');
        $this->enforceMarketingReadiness($property);
        $this->enforceSyndicationApproval($property, 'Property24');

        // Portal Agent Mismatch Guard. A confirmed switch sends the full listing
        // (that is what carries the agent) and then puts it back on the market —
        // queued, because the full send can take a minute with photos.
        $confirmSwitch = $request->boolean('confirm_agent_switch');
        if ($blocked = $this->agentConflictResponse($property, $confirmSwitch)) {
            return $blocked;
        }
        if ($confirmSwitch && app(PortalAgentGuard::class)->check($property, PortalAgentGuard::P24) !== null) {
            if ($property->isPpExclusiveActive()) {
                return response()->json(['success' => false, 'message' => 'Cannot reactivate — Private Property exclusivity is active until ' . $property->pp_delay_until->format('d M Y') . '.', 'p24_syndication_status' => $property->p24_syndication_status], 422);
            }
            $property->update(['p24_syndication_status' => 'submitting', 'p24_last_error' => null]);
            SubmitListingToProperty24::dispatch($property, true, true);
            return response()->json([
                'success' => true,
                'queued'  => true,
                'message' => 'Sending to Property24 under the listing agent… this can take up to a minute.',
                'p24_syndication_status' => 'submitting',
            ], 202);
        }

        $result = $this->syndicationService->reactivateListing($property);
        return response()->json([
            'success'                => $result['success'],
            'message'                => $result['message'],
            'p24_syndication_status' => $property->fresh()->p24_syndication_status,
            'agent_conflict'         => $result['agent_conflict'] ?? null,
        ], $result['success'] ? 200 : (isset($result['agent_conflict']) ? 409 : 422));
    }

    /**
     * 409 + the conflict when the send must not go ahead as asked: a listing
     * agent is inactive in CoreX (never overridable), or Property24 holds the
     * listing under a different agent and the user has not confirmed the switch.
     * .ai/specs/portal-agent-mismatch-guard.md §4
     */
    private function agentConflictResponse(Property $property, bool $confirmSwitch): ?JsonResponse
    {
        $guard    = app(PortalAgentGuard::class);
        $conflict = $guard->check($property, PortalAgentGuard::P24);

        if ($conflict === null || ($confirmSwitch && $conflict['can_switch'])) {
            return null;
        }

        $guard->recordConflict($property, PortalAgentGuard::P24, $conflict);

        return response()->json([
            'success'                => false,
            'message'                => $conflict['message'],
            'agent_conflict'         => $conflict,
            'p24_syndication_status' => $property->p24_syndication_status,
            'p24_ref'                => $property->p24_ref,
        ], 409);
    }

    public function status(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);
        $result = $this->syndicationService->syncActivationStatus($property);
        $fresh = $property->fresh();
        return response()->json([
            'success' => $result['success'], 'message' => $result['message'] ?? '',
            'p24_syndication_status' => $fresh->p24_syndication_status,
            'p24_ref' => $fresh->p24_ref,
            'p24_activated_at' => $fresh->p24_activated_at?->format('d M Y H:i'),
            'p24_last_submitted_at' => $fresh->p24_last_submitted_at?->format('d M Y H:i'),
            'p24_last_error' => $fresh->p24_last_error,
        ]);
    }

    private function authorizeProperty(Property $property): void
    {
        $user  = auth()->user();
        $scope = PermissionService::getDataScope($user, 'properties');
        if ($scope === 'all') return;
        if ($scope === 'branch' && (int) $property->branch_id === (int) $user->effectiveBranchId()) return;
        if ($scope === 'own' && (int) $property->agent_id === (int) $user->id) return;
        abort(403);
    }
}
