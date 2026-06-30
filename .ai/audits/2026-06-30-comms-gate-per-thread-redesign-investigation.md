# Investigation — Comms Gate Per-Thread Redesign (basis for spec)

**Date:** 2026-06-30
**Status:** INVESTIGATION ONLY (no code). Basis for the per-thread gate redesign spec
that supersedes AT-118's contact-level grant and folds in AT-130 (OTP break-glass).
**Read:** CLAUDE.md, `.ai/specs/at118-communications-access-gate.md`, AT-130 (Jira — no
repo file exists), comms data layer (models/services/migrations/controllers/views).

> **AT-130 note:** there is **no `.ai` audit/spec file for AT-130**. The "OTP audit
> findings" referenced are not committed. AT-130 (Jira `AT-130`, **status: To Do**,
> no comments) = *"Canonical OTP service (build once) — first consumer: comms gate
> break-glass unlock (admin, owner unreachable); supplement-not-replace, loudly
> audited."* Its design leans (captured below in §C2) come from the Jira description.
> The promised OTP-mechanisms audit is still "in flight" — flagged as a spec gap.

---

## A. THREAD STRUCTURE

### A1. How comms group into threads (`communications.thread_key`)

- Column: `communications.thread_key` `varchar(255)` NULLABLE, indexed
  (`comm_thread_idx`) — `database/migrations/2026_06_26_000001_create_communications_table.php:26,48`.
- **Email** — `ImapMailboxPoller::threadKey()`
  (`app/Services/Communications/ImapMailboxPoller.php:361-373`): takes the **first
  `References` header id**, else **`In-Reply-To`**, else the message's **own
  `Message-ID`**. So a thread is keyed by the conversation's root Message-ID; a brand-
  new email is a thread-of-one keyed by itself. Standard RFC-5322 threading. **Reliable
  for well-behaved clients; subject is NOT used** — a client that strips
  References/In-Reply-To starts a new thread (acceptable, conservative).
- **WhatsApp** — `WaArchiveIngestor` sets `thread_key = $chatId` (the WA chat id)
  (`app/Services/Communications/WaArchiveIngestor.php:20,96`). One chat = one thread.
  **Very reliable** when the capture path runs.
- **Outbound provisional** (comms-tile quick-send) — `OutboundProvisionalLogger`
  sets `thread_key = null` (`app/Services/Communications/OutboundProvisionalLogger.php:45`);
  `ProvisionalReconciler` later fills it from the matched real send if any
  (`ProvisionalReconciler.php:99`). So provisional rows are null-thread until reconciled.

### A1b. Can we list "threads for a contact" cleanly? — YES.
Comms resolve to a contact via `communication_links` (polymorphic:
`linkable_type=Contact::class, linkable_id=contact->id`) — used in
`ContactController.php:335-338` and `CommsAccessGrantService::commsForContact()`
(`:252-259`). Group that set by `thread_key` → threads. **AT-127 already builds exactly
this grouping** in `Communication::scopeVisibleTo` (b2 subquery,
`app/Models/Communications/Communication.php:190-203`) — reuse it (see §E2).

**Per-thread metadata available WITHOUT the body** (all already columns on
`communications`): `thread_key`, `subject` (string 1024), `occurred_at` (→ first/last
message date + count via GROUP BY), `channel` (email/whatsapp), `direction`,
`from_identifier`, `participant_identifiers` (JSON to/from/cc), `has_attachments`,
`owner_user_id` (→ owning agent name). A thread-list row = `{subject, channel,
msg_count, last_date, owner_agent, has_attachments}` — **no body needed**.

### A2. Safe-to-show metadata vs gated body
- **Body / must stay gated:** `body_text` (mediumText), `body_preview` (255 — a slice of
  the body; **TREAT AS BODY**, do NOT show in the locked list), `raw_path` (raw payload
  on disk), `attachments` content.
