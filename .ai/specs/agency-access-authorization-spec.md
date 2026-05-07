# Agency Access Authorization Spec

> Status: **APPROVED — decisions locked 2026-05-07**
> Created: 2026-05-07

## Overview
When a platform-level user (currently `super_admin`; eventually `developer`) attempts to switch into another agency, that agency may require explicit authorization from one of its admins before the switch is allowed. Each agency controls this independently via a per-agency setting.

- **Flag OFF** → switch happens immediately (existing behaviour, unchanged).
- **Flag ON** → switch is held; requester sees a waiting modal; agency admins receive an authorization prompt; switch only completes on approval.

## Goals
- Per-agency control over inbound platform-level access.
- Real-time approve / deny / expire / cancel flow with clear UI states.
- Full audit trail for compliance.
- Zero impact on agencies that don't enable the flag.
- Architecture is role-agnostic so the future `developer` role plugs in via permission only — no refactor needed.

---

## DECISIONS (locked 2026-05-07)

1. **Real-time delivery** → **polling**. 3s for requester status, 5s for admin inbox. No Reverb in v1.
2. **Pending request timeout** → **5 minutes**.
3. **Granted session duration** → **24 hours auto-revoke** after approval (covers internet disruption / longer issues). Stored as `granted_session_expires_at = approved_at + 24h`.
4. **Reason field** on requester side → **optional**. Free-text box, may be blank; shown to admin if filled.
5. **Email fallback** → **NO**. In-app popup only. Admin must be in CoreX to authorize. If no admin is online, the request expires after 5 min and the requester sees "no admin available, please retry later".
6. **Developer role + `platform.cross_agency_access` permission** → **deferred to a later spec**. v1 gate is by `super_admin` / owner-role only (matches existing `owner_only` middleware on the switcher routes).
7. **Which admins get notified** → **requester picks** at request time. The switcher first surfaces the list of admins for the target agency; requester selects one or more, only those admins receive the popup. First-to-act wins.

### Permission gating (resolved)
- Switcher routes stay behind the existing `owner_only` middleware (set in the agency-admin-rule work).
- New permissions (`agency.manage_access_authorization`, `agency.authorize_external_access`) are added for the agency-side flag toggle and for approve/deny.
- Requester-side platform permission (`platform.cross_agency_access`) is **deferred** with the developer-role spec.

---

## Architecture

### Cross-agency table — NOT scoped by AgencyScope
The `agency_access_requests` table is the **only** table in CoreX that intentionally crosses agency boundaries. The `target_agency_id` represents the agency being accessed; the `requester_user_id` belongs to the platform/super_admin context.

**Do NOT add `BelongsToAgency` trait or `AgencyScope` global scope to `AgencyAccessRequest`.** Queries are explicitly bounded by `target_agency_id` or `requester_user_id` on every read.

### Flag location
Add `require_external_access_authorization` (BOOLEAN, default FALSE) directly to the `agencies` table. Indexed for cheap reads on every cross-agency switch attempt — it does not belong inside a JSON settings blob.

### Switch flow

```
super_admin clicks agency in switcher
  → POST /api/agency/switch-request { target_agency_id }
  → AgencyController@switchRequest
      if target.require_external_access_authorization == false:
          → perform switch (existing behaviour)
          → return { status: 'switched' }
      else:
          → return { status: 'admin_required', admins: [...] }   // requester picks
          → (next call:) POST /api/agency/switch-request with admin_user_ids[]
          → if duplicate pending request from this user → return existing
          → create AgencyAccessRequest (status=pending, expires_at=now()+5min)
          → attach selected admin_user_ids to the request (notification fan-out list)
          → return { status: 'pending', request_id }
```

```
Requester sees "Waiting for authorization..." modal
  → polls GET /api/agency/access-request/{id}/status every 3s
  → on status change:
      approved  → POST /api/agency/switch-confirm/{id} → switch → close modal
      denied    → show denial reason → close modal
      expired   → show timeout message → close modal
      cancelled → close modal silently
```

```
Target agency admin sees real-time notification:
  → header bell increments + modal opens
  → "[Requester] (super_admin) is requesting access. Reason: [...]
     Expires in [countdown]. [Approve] [Deny]"
  → POST /api/agency/access-request/{id}/authorize { decision, denial_reason? }
  → status flips → requester's poll picks it up next cycle
```

---

## Database

### Migration 1 — `agencies.require_external_access_authorization`
```php
Schema::table('agencies', function (Blueprint $table) {
    $table->boolean('require_external_access_authorization')
        ->default(false)
        ->after('is_active');
    $table->index('require_external_access_authorization');
});
```

