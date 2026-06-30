# AT-118 — Communications Access Gate: Permission-Surface Audit

> **READ-ONLY audit. No code, no commits.** Maps the FULLY-LOCKED AT-118 decision
> set (design session 2026-06-17, on the Jira ticket) onto the existing code to
> determine what is already enforceable vs net-new. This is the foundation for the
> AT-118 spec — it does NOT re-decide anything.
> Date: 2026-06-30 · Branch: Staging · Author: Claude (Opus 4.8)
> Companion: `.ai/audits/2026-06-29-at122-ingestion-audit.md` (comms storage + `owner_user_id`)

---

## TL;DR — the gap map at a glance

| Locked decision | Verdict | Reuse / build |
|---|---|---|
| **Default visibility** = role-manager + owning agent | **NET-NEW gate**, reuses owner column | `communications.owner_user_id` (AT-122) is the owner signal; the chokepoint exists but is a single binary capability today. The per-agent scope is net-new. |
| **Flow A — request → owner/RM authorise** | **HIGHLY REUSABLE** | `AgencyAccessRequest` model + `AgencyAccessRequestController` + `agency-access:expire` (every-minute) is a complete request→approve→time-limited-grant→auto-expire framework. Fork it. |
| **Session-only grant + midnight reset** | **REUSABLE pattern, NEW policy** | AgencyAccessRequest grants a fixed 24h window stored in `granted_session_expires_at`. AT-118 needs *session-bound + nightly 00:00 revoke* — same machinery, different expiry policy (net-new `dailyAt('00:00')` job + session marker). |
| **POPIA log** (who asked / granted / when / which contact) | **REUSABLE patterns, recommend dedicated table** | `mailbox_credential_reveals` + `signature_audit_log` are the gold-standard immutable-append patterns; `contact_access_log` already auto-logs contact views. A dedicated `comms_access_audit_log` is the clean POPIA home. |
| **Flow B — mandatory successor + bulk transfer** | **PARTIALLY REUSABLE** | `AgentDeletionService` already does contacts+properties reassign + mandatory QR reroute. NET-NEW: the *successor-nomination gate* (delete can currently orphan), FICA reassign, and `communications.owner_user_id` reassign. Flow B must **NOT** call `reassignDeals()` (deals/commissions stay). |

**Critical naming clarification:** there is **no role literally named "role-manager"** in
CoreX. Role management is the **`admin`** role via the permissions `access_role_manager`
/ `edit_permissions` / `change_user_roles` (`config/corex-permissions.php:426-430`). The
locked model's "role-manager role" must be mapped in the spec to either (a) holders of
`access_role_manager` (admin/owner), or (b) a new dedicated grant capability. **Spec
decision needed — flagged below, not decided here.**

---

## A. CURRENT COMMS VISIBILITY (the chokepoint)

### A1. The single chokepoint — one binary capability

- **Controller:** `app/Http/Controllers/CoreX/ContactController.php:320-332`.
  - `:320` — `$canViewComms = (bool) auth()->user()?->hasPermission('access_communication_archive');`
  - `:322-331` — the `$contactComms` query runs **only inside `if ($canViewComms)`**; it pulls
    `Communication` rows `whereNull('purged_at')` and `whereHas('links', linkable_type=Contact, linkable_id=$contact->id)`, `orderByDesc('occurred_at')->limit(200)`.
  - `:340` — `$canViewComms` + `$contactComms` passed to the view.
- **View:** `resources/views/corex/contacts/show.blade.php`
  - `:274` — Communications tab label; badge count forced to `0` when `!$canViewComms`.
  - `:280` — tab hidden when `!$canViewComms`.
  - `:425-427` — summary cross-link gated on `$canViewComms`.
  - `:1835-1845` — the `#tab-communications` panel ("Communication Archive") loops `$contactComms`, gated `@if($canViewComms)`.
- **Gate is content/agency-level, NOT participant/owner-scoped.** It answers "may this user
  see the comms archive at all?" — not "is this user the owner of *this contact's* threads?"
  This is exactly the gap AT-118 closes. The chokepoint is ready for a scoped rule to slot in.
- Same capability also gates the agency-wide archive: `routes/web.php:1689` →
  `Compliance\CommunicationArchiveController`.

### A2. Who has the capability today

- **Definition:** `config/corex-permissions.php:122` —
  `access_communication_archive` (`section=compliance`, `module=communication_archive`).
