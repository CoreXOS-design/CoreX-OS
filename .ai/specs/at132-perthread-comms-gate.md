# CoreX OS — AT-132 (Wave 1): Per-Thread Communications Access Gate

**Spec ID:** AT-132 (Wave 1)
**Status:** Draft — ready for review (decisions locked by Johan)
**Author:** Johan (product owner — requirements) + Claude (senior engineer — implementation)
**Date:** 2026-06-30
**Investigation basis:** `.ai/audits/2026-06-30-comms-gate-per-thread-redesign-investigation.md`
**Supersedes (in part):** AT-118 §3.3 contact-level grant model → becomes thread-level.
**Reuses:** AT-127 (thread-level participant visibility / `thread_key` grouping in
`Communication::scopeVisibleTo`), AT-118 grant infra (`CommsAccessRequest`,
`CommsAccessGrantService`, `comms_access_audit_log`, midnight reset, logout revoke).
**Wave boundary:** Wave 1 = per-thread gate + thread-list view + **session / always**
modes + the body-surface gap fix (this spec). **Wave 2 = AT-130 `OtpService` then the
`otp` break-glass grant mode** (separate spec/build; only the plug-in point is noted here).

---

## 0. Johan's non-negotiables (inherited from AT-118 §0)

1. **A clear, complete, immutable audit log** — every request, grant, decline, expiry,
   revoke, and (Wave 2) OTP unlock recorded immutably, now stamped with `thread_key` + `grant_mode`.
2. **Clear agent attribution on the user-facing side** — the owning agent is always shown;
   the access state ("you have access" / "request access" / "granted until logout" /
   "always") is never ambiguous, per thread.

---

## 1. Purpose & the flaw being fixed

The AT-118 gate is **contact-level**: a single grant opens *every* thread on a contact, and
a non-owner sees a blank lock panel with no idea what threads exist — a blind request for
everything (`ContactController.php:345-348`; `CommsAccessRequest` keyed on `contact_id`
only; `CommsAccessGrantService::hasActiveGrant(User,Contact)`).

AT-132 Wave 1 makes the gate **thread-scoped**:
- a non-owner sees a **list of the contact's threads** (safe metadata only, never bodies),
- requests access to a **specific thread**,
- the owner/authoriser approves in a **mode** (this session *or* always-for-this-thread),
- the **body surface is gated in the same place** (no bypass), and
- everything is audited with thread + mode.

### Pillar connections
- **Contact** (primary): threads resolve to a contact via `communication_links`; the gate
  + thread-list live on the contact surface.
- **Agent** (`User`): owning agent (`communications.owner_user_id`), requester, authoriser.
- **Property:** unchanged (property-pillar comms surface remains a fast-follow, AT-118 §3.6).

---

## 2. Locked decisions (Johan — do not re-litigate)

1. **Subject visibility:** show the subject in the locked thread-list **by default**; add an
   **owner-controlled per-thread "hide subject" toggle**. When hidden, the row shows
   metadata only (channel · date · message count · "Subject hidden by owning agent").
2. **Null-thread keying:** comms with NULL/empty `thread_key` key the grant on
   **`communication_id`** — never grouped (same isolation AT-127 already enforces in
   `scopeVisibleTo` b1).
3. **Body surface:** **fix in place.** `CommunicationArchiveController` must honour
   `scopeVisibleTo` + the per-thread grant. **ONE gated path**, not a second surface.
4. **Sequence:** Wave 1 here (gate + list + session/always). Wave 2 = AT-130 `OtpService`
   then the `otp` grant mode (bolt-on).

---

## 3. Engineering decisions

### 3.1 The grant becomes thread-scoped
- `comms_access_requests` gains a **nullable `thread_key`** and a nullable
  **`communication_id`** (for null-thread comms, decision 2). A grant row therefore points
  at exactly one of: a `thread_key` (a real thread), a `communication_id` (a null-thread
  single comm), or **neither** (a legacy/whole-contact grant — see §5 compat; not issued by
  the new UI but honoured so existing rows keep working).