- **Safe-ish metadata:** `channel`, `direction`, `occurred_at`, `has_attachments` (bool),
  message count, `owner_user_id`.
- **`subject` — JUDGEMENT CALL (flag for Johan).** It is the natural list-row label, but
  an email/WA subject can itself leak ("Re: John's divorce settlement", "Offer R2.4m
  — confidential"). Live staging subjects today are benign ("Invoice", "PROGRESS
  REPORT : SECTION 8 …") but that is not a guarantee. **Options for the spec:** (a) show
  subject in the locked list (max disclosure, simplest); (b) show subject only for
  email and a generic "WhatsApp conversation" for WA (WA has no subject anyway — it is
  NULL); (c) redact to channel + date + count only ("Email thread · 3 messages · 29 Jun")
  until granted. **Recommend (b)+(c) hybrid: never show `body_preview`; show `subject`
  but allow a per-agency "hide subjects in locked list" toggle.** Decision belongs in
  the spec.

---

## B. CURRENT GRANT SCOPE — confirms the flaw (it is CONTACT-level, not thread)

### B1. The grant opens ALL of a contact's threads. Exact points:
- **Store/model:** `CommsAccessRequest` is keyed on `contact_id` only — no thread
  reference (`app/Models/Communications/CommsAccessRequest.php:27-32`; scopes
  `forContact($contactId)` `:65-68`).
- **The live-grant check is per-contact:** `CommsAccessGrantService::hasActiveGrant(User,
  Contact)` → `byRequester()->forContact($contact->id)->liveGrant()->exists()`
  (`app/Services/Communications/CommsAccessGrantService.php:32-48`). One boolean per
  contact.
- **The gate then opens the WHOLE contact:** `ContactController::show`
  (`app/Http/Controllers/CoreX/ContactController.php:329-348`) — if `$hasGrant` is true,
  `$canViewComms = true` and `$contactComms = $contactCommsQuery->get()` returns **every
  thread linked to the contact, unfiltered** (no `scopeVisibleTo`). → **a single grant =
  every thread on that contact.** This is the flaw to fix.
- (For own/branch scope without a grant, `scopeVisibleTo` already filters per-thread by
  ownership/participation at `:349-353` — so the *scope* path is already thread-granular;
  only the *grant* path is contact-wide.)

### B2. `comms_access_requests` schema — what must change for thread-scoping
Current columns (`2026_07_13_000001_create_comms_access_requests_table.php`):
`agency_id, contact_id, requester_user_id, status, reason, denial_reason,
authorized_by_user_id, authorized_at, expires_at, granted_session_expires_at,
revoked_at, revoked_reason, session_id` + timestamps + softDeletes.
**No `thread_key` / no thread reference — only `contact_id`.**

To make a grant THREAD-scoped, add a **nullable `thread_key`** (varchar 255) column:
- request created for a specific `(contact_id, thread_key)`;
- `NULL thread_key` = a legacy/whole-contact grant (back-compat) OR the deliberate
  "all threads on this contact" grant mode;
- index `(requester_user_id, contact_id, thread_key, status)`.
Then `forContact()` gains a `forThread(?$threadKey)` sibling and `hasActiveGrant` becomes
`hasActiveGrant(User, Contact, ?string $threadKey)` (NULL-thread grant still satisfies
any thread on the contact, so "always all" remains expressible).

### B3. How the gate (`scopeVisibleTo` + controller) changes to per-thread
- **Add a grant tier to `scopeVisibleTo`** (today it is owner/scope/participant only;
  the grant tier lives in the controller as a contact-wide boolean). New shape:
  `owner OR scope OR participant OR (thread_key IN {threads this user holds a live
  grant for, for this contact})`. The grant set = `CommsAccessRequest::byRequester
  ->forContact->liveGrant()->pluck('thread_key')`; a NULL-thread grant means "all
  threads on this contact" (don't filter).
- **Controller (`ContactController::show:342-354`)** stops treating `$hasGrant` as an
  all-or-nothing contact switch. Instead it always runs `$contactCommsQuery
  ->visibleTo($viewer,$scope)` and `scopeVisibleTo` now unions in the per-thread grants.
  `$canViewComms` becomes "≥1 thread visible"; the locked LIST still renders for the rest
  (§D).
- **NULL thread_key comms** keep AT-127's behaviour (each is its own thread-of-one,
  matched per-message, never grouped) — a per-thread grant on a null-thread comm must
  key on the comm id, not thread_key (spec must handle: grant `thread_key=NULL` is
  ambiguous with "all", so null-thread single comms likely need a `communication_id`
  grant column OR are always treated under the contact-wide grant). **Flag for spec.**