- **Defaults:** `branch_manager` **HAS** it (`:591`); standard **`agent` does NOT**
  (absent from the agent include list) — a plain agent sees no Communications tab.
- Related capabilities: `triage_communications` (`:125`; granted to BM **and** agent `:688`),
  `view_communication_flag_register` (`:127`), `reveal_mailbox_credential` (`:132`).

### A3. How a thread resolves to a contact and to an owning agent

- **Thread → Contact:** polymorphic only. `communications` has **no `contact_id` column**.
  Association lives in `communication_links` (`Communication::links()` HasMany,
  `app/Models/Communications/Communication.php:47-50`) with `linkable_type=Contact`,
  `linkable_id`. Any per-agent rule must derive contact-ownership from `communication_links`
  (and optionally `from_identifier` / `participant_identifiers`).
- **Thread → owning agent:** `communications.owner_user_id` (AT-122) →
  `Communication::owner()` BelongsTo User (`Communication.php:57-60`).
  - Migration: `database/migrations/2026_07_11_000001_add_owner_user_id_to_communications_table.php:25`
    (nullable, `after('source_ref')`, `nullOnDelete`). Index `comm_agency_owner_idx` on
    `(agency_id, owner_user_id)` (`:31`).
  - **Stamped ONLY on new ingest / reconcile:** email → mailbox `user_id`
    (`EmailArchiveIngestor.php:119`), WhatsApp → device `user_id`
    (`WaArchiveIngestor.php:115`), reconcile (`ProvisionalReconciler.php:113`).
  - **Population reality:** the migration is dated **2026-07-11** (future-dated; not yet
    run on live/staging per the AT-122 audit). The only existing `communications` rows
    are the 86 live / 60 staging **outbound provisional logs** (written by
    `OutboundProvisionalLogger`), which predate the column → their `owner_user_id` is
    **NULL**. There are **0 ingested inbound rows anywhere**. So today `owner_user_id` is
    effectively **unpopulated** — it will populate organically as ingestion goes live.
    **Implication for AT-118:** the owner signal exists structurally but carries no data
    yet; the gate's "owning agent" branch is testable only against newly-ingested comms.
- **No per-agent visibility scope exists on the model** — there is no `scopeVisibleTo` on
  `Communication` (confirmed by grep). Net-new.

---

## B. THE PERMISSION / ROLE SYSTEM (reuse target)

### B1. Mechanism + the AT-120 "register a scoped capability" recipe

- **PermissionService** `app/Services/PermissionService.php`:
  - `getDataScope(User, string $module): ?string` (`:105-152`) → `own` | `branch` | `all` | `null`.
    Owner role → always `all` (`:108`). Unseeded-DB fallbacks: super_admin/admin→all,
    branch_manager/office_admin→branch, else→own (`:126-130`).
  - `userHasPermission(User, string $key): bool` (`:197-232`); owner bypass (`:200-201`);
    per-request cache (`:11-21`).
- **User entry points:** `User::hasPermission()` / `hasAnyPermission()` (`app/Models/User.php:694-702`).
- **Blade:** `@permission('key') … @endpermission` (`app/Providers/AppServiceProvider.php:578-580`).
- **Config structure** `config/corex-permissions.php`:
  - Permission defs `:33-521` (`key`/`label`/`section`/`type`/`module`/`sort_order`).
  - Per-role default-grant `include` arrays `:529-781` (keyed `super_admin`/`admin`/
    `branch_manager`/`agent`/`viewer`; super_admin = `'*'`).
  - `scope_defaults` `:788-794` (the only place `.view` scope is set on fresh install:
    branch_manager→branch, agent→own).
- **Canonical scoped-capability recipe (AT-120 `outreach_queue.*`):**
  1. Config: `outreach_queue.view/dispatch/cancel` (`config/corex-permissions.php:475-477`) +
     scope_defaults (`:788-794`).
  2. Model: `BelongsToAgency` + `BelongsToBranch` + `agent_id` owner + `scopeVisibleTo(Builder, User, ?string $scope)`
     (`app/Models/Outreach/OutreachQueue.php:5-6, 28-29, 50-51, 122-132`).
  3. Route gate: `->middleware('permission:outreach_queue.view')` (`routes/web.php:2494-2498`).
  4. Controller list: `$scope = PermissionService::getDataScope($user,'outreach_queue') ?? 'own'; Model::visibleTo($user,$scope)…`
     (`app/Http/Controllers/CoreX/OutreachQueueController.php:40-74`).
  5. Controller act-own: re-check capability + `agent_id === user->id` server-side on each action
     (`OutreachQueueController.php:118-208` dispatch, `:211-228` cancel).