### Migration 2 — `agency_access_requests`
```php
Schema::create('agency_access_requests', function (Blueprint $table) {
    $table->id();
    $table->foreignId('target_agency_id')->constrained('agencies')->cascadeOnDelete();
    $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
    $table->string('requester_role'); // super_admin, developer
    $table->enum('status', ['pending', 'approved', 'denied', 'expired', 'cancelled'])
        ->default('pending');
    $table->text('reason')->nullable();
    $table->text('denial_reason')->nullable();
    $table->foreignId('authorized_by_user_id')->nullable()->constrained('users');
    $table->timestamp('authorized_at')->nullable();
    $table->timestamp('expires_at');
    $table->timestamp('granted_session_expires_at')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['target_agency_id', 'status']);
    $table->index(['requester_user_id', 'status']);
    $table->index('expires_at');
});

// Pivot table for which admins were targeted by each request (decision #7).
Schema::create('agency_access_request_admins', function (Blueprint $table) {
    $table->id();
    $table->foreignId('request_id')->constrained('agency_access_requests')->cascadeOnDelete();
    $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
    $table->timestamps();
    $table->unique(['request_id', 'admin_user_id']);
});
```

---

## Models

### `AgencyAccessRequest`
- **Relationships**: `targetAgency()`, `requester()` (User), `authorizer()` (User).
- **Scopes**: `pending()`, `notExpired()`, `forAgency($id)`, `byRequester($id)`.
- **Methods**: `isPending()`, `isApproved()`, `markApproved($adminId)`, `markDenied($adminId, $reason)`, `markExpired()`, `markCancelled()`.
- **Casts**: `expires_at`, `authorized_at`, `granted_session_expires_at` → datetime.
- **No `BelongsToAgency` trait** — see Architecture note above.

### `Agency`
- Add `accessRequests()` hasMany relationship.
- Add accessor `requiresExternalAccessAuthorization()` for readability.
- Add scope `requiresAccessAuth()`.

---

## Controllers

### `AgencyController` updates
- `switchRequest(Request $r)` — new method replacing or wrapping the current switch endpoint.
  - Validates `target_agency_id`, requires `platform.cross_agency_access` permission.
  - Branches on the flag — immediate switch or creates request.
- `switchConfirm($requestId)` — completes the switch only after approval.
  - Verifies request belongs to current user, status=approved, not expired.
  - Performs the actual session/agency context switch.

### `AgencyAccessRequestController` (new)
- `store(Request $r)` — create request (typically called from `switchRequest`).
- `status($id)` — polled by requester (own requests only).
- `cancel($id)` — requester cancels their own pending request.
- `authorize($id, Request $r)` — admin approves or denies.
  - Requires `agency.authorize_external_access` permission **AND** user belongs to `target_agency_id`.
- `inbox()` — list pending requests for current admin's agency (drives the header bell).

---

## Permissions (RMCP)

Add to `HfcRmcpMasterSeeder` under a new **Platform Security** section:

| Permission | Purpose | Default role |
|---|---|---|
| `agency.manage_access_authorization` | Toggle the flag on own agency | `admin` |
| `agency.authorize_external_access` | Approve / deny inbound requests | `admin` |

`platform.cross_agency_access` is **deferred** — added when the `developer` role spec lands. v1 uses the existing `owner_only` middleware on switcher routes.

Re-run seeder after adding; verify both new permissions appear in the RMCP UI.

---

## UI

### Agency settings — new "Remote Access" tab
- **Location:** `/corex/settings` (existing tabbed settings page at [resources/views/corex/settings.blade.php](resources/views/corex/settings.blade.php)). Add a new tab named **"Remote Access"** alongside the existing tabs.
- **Single toggle:** *"Require system owner consent for remote access"* (default **OFF**).
- **Helper text:** *"When OFF, a system owner can switch into this agency without asking. When ON, every cross-agency access attempt by a system owner triggers an approval request that an admin you choose must accept before access is granted."*
- **Permission-gated:** visible only to users with `agency.manage_access_authorization` (default: `admin` role). Non-admins don't see the tab at all.
- The toggle writes to `agencies.require_external_access_authorization`.
- Audit-logged via `agency_access_auth_flag_toggled` with the actor + old/new values.

**Navigation:** the tab appears inline in the existing `/corex/settings` page — no separate route. Permission gating in the tab nav and on the form POST.

### Agency switcher — admin picker (when target has the flag ON)
- Click target agency → if response is `admin_required`, switcher swaps to a **picker step**:
  - Lists active admins of the target agency (name + email).
  - Requester selects one or more (checkbox list, must pick ≥1).
  - Optional **"Reason for access"** text area below the list.
  - **Send request** button fires the second POST with `admin_user_ids[]` and `reason`.