---

## C. GRANT MODES

### C1. "ALWAYS / lifetime of this thread" (permanent grant) in the existing store
Today every grant is session + end-of-day capped:
- `markApproved()` sets `granted_session_expires_at = now()->endOfDay()`
  (`CommsAccessRequest.php:99-110`);
- `liveGrant()` requires `granted_session_expires_at > now()`
  (`CommsAccessRequest.php:76-81`);
- midnight job `revokeAllActive()` revokes every `liveGrant()`
  (`CommsAccessGrantService.php:133-157`, scheduled `comms-access:reset` dailyAt 00:00,
  `routes/console.php:93`);
- logout listener `revokeForUser()` revokes the user's live grants
  (`CommsAccessGrantService.php:164-184`).

**For an "always" mode**, add a grant **mode/kind** discriminator (e.g.
`grant_mode ENUM('session','always')` or a boolean `is_persistent`) and:
- `liveGrant()` becomes: `status=approved AND revoked_at IS NULL AND (grant_mode='always'
  OR granted_session_expires_at > now())` — a persistent grant has NULL/ignored expiry.
- **The midnight reset MUST skip 'always' grants** — `revokeAllActive()` query gets
  `->where('grant_mode','session')` (or `where('is_persistent',false)`). Same for the
  **logout** revoke (`revokeForUser`) — an "always" grant survives logout by definition.
- An "always" grant is necessarily **thread-scoped** (B2) — "always for this thread", not
  "always for everything". It still soft-revocable (owner/admin "Revoke access" →
  `markRevoked`), and every issue/revoke audited.
- **Coexistence:** session and always grants are just rows differing by `grant_mode`; the
  two reset paths filter on it. No conflict.

### C2. OTP break-glass (AT-130) as a grant MODE
AT-130's locked leans (from Jira) map cleanly onto a per-thread grant:
- **Who triggers:** admin / `communications.grant_access` holders only (never plain
  agent) — capability-gated, same authority set as today's approvers
  (`canAuthorize`, `CommsAccessGrantService.php:207-218`).
- **Supplement not replace:** OTP is break-glass when the owner is unreachable; the normal
  request→approve flow stays the everyday path.
- **What it produces:** a **session-scoped, midnight-reset, thread-scoped** grant —
  *identical scope/expiry to a normal Flow-A approval*, just self-authorised via a
  validated OTP instead of an owner click. So it is literally `grant_mode='session'`
  reached by a different door. It does NOT grant "always" and does NOT broaden scope.
- **Destination:** the requester's OWN verified email (NOT the fixed `.env` OTP address —
  else the `.env` mailbox holder is a master key). The promised audit of the `.env` OTP
  mailer config is still outstanding (spec gap).
- **Loudly audited:** `CommsAccessAuditLog::record()` **validates the event type against
  `EVENT_TYPES`** (`app/Models/Communications/CommsAccessAuditLog.php:36-42,111`) — so
  two new event constants **`otp_issued` + `otp_unlock` must be added to `EVENT_TYPES`**
  or `record()` throws. Every OTP issue and unlock writes a row (Johan req 1 — an
  unlogged OTP unlock = silent bypass).