- **Sync:** `app/Console/Commands/SyncPermissions.php` — `--merge-defaults` (`:209-304`, safe,
  inserts missing keys without overwriting customisations; run after deploy).

> **AT-118 fit:** the AT-120 recipe is the template for a new `communications.*` capability set
> (e.g. `communications.view` scoped, `communications.grant_access`, `communications.revoke_access`).
> BUT AT-118's default-visibility rule is **owner-identity-based** (`owner_user_id === user->id`),
> which is finer than own/branch/all. The own/branch/all scope still applies on top (role-manager =
> a scope of `all`/`branch`; everyone else = the owner-identity test + a transient grant).

### B2. Session-scoped / time-limited grant — REUSABLE (the headline find)

`app/Models/AgencyAccessRequest.php` + `app/Http/Controllers/Api/AgencyAccessRequestController.php`
is a **complete, production grant framework** for cross-agency access — and it is the closest
existing analogue to Flow A:

- States `STATUS_PENDING/APPROVED/DENIED/EXPIRED/CANCELLED` (`:21-25`); helper predicates `:100-103`.
- `PENDING_TTL_MINUTES = 5` (`:27`) — request auto-expires if not actioned.
- `GRANT_HOURS = 24` (`:28`) + `granted_session_expires_at` (`:40,46`) — the time-limited grant window.
- Controller workflow: inspect (`:29-87`) → request/store (`:94-167`, targets approvers) →
  approver inbox (`:228-257`) → authorize approve/deny w/ `lockForUpdate()` (`:264-310`) →
  requester polls status (`:175-200`) → confirm-switch populates session
  (`:317-344`, sets `agency_access_grant_until` session marker).
- Auto-expiry: `app/Console/Commands/ExpireStaleAccessRequests.php:27-40`, scheduled
  `Schedule::command('agency-access:expire')->everyMinute()` (`routes/console.php:89`).

> **Verdict — REUSABLE (high).** Fork this into a comms-access grant
> (`CommsAccessRequest` / `CommsAccessGrant`). The one **net-new policy difference**: AT-118
> wants **session-bound + a hard midnight (00:00) reset**, where AgencyAccessRequest uses a
> rolling 24h window. Implementation = the same model + every-minute expiry sweep, PLUS a
> `dailyAt('00:00')` job that revokes any live grant (the established scheduling idiom — e.g.
> `routes/console.php:96-97` comms prune jobs run `dailyAt`). Session-death revocation = clear
> the session grant marker on logout (mirror the `agency_access_grant_until` session pattern).

### B3. Notification / request mechanism — REUSABLE

- Standard Laravel notifications table (`database/migrations/2026_03_25_100209_create_notifications_table.php`);
  `User` is `Notifiable` (`app/Models/User.php:17`); notification classes in `app/Notifications/`.
- Event-type catalogue + dispatch log (`notification_event_types`, `notification_dispatch_log`
  migrations `2026_04_27_100001/3`) + per-user prefs (`UserNotificationPreference`).
- The AgencyAccessRequest approver-inbox pattern (B2) is itself the "request → someone
  authorises" workflow the locked model calls "modelled like a calendar-invite request".

> **Verdict — REUSABLE.** Notify the owning agent + the role-manager via in-app notifications
> on request; the approver-inbox + authorize endpoints mirror Flow A's "either one can authorise"
> (either/or, not dual-control — enforce by accepting the first approver to act).

---

## C. OFFBOARDING / SOFT-DELETE (Flow B)

### C1. How a user is soft-deleted today

- `User` uses `SoftDeletes` (`app/Models/User.php:16,23`); `deleted_at` present.
- Delete flow: `app/Http/Controllers/Admin/UserManagementController.php:909-962`:
  preview (`:873`), **mandatory QR reroute** to a successor (`:920-933`, via
  `AgentDeletionService::setQrReroute()`), **optional** reassignment (`:938-951`, gated on
  `has_any`), set `is_active=false` (`:955`), P24 push (`:958`), soft-delete (`:960`).
- **Current behaviour ≠ Flow B:** QR reroute is mandatory, but record **reassignment is
  optional** — if the admin skips it, the departed agent's contacts/properties keep the
  dead `user_id` (orphaned), and FICA/comms are never touched. There is **no
  successor-nomination gate** that blocks the delete. **This is the core net-new of Flow B.**

