<?php

namespace App\Http\Controllers\Communications;

use App\Http\Controllers\Controller;
use App\Models\Communications\CommsAccessRequest;
use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Services\Communications\CommsAccessGrantService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AT-118 — Flow A: request access to a contact's communications, and the
 * owner/grant_access-holder authorise it (either/or). Session-scoped grant.
 * See .ai/specs/at118-communications-access-gate.md §3.3.
 */
class CommsAccessRequestController extends Controller
{
    public function __construct(protected CommsAccessGrantService $grants) {}

    /**
     * A comms-role user (has communications.view scope) requests access to a
     * contact's threads. POST /api/v1/comms-access/request
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'contact_id'       => 'required|integer|exists:contacts,id',
            'reason'           => 'nullable|string|max:1000',
            // AT-132 — the request targets a SPECIFIC thread (real thread_key) or a
            // null-thread comm (communication_id). Both omitted = legacy whole-contact.
            'thread_key'       => 'nullable|string|max:255',
            'communication_id' => 'nullable|integer',
        ]);

        // Only a communications-capable user may request (a non-comms role such
        // as a plain viewer has no business in the comms archive at all).
        if (PermissionService::getDataScope($user, 'communications') === null
            && !$user->hasPermission('communications.grant_access')) {
            return response()->json(['ok' => false, 'error' => 'You do not have access to communications.'], 403);
        }

        // AgencyScope ensures the contact is in the user's agency (404 otherwise).
        $contact = Contact::findOrFail($data['contact_id']);

        $threadKey = ($data['thread_key'] ?? '') !== '' ? $data['thread_key'] : null;
        $commId    = $data['communication_id'] ?? null;

        // The thread / comm must actually belong to THIS contact (and agency, via
        // AgencyScope on Communication) — no requesting access to an unrelated thread.
        if ($threadKey !== null || $commId !== null) {
            $belongs = Communication::query()->whereNull('purged_at')
                ->whereHas('links', fn ($q) => $q->where('linkable_type', Contact::class)
                                                  ->where('linkable_id', $contact->id))
                ->when($threadKey !== null, fn ($q) => $q->where('thread_key', $threadKey))
                ->when($threadKey === null && $commId !== null, fn ($q) => $q->where('id', $commId))
                ->exists();
            if (!$belongs) {
                return response()->json(['ok' => false, 'error' => 'That conversation was not found on this contact.'], 422);
            }
        }

        $req = $this->grants->requestAccess($user, $contact, $data['reason'] ?? null, $threadKey, $commId);

        return response()->json([
            'ok'         => true,
            'request_id' => $req->id,
            'status'     => $req->status,
            'thread_key' => $req->thread_key,
            'expires_at' => $req->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Owner OR grant_access holder approves/declines (either/or, row-locked so two
     * approvers can't double-act). POST /api/v1/comms-access/{commsAccessRequest}/authorize
     */
    public function authorize(CommsAccessRequest $commsAccessRequest, Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$this->grants->canAuthorize($user, $commsAccessRequest)) {
            return response()->json(['ok' => false, 'error' => 'You are not authorised to act on this request.'], 403);
        }

        $data = $request->validate([
            'decision'      => 'required|in:approve,decline',
            'denial_reason' => 'nullable|string|max:500',
            // AT-132 — the approver picks the grant mode. 'otp' is Wave 2 (not accepted here).
            'grant_mode'    => 'nullable|in:session,always',
        ]);

        return DB::transaction(function () use ($commsAccessRequest, $user, $data) {
            $fresh = CommsAccessRequest::lockForUpdate()->find($commsAccessRequest->id);
            if (!$fresh || !$fresh->isPending()) {
                return response()->json(['ok' => false, 'error' => 'This request has already been handled.'], 409);
            }
            if ($fresh->expires_at->isPast()) {
                $fresh->markExpired();
                return response()->json(['ok' => false, 'error' => 'This request has expired.'], 410);
            }

            if ($data['decision'] === 'approve') {
                $this->grants->approve($fresh, $user, $data['grant_mode'] ?? CommsAccessRequest::MODE_SESSION);
            } else {
                $this->grants->decline($fresh, $user, $data['denial_reason'] ?? null);
            }

            return response()->json(['ok' => true, 'status' => $fresh->status]);
        });
    }

    /**
     * AT-132 — explicit revoke of a live grant. The requester (self), the owning
     * agent of the thread, or a communications.grant_access holder may revoke. The
     * only way to end an 'always' grant. Audited (EVENT_REVOKE).
     * POST /api/v1/comms-access/{commsAccessRequest}/revoke
     */
    public function revoke(CommsAccessRequest $commsAccessRequest, Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$this->grants->canRevoke($user, $commsAccessRequest)) {
            return response()->json(['ok' => false, 'error' => 'You are not authorised to revoke this access.'], 403);
        }

        $data = $request->validate(['reason' => 'nullable|string|max:255']);

        return DB::transaction(function () use ($commsAccessRequest, $user, $data) {
            $fresh = CommsAccessRequest::lockForUpdate()->find($commsAccessRequest->id);
            if (!$fresh || !$fresh->isLiveGrant()) {
                return response()->json(['ok' => false, 'error' => 'This access is no longer active.'], 409);
            }
            $this->grants->revokeGrant($fresh, $user, $data['reason'] ?? 'manual_revoke');

            return response()->json(['ok' => true, 'status' => $fresh->status]);
        });
    }

    /**
     * Requester polls their request's status. GET /api/v1/comms-access/{commsAccessRequest}/status
     */
    public function status(CommsAccessRequest $commsAccessRequest, Request $request): JsonResponse
    {
        $user = $request->user();
        if ((int) $commsAccessRequest->requester_user_id !== (int) $user->id) {
            return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        if ($commsAccessRequest->isPending() && $commsAccessRequest->expires_at->isPast()) {
            $commsAccessRequest->markExpired();
        }

        return response()->json([
            'ok'            => true,
            'status'        => $commsAccessRequest->status,
            'denial_reason' => $commsAccessRequest->denial_reason,
            'granted_until' => optional($commsAccessRequest->granted_session_expires_at)->toIso8601String(),
        ]);
    }

    /**
     * Approver inbox — pending requests in the agency this user may authorise.
     * GET /comms-access/inbox  (name: corex.comms-access.inbox)
     */
    public function inbox(Request $request)
    {
        $user = $request->user();

        $pending = CommsAccessRequest::query()
            ->pending()
            ->where('expires_at', '>', now())
            ->with(['requester:id,name,email', 'contact:id,first_name,last_name'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn ($req) => $this->grants->canAuthorize($user, $req))
            ->values();

        return view('corex.communications.access-inbox', ['requests' => $pending]);
    }

    /**
     * AT-132 — owner (or grant_access holder) toggles a thread's hide-subject.
     * POST /api/v1/comms-access/thread-settings  {contact_id, thread_key, hide_subject}
     */
    public function threadSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'contact_id'   => 'required|integer|exists:contacts,id',
            'thread_key'   => 'required|string|max:255',
            'hide_subject' => 'required|boolean',
        ]);

        // AgencyScope ensures the contact is in the user's agency (404 otherwise).
        $contact = Contact::findOrFail($data['contact_id']);

        $ok = $this->grants->setThreadHideSubject($user, $contact, $data['thread_key'], (bool) $data['hide_subject']);
        if (!$ok) {
            return response()->json(['ok' => false, 'error' => 'Only the owning agent or a manager can change this.'], 403);
        }

        return response()->json(['ok' => true, 'hide_subject' => (bool) $data['hide_subject']]);
    }
}
