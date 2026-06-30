<?php

namespace App\Services\Communications;

use App\Models\Communications\CommsAccessAuditLog;
use App\Models\Communications\CommsAccessRequest;
use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\User;
use App\Notifications\Communications\CommsAccessRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * AT-118 — orchestrates Flow A (request → authorise → session-scoped grant) for
 * the Communications Access Gate, and the revocation paths (logout + midnight).
 * Every state change is POPIA-logged into comms_access_audit_log (the Step-1 sink).
 *
 * hasActiveGrant() is the seam the Step-2 gate (ContactController) calls — it now
 * returns true for a live, session-bound grant.
 *
 * Spec: .ai/specs/at118-communications-access-gate.md §3.3
 */
class CommsAccessGrantService
{
    /**
     * Does $user hold a live, session-scoped grant for THIS contact's threads?
     * Live = approved, not revoked, before its hard expiry, AND bound to the
     * current login session (true "current session only"). The Step-2 gate opens
     * for the contact when this is true.
     */
    public function hasActiveGrant(User $user, Contact $contact): bool
    {
        $sid = $this->currentSessionId();

        return CommsAccessRequest::query()
            ->byRequester($user->id)
            ->forContact($contact->id)
            ->liveGrant()
            ->where(function ($q) use ($sid) {
                // A grant bound to a session only opens the gate inside that
                // session; an unbound (null) grant is a defensive fallback.
                $q->whereNull('session_id');
                if ($sid !== null) {
                    $q->orWhere('session_id', $sid);
                }
            })
            ->exists();
    }

    /**
     * A non-owner requests access to a contact's threads. Reuses a still-pending
     * request from the same requester+contact. Logs 'request' + notifies the
     * owning agent and communications.grant_access holders.
     */
    public function requestAccess(User $requester, Contact $contact, ?string $reason = null): CommsAccessRequest
    {
        $existing = CommsAccessRequest::query()
            ->byRequester($requester->id)
            ->forContact($contact->id)
            ->pending()
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
        if ($existing) {
            return $existing;
        }

        $req = DB::transaction(function () use ($requester, $contact, $reason) {
            return CommsAccessRequest::create([
                'agency_id'         => $contact->agency_id,
                'contact_id'        => $contact->id,
                'requester_user_id' => $requester->id,
                'status'            => CommsAccessRequest::STATUS_PENDING,
                'reason'            => $reason,
                // Pending requests are same-day: a request not actioned today is
                // stale (swept by the midnight reset). Grant binds to this session.
                'expires_at'        => now()->endOfDay(),
                'session_id'        => $this->currentSessionId(),
            ]);
        });

        CommsAccessAuditLog::record(CommsAccessAuditLog::EVENT_REQUEST, [
            'agency_id'     => $req->agency_id,
            'actor_user_id' => $requester->id,
            'contact_id'    => $contact->id,
            'detail'        => ['reason' => $reason, 'request_id' => $req->id],
        ]);

        $this->notifyApprovers($req, $requester, $contact);

        return $req;
    }

    /** Approve — either/or (owner OR grant_access holder). Logs 'grant'. */
    public function approve(CommsAccessRequest $req, User $approver): bool
    {
        $ok = $req->markApproved($approver->id);

        CommsAccessAuditLog::record(CommsAccessAuditLog::EVENT_GRANT, [
            'agency_id'       => $req->agency_id,
            'actor_user_id'   => $approver->id,
            'subject_user_id' => $req->requester_user_id,
            'contact_id'      => $req->contact_id,
            'detail'          => [
                'request_id'   => $req->id,
                'granted_until' => optional($req->granted_session_expires_at)->toIso8601String(),
            ],
        ]);

        return $ok;
    }

    /** Decline — logs 'decline' with the reason. */
    public function decline(CommsAccessRequest $req, User $approver, ?string $reason = null): bool
    {
        $ok = $req->markDeclined($approver->id, $reason);

        CommsAccessAuditLog::record(CommsAccessAuditLog::EVENT_DECLINE, [
            'agency_id'       => $req->agency_id,
            'actor_user_id'   => $approver->id,
            'subject_user_id' => $req->requester_user_id,
            'contact_id'      => $req->contact_id,
            'detail'          => ['request_id' => $req->id, 'reason' => $reason],
        ]);

        return $ok;
    }