- **Canonical service:** AT-130 wants ONE reusable `OtpService` (generate/deliver/
  validate/single-use/expiry/rate-limit/audit), comms-gate as first consumer — not a
  bespoke gate-OTP. **AT-130 is To Do / unbuilt** — the per-thread spec should treat the
  OTP mode as "depends on AT-130 `OtpService`" and define the consumer contract
  (purpose, destination, trigger capability, what unlocking grants, audit sink).

**Net:** three modes on the per-thread grant — **this-session** (Flow A approve, exists
today), **always-for-this-thread** (new persistent kind, C1), **OTP break-glass** (new
door to a session-kind grant, C2 / AT-130).

---

## D. THREAD-LIST UI (non-owner view)

### D1. What the contact Communications tab renders today
`resources/views/corex/contacts/show.blade.php`:
- **Owner / entitled view** (`$canViewComms`): full thread list, each row links to
  `compliance.comm-archive.show` ("Open thread →") (`:1868-1916`). Shows subject +
  `body_preview` + from + date + attachment flag.
- **Locked view** (`$canRequestComms`, comms-capable but no access): a **single full-panel
  lock** — 🔒 "Communications are private to the owning agent" + one optional-reason input
  + one **"Request access"** button for the **whole contact** (`:1917-1964`). **No thread
  list is shown at all** — the non-owner sees zero thread metadata today.
- Tab is hidden entirely if neither `$canViewComms` nor `$canRequestComms`
  (`:286, :1858`).

**The redesign** replaces the single lock panel with a **list of thread rows** (metadata
from §A1b, body/preview withheld) each carrying its **own "Request access"** control
(and, for authorisers, a mode chooser / OTP break-glass). Build a new partial; reuse the
row markup from the entitled list (`:1882-1910`) minus `body_preview`/thread-link.

### D1b. CRITICAL — the BODY surface is gated SEPARATELY (split-gate gap)
"Open thread →" links to `compliance.comm-archive.show` / `.thread`, which sits behind a
**different, agency-wide binary permission** `access_communication_archive`
(`routes/web.php:1697`). `CommunicationArchiveController::index/thread/show` does **NOT**
apply `scopeVisibleTo` or any per-contact/per-thread grant
(`app/Http/Controllers/Compliance/CommunicationArchiveController.php:19-70`). So today:
- the **thread LIST** on the contact tab is gated per-contact (AT-118), but
- the **thread BODY** is gated by the agency-wide compliance permission — anyone with
  `access_communication_archive` reads every body; anyone without it can't open the body
  even when AT-118 granted them the contact.

**The per-thread spec MUST close this:** either (a) enforce the per-thread grant on the
archive `thread/show` routes too (apply `scopeVisibleTo` + grant union there), or (b)
build a per-contact thread-body surface that reuses the gate. Otherwise "gate the body"
is not actually enforced where the body lives.

### D2. Owner-side approve UI + mode choice
- **Inbox:** `CommunicationArchiveController` … actually `CommsAccessRequestController::inbox`
  → `resources/views/corex/communications/access-inbox.blade.php`, route
  `corex.comms-access.inbox` (`routes/web.php:2517-2519`). Each row: "{requester} requests
  access to {contact}" + Approve / Decline / View contact (`access-inbox.blade.php:16-39`).
  Approve POSTs `/api/v1/comms-access/{id}/authorize` with `{decision}` only
  (`:54-68`; controller `authorize()` `CommsAccessRequestController.php:59-89`).
- **What needs adding for per-thread + mode:** (1) the request + inbox row must name the
  **specific thread** (subject/date), not just the contact; (2) the approve action gains a
  **mode choice** — "this session" vs "always for this thread"; (3) a separate
  **break-glass / OTP** affordance for `grant_access` holders when the owner is the one
  unreachable (self-authorise via OTP). `authorize()` gains a `mode`/`grant_mode` param;
  `approve()` (`CommsAccessGrantService.php:95-111`) sets `grant_mode` + (for 'always')
  a NULL/persistent expiry.