- `hasActiveGrant` becomes `hasActiveGrant(User $user, Contact $contact, ?string $threadKey,
  ?int $communicationId = null)` — keys on thread/comm, scoped to the contact + requester.
- `requestAccess` and `approve` carry the thread/comm + mode through to the row + audit.

### 3.2 The grant gains a mode
- New `grant_mode` column on `comms_access_requests`: **`session`** (default — current
  behaviour) | **`always`** (permanent for that thread). `otp` is **reserved for Wave 2**
  (documented as a future enum value; not implemented now).
- `session` = unchanged: end-of-day hard cap (`granted_session_expires_at`), revoked at
  logout + 00:00 midnight reset.
- `always` = permanent: `granted_session_expires_at` is **NULL**; **skipped** by the
  midnight reset AND the logout revoke; only ends by explicit owner/admin revoke
  (`markRevoked`) or soft-delete. Still per-thread, never contact-wide.

### 3.3 The "hide subject" toggle — where it persists
**Cleanest option (chosen): a dedicated `comms_thread_settings` table**, keyed
`(agency_id, thread_key)` (+ nullable `communication_id` for null-thread comms), one row per
thread carrying `hide_subject` (boolean) and `set_by_user_id`. Rationale:
- A thread is **not a row** in `communications` — it is a *group* of rows sharing a
  `thread_key`. Putting `hide_subject` on `communications` would force writing it to every
  message in the thread and re-syncing on each new message. A per-thread settings row is the
  one-source-of-truth (Architectural Law §100) and is the natural home for any future
  per-thread setting (pin, mute, retention override).
- `BelongsToAgency` (AgencyScope), `SoftDeletes`, append-cheap. Lookup is a single indexed
  read joined by `thread_key` when rendering the list.
- Only the **owning agent** (or `communications.grant_access` holder) may set it (it is an
  owner privacy control), enforced server-side.

### 3.4 The body-surface gap fix (critical — decision 3)
`CommunicationArchiveController::index/thread/show`
(`app/Http/Controllers/Compliance/CommunicationArchiveController.php`) today applies **no**
`scopeVisibleTo` and **no** grant check — it is gated only by the agency-wide
`access_communication_archive` permission (`routes/web.php:1697`). So a per-thread grant is
enforced on the contact-tab **list** but the **body** is wide open to anyone with that
binary permission, and closed to anyone without it even when granted. **Wave 1 fixes this in
place:** the archive controller's `index`, `thread`, and `show` must filter through the
**same gate** the contact tab uses — `Communication::scopeVisibleTo($viewer, $scope)` unioned
with the viewer's active per-thread/per-comm grants — so the body is gated where it lives.
`access_communication_archive` remains the *entry* permission to the compliance archive area,
but row/thread/message access inside it is now the per-thread gate (one path).

### 3.5 Reuse, don't rebuild
- Thread grouping for a contact = AT-127's existing `thread_key` grouping
  (`Communication::scopeVisibleTo` b2 subquery, `Communication.php:190-203`) — the grant
  tier plugs in as one additional `orWhere`.
- Grant lifecycle (request → approve → session expiry → logout/midnight revoke) = AT-118
  `CommsAccessGrantService` — extended, not replaced.
- Audit sink = existing `comms_access_audit_log` / `CommsAccessAuditLog::record()`.

---

## A. DATA MODEL / MIGRATIONS

All additive + nullable. **Live `comms_access_requests` = 0 rows; staging = 2** (both
approved) — migration is trivial and breaks nothing.

**Migration 1 — extend `comms_access_requests`:**
- `thread_key` `varchar(255)` **nullable** (the granted thread; NULL when the grant targets
  a null-thread comm or is a legacy whole-contact grant).
- `communication_id` `unsignedBigInteger` **nullable**, FK→`communications.id` nullOnDelete
  (the granted null-thread comm, decision 2).
- `grant_mode` `varchar(20)` **default `'session'`** (`session` | `always`; `otp` reserved
  Wave 2).
