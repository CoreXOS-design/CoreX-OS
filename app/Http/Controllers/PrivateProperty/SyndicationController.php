<?php

namespace App\Http\Controllers\PrivateProperty;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyndicationController extends Controller
{
    private PrivatePropertySyndicationService $syndicationService;
    private PrivatePropertyListingMapper $mapper;

    public function __construct(
        PrivatePropertySyndicationService $syndicationService,
        PrivatePropertyListingMapper $mapper
    ) {
        $this->syndicationService = $syndicationService;
        $this->mapper = $mapper;
    }

    /**
     * Toggle PP syndication enabled/disabled for a property.
     */
    public function toggle(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);

        $wasEnabled = (bool) $property->pp_syndication_enabled;
        $nowEnabled = !$wasEnabled;

        $updateData = ['pp_syndication_enabled' => $nowEnabled];

        // If enabling and status is null, set to pending
        if ($nowEnabled && $property->pp_syndication_status === null) {
            $updateData['pp_syndication_status'] = 'pending';
        }

        // If disabling and currently submitted/active, deactivate on PP
        if (!$nowEnabled && in_array($property->pp_syndication_status, ['submitted', 'active'])) {
            $result = $this->syndicationService->deactivateListing($property);
            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to deactivate on PP: ' . ($result['message'] ?? 'Unknown error'),
                ], 422);
            }
        }

        $property->update($updateData);

        return response()->json([
            'success'                => true,
            'pp_syndication_enabled' => $nowEnabled,
            'pp_syndication_status'  => $property->fresh()->pp_syndication_status,
        ]);
    }

    /**
     * Submit a property listing to Private Property.
     */
    public function submit(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);

        // Save exclusive days if provided
        if ($request->has('pp_exclusive_days')) {
            $property->update([
                'pp_exclusive_days' => $request->input('pp_exclusive_days') ?: null,
            ]);
            $property->refresh();
        }

        // Pre-flight readiness check — block submission if required fields are missing
        $missing = $this->mapper->checkReadiness($property);
        if (!empty($missing)) {
            $labels = array_map(fn($m) => $m['label'], $missing);

            $property->update([
                'pp_syndication_status' => 'error',
                'pp_last_error'         => 'Missing required fields: ' . implode(', ', $labels),
            ]);

            return response()->json([
                'success'               => false,
                'message'               => 'Cannot submit — required fields are missing',
                'pp_syndication_status' => 'error',
                'pp_ref'                => $property->pp_ref,
                'errors'                => $labels,
                'missing_fields'        => $missing,
            ], 422);
        }

        $result = $this->syndicationService->submitListing($property);

        return response()->json([
            'success'               => $result['success'],
            'message'               => $result['message'],
            'pp_syndication_status' => $property->fresh()->pp_syndication_status,
            'pp_ref'                => $property->fresh()->pp_ref,
            'errors'                => $result['errors'] ?? [],
        ], $result['success'] ? 200 : 422);
    }

    /**
     * Return feed-readiness status (missing fields) for a property.
     */
    public function readiness(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);

        $missing = $this->mapper->checkReadiness($property);

        return response()->json([
            'ready'          => empty($missing),
            'missing_fields' => $missing,
        ]);
    }

    /**
     * Deactivate a property listing on Private Property.
     */
    public function deactivate(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);

        $result = $this->syndicationService->deactivateListing($property);

        return response()->json([
            'success'               => $result['success'],
            'message'               => $result['message'],
            'pp_syndication_status' => $property->fresh()->pp_syndication_status,
        ], $result['success'] ? 200 : 422);
    }

    /**
     * Check/sync activation status from Private Property.
     */
    public function status(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);

        $result = $this->syndicationService->syncActivationStatus($property);

        $fresh = $property->fresh();

        return response()->json([
            'success'               => $result['success'],
            'message'               => $result['message'] ?? '',
            'pp_syndication_status' => $fresh->pp_syndication_status,
            'pp_ref'                => $fresh->pp_ref,
            'pp_activated_at'       => $fresh->pp_activated_at?->format('d M Y H:i'),
            'pp_last_submitted_at'  => $fresh->pp_last_submitted_at?->format('d M Y H:i'),
            'pp_last_error'         => $fresh->pp_last_error,
        ]);
    }

    /**
     * Reactivate a deactivated listing on PP.
     */
    public function reactivate(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);

        $result = $this->syndicationService->reactivateListing($property);

        return response()->json([
            'success'               => $result['success'],
            'message'               => $result['message'],
            'pp_syndication_status' => $property->fresh()->pp_syndication_status,
        ], $result['success'] ? 200 : 422);
    }

    /**
     * Submit a showday event to PP for this property.
     */
    public function showday(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);

        $request->validate([
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after:start_date',
            'description' => 'nullable|string|max:500',
        ]);

        $result = $this->syndicationService->submitShowday($property, [
            'start_date'  => \Carbon\Carbon::parse($request->start_date)->format('Y-m-d\TH:i:s'),
            'end_date'    => \Carbon\Carbon::parse($request->end_date)->format('Y-m-d\TH:i:s'),
            'description' => $request->description ?? 'Open Showday',
        ]);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Update address visibility toggles for PP.
     */
    public function updateVisibility(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);

        $property->update([
            'pp_hide_street_name'   => (bool) $request->input('hide_street_name', false),
            'pp_hide_street_number' => (bool) $request->input('hide_street_number', false),
            'pp_hide_complex_name'  => (bool) $request->input('hide_complex_name', false),
            'pp_hide_unit_number'   => (bool) $request->input('hide_unit_number', false),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Address visibility updated',
        ]);
    }

    /**
     * Register/update an agent on PP.
     */
    public function registerAgent(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|exists:users,id']);

        $user = User::findOrFail($request->user_id);
        $result = $this->syndicationService->registerAgent($user);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Deactivate an agent on PP.
     */
    public function deactivateAgent(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|exists:users,id']);

        $user = User::findOrFail($request->user_id);
        $result = $this->syndicationService->registerAgent($user, false);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Upload an agent's profile image to PP.
     */
    public function uploadAgentImage(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'   => 'required|exists:users,id',
            'image_url' => 'required|url',
        ]);

        $user = User::findOrFail($request->user_id);
        $result = $this->syndicationService->uploadAgentImage($user, $request->image_url);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Authorize access to the property — mirrors PropertyController pattern.
     */
    private function authorizeProperty(Property $property): void
    {
        /** @var \App\Models\User $user */
        $user  = auth()->user();
        $scope = PermissionService::getDataScope($user, 'properties');

        if ($scope === 'all') return;
        if ($scope === 'branch' && (int) $property->branch_id === (int) $user->effectiveBranchId()) return;
        if ($scope === 'own' && (int) $property->agent_id === (int) $user->id) return;

        abort(403);
    }
}
