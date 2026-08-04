<?php

namespace App\Http\Controllers\PrivateProperty;

use App\Http\Controllers\Controller;
use App\Jobs\PollPrivatePropertyActivation;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyndicationController extends Controller
{
    use \App\Http\Controllers\Concerns\EnforcesMarketingReadiness;

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

        // Gate: only enforce when ENABLING syndication (disabling is always allowed)
        if (!$wasEnabled) {
            $this->enforceListingNotDraft($property, 'Private Property');
            $this->enforceMarketingReadiness($property);
        }
        $nowEnabled = !$wasEnabled;

        $updateData = ['pp_syndication_enabled' => $nowEnabled];

        // If enabling and status is null, set to pending
        if ($nowEnabled && $property->pp_syndication_status === null) {
            $updateData['pp_syndication_status'] = 'pending';
        }

        // Switching syndication off must take the listing OFF the portal. Guard on
        // "may still be live" (a pp_ref and no 'deactivated' marker), never on a
        // whitelist of statuses — the old ['submitted','active'] check skipped the
        // delist for 'pending'/'error' and left the listing live on PP.
        if (!$nowEnabled && $property->mayBeLiveOnPp()) {
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
        $this->enforceListingNotDraft($property, 'Private Property');
        $this->enforceMarketingReadiness($property);

        if ($errorResponse = $this->validateAndSaveExclusiveDays($request, $property)) {
            return $errorResponse;
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

        $fresh = $property->fresh();

        // If submission succeeded but PP didn't return a ref yet (the common async case),
        // queue an auto-poll so the badge flips to "active" without the user clicking Refresh.
        if ($result['success'] && $fresh->pp_syndication_status === 'submitted' && empty($fresh->pp_ref)) {
            PollPrivatePropertyActivation::start($property->id);
        }

        return response()->json([
            'success'               => $result['success'],
            'message'               => $result['message'],
            'pp_syndication_status' => $fresh->pp_syndication_status,
            'pp_ref'                => $fresh->pp_ref,
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
        $this->enforceListingNotDraft($property, 'Private Property');
        $this->enforceMarketingReadiness($property);

        if ($errorResponse = $this->validateAndSaveExclusiveDays($request, $property)) {
            return $errorResponse;
        }

        $result = $this->syndicationService->reactivateListing($property);

        $fresh = $property->fresh();

        if ($result['success'] && $fresh->pp_syndication_status === 'submitted' && empty($fresh->pp_ref)) {
            PollPrivatePropertyActivation::start($property->id);
        }

        // AT-369 — ListingStatusUpdate (reactivate) only flips PP's status; it
        // never carries listing CONTENT (price, description,
        // SoleMandateExclusiveDays, ...). A prior version of this method chased
        // a successful reactivate with a full submitListing() call to push
        // content in the same action — reverted: submitListing() unconditionally
        // writes pp_syndication_status='error' on ANY internal failure
        // (validation, SOAP fault), which would have overwritten a genuinely
        // successful reactivation with a false error status — the exact
        // audit-truth violation this codebase's AT-68 rule exists to prevent
        // ("never write a status that did not occur"). So: reactivate stays a
        // pure, safe status flip. If exclusivity was just set, tell the agent
        // it still needs a Refresh to actually reach PP.
        $message = $result['message'] ?? '';
        if ($result['success'] && $request->has('pp_exclusive_days') && (int) $request->input('pp_exclusive_days') > 0) {
            $message = trim($message . ' Click Refresh to push the exclusivity setting to Private Property.');
        }

        return response()->json([
            'success'               => $result['success'],
            'message'               => $message,
            'pp_syndication_status' => $fresh->pp_syndication_status,
            'pp_ref'                => $fresh->pp_ref,
        ], $result['success'] ? 200 : 422);
    }

    /**
     * AT-369 — shared validation for the agent opt-in exclusivity field, used by
     * both submit() and reactivate() (the two entry points that push listing
     * content to PP). Never trusted from the client alone: 0 always clears it
     * (untick is always legal); a positive value must be an integer within
     * 1..agency-max AND the listing must be a sole mandate Sale. Anything else
     * is rejected outright — never a silent drop, never a partial submit that
     * goes out without the exclusivity the agent thought they'd set. Mutates
     * $property in place (via ->update()) and returns null on success, or the
     * JsonResponse the caller should return immediately on failure.
     */
    private function validateAndSaveExclusiveDays(Request $request, Property $property): ?JsonResponse
    {
        if (!$request->has('pp_exclusive_days')) {
            return null;
        }

        $requested = (int) $request->input('pp_exclusive_days');

        if ($requested > 0) {
            $isSoleMandateSale = in_array(strtolower($property->mandate_type ?? ''), ['sole', 'sole mandate'], true)
                && ($property->listing_type ?? 'sale') === 'sale';

            if (!$isSoleMandateSale) {
                return response()->json([
                    'success' => false,
                    'message' => 'Private Property exclusivity is only available for sole mandate Sale listings.',
                ], 422);
            }

            $agencyMax = (int) PerformanceSetting::get('pp_exclusive_days_max', 92, $property->agency_id);

            if ($requested > $agencyMax) {
                return response()->json([
                    'success' => false,
                    'message' => "Exclusive days must be between 1 and {$agencyMax} (your agency's configured maximum).",
                ], 422);
            }

            $property->update(['pp_exclusive_days' => $requested]);
        } else {
            $property->update(['pp_exclusive_days' => null]);
        }

        $property->refresh();

        return null;
    }

    /**
     * Create a showday event for this property (saved locally, synced to PP on submission).
     */
    public function showday(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);

        $request->validate([
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after:start_date',
            'description' => 'nullable|string|max:500',
        ]);

        $showday = $property->showdays()->create([
            'start_date'  => \Carbon\Carbon::parse($request->start_date),
            'end_date'    => \Carbon\Carbon::parse($request->end_date),
            'description' => $request->description ?? 'Open Showday',
            'active'      => true,
        ]);

        $showdays = $property->activeShowdays()->get()->map(fn($s) => [
            'id'          => $s->id,
            'start_date'  => $s->start_date->format('d M Y H:i'),
            'end_date'    => $s->end_date->format('d M Y H:i'),
            'description' => $s->description,
            'active'      => $s->active,
        ]);

        return response()->json([
            'success'  => true,
            'message'  => 'Showday created',
            'showday'  => [
                'id'          => $showday->id,
                'start_date'  => $showday->start_date->format('d M Y H:i'),
                'end_date'    => $showday->end_date->format('d M Y H:i'),
                'description' => $showday->description,
            ],
            'showdays' => $showdays,
        ]);
    }

    /**
     * Delete a showday event.
     */
    public function deleteShowday(Request $request, Property $property, int $showdayId): JsonResponse
    {
        $this->authorizeProperty($property);

        $showday = $property->showdays()->findOrFail($showdayId);
        $showday->delete();

        $showdays = $property->activeShowdays()->get()->map(fn($s) => [
            'id'          => $s->id,
            'start_date'  => $s->start_date->format('d M Y H:i'),
            'end_date'    => $s->end_date->format('d M Y H:i'),
            'description' => $s->description,
            'active'      => $s->active,
        ]);

        return response()->json([
            'success'  => true,
            'message'  => 'Showday removed',
            'showdays' => $showdays,
        ]);
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