- Index `(requester_user_id, contact_id, thread_key, status)` and
  `(requester_user_id, communication_id, status)`.
- **Back-compat:** existing rows get `thread_key=NULL, communication_id=NULL,
  grant_mode='session'` → behave as today's whole-contact session grant until they expire
  (they are end-of-day capped, so they self-clear that night).
- `granted_session_expires_at` stays nullable; for `always` grants it is **NULL** (the
  liveGrant check changes — §B).

**Migration 2 — new `comms_thread_settings`:**
- `id`, `agency_id` (FK, BelongsToAgency), `thread_key` `varchar(255)` nullable,
  `communication_id` nullable FK (null-thread case), `hide_subject` boolean default false,
  `set_by_user_id` FK→users nullOnDelete, `timestamps`, `softDeletes`.
- Unique `(agency_id, thread_key)` and `(agency_id, communication_id)` (partial — exactly one
  of the two is set per row).
- Index `(agency_id, thread_key)`.

**No change to** `communications`, `communication_links`, or `comms_access_audit_log` schema
(the audit `detail` JSON already carries `thread_key`/`mode` — see §F). Re-run
`php artisan schema:dump` after the migrations (non-negotiable #12a).

**New model:** `app/Models/Communications/CommsThreadSetting.php` (`BelongsToAgency`,
`SoftDeletes`).

---

## B. THE GATE (thread-scoped)

### B1. `CommsAccessGrantService::hasActiveGrant`
Signature → `hasActiveGrant(User $user, Contact $contact, ?string $threadKey, ?int
$communicationId = null): bool`. Live grant exists for `(requester=user, contact)` AND:
- if `$threadKey` non-empty → a `liveGrant()` row with matching `thread_key`, **OR** a
  legacy whole-contact live grant (`thread_key IS NULL AND communication_id IS NULL`);
- if `$threadKey` empty/null → a `liveGrant()` row with matching `communication_id`, **OR** a
  legacy whole-contact live grant.
New scopes on `CommsAccessRequest`: `forThread(?string)`, `forCommunication(?int)`.

### B2. `liveGrant()` must honour `always`
`CommsAccessRequest::scopeLiveGrant` becomes:
```
status = 'approved' AND revoked_at IS NULL
AND ( grant_mode = 'always' OR granted_session_expires_at > now() )
```
`isLiveGrant()` mirrors it. `markApproved($approverId, $mode)` sets `grant_mode=$mode` and
`granted_session_expires_at = $mode === 'always' ? null : now()->endOfDay()`.

### B3. `Communication::scopeVisibleTo` — add the per-thread grant tier
Today: `owner OR scope-tier OR participant (AT-127 thread-level)`
(`Communication.php:143-207`). Add a **fourth branch**: OR the comm's thread is one the
viewer holds a live grant for. Concretely, inside the existing `where(function($outer){…})`:
- compute the viewer's **granted thread set** and **granted comm-id set** from
  `CommsAccessRequest::byRequester($user->id)->liveGrant()` (pluck `thread_key` non-null →
  threads; pluck `communication_id` non-null → comm ids; detect any legacy whole-contact
  grant for the contact in question);
- `$outer->orWhere(fn($g) => $g->whereIn('thread_key', $grantedThreads))` (only non-empty
  thread_keys), and `->orWhereIn('id', $grantedCommIds)` for null-thread comms.
- Multi-tenant safe (AgencyScope already applies to the subqueries); indexed on
  `comm_thread_idx`.
- **Null-thread isolation preserved (decision 2):** a null-thread comm is reachable only via
  its `communication_id` grant (or participant b1), never via a `thread_key IN (...)` clause.

> Note: `scopeVisibleTo` takes `(User,$scope)` today. The grant set depends on the *contact*
> for the legacy-whole-contact case. Pass the contact context where the scope is invoked
> (contact tab + archive), or resolve the grant set per-query from the comm's own
> contact-link. Implementation detail for the build; the rule is "owner OR scope OR
> participant OR live-grant-for-this-thread/comm".

### B4. Controller gate (`ContactController::show`)
Replace the all-or-nothing `$hasGrant` switch (`ContactController.php:329-354`). The gate now
**always** runs `$contactCommsQuery->visibleTo($viewer, $scope)` (which now unions in the
per-thread grants). `$canViewComms` = "≥1 thread visible". The contact's **other** threads
(not visible) are still listed as **locked rows** (§C) — the tab renders whenever the viewer
has comms scope at all, mixing visible + locked threads.

### B5. "always" survives midnight + logout (decision / §A)
- `CommsAccessGrantService::revokeAllActive()` (midnight, `:133-157`) and `revokeForUser()`
  (logout, `:164-184`) add `->where('grant_mode', '!=', 'always')` (equivalently
  `where('grant_mode','session')`) to their `liveGrant()` sweep. An `always` grant is
  therefore never touched by the nightly reset or logout — it ends only on explicit revoke.
- `expireStalePending()` unchanged (pending TTL is mode-agnostic).

---

## C. THREAD-LIST VIEW (non-owner)

Replace the single full-panel lock (`show.blade.php:1917-1964`) with a **per-thread list**.

### C1. Build the thread list (controller)
In `ContactController::show`, after resolving the visible set, build a
**`$contactThreads`** collection — group the contact's non-purged comms by `thread_key`
(null-thread comms each become a thread-of-one keyed by `comm:{id}`), reusing the AT-127
grouping. For each thread compute **safe metadata only**:
- `channel` (email/whatsapp), `last_date` (max `occurred_at`), `message_count`,
  `owner_agent` (owner_user_id → name), `has_attachments` (any in thread),
  `subject` (latest non-empty subject) **unless hidden** (`comms_thread_settings.hide_subject`),
  `is_visible` (viewer can see bodies), `grant_state` (`owner` / `granted_session` /
  `granted_always` / `lockable`), `thread_key` (or `communication_id`).
- **NEVER** include `body_text` or `body_preview` for locked threads.

### C2. Render (`resources/views/corex/contacts/show.blade.php`)
- **Visible threads** render as today's archive rows (subject + preview + open-thread link).
- **Locked threads** render a metadata-only row: channel chip · date · "N messages" · owning
  agent · 📎 (if attachments) · subject (or "Subject hidden by owning agent" when hidden) ·
  a **"Request access"** button keyed on this thread (`thread_key` or `communication_id`).
- **No Silent Locks (STANDARDS):** each locked row says why (private to owning agent) and
  offers the unlock action (Request access). A `granted_session` row shows the "granted until
  logout / resets at midnight" banner; a `granted_always` row shows an "always — you have
  standing access to this thread" chip.
- Owner / `grant_access` holder additionally sees a small **"Hide subject"** toggle per
  thread (decision 1) → POST to the thread-settings endpoint.
- Design system: header comment `DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md`, tokens via
  `var(--token,#fallback)`, plain-English labels (STANDARDS §F.8).

---

## D. REQUEST + APPROVE (per-thread + mode)

### D1. Request (per-thread)
- `POST /api/v1/comms-access/request` payload gains `thread_key` **or** `communication_id`
  (one required) alongside `contact_id` + optional `reason`.
- `CommsAccessRequestController::store` validates the thread/comm belongs to the contact
  (a comm linked to the contact with that `thread_key`/`id`), then
  `CommsAccessGrantService::requestAccess($user,$contact,$reason,$threadKey,$commId)` creates
  the pending row stamped with the thread/comm. Reuses the existing pending-dedup + notify
  (owner ∪ `grant_access` holders, `eligibleApproverIds`). Audit `request` with thread/comm.
- A non-owner can only request a thread they can SEE in the list (they can't request what
  isn't shown) — the list is the discovery surface.

### D2. Approve with mode (the inbox)
- Inbox view `resources/views/corex/communications/access-inbox.blade.php` row now names the
  **specific thread** (subject/date/channel) the colleague asked for, not just the contact.
- Approve gains a **mode choice**: **"This session"** (default) or **"Always (this thread)"**.
  (A third "OTP" path is **not** in this UI — Wave 2.)
- `POST /api/v1/comms-access/{req}/authorize` payload gains `grant_mode` (`session|always`);
  `authorize()` passes it to `CommsAccessGrantService::approve($req,$user,$mode)` →
  `markApproved($approverId,$mode)`. Decline unchanged. Row-locked (existing `lockForUpdate`)
  so two approvers can't double-act.
- **Only the thread's owner or a `communications.grant_access` holder** may approve
  (`canAuthorize`, unchanged) — and `always` is a deliberate, audited choice.

### D3. Thread-settings endpoint (hide subject)
- `POST /api/v1/comms-access/thread-settings` (or `…/threads/{threadRef}/settings`) —
  payload `{contact_id, thread_key|communication_id, hide_subject:bool}`. Server checks the
  actor is the owning agent of that thread OR holds `communications.grant_access`. Upserts the
  `comms_thread_settings` row (`set_by_user_id`, AgencyScope). Registered under `/api/v1/*`
  with a `->name()` (non-negotiable #7), discoverable in Admin → API.

---

## E. BODY-SURFACE GAP FIX (critical — decision 3)

`CommunicationArchiveController` (`compliance.comm-archive.*`, `routes/web.php:1697`):
- **`index`** — the archive list query gains `->visibleTo($viewer, $scope)` (union per-thread
  grants), so a user only lists messages they may see (today it lists everything in the
  agency for any `access_communication_archive` holder).
- **`thread($threadKey)`** — after loading the thread, assert the viewer can see it
  (owner/scope/participant/grant); if not → 403 with the No-Silent-Locks message + a
  "Request access" path back to the contact thread (do not silently 404).
- **`show(Communication)`** — same per-message assertion (handles null-thread comms via
  `communication_id` grant).
- `access_communication_archive` stays the **entry** permission to the compliance area; the
  per-thread gate governs **which rows/threads/messages** are returned inside it. **One gated
  path** — the body can no longer be reached around the per-thread grant.
- Enforced **server-side** (the data is filtered/refused, not merely hidden).

---

## F. AUDIT

Every state change writes one immutable `comms_access_audit_log` row via
`CommsAccessAuditLog::record()`, now stamping **`thread_key`** (or `communication_id`) and
**`grant_mode`** into the `detail` JSON (the table already has `contact_id`, `actor_user_id`,
`subject_user_id`, `detail` JSON — no schema change needed):
- `request` (who, contact, thread/comm, reason),
- `grant` (authoriser, requester, thread/comm, **mode**, granted_until or "always"),
- `decline` (authoriser, reason),
- `session_expired` (logout), `midnight_reset` (00:00) — **only for `session` grants**
  (always grants are never swept, so they never emit these),
- explicit `revoke` of an `always` grant (owner/admin action) — uses the existing
  `markRevoked` + a logged event (reuse `EVENT_SESSION_EXPIRED` with reason `manual_revoke`,
  or add `EVENT_REVOKE` to `EVENT_TYPES` — engineer's call; if added, extend the
  `EVENT_TYPES` array so `record()` does not reject it, `CommsAccessAuditLog.php:36-42,111`).
- **Wave 2 (note only, do NOT build):** `otp_issued` + `otp_unlock` must be added to
  `EVENT_TYPES` when the OTP mode lands.

---

## G. WAVE 2 HOOK — where OTP break-glass plugs in (note only)

Wave 2 = AT-130 `OtpService` (canonical, extracted from `ClientAuthService` per
`.ai/audits/2026-06-30-at130-otp-engine-sweep.md`) + a third `grant_mode = 'otp'`:
- **Trigger:** admin / `communications.grant_access` holder only (capability-gated, no
  hardcoded roles) when the owner is unreachable.
- **Mechanism:** the authoriser self-authorises via `OtpService` (code to their OWN verified
  email) → on valid OTP, the system creates a **`session`-equivalent grant for THAT thread**
  (same end-of-day cap + midnight reset + logout revoke — NOT `always`, NOT broader).
- **Where it bolts on:** a new authorize path that, on OTP success, calls the same
  `approve()`/`markApproved($approver,'otp')` with thread scope — so the grant row, gate, and
  thread-list states are **unchanged**; only the *door* differs. Add `otp` to the
  `grant_mode` enum and `otp_issued`/`otp_unlock` to `EVENT_TYPES`.
- **Because Wave 1 already makes the grant thread-scoped + mode-aware**, Wave 2 is a
  **bolt-on, not a rework**: it adds one enum value, one authorize entry point, and two audit
  event types.

---

## H. BUILD ORDER (Wave 1)

1. **Migrations** — extend `comms_access_requests` (`thread_key`, `communication_id`,
   `grant_mode`) + create `comms_thread_settings` + `CommsThreadSetting` model;
   `schema:dump`.
2. **Thread-scope the gate** — `CommsAccessRequest` scopes (`forThread`/`forCommunication`),
   `scopeLiveGrant` honours `always`, `markApproved($id,$mode)`;
   `CommsAccessGrantService::hasActiveGrant(...,thread/comm)` + `requestAccess`/`approve`
   carry thread+mode; `Communication::scopeVisibleTo` adds the grant tier (null-thread via
   `communication_id`); `revokeAllActive`/`revokeForUser` skip `always`. Tests:
   per-thread grant opens only its thread; always survives a simulated midnight + logout;
   null-thread keyed on comm id; legacy whole-contact grant still works.
3. **Body-surface gap fix** — `CommunicationArchiveController::index/thread/show` enforce
   `scopeVisibleTo` + grant (decision 3). Test: granted-thread body opens; non-granted body
   403s with the unlock path; null-thread comm body via comm-id grant.
4. **Thread-list view** — controller builds `$contactThreads` (safe metadata, subject-hide);
   blade replaces the lock panel with visible + locked rows + per-thread Request access
   (No-Silent-Locks, design tokens, plain-English labels).
5. **Per-thread request + approve-with-mode** — request payload carries thread/comm; inbox
   names the thread + mode chooser; `authorize` accepts `grant_mode`; thread-settings
   endpoint for hide-subject (all under `/api/v1/*`, named, in the API catalogue).
6. **Audit wiring** — stamp `thread_key`/`communication_id` + `grant_mode` on every event;
   confirm `always` grants emit no midnight/logout events; manual-revoke logged.
7. **Robustness sweep** — server-side enforcement everywhere (list + body); AgencyScope on
   all new reads/writes; soft-deletes (no hard deletes); NULL owner/thread handled (never
   opens by accident, never crashes); nav path exists for any new surface (the inbox already
   has one); run the single most-relevant test file per change (non-negotiable #13); demo
   deploy if data-shape visible (non-negotiable #12).

---

## 4. Permissions

- No new capability. Reuse `communications.view` (scoped own/branch/all),
  `communications.grant_access` (authoriser + always-mode + hide-subject control),
  `access_communication_archive` (entry to the compliance archive area, now with per-thread
  row gating inside it). No hardcoded role names (CLAUDE.md / capabilities only).

## 5. Migration / compatibility

- Live `comms_access_requests` = **0 rows**; staging = **2** (both approved, end-of-day
  capped). New columns nullable + defaulted → **zero breakage**; legacy rows behave as
  whole-contact session grants until they expire that night.
- `comms_thread_settings` starts empty (default = subject shown).
- Live comms today = 95, all null-thread WA provisional; the null-thread `communication_id`
  path (decision 2) is therefore the **common** path on live, not an edge case — it is a
  first-class branch in the gate, list, and body fix.

## 6. Robustness & hard rules (Wave 1)

- Server-side enforcement on **both** the list and the body (decision 3) — UI hiding is not
  the gate.
- Immutable audit (append-only); soft-deletes only; AgencyScope intact.
- Null/empty `thread_key` isolated (keyed on `communication_id`, never grouped — decision 2).
- `always` grants survive logout + midnight; end only on explicit revoke (audited).
- NULL `owner_user_id` rows never open by accident under own/branch (AT-118 §9 preserved).
- Either/or authorisation (owner OR `grant_access`), never dual-control.
- Domain events (non-negotiable #9): if a cross-pillar reaction is needed (e.g. notify owner
  on an `always` grant), use the events catalogue, not ad-hoc calls.

## 7. Acceptance criteria (Wave 1)

- A non-owner with comms scope sees a **list of the contact's threads** (safe metadata; no
  bodies/previews) and can **request a specific thread**; the locked rows state why + offer
  Request access (No Silent Locks).
- An approved **session** grant opens **only that thread** for the session; after logout AND
  after 00:00 it does not. An approved **always** grant opens that thread and **survives**
  logout + the midnight reset; it ends only on explicit revoke.
- A grant on one thread does **not** reveal the contact's other threads.
- Null-thread comms gate on `communication_id` (granting one does not reveal other
  null-thread comms).
- The **body surface** (`compliance.comm-archive.thread/show`) returns a body only to a
  viewer who passes the per-thread gate; a non-granted body is **refused server-side** (403
  with unlock path), not merely hidden — verified by direct request.
- Owner can **hide a thread's subject**; the locked list then shows metadata only.
- Every request/grant(+mode)/decline/expiry/midnight/revoke writes one immutable
  `comms_access_audit_log` row stamped with thread + mode; `always` grants emit no
  midnight/logout rows.
- AgencyScope holds; no hard deletes; migration additive; existing grants unaffected.

## 8. Files to create / modify

**Create:**
- `database/migrations/xxxx_add_thread_scope_and_mode_to_comms_access_requests.php`
- `database/migrations/xxxx_create_comms_thread_settings_table.php`
- `app/Models/Communications/CommsThreadSetting.php`
- thread-list partial + locked-row + per-thread request UI (under contacts/show or a partial)

**Modify:**
- `app/Models/Communications/CommsAccessRequest.php` (scopes `forThread`/`forCommunication`,
  `scopeLiveGrant` + `isLiveGrant` honour `always`, `markApproved($id,$mode)`, fillable)
- `app/Services/Communications/CommsAccessGrantService.php` (`hasActiveGrant` thread/comm,
  `requestAccess`/`approve` carry thread+mode, `revokeAllActive`/`revokeForUser` skip
  `always`, audit stamps)
- `app/Models/Communications/Communication.php` (`scopeVisibleTo` grant tier)
- `app/Http/Controllers/CoreX/ContactController.php` (gate rewrite + `$contactThreads`)
- `app/Http/Controllers/Communications/CommsAccessRequestController.php` (thread/comm on
  request, `grant_mode` on authorize, thread-settings endpoint)
- `app/Http/Controllers/Compliance/CommunicationArchiveController.php` (per-thread gate on
  index/thread/show — decision 3)
- `resources/views/corex/contacts/show.blade.php` (thread-list replaces lock panel)
- `resources/views/corex/communications/access-inbox.blade.php` (thread context + mode chooser)
- `routes/web.php` / `routes/api.php` (request payload, authorize `grant_mode`, thread-settings
  route under `/api/v1/*`, named)
- `app/Models/Communications/CommsAccessAuditLog.php` (optional `EVENT_REVOKE` in
  `EVENT_TYPES`)
- `database/schema/mysql-schema.sql` (re-dump), `.ai/CHAT_STARTER.md` (AT-132 → IN FLIGHT)

## 9. Out of scope (Wave 1)

- **OTP break-glass grant mode** (`grant_mode='otp'`) + AT-130 `OtpService` — **Wave 2**.
- Property-pillar comms surface (AT-118 fast-follow).
- Switching email ingestion ON (separate go-live step).
- Per-identifier opt-out / AT-112 pack gating (separate tickets).
