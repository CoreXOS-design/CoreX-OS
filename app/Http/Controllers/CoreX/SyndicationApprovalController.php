<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\PermissionService;
use App\Services\Syndication\SyndicationApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Layer 3 — the five transitions of the syndication approval gate.
 * Spec: .ai/specs/syndication-approval-gate.md §5, §6.2.
 *
 * There is no queue SCREEN here by design (spec D7): the Properties list,
 * filtered to `?filter=approval_pending`, IS the queue. This controller only
 * owns the writes.
 *
 * Authority (spec §6.2) is ONE rule — SyndicationApprovalService::canApprove()
 * — resolved from the agency's chosen-approver roster, with the standing
 * owner/agency-admin fallback. The roster is the single source of truth; there
 * is deliberately no second role-matrix permission for "may approve", because
 * two lists of who approves would drift.
 */
class SyndicationApprovalController extends Controller
{
    public function __construct(private SyndicationApprovalService $service)
    {
    }

    /** Agent: "Send for approval" — only once compliance is complete (D5). */
    public function request(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);
        $this->assertFeatureOn($property);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! $this->service->canRequest($property)) {
            return response()->json([
                'success' => false,
                'message' => $this->service->isApproved($property)
                    ? 'This listing is already approved for syndication.'
                    : 'This listing is not ready to send for approval — finish its compliance first.',
            ], 422);
        }

        $this->service->request($property, $request->user(), $data['note'] ?? null);

        return $this->state($property, 'Sent for approval.');
    }

    /** Agent: withdraw their own pending request. */
    public function cancel(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);
        $this->assertFeatureOn($property);

        $requesterId = $this->service->pendingRequesterId($property);

        if ($requesterId === null) {
            return response()->json([
                'success' => false,
                'message' => 'There is no pending approval request to cancel.',
            ], 422);
        }

        // Only the person who raised the request (or an approver) may withdraw it.
        abort_unless(
            $requesterId === (int) $request->user()->id || $this->service->canApprove($request->user(), $property),
            403
        );

        if (! $this->service->cancel($property, $request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'There is no pending approval request to cancel.',
            ], 422);
        }

        return $this->state($property, 'Approval request cancelled.');
    }

    /** Approver: approve — the stamp that unlocks every portal, permanently. */
    public function approve(Request $request, Property $property): JsonResponse
    {
        $this->assertCanApprove($request, $property);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($this->service->isApproved($property)) {
            return $this->state($property, 'This listing is already approved.');
        }

        $this->service->approve($property, $request->user(), $data['note'] ?? null);

        return $this->state($property->fresh(), 'Approved for syndication.');
    }

    /** Approver: reject — the reason is mandatory and reaches the agent. */
    public function reject(Request $request, Property $property): JsonResponse
    {
        $this->assertCanApprove($request, $property);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        if (! $this->service->reject($property, $request->user(), $data['reason'])) {
            return response()->json([
                'success' => false,
                'message' => 'There is no pending approval request on this listing, so nothing was rejected.',
            ], 422);
        }

        return $this->state($property->fresh(), 'Listing not approved — the agent has been told why.');
    }

    /**
     * Approver: revoke an approval already given. Stops the listing going
     * anywhere NEW; deliberately does not pull it off a portal it already
     * reached (spec §5.5).
     */
    public function revoke(Request $request, Property $property): JsonResponse
    {
        $this->assertCanApprove($request, $property);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        if (! $this->service->isApproved($property)) {
            return response()->json([
                'success' => false,
                'message' => 'This listing is not approved, so there is nothing to withdraw.',
            ], 422);
        }

        $this->service->revoke($property, $request->user(), $data['reason']);

        return $this->state($property->fresh(), 'Approval withdrawn. Anything already live on a portal is untouched.');
    }

    // ── Guards ──────────────────────────────────────────────────────────

    /**
     * Refuse every transition when the agency has not switched layer 3 on —
     * otherwise an approval stamp could be written on an agency that does not
     * use the feature, and would then silently apply if they ever turned it on.
     */
    private function assertFeatureOn(Property $property): void
    {
        abort_unless($this->service->isRequired($property), 404);
    }

    private function assertCanApprove(Request $request, Property $property): void
    {
        $this->assertFeatureOn($property);
        abort_unless($this->service->canApprove($request->user(), $property), 403);
    }

    /**
     * Own / branch / agency scoping for the AGENT-side actions, mirroring the
     * three syndication controllers' own authorizeProperty() exactly. Agency
     * isolation itself comes from the Property route binding (AgencyScope →
     * 404 across agencies).
     */
    private function authorizeProperty(Property $property): void
    {
        $user  = auth()->user();
        $scope = PermissionService::getDataScope($user, 'properties');

        if ($scope === 'all') return;
        if ($scope === 'branch' && (int) $property->branch_id === (int) $user->effectiveBranchId()) return;
        if ($scope === 'own' && (int) $property->agent_id === (int) $user->id) return;

        // An approver may act on any listing they are allowed to see, even one
        // that is not their own — that is the entire point of the role.
        if ($this->service->canApprove($user, $property)) return;

        abort(403);
    }

    private function state(Property $property, string $message): JsonResponse
    {
        return response()->json([
            'success'        => true,
            'message'        => $message,
            'approval_state' => $this->service->stateFor($property)->toArray(),
        ]);
    }
}
