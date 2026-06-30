<?php

namespace App\Services\Communications;

use App\Models\Communications\CommsAccessAuditLog;
use App\Models\Communications\CommsAccessRequest;
use App\Models\Communications\CommsThreadSetting;
use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\User;
use App\Notifications\Communications\CommsAccessRequested;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
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
     * AT-132 — does $user hold a live grant that opens a SPECIFIC thread (or, for a
     * null-thread comm, that specific comm) on this contact? A live grant
     * (session or always — see CommsAccessRequest::scopeLiveGrant) matches when,
     * for (requester, contact):
     *
     *   (a) LEGACY whole-contact grant — thread_key NULL **and** communication_id
     *       NULL. Pre-AT-132 grants (and the 2 existing staging rows) are stored
     *       this way; they keep opening the WHOLE contact (backward-compat). The
     *       2-arg call hasActiveGrant($user,$contact) therefore still means "does
     *       the user have whole-contact access?" and only legacy grants satisfy it.
     *   (b) THREAD match — $threadKey given and equals the grant's thread_key.
     *   (c) NULL-THREAD match — $threadKey null/empty and $communicationId equals
     *       the grant's communication_id (null-thread comms are keyed on the comm,
     *       NEVER grouped — same isolation as AT-127).
     *
     * session_id is intentionally NOT matched (it regenerates on every login /
     * switch-user). "Session only / dies at logout + midnight" is preserved by
     * RevokeCommsGrantsOnLogout, comms-access:reset, and the end-of-day cap in
     * scopeLiveGrant — all of which now skip grant_mode=always (it persists).
     */
    public function hasActiveGrant(User $user, Contact $contact, ?string $threadKey = null, ?int $communicationId = null): bool
    {
        $threadKey = ($threadKey === '') ? null : $threadKey;

        return CommsAccessRequest::query()
            ->byRequester($user->id)
            ->forContact($contact->id)
            ->liveGrant()
            ->where(function ($q) use ($threadKey, $communicationId) {
                // (a) legacy whole-contact grant — opens every thread on the contact.
                $q->where(function ($legacy) {
                    $legacy->whereNull('thread_key')->whereNull('communication_id');
                });
                // (b) thread-scoped match.
                if ($threadKey !== null) {
                    $q->orWhere('thread_key', $threadKey);
                }
                // (c) null-thread comm — keyed on communication_id only.
                if ($threadKey === null && $communicationId !== null) {
                    $q->orWhere('communication_id', $communicationId);
                }
            })
            ->exists();
    }

    /**
     * AT-132 — contact ids the user holds a LIVE legacy whole-contact grant for
     * (thread_key AND communication_id both null — pre-AT-132 / the 2 staging rows).
     * Such a grant opens EVERY thread on that contact. Used to honour legacy grants
     * in the archive body surface (the contact tab honours them via the 2-arg
     * hasActiveGrant); per-thread grants are NOT here (scopeVisibleTo handles those).
     */
    public function legacyGrantedContactIds(User $user): array
    {
        return CommsAccessRequest::query()
            ->byRequester($user->id)
            ->liveGrant()
            ->whereNull('thread_key')
            ->whereNull('communication_id')
            ->pluck('contact_id')
            ->map(fn ($id) => (int) $id)
            ->unique()->values()->all();
    }

    /**
     * AT-132 — THE one source of truth for which communications a user may see.
     * Shared by the contact tab and the compliance archive body surface so the two
     * can never drift. access_communication_archive remains the ENTRY gate (route
     * middleware); this filters WHICH rows are returned inside it. A user sees a
     * comm iff:
     *   - they hold communications.grant_access (authoriser must see to authorise)
     *     OR their communications.view scope is 'all'  → the full archive; else
     *   - Communication::scopeVisibleTo (owner OR own/branch scope OR AT-127
     *     participant OR a live per-thread / per-comm grant), OR
     *   - they hold a live LEGACY whole-contact grant for the comm's linked contact.
     * Mirrors ContactController::show's gate composition exactly (parity).
     */
    public function applyArchiveVisibility(Builder $query, User $user): Builder
    {
        $scope        = PermissionService::getDataScope($user, 'communications');
        $isAuthoriser = $user->hasPermission('communications.grant_access');

        // Authoriser / 'all' scope → the full archive (compliance), unchanged.
        if ($isAuthoriser || $scope === 'all') {
            return $query;
        }

        $legacyContactIds = $this->legacyGrantedContactIds($user);

        return $query->where(function (Builder $w) use ($user, $scope, $legacyContactIds) {
            // owner / scope / AT-127 participant / per-thread+per-comm grant (Step 2).
            $w->visibleTo($user, $scope);

            // legacy whole-contact grant → every comm linked to that contact.
            if (!empty($legacyContactIds)) {
                $w->orWhereHas('links', function ($l) use ($legacyContactIds) {
                    $l->where('linkable_type', Contact::class)
                      ->whereIn('linkable_id', $legacyContactIds);
                });
            }
        });
    }

    /**
     * A non-owner requests access to a contact's threads. Reuses a still-pending
     * request from the same requester+contact. Logs 'request' + notifies the
     * owning agent and communications.grant_access holders.
     */
    public function requestAccess(User $requester, Contact $contact, ?string $reason = null, ?string $threadKey = null, ?int $communicationId = null): CommsAccessRequest
    {
        $threadKey = ($threadKey === '') ? null : $threadKey;

        // Reuse a still-pending request for the SAME thread / null-thread comm /
        // (legacy) whole-contact target — never collapse distinct threads together.
        $existing = CommsAccessRequest::query()
            ->byRequester($requester->id)
            ->forContact($contact->id)
            ->pending()
            ->where('expires_at', '>', now())
            ->when($threadKey !== null, fn ($q) => $q->where('thread_key', $threadKey))
            ->when($threadKey === null && $communicationId !== null,
                fn ($q) => $q->whereNull('thread_key')->where('communication_id', $communicationId))
            ->when($threadKey === null && $communicationId === null,
                fn ($q) => $q->whereNull('thread_key')->whereNull('communication_id'))
            ->latest()
            ->first();
        if ($existing) {
            return $existing;
        }

        $req = DB::transaction(function () use ($requester, $contact, $reason, $threadKey, $communicationId) {
            return CommsAccessRequest::create([
                'agency_id'         => $contact->agency_id,
                'contact_id'        => $contact->id,
                'thread_key'        => $threadKey,
                'communication_id'  => $communicationId,
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
            'agency_id'        => $req->agency_id,
            'actor_user_id'    => $requester->id,
            'contact_id'       => $contact->id,
            'communication_id' => $communicationId,
            'detail'           => ['reason' => $reason, 'request_id' => $req->id, 'thread_key' => $threadKey],
        ]);

        $this->notifyApprovers($req, $requester, $contact);

        return $req;
    }

    /**
     * AT-132 — set/clear a thread's hide-subject toggle (owner privacy control).
     * Authorised for the thread's OWNING agent (owns ≥1 comm in the thread on this
     * contact) OR a communications.grant_access holder. Returns false if not
     * authorised. Idempotent; restores a soft-deleted settings row if present.
     */
    public function setThreadHideSubject(User $actor, Contact $contact, string $threadKey, bool $hide): bool
    {
        $threadKey = trim($threadKey);
        if ($threadKey === '') {
            return false;
        }

        $isAuthoriser = $actor->hasPermission('communications.grant_access');
        $ownsThread   = Communication::query()->whereNull('purged_at')
            ->where('thread_key', $threadKey)
            ->where('owner_user_id', $actor->id)
            ->whereHas('links', fn ($q) => $q->where('linkable_type', Contact::class)
                                              ->where('linkable_id', $contact->id))
            ->exists();
        if (!$isAuthoriser && !$ownsThread) {
            return false;
        }

        $setting = CommsThreadSetting::withTrashed()
            ->where('agency_id', $contact->agency_id)
            ->where('contact_id', $contact->id)
            ->where('thread_key', $threadKey)
            ->first();

        if ($setting) {
            if ($setting->trashed()) {
                $setting->restore();
            }
            $setting->update(['hide_subject' => $hide, 'set_by_user_id' => $actor->id]);
        } else {
            CommsThreadSetting::create([
                'agency_id'      => $contact->agency_id,
                'contact_id'     => $contact->id,
                'thread_key'     => $threadKey,
                'hide_subject'   => $hide,
                'set_by_user_id' => $actor->id,
            ]);
        }

        return true;
    }

    /**
     * Approve — either/or (owner OR grant_access holder). $mode = session | always
     * (AT-132). Carries the request's thread_key/communication_id onto the grant.
     * Logs 'grant' with thread_key + grant_mode.
     */
    public function approve(CommsAccessRequest $req, User $approver, string $mode = CommsAccessRequest::MODE_SESSION): bool
    {
        $ok = $req->markApproved($approver->id, $mode);
        $req->refresh();

        CommsAccessAuditLog::record(CommsAccessAuditLog::EVENT_GRANT, [
            'agency_id'        => $req->agency_id,
            'actor_user_id'    => $approver->id,
            'subject_user_id'  => $req->requester_user_id,
            'contact_id'       => $req->contact_id,
            'communication_id' => $req->communication_id,
            'detail'           => [
                'request_id'    => $req->id,
                'thread_key'    => $req->thread_key,
                'grant_mode'    => $req->grant_mode,
                'granted_until' => $req->grant_mode === CommsAccessRequest::MODE_ALWAYS
                    ? 'always'
                    : optional($req->granted_session_expires_at)->toIso8601String(),
            ],
        ]);

        return $ok;
    }

    /** Decline — logs 'decline' with the reason. */
    public function decline(CommsAccessRequest $req, User $approver, ?string $reason = null): bool
    {
        $ok = $req->markDeclined($approver->id, $reason);

        CommsAccessAuditLog::record(CommsAccessAuditLog::EVENT_DECLINE, [
            'agency_id'        => $req->agency_id,
            'communication_id' => $req->communication_id,
            'actor_user_id'   => $approver->id,
            'subject_user_id' => $req->requester_user_id,
            'contact_id'      => $req->contact_id,
            'detail'          => ['request_id' => $req->id, 'reason' => $reason, 'thread_key' => $req->thread_key],
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
        // AT-132: ALWAYS grants are permanent — they survive the midnight reset
        // (only an explicit revoke ends them); only SESSION grants are swept.
        CommsAccessRequest::query()
            ->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->liveGrant()
            ->where('grant_mode', '!=', CommsAccessRequest::MODE_ALWAYS)
            ->orderBy('id')
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

        // AT-132: ALWAYS grants survive logout too — only SESSION grants are revoked.
        CommsAccessRequest::query()->byRequester($user->id)->liveGrant()
            ->where('grant_mode', '!=', CommsAccessRequest::MODE_ALWAYS)
            ->orderBy('id')
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