### C2. Agent-ownership column map (what transfers vs what stays)

| Domain | Column(s) | Table | Flow B action |
|---|---|---|---|
| Contacts | `agent_id`, `second_agent_id`, `created_by_user_id` | `contacts` (`Contact.php:34-35`; migration `2026_06_17_120000…:20,22`) | **TRANSFER** |
| Properties / "stock" | `agent_id`, `pp_second_agent_id` | `properties` (`Property.php:19`; `2026_02_25_201319…:40`, `2026_03_23_150000…:12`) | **TRANSFER current listings** — see note on "old stock" |
| FICA | `requested_by`, `verified_by`, `agent_verified_by`, `co_verified_by` | `fica_submissions` (`FicaSubmission.php:21,29,36,41`; `2026_03_26_100000…:15,23`) | **TRANSFER** — net-new (not handled today) |
| Communications | `owner_user_id` | `communications` (`Communication.php:28`; `2026_07_11_000001…:25`) | **TRANSFER** — net-new (gate re-points automatically once owner moves) |
| Comm links | `confirmed_by` | `communication_links` (`CommunicationLink.php:44`) | provenance, not ownership — leave |
| Deal register v1 | `deal_user.user_id`, `deal_settlements.user_id`, `deals.managed_by_user_id` | (`2026_01_15…`, `2026_01_16…`, `2026_07_03_000002…:19`) | **STAYS** (do NOT move) |
| Deal pipeline v2 | `deals_v2.listing_agent_id/selling_agent_id`, `deal_v2_agents.user_id`, `deal_v2_settlements.user_id` | (`2026_03_30_300003…`, `…300005…`, `…500003`) | **STAYS** |
| Commissions | `commission_ledger.user_id`, `revenue_share_ledger.receiving_agent_id`, `deal_money_lines.user_id` | (`2026_03_27_300000…:93,131`) | **STAYS** (moving rewrites who earned what) |

> "Old stock STAYS with departed agent" needs a one-line spec clarification (flagged below):
> the property `agent_id` is the same column for live and historic listings, so "transfer
> current, keep old" requires a status-based split (e.g. transfer on-market stock, leave
> sold/withdrawn/expired). Decision needed.

### C3. Bulk-reassign tooling — PARTIALLY REUSABLE

`app/Services/Admin/AgentDeletionService.php`:
- `preview()` (`:31-66`), `setQrReroute()` (`:78-92`), `reassignAndCleanup()` (`:115-211`,
  properties primary/secondary + contacts; soft-deletes events/tasks),
  `reassignDeals()` (`:251-308`), `moveAgentSlots()` (`:317-356`).

> **Verdict:**
> - **REUSE** `reassignAndCleanup()` for contacts + properties, and `setQrReroute()` (already mandatory).
> - **DO NOT CALL** `reassignDeals()` in Flow B — deals/commissions stay with the departed agent.
> - **NET-NEW:** (1) a **mandatory successor-nomination gate** that blocks `delete()` until an
>   *active* successor is chosen (no house-account fallback); (2) FICA reassignment
>   (`fica_submissions.requested_by` et al.); (3) comms reassignment
>   (`communications.owner_user_id`) — which automatically re-points the AT-118 gate.

---

## D. POPIA AUDIT LOG

Existing audit mechanisms (all append-only / immutable where it matters):

| Mechanism | Table | Migration / writer | Fit for AT-118 |
|---|---|---|---|
| Mailbox credential reveals | `mailbox_credential_reveals` | `2026_06_28_000002…:18-35`; writer `Settings/EmailSetupController.php:92-99` | **Gold-standard pattern** — `revealed_by` / `revealed_for_user_id` / `revealed_at` / `ip_address`, no soft-delete. Copy verbatim. |
| Signature audit log | `signature_audit_log` | `2026_02_26_600005…:11-31` | **Best structural template** — `actor_type`+`actor_id`, `action` string, `metadata_json`, immutable. |
| Impersonation logs | `impersonation_logs` | `2026_04_22_080001…:11-22`; writer `Admin/ImpersonateController.php:35-41,78-84` | Clean actor/subject/action/ip model. |
| Contact access log | `contact_access_log` | `2026_05_05_000017…:10-24`; **auto-writer** middleware `app/Http/Middleware/LogsContactAccess.php:39-48` (`action_type` enum view/edit/export/share/delete/merge) | Already logs *who viewed a contact*; lacks a `decision` field + per-thread granularity. Extend or sit alongside. |
| Contact consent records | `contact_consent_records` | `2026_05_05_000017…:26-46` | For *consent categories* (incl. `channel_whatsapp`/`channel_email`), not per-instance access. Keep separate. |

