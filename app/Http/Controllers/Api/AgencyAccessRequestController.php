<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyAccessRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * API for the cross-agency access consent flow.
 * See .ai/specs/agency-access-authorization-spec.md.
 *
 * v1 gate: only owner-role users may initiate requests. The
 * `platform.cross_agency_access` permission is deferred per spec decision #6.
 */
class AgencyAccessRequestController extends Controller
{
    /**
     * Inspect a target agency and tell the requester whether to switch
     * directly or pick admins first.
     *
     * GET /api/v1/agency-access/inspect/{agency}
     */
    public function inspect(Agency $agency): JsonResponse
    {
        $user = auth()->user();
        if (!$user || !$user->isOwnerRole()) {
            return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        if (!$agency->requiresExternalAccessAuthorization()) {
            return response()->json([
                'ok'      => true,
                'mode'    => 'instant',
                'agency'  => ['id' => $agency->id, 'name' => $agency->name],
            ]);
        }

        // Persistent 24h grant: if this requester already has an approved
        // request for this agency still within its grant window, walk straight
        // back in — no re-authorization. The grant survives switching away.
        $liveGrant = AgencyAccessRequest::query()
            ->byRequester($user->id)
            ->forAgency($agency->id)
            ->where('status', AgencyAccessRequest::STATUS_APPROVED)
            ->where('granted_session_expires_at', '>', now())
            ->latest('granted_session_expires_at')
            ->first();
        if ($liveGrant) {
            return response()->json([
                'ok'                 => true,
                'mode'               => 'instant',
                'agency'             => ['id' => $agency->id, 'name' => $agency->name],
                'grant_active_until' => $liveGrant->granted_session_expires_at->toIso8601String(),
                'grant_request_id'   => $liveGrant->id,
            ]);
        }

        // Cross-agency by design: bypass AgencyScope so the requester (an
        // owner inside another agency) can see the target agency's admins.
        $admins = User::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->where('agency_id', $agency->id)
            ->where('role', 'admin')
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]);

        if ($admins->isEmpty()) {
            return response()->json([
                'ok'    => false,
                'error' => 'This agency requires authorization but has no Admin to ask. Contact a system owner.',
            ], 422);
        }