    /**
     * Midnight reset — revoke EVERY live grant (across all agencies) and log a
     * 'midnight_reset' event per grant. Returns the number revoked.
     */
    public function revokeAllActive(string $reason = 'midnight_reset'): int
    {
        $count = 0;

        // System-wide sweep (runs in console with no auth) — bypass AgencyScope
        // so every agency's live grants are revoked, then log per-grant agency_id.
        CommsAccessRequest::query()
            ->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->liveGrant()->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$count, $reason) {
                foreach ($chunk as $grant) {
                    $grant->markRevoked($reason);
                    CommsAccessAuditLog::record(CommsAccessAuditLog::EVENT_MIDNIGHT_RESET, [
                        'agency_id'       => $grant->agency_id,
                        'actor_user_id'   => null, // system
                        'subject_user_id' => $grant->requester_user_id,
                        'contact_id'      => $grant->contact_id,
                        'detail'          => ['request_id' => $grant->id, 'reason' => $reason],
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Logout — revoke the logging-out user's live grants and log 'session_expired'.
     * Belt-and-braces with the session_id binding (which already closes the gate
     * on a new session); this makes the end-of-session explicit in the audit trail.
     */
    public function revokeForUser(User $user, string $reason = 'logout'): int
    {
        $count = 0;

        CommsAccessRequest::query()->byRequester($user->id)->liveGrant()->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$count, $reason, $user) {
                foreach ($chunk as $grant) {
                    $grant->markRevoked($reason);
                    CommsAccessAuditLog::record(CommsAccessAuditLog::EVENT_SESSION_EXPIRED, [
                        'agency_id'       => $grant->agency_id,
                        'actor_user_id'   => $user->id,
                        'subject_user_id' => $grant->requester_user_id,
                        'contact_id'      => $grant->contact_id,
                        'detail'          => ['request_id' => $grant->id, 'reason' => $reason],
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    /** Mark stale pending requests (past expires_at) as expired. Returns count. */
    public function expireStalePending(): int
    {
        $count = 0;
        CommsAccessRequest::query()
            ->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->pending()->where('expires_at', '<', now())->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$count) {
                foreach ($chunk as $req) {
                    $req->markExpired();
                    $count++;
                }
            });
        return $count;
    }

    /**
     * May $user authorise this request? Either/or, NOT dual control:
     * a communications.grant_access holder OR an owning agent of any of the
     * contact's threads — within the same agency.
     */
    public function canAuthorize(User $user, CommsAccessRequest $req): bool
    {
        if ((int) $user->agency_id !== (int) $req->agency_id) {
            return false;
        }
        if ($user->hasPermission('communications.grant_access')) {
            return true;
        }
        return $this->commsForContact($req->contact_id)
            ->where('owner_user_id', $user->id)
            ->exists();
    }

    /** The user ids notified on a request: owning agents ∪ grant_access holders. */
    public function eligibleApproverIds(Contact $contact): array
    {
        $ownerIds = $this->commsForContact($contact->id)
            ->whereNotNull('owner_user_id')
            ->distinct()
            ->pluck('owner_user_id')
            ->all();

        $grantHolders = User::where('agency_id', $contact->agency_id)
            ->where('is_active', 1)
            ->get()
            ->filter(fn ($u) => $u->hasPermission('communications.grant_access'))
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_merge($ownerIds, $grantHolders)));
    }

    // ── internals ──

    protected function notifyApprovers(CommsAccessRequest $req, User $requester, Contact $contact): void
    {
        $ids = array_values(array_diff($this->eligibleApproverIds($contact), [$requester->id]));
        if (empty($ids)) {
            return;
        }
        $approvers = User::whereIn('id', $ids)->get();
        Notification::send($approvers, new CommsAccessRequested($req));
    }

    /** Non-purged communications linked to a contact (the thread set). */
    protected function commsForContact(int $contactId)
    {
        return Communication::query()
            ->whereNull('purged_at')
            ->whereHas('links', function ($q) use ($contactId) {
                $q->where('linkable_type', Contact::class)->where('linkable_id', $contactId);
            });
    }

    protected function currentSessionId(): ?string
    {
        try {
            $req = request();
            if ($req && $req->hasSession()) {
                return $req->session()->getId();
            }
        } catch (\Throwable $e) {
            // no session bound (console/job) — unbound
        }
        return null;
    }
}