> **Verdict — patterns REUSABLE; recommend a dedicated `comms_access_audit_log` table** (net-new,
> following `mailbox_credential_reveals` + `signature_audit_log`): immutable append, `agency_id`
> + `contact_id` (+ nullable `communication_id`/`thread_key`), `actor_user_id`, `action`
> (requested/granted/declined/revoked), `granted_until`, `ip_address`/`user_agent`, `metadata`.
> Cleaner for POPIA than overloading `contact_access_log` (which conflates data-view with
> access-decision). Reuses `BelongsToAgency`; no soft-delete (an audit trail is never edited).

---

## E. THE GAP MAP (spec foundation)

| # | Locked decision | Classification | Reuse X / Build Y |
|---|---|---|---|
| 1 | **Default visibility** = role-manager + owning agent | **NET-NEW gate over reusable signals** | Owner signal = `communications.owner_user_id` (A3, exists; unpopulated until ingestion live). Chokepoint = `ContactController.php:320` (A1). Build: per-contact owner-identity test + role-manager scope, replacing the binary `access_communication_archive`. Use AT-120 recipe (B1) for the new `communications.*` capability + scope. |
| 2 | **Flow A** — non-owner requests → owner OR role-manager authorises (either/or) | **REUSABLE (fork)** | Fork `AgencyAccessRequest` + controller + approver-inbox + `agency-access:expire` (B2/B3). First approver to act wins (either/or). Notify via Laravel notifications (B3). |
| 3 | **Session-only grant + midnight 00:00 reset** | **REUSABLE machinery, NET-NEW policy** | Same grant model as #2 but: bind to session (clear marker on logout, mirror `agency_access_grant_until`) + a net-new `dailyAt('00:00')` revoke job (idiom at `routes/console.php`). Replaces the rolling `GRANT_HOURS=24`. |
| 4 | **POPIA log** (asked/granted/declined, when, which contact/threads) | **NET-NEW table, reusable patterns** | New `comms_access_audit_log` modelled on `mailbox_credential_reveals` + `signature_audit_log` (D). Write on request + grant + decline + revoke. |
| 5a | **Flow B** — mandatory successor blocks soft-delete | **NET-NEW** | Add a successor-nomination gate to `UserManagementController::delete()` (C1) — block until an active successor chosen; no house-account fallback. |
| 5b | **Flow B** — bulk transfer contacts+properties+FICA+comms | **PARTIALLY REUSABLE** | REUSE `AgentDeletionService::reassignAndCleanup()` (contacts+properties) + `setQrReroute()`. BUILD FICA reassign + `communications.owner_user_id` reassign (C2/C3). |
| 5c | **Flow B** — deals/commissions/old stock STAY | **REUSABLE-by-omission** | Do NOT call `reassignDeals()`. Clarify "old stock" split for properties (C2). |

### Open spec decisions surfaced (NOT decided here — for Johan)

1. **"Role-manager role" mapping** — there is no such role. Map to `access_role_manager`
   holders (admin/owner), or mint a dedicated `communications.grant_access` capability?
   (B intro.)
2. **"Old stock STAYS"** — properties use one `agent_id` for live + historic. Define the
   split (transfer on-market, leave sold/withdrawn/expired?). (C2.)
3. **Audit home** — dedicated `comms_access_audit_log` (recommended) vs extending
   `contact_access_log` with a `decision` column. (D.)
4. **Property visibility** — the ticket says comms are "optionally visible from the property."
   Confirm whether the gate also surfaces on the Property pillar, and reuse the same
   owner-identity test there. (Not yet wired anywhere — net-new surface.)

---

## Appendix — empirical data state (per AT-122 audit, re-confirmed)

- `0` ingested inbound `communications` rows on live / staging / dev; `0` paired WA devices;
  `0` active mailboxes. Existing rows = outbound provisional logs only (`owner_user_id` NULL).
- **No data-migration pressure:** AT-118 can ship its gate with no live ingested backlog to
  reclassify; `owner_user_id` populates organically as ingestion goes live (post AT-122).