        return response()->json([
            'ok'     => true,
            'mode'   => 'consent',
            'agency' => ['id' => $agency->id, 'name' => $agency->name],
            'admins' => $admins,
        ]);
    }

    /**
     * Create a pending request after the requester picks admins.
     *
     * POST /api/v1/agency-access/request
     */
    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user || !$user->isOwnerRole()) {
            return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'target_agency_id' => 'required|integer|exists:agencies,id',
            'admin_user_ids'   => 'required|array|min:1',
            'admin_user_ids.*' => 'integer|exists:users,id',
            'reason'           => 'nullable|string|max:1000',
        ]);

        $agency = Agency::findOrFail($data['target_agency_id']);
        if (!$agency->requiresExternalAccessAuthorization()) {
            return response()->json(['ok' => false, 'error' => 'Agency does not require authorization.'], 422);
        }

        // Validate every selected admin actually belongs to the target agency
        // and currently holds admin role + is active.
        $validAdminIds = User::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->whereIn('id', $data['admin_user_ids'])
            ->where('agency_id', $agency->id)
            ->where('role', 'admin')
            ->where('is_active', 1)
            ->pluck('id')
            ->all();
        if (count($validAdminIds) !== count($data['admin_user_ids'])) {
            return response()->json(['ok' => false, 'error' => 'One or more selected admins are invalid.'], 422);
        }

        // Reuse a still-pending request from this requester to this agency
        $existing = AgencyAccessRequest::byRequester($user->id)
            ->forAgency($agency->id)
            ->pending()
            ->notExpired()
            ->latest()
            ->first();
        if ($existing) {
            return response()->json([
                'ok'         => true,
                'reused'     => true,
                'request_id' => $existing->id,
                'expires_at' => $existing->expires_at->toIso8601String(),
            ]);
        }

        $req = DB::transaction(function () use ($user, $agency, $validAdminIds, $data) {
            $req = AgencyAccessRequest::create([
                'target_agency_id'  => $agency->id,
                'requester_user_id' => $user->id,
                'requester_role'    => $user->role ?? 'system_owner',
                'status'            => AgencyAccessRequest::STATUS_PENDING,
                'reason'            => $data['reason'] ?? null,
                'expires_at'        => now()->addMinutes(AgencyAccessRequest::PENDING_TTL_MINUTES),
            ]);
            $req->targetedAdmins()->attach($validAdminIds);
            return $req;
        });

        Log::info('agency_access_requested', [
            'request_id'        => $req->id,
            'target_agency_id'  => $agency->id,
            'requester_user_id' => $user->id,
            'admin_user_ids'    => $validAdminIds,
            'reason'            => $req->reason,
        ]);

        return response()->json([
            'ok'         => true,
            'request_id' => $req->id,
            'expires_at' => $req->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Polled by the requester every 3s to learn the request's status.
     *
     * GET /api/v1/agency-access/{request}/status
     */
    public function status(AgencyAccessRequest $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user || (int) $request->requester_user_id !== (int) $user->id) {
            return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        // Auto-expire on read so requesters get an immediate signal even if
        // the scheduler hasn't fired yet.
        if ($request->isPending() && $request->expires_at->isPast()) {
            $request->markExpired();
            Log::info('agency_access_expired', ['request_id' => $request->id]);
        }

        return response()->json([
            'ok'             => true,
            'status'         => $request->status,
            'denial_reason'  => $request->denial_reason,
            'expires_at'     => $request->expires_at->toIso8601String(),
            'authorized_at'  => $request->authorized_at?->toIso8601String(),
            'agency'         => [
                'id'   => $request->target_agency_id,
                'name' => optional($request->targetAgency)->name,
            ],
        ]);
    }

    /**
     * Requester cancels their own pending request.
     *
     * POST /api/v1/agency-access/{request}/cancel
     */
    public function cancel(AgencyAccessRequest $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user || (int) $request->requester_user_id !== (int) $user->id) {
            return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        if (!$request->isPending()) {
            return response()->json(['ok' => false, 'error' => 'Request is no longer pending.'], 422);
        }

        $request->markCancelled();
        Log::info('agency_access_cancelled', ['request_id' => $request->id, 'by' => $user->id]);

        return response()->json(['ok' => true]);
    }

    /**
     * Admin inbox — pending requests targeted at the current admin.
     *
     * GET /api/v1/agency-access/inbox
     */
    public function inbox(): JsonResponse
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'admin' || !$user->agency_id) {
            return response()->json(['ok' => true, 'requests' => []]);
        }

        $requests = AgencyAccessRequest::query()
            ->forAgency($user->agency_id)
            ->pending()
            ->notExpired()
            ->whereHas('targetedAdmins', fn ($q) => $q->where('admin_user_id', $user->id))
            ->with('requester:id,name,email,role')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($r) => [
                'id'             => $r->id,
                'requester'      => [
                    'id'    => $r->requester?->id,
                    'name'  => $r->requester?->name,
                    'email' => $r->requester?->email,
                    'role'  => $r->requester_role,
                ],
                'reason'         => $r->reason,
                'expires_at'     => $r->expires_at->toIso8601String(),
                'created_at'     => $r->created_at->toIso8601String(),
            ]);

        return response()->json(['ok' => true, 'requests' => $requests]);
    }

    /**
     * Admin approves or denies. Row-locked so two admins can't double-approve.
     *
     * POST /api/v1/agency-access/{request}/authorize
     */
    public function authorize(AgencyAccessRequest $request, Request $http): JsonResponse
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'admin' || (int) $user->agency_id !== (int) $request->target_agency_id) {
            return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        // Must have been a targeted admin
        $isTargeted = $request->targetedAdmins()->where('admin_user_id', $user->id)->exists();
        if (!$isTargeted) {
            return response()->json(['ok' => false, 'error' => 'You are not authorised to act on this request.'], 403);
        }

        $data = $http->validate([
            'decision'      => 'required|in:approve,deny',
            'denial_reason' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($request, $user, $data) {
            $fresh = AgencyAccessRequest::lockForUpdate()->find($request->id);
            if (!$fresh || !$fresh->isPending()) {
                return response()->json(['ok' => false, 'error' => 'Already handled.'], 409);
            }
            if ($fresh->expires_at->isPast()) {
                $fresh->markExpired();
                return response()->json(['ok' => false, 'error' => 'Request expired.'], 410);
            }

            if ($data['decision'] === 'approve') {
                $fresh->markApproved($user->id);
                Log::info('agency_access_authorized', [
                    'request_id'        => $fresh->id,
                    'target_agency_id'  => $fresh->target_agency_id,
                    'requester_user_id' => $fresh->requester_user_id,
                    'admin_id'          => $user->id,
                ]);
            } else {
                $fresh->markDenied($user->id, $data['denial_reason'] ?? null);
                Log::info('agency_access_denied', [
                    'request_id'    => $fresh->id,
                    'admin_id'      => $user->id,
                    'denial_reason' => $fresh->denial_reason,
                ]);
            }

            return response()->json(['ok' => true, 'status' => $fresh->status]);
        });
    }

    /**
     * Requester finalises the switch after an admin approved.
     *
     * POST /api/v1/agency-access/{request}/confirm-switch
     */
    public function confirmSwitch(AgencyAccessRequest $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user || (int) $request->requester_user_id !== (int) $user->id) {
            return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        if (!$user->isOwnerRole()) {
            return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        if (!$request->isApproved()) {
            return response()->json(['ok' => false, 'error' => 'Request is not approved.'], 422);
        }
        if (!$request->granted_session_expires_at || $request->granted_session_expires_at->isPast()) {
            return response()->json(['ok' => false, 'error' => 'Granted session has expired.'], 410);
        }

        session([
            'active_agency_id'             => (int) $request->target_agency_id,
            'agency_access_request_id'     => $request->id,
            'agency_access_grant_until'    => $request->granted_session_expires_at->timestamp,
        ]);

        return response()->json([
            'ok'        => true,
            'agency_id' => $request->target_agency_id,
            'redirect'  => route('corex.dashboard'),
        ]);
    }
}
