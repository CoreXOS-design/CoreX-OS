# CoreX OS — AT-118: Communications Access Gate

**Spec ID:** AT-118
**Status:** Draft — ready for build (decisions locked)
**Author:** Johan (product owner — requirements) + Claude (senior engineer — implementation)
**Date:** 2026-06-30
**Audit basis:** `.ai/audits/2026-06-30-at118-comms-access-gate-audit.md`
**Locked design session:** 2026-06-17 (the access model — do NOT re-litigate)
**Depends on:** AT-122 (ingestion + `communications.owner_user_id` stamp), AT-120 (capability/scope
pattern). Relates: AT-112 (viewing-pack gating — same shared-permission family).

---

## 0. Johan's two non-negotiable requirements (the spine)

1. **A clear, complete, immutable audit log** — who was who, when; who did what, when. Every access
   request, grant, decline, expiry, and ownership transfer is recorded immutably.
2. **Clear agent attribution on the user-facing side** — at any time, the UI shows which agent was/is
   on a property and a contact. Attribution is never ambiguous.

Every implementation decision below serves these two. Internal mechanics are the engineer's call
provided these two hold.

---

## 1. Purpose

Ingested WhatsApp + email communications are linked to a contact behind a permission gate. Default
visibility is the owning agent + a grant-authorised role; everyone else must request access. This is
the security layer that must exist BEFORE email ingestion is switched on for all agents (so private/
business threads aren't exposed agency-wide). Match-only (AT-122) governs WHAT is ingested; AT-118
governs WHO sees it.

### Pillar connections
- **Contact** (primary): comms resolve to a contact via `communication_links`; the gate lives on the
  contact surface; reads contact ownership, writes the access-decision audit.
- **Agent** (`User`): the owning agent (`communications.owner_user_id`), the requester, the
  authoriser, and the successor; offboarding transfer touches agent attribution.
- **Property** (read, attribution only this build): Flow B's status-based stock split reads/writes
  `properties.agent_id`; the property-comms *surface* is a fast-follow (§3.6).

---

## 2. Locked Model (from the 2026-06-17 session)

**Default visibility (no request needed):**
- The OWNING AGENT — `communications.owner_user_id` (the agent whose mailbox/WA device ingested the
  message; stamped by AT-122; populates organically as ingestion runs).
- Holders of the new `communications.grant_access` capability (the "role-manager" of the locked model,
  implemented as a capability — see §3.1).

**Flow A — owner active (transient access):**
- A non-owner viewing a gated thread clicks "Request access".
- Notifies the owning agent AND `communications.grant_access` holders → EITHER may authorise
  (either/or, not dual control).
- On approval: requester sees that contact's threads for the CURRENT SESSION ONLY.
- Access ends at logout PLUS a MIDNIGHT auto-reset (closes the never-closing-session loophole).
- Every step POPIA-logged (req 1).

**Flow B — owner soft-deleted (permanent transfer):**
- MANDATORY successor nomination — an agent CANNOT be soft-deleted until an active successor is named.
- Bulk transfer to successor: contacts, properties (per §3.4 status rule), FICA, communications
  (the gate re-points automatically as owner_user_id / ownership moves).
- Stays with departed agent: deal register, commissions, and historic/sold stock (§3.4).
- The transfer is audit-logged (req 1); attribution stays clear (req 2).

---

## 3. Engineering Decisions (locked by Claude, serving §0)

### 3.1 Grant authority = new capability (not a hardcoded role)
- Register `communications.grant_access` via the existing capability system (the AT-120 recipe).
  Agency-configurable: each agency assigns it to whichever roles it wants (admin/owner by default;
  an agency may add an office manager / senior agent).
- Also register the scoped `communications.view` capability (own/branch/all via
  `PermissionService::getDataScope`) for the baseline gate, consistent with AT-120.
- No hardcoded role-name checks anywhere (CLAUDE.md). The audit confirmed there is **no role literally
  named "role-manager"** — this capability IS the locked model's "role-manager".

### 3.2 The gate (replaces the binary chokepoint)
- Today: `access_communication_archive` — one binary, content/agency-level capability at
  `app/Http/Controllers/CoreX/ContactController.php:320-332`. This is the chokepoint to replace.
- New gate logic at the same chokepoint: a user may see a contact's communications IF
  - they are the owning agent of the thread(s) (`owner_user_id`), OR
  - their `communications.view` scope covers it (own/branch/all per role config), OR
  - they hold an active session-scoped grant for that contact (Flow A), OR
  - they hold `communications.grant_access` (the authoriser sees in order to authorise).
- Enforced server-side at the chokepoint (not just hidden in the UI). Threads resolve to a contact via
  `communication_links` and to an owner via `owner_user_id` (audit confirmed; `communications` has no
  `contact_id` column).
- `access_communication_archive` is retained for the agency-wide compliance archive
  (`routes/web.php:1689`); the per-contact gate is the new logic. Confirm the two do not collide.

### 3.3 Flow A = fork AgencyAccessRequest
- The audit found `AgencyAccessRequest` (`app/Models/AgencyAccessRequest.php`) + its controller
  (`app/Http/Controllers/Api/AgencyAccessRequestController.php`) + the every-minute
  `agency-access:expire` command (`app/Console/Commands/ExpireStaleAccessRequests.php`,
  `routes/console.php:89`) is a complete request→approve→time-limited-grant→auto-expire framework
  (pending TTL, grant window, `granted_session_expires_at`, approver inbox, `lockForUpdate`). FORK
  this for comms access — do not build from scratch.
- Adaptations:
  - Grant is SESSION-SCOPED (ends at logout) AND midnight-reset (a scheduled job revokes all active
    comms grants at 00:00 agency-time — the machinery is the expire-command pattern; the midnight
    policy is the new part).
  - Scope a grant to a SINGLE contact's threads (not an agency-wide switch).
  - Notify owner + grant_access holders; EITHER approves (first authoriser to act wins; never
    dual-control); POPIA-log request/grant/decline (§3.5).

### 3.4 Departed-agent stock = status-based split
- On agent soft-delete (Flow B), partition the departed agent's properties:
  - ACTIVE / on-market (per the agency property-status "active" semantics) → TRANSFER to successor
    (someone must service live listings; comms + owner_user_id re-point).
  - SOLD / registered / historic → STAY attributed to the departed agent (their track record /
    earned attribution; moving them would rewrite who transacted).
- Contacts, FICA, and communications transfer to the successor. Deal register + commissions stay.
- Implementation: extend `app/Services/Admin/AgentDeletionService.php` (which already reassigns
  contacts + properties via `reassignAndCleanup()` and sets the mandatory QR reroute via
  `setQrReroute()`) to (a) make successor nomination MANDATORY (block delete without it), (b) apply the
  status-based property split, (c) include FICA (`fica_submissions.requested_by` et al.) + comms
  (`communications.owner_user_id`) reassignment. **Do NOT call `reassignDeals()`** — deals/commissions
  stay with the departed agent.
- REQUIREMENT (req 2): the user-facing side always shows which agent is/was on each property and
  contact. Transferred records show the successor as current; historic records keep the departed
  agent's attribution. The transfer event is audit-logged (req 1).

### 3.5 Audit log = dedicated immutable table
- New `comms_access_audit_log` (modelled on the immutable `signature_audit_log` /
  `mailbox_credential_reveals` patterns the audit praised). Append-only, no updates, no hard deletes.
  Uses `BelongsToAgency`; AgencyScope intact.
- Records EVERY: access request (who, which contact, when), grant/decline (who authorised, when),
  session-grant expiry / midnight reset, and Flow B ownership transfers (who moved what to whom, when).
- Suggested columns (engineer's discretion, must satisfy req 1): `agency_id`, `contact_id` (nullable
  for transfer events), `communication_id`/`thread_key` (nullable), `actor_user_id`, `actor_type`
  (user/system), `action` (requested/granted/declined/revoked/expired/midnight_reset/transferred),
  `subject_user_id` (owner/successor as relevant), `granted_until`, `reason`, `metadata` (JSON),
  `ip_address`, `user_agent`, `created_at`. No `updated_at`/`deleted_at`.
- This table is the literal embodiment of req 1 — the single source of "who was who when, who did what
  when." Queryable for POPIA evidence.

### 3.6 Surface scope
- v1: the CONTACT communications gate (comms resolve on the contact).
- Property-pillar comms visibility: noted as a fast-follow (net-new surface; build after the core gate
  is proven and ingestion is switched on). Out of scope for this build.

---

## 4. Build Order

1. Register `communications.view` (scoped own/branch/all) + `communications.grant_access` capabilities
   in `config/corex-permissions.php` (AT-120 recipe) + `scope_defaults`. Run
   `corex:sync-permissions --merge-defaults` on each environment after deploy.
2. `comms_access_audit_log` migration + immutable model (append-only) + a thin
   `CommsAccessAuditService` logging sink. Wire it FIRST so every subsequent step logs into it.
   Re-run `php artisan schema:dump` after the migration (non-negotiable #12a).
3. Replace the binary chokepoint (`ContactController.php:320-332`) with the new gate logic (§3.2):
   owner OR scope OR active-grant OR grant_access-holder. Server-side. Default visibility correct.
   Add a `Communication::scopeVisibleTo()` (net-new; mirror the AT-120 `OutreachQueue` pattern but
   key on `owner_user_id` + contact-link).
4. Flow A: fork `AgencyAccessRequest` → comms access request → notify owner + grant_access holders →
   either approves → session-scoped grant (scoped to one contact) → contact-scoped visibility.
   Log every step into `comms_access_audit_log`.
5. Midnight reset: scheduled job (`dailyAt('00:00')`, the established idiom) revokes all active comms
   grants at 00:00 agency-time (+ logout already ends session grants). Log the reset.
6. Flow B: extend `AgentDeletionService` — mandatory successor (block delete without), status-based
   property split (§3.4), FICA + comms owner reassignment, gate re-points. Log the transfer.
7. User-facing attribution (req 2): confirm contact + property surfaces clearly show the current/
   historic agent; the comms tab shows the owning agent + access state (you have access / request
   access / granted until logout).
8. Robustness + nav + POPIA-log verification pass.

---

## 5. Data Model / Migrations

- **New table:** `comms_access_audit_log` (§3.5). Immutable, append-only, `BelongsToAgency`.
- **New permissions:** `communications.view` (scoped), `communications.grant_access` (config entries +
  scope_defaults in `config/corex-permissions.php`).
- **Forked grant model:** a comms-access request/grant model forked from `AgencyAccessRequest`
  (engineer names it; e.g. `CommsAccessRequest`) — pending TTL + session-scoped grant + contact scope.
- **No schema change to** `communications` (uses existing `owner_user_id` from AT-122) or
  `communication_links`.
- **Flow B touches existing columns only:** `contacts.agent_id`/`second_agent_id`,
  `properties.agent_id`/`pp_second_agent_id` (status-split), `fica_submissions.requested_by` et al.,
  `communications.owner_user_id`. No new columns required for transfer.

---

## 6. UI Placement & Navigation

- **Comms tab on contact** (`resources/views/corex/contacts/show.blade.php`): same tab, new states —
  *you have access* (renders archive), *request access* (button → Flow A), *granted until logout*
  (banner per STANDARDS "No Silent Locks": say why locked + offer the unlock action).
- **Approver inbox:** the request surfaces to the owning agent + grant_access holders via in-app
  notifications (Laravel notifications, already wired) + an inbox view (fork the AgencyAccessRequest
  approver-inbox surface). Approve/decline inline.
- **Offboarding modal** (`UserManagementController` delete preview): successor selector becomes
  MANDATORY (cannot submit without an active successor); show what transfers vs stays.
- **No orphaned pages** (non-negotiable #2): every new view has a nav path the same day.

---

## 7. Permissions Required

- `communications.view` — scoped own/branch/all (baseline gate).
- `communications.grant_access` — authorise Flow A requests + see-to-authorise (the locked model's
  "role-manager"). Agency-configurable; default admin/owner.
- Offboarding remains under existing user-management permissions (`users.archive` /
  `manage_users`); successor nomination is enforced in the flow, not a new capability.

---

## 8. User Flow (step by step)

**Flow A (non-owner requests access):**
1. Agent opens a contact whose comms they don't own and lack scope/grant for → sees "Request access"
   (not the threads).
2. Clicks Request access → `CommsAccessRequest` created (pending TTL) → audit-logged (requested).
3. Owning agent + grant_access holders notified (in-app).
4. Either authoriser approves or declines → audit-logged (granted/declined). First to act wins.
5. On approval, requester (current session) sees that contact's threads. Banner: "Granted until
   logout."
6. Access ends at logout OR at the next 00:00 midnight reset → audit-logged (expired/midnight_reset).

**Flow B (offboarding):**
1. Admin opens delete/offboard for an agent → must select an ACTIVE successor (blocked otherwise).
2. On confirm: active properties + contacts + FICA + comms `owner_user_id` transfer to successor;
   historic/sold stock + deals + commissions stay with the departed agent; QR reroute set.
3. Agent soft-deleted. The comms gate now re-points to the successor automatically.
4. Whole transfer audit-logged (transferred); contact/property attribution reflects successor (current)
   and departed agent (historic).

---

## 9. Robustness & Hard Rules

- Server-side enforcement at the chokepoint (UI hiding is not the gate).
- Immutable audit log (append-only; no update/hard-delete path).
- No hardcoded role names (capabilities only).
- Session grant ends at logout AND midnight; a never-closed session cannot retain access past 00:00.
- Mandatory successor BLOCKS soft-delete (no orphaned comms/contacts; no house-account fallback).
- AgencyScope intact; soft-delete discipline (no hard deletes).
- `owner_user_id` may be NULL on legacy/outbound rows — the gate handles NULL owner gracefully (NULL
  owner = no owning agent → falls to scope/grant rules; never crashes, never opens by accident).
- Either/or authorisation (owner OR grant_access holder), never dual-control.
- Domain events (non-negotiable #9): emit named events for access-granted / ownership-transferred
  rather than ad-hoc cross-pillar calls; check `.ai/specs/corex-domain-events-spec.md` for existing
  events before inventing new ones.

---

## 10. Acceptance Criteria

- A plain agent who neither owns nor has scope/grant for a contact's comms sees "Request access", not
  the threads — verified server-side (direct request to the data path is refused, not just hidden).
- The owning agent sees their contact's comms with no request.
- A grant_access holder can authorise; on approval the requester sees the threads for that session
  only; after logout AND after 00:00 they do not.
- Every request/grant/decline/expiry/midnight-reset/transfer writes one immutable row to
  `comms_access_audit_log` with who/what/when (req 1) — verified by inspecting the rows.
- An agent cannot be soft-deleted without an active successor; on delete, active stock + contacts +
  FICA + comms transfer, historic stock + deals + commissions stay; gate re-points (req 2) — verified.
- Contact + property surfaces show current vs historic agent unambiguously (req 2).
- `corex:sync-permissions --merge-defaults` registers the new capabilities; sidebar/route/controller
  all gate correctly; nav path exists.
- NULL `owner_user_id` rows never open by accident and never crash the gate.

---

## 11. Files to Create or Modify

**Create:**
- `database/migrations/xxxx_create_comms_access_audit_log_table.php`
- `app/Models/Communications/CommsAccessAuditLog.php` (immutable; `BelongsToAgency`)
- `app/Services/Communications/CommsAccessAuditService.php` (logging sink)
- `app/Models/Communications/CommsAccessRequest.php` (forked from `AgencyAccessRequest`)
- controller for the comms-access request/approve workflow (forked from
  `Api/AgencyAccessRequestController`)
- `app/Console/Commands/Communications/ResetCommsAccessGrants.php` (midnight reset)
- approver-inbox view + request-access UI partial

**Modify:**
- `config/corex-permissions.php` (new capabilities + scope_defaults)
- `app/Http/Controllers/CoreX/ContactController.php:320-332` (new gate logic)
- `app/Models/Communications/Communication.php` (add `scopeVisibleTo`)
- `resources/views/corex/contacts/show.blade.php` (access states on the comms tab)
- `app/Services/Admin/AgentDeletionService.php` (mandatory successor, status split, FICA + comms reassign)
- `app/Http/Controllers/Admin/UserManagementController.php` (mandatory successor enforcement)
- `routes/web.php` (request/approve routes under `/api/v1/*`, non-negotiable #7) + `routes/console.php`
  (midnight reset schedule)
- `database/schema/mysql-schema.sql` (re-dump after migration)
- `.ai/CHAT_STARTER.md` (move AT-118 to IN FLIGHT)

---

## 12. Out of Scope (this build)

- Per-identifier / property-pillar comms surface (fast-follow).
- Switching email ingestion ON (separate go-live step AFTER this lands + promotes).
- Per-identifier opt-out, AT-112 pack gating (separate tickets in the same permission family).
- Programmatic send / any ingestion behaviour change (AT-122 owns ingestion).