---

## E. MIGRATION / COMPAT

### E1. Existing rows — does thread-scoping break them?
- **LIVE (`nexus_os`): `comms_access_requests` = 0 rows.** Nothing to migrate. Adding a
  nullable `thread_key`/`grant_mode` is purely additive.
- **STAGING (`hfc_staging`): 2 rows, both `approved`** (test grants). A new nullable
  `thread_key` defaults NULL = "whole contact" → existing grants keep working unchanged;
  `grant_mode` defaults `'session'`. **No breakage.**
- **Comms data today:** LIVE = 95 comms, **all WhatsApp, all NULL thread_key**
  (comms-tile outbound-provisional sends; 0 email — ingestion still off). STAGING = 5
  email (4 distinct threads; one 2-message "Invoice"/"Re: Invoice" thread proves
  References/In-Reply-To grouping works) + 60 WhatsApp **all NULL thread_key** (all
  provisional quick-sends — the `WaArchiveIngestor` chat-id path is unexercised). →
  **Real multi-message threads exist only for email on staging; WA real-capture threading
  is not yet proven in data.** The null-thread case is the common case today — the spec's
  null-thread handling (§B3) is load-bearing, not an edge case.

### E2. Does AT-127 already give us the thread grouping?
**Yes.** AT-127 (live in `Communication::scopeVisibleTo`,
`app/Models/Communications/Communication.php:178-205`) already:
- groups a contact's comms by `thread_key` (b2 Eloquent subquery, AgencyScope +
  SoftDeletes safe, indexed `comm_thread_idx`);
- treats NULL/empty `thread_key` as isolated thread-of-one (b1 per-message match), which
  is the exact semantics the per-thread grant needs;
- maps participant→agent via `communication_mailboxes` only.
The per-thread grant tier (§B3) plugs into this same `scopeVisibleTo` as an additional
`orWhere(thread_key IN granted-threads)` branch — **reuse, don't rebuild.**

---

## SUMMARY — the redesign in one screen

1. **Thread structure** exists and is usable: email keys off References/In-Reply-To/own
   Message-ID; WA off chat id; provisional = null-thread. Group a contact's comms by
   `thread_key` (AT-127 already does). Safe metadata = channel/date/count/owner/attachment
   flag; **`body_preview` is body — never show it locked; `subject` is a judgement call.**
2. **The flaw is real:** grant is keyed on `contact_id` only
   (`CommsAccessRequest` + `hasActiveGrant` + `ContactController:345-348`) → one grant
   opens every thread. Add a nullable `thread_key` (+ index) to `comms_access_requests`;
   `hasActiveGrant` and `scopeVisibleTo` key on thread.
3. **Modes:** add `grant_mode` (`session` | `always`); `always` is thread-scoped, skipped
   by the midnight reset AND logout revoke. **OTP break-glass (AT-130, unbuilt) = a
   capability-gated door to a `session` grant** for the same thread — add `otp_issued` +
   `otp_unlock` to `CommsAccessAuditLog::EVENT_TYPES`.
4. **UI:** replace the single contact-level lock panel with a **per-thread metadata list +
   per-thread Request access**; inbox/approve gains thread context + mode choice.
   **Close the split-gate gap:** the body surface (`compliance.comm-archive.*`,
   `access_communication_archive`) must enforce the per-thread grant too, or be replaced
   by a gated per-contact thread-body view.
5. **Migration is trivial:** 0 live / 2 staging access-request rows, all additive nullable
   columns. AT-127 supplies the thread grouping to reuse.

**Open decisions for the spec author (Johan):** (a) show subject in locked list? (b)
null-thread grant keying — `communication_id` column vs always-under-contact-grant; (c)
fix the body surface in-place vs new per-contact thread view; (d) AT-130 `OtpService`
must be built first (it is To Do) — sequence it ahead of the OTP mode.
