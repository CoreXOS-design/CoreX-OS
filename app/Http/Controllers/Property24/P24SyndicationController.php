<?php

namespace App\Http\Controllers\Property24;

use App\Http\Controllers\Controller;
use App\Jobs\SubmitListingToProperty24;
use App\Models\Property;
use App\Services\PermissionService;
use App\Services\Syndication\Property24\Property24ListingMapper;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class P24SyndicationController extends Controller
{
    use \App\Http\Controllers\Concerns\EnforcesMarketingReadiness;

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
        if ($nowEnabled) { $this->enforceListingNotDraft($property, 'Property24'); $this->enforceMarketingReadiness($property); }
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
        $property->update(['p24_syndication_status' => 'submitting', 'p24_last_error' => null]);
        SubmitListingToProperty24::dispatch($property);

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
        $result = $this->syndicationService->reactivateListing($property);
        return response()->json(['success' => $result['success'], 'message' => $result['message'], 'p24_syndication_status' => $property->fresh()->p24_syndication_status], $result['success'] ? 200 : 422);
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