### Agency switcher — pending state
- After send → if response is `pending`, replace switcher with a **locked modal**:
  - Spinner + *"Waiting for authorization from [Agency Name]..."*
  - Countdown to expiry.
  - **Cancel request** button.
  - Polls `/api/agency/access-request/{id}/status` every 3s.
  - Auto-closes on any terminal status (approved/denied/expired/cancelled).

### Admin authorization modal
- Triggered by polling `/api/agency/access-request/inbox` every 5s while admin is in CoreX. Only admins explicitly selected by the requester appear in their inbox (decision #7).
- Shows: requester name + role, optional reason, countdown to expiry.
- Buttons: **[Approve]** **[Deny with reason]**.
- Header **bell icon** shows count of pending inbound requests; clicking opens the modal stack.

---

## Edge cases

1. **Duplicate pending request** from same user to same agency → return existing, don't create new.
2. **Two admins approve simultaneously** → first transaction wins via row lock; second sees "already handled" and modal closes.
3. **Requester closes browser** → pending request remains until `expires_at`; expiry job marks it later.
4. **No admin online** → request expires; requester sees "no admin available, please retry later".
5. **Requester also belongs to target agency** → shouldn't happen for `super_admin` (no `agency_id`), but guard explicitly with a 422.
6. **Agency soft-deleted while request pending** → cascade or expiry job marks request expired.
7. **User loses `platform.cross_agency_access`** while request pending → deny on `switchConfirm`.
8. **Granted session still active when admin disables the flag** → existing session stands until manual switch-out (no retroactive revoke in v1).

---

## Audit logging

Use the existing audit log infrastructure. Log events:

| Event | Actor | Notes |
|---|---|---|
| `agency_access_requested` | requester | Includes target_agency_id, reason |
| `agency_access_authorized` | admin | Includes requester_user_id |
| `agency_access_denied` | admin | Includes denial_reason |
| `agency_access_expired` | system | From expiry job |
| `agency_access_cancelled` | requester | — |
| `agency_access_auth_flag_toggled` | admin | Old value → new value |

Every entry includes `target_agency_id` and `request_id` for traceability.

---

## Background jobs

### `ExpireStaleAccessRequestsJob`
- Scheduled every 1 minute.
- Marks all `pending` requests where `expires_at < now()` as `expired`.
- Writes audit entries for each.

---

## Build sequence

Execute in order. After each prompt: `php -l`, `view:clear`, `dev-check.ps1`, Tinker verification.

- **A.** Migration: add `require_external_access_authorization` to `agencies`. Update factory.
- **B.** Migration: create `agency_access_requests` table.
- **C.** `AgencyAccessRequest` model with relationships, scopes, methods, factory. Unit tests for state transitions.
- **D.** `AgencyAccessRequestController` skeleton: `store`, `status`, `cancel`, `inbox`. No authorize yet.
- **E.** Update `AgencyController@switch` → split into `switchRequest` + `switchConfirm`. Branching logic on flag.
- **F.** RMCP seeder: add three new permissions. Re-run seeder, verify on dev.
- **G.** `/corex/settings` — add **Remote Access** tab with the consent toggle. Permission-gated by `agency.manage_access_authorization`.
- **H.** Frontend agency switcher: handle `pending` response, route to waiting modal.
- **I.** Requester waiting modal with 3s polling + cancel button.
- **J.** Admin authorization modal + header bell + inbox polling. Wire Approve / Deny.
- **K.** `AgencyAccessRequestController@authorize` method. Full end-to-end on dev.
- **L.** ~~Email notification~~ — **dropped per decision #5**, in-app popup only.
- **M.** `ExpireStaleAccessRequestsJob` + register in scheduler. Verify expiry path. Also handles 24h granted-session expiry (force switch-out when `granted_session_expires_at` passes).
- **N.** Audit log integration for all six events.
- **O.** End-to-end test scenarios:
  - Flag OFF: super_admin switches → immediate (regression test).
  - Flag ON: super_admin → admin approves → switch completes.
  - Flag ON: super_admin → admin denies → switch blocked.
  - Flag ON: super_admin → no response → expires after 5 min.
  - Flag ON: super_admin cancels → admin modal closes.
  - Flag ON: two admins, one approves first → other sees "already handled".

---

## First production canary
**HFC.** Flag stays OFF on HFC by default; toggle ON for one staging dry-run with a second test agency, then OFF again. Once feature is stable, separate decision on whether to default new agencies to ON.
