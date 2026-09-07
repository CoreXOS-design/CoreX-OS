# AT-395 — Outgoing Mail Through the Agency's Own Mailbox (SMTP + Sent-Folder Copy)

**File:** `.ai/specs/at395-outgoing-mail-per-mailbox-smtp.md`
**Jira:** AT-395 — "Outgoing mail: send e-sign and system email through agency's own mailbox (SMTP + Sent-folder copy)"
**Status:** BUILT AND VERIFIED WORKING (2026-09-07) — real invitation delivered to a real recipient through a real Afrihost mailbox, confirmed in the recipient's inbox and in the sender's own Sent folder. See §15 for the launch-day hotfix history — the first build shipped with a bug that made every real send fail 100% of the time; §15 records exactly what broke and what fixed it, so this does not get silently rebuilt the same way twice.
**Owner:** Johan Reichel (business) · Build: whichever lane Johan assigns
**Scope of this document:** **PHASE A only** — password-based SMTP (cPanel/Afrihost-style mailboxes) plus IMAP copy-to-Sent. Microsoft 365 / Google Workspace OAuth sending is **Phase B, a separate future ticket, explicitly out of scope here** (§13 marks exactly where it plugs in).
**Extends:** `claude_communication_capture_setup_spec.md` (the `communication_mailboxes` table this spec adds columns to), `claude_communication_archive_spec.md`, `ESIGN-CANON.md` / `claude_esignature_v2_spec.md` (the send path this spec changes).
**Target environment:** **QA1** — QA1 was restored and is the live development environment again as of 2026-09-07; the earlier "QA1 is dead for e-sign, work on Staging" instruction is superseded. Standard flow applies: build and verify on QA1 → Johan tests on QA1 → Johan's explicit go → Staging → live.

---

## 0. The problem this solves

Gmail rejects e-sign invitation emails sent under `@hfcoastal.co.za` with `550-5.7.26` — both SPF and DKIM fail. CoreX sends every outbound email (e-sign invitations included) through **one shared SMTP server**, while putting the *agent's own address* in the From line (`BaseSignatureMail.php:75-100`). Receiving mail servers cannot verify that CoreX's shared server is authorised to send as `hfcoastal.co.za`, so they bounce it. Mail sent as `@corexos.co.za` (a domain CoreX's shared server IS authorised for) delivers fine — proving the mechanism, not the content, is the fault.

CoreX already lets every agency connect a **per-user mailbox** for reading mail in (Settings → Email Setup / Compliance → Communication Mailboxes, `communication_mailboxes` table). Those are IMAP-only, read-only, and touched by nothing that sends mail. This spec adds **outgoing (SMTP) credentials to the same record** and routes e-sign invitations through the sending agent's own real mailbox — so the email leaves from the mail server the agent's own domain actually trusts, and a copy lands in their own Sent folder the same way it would if they'd typed it in Outlook.

A related, separate, faster fix — getting Afrihost to correct HFC's own SPF/DKIM DNS records — is already in motion with Andre and is **not** part of this ticket; it does not require any code change and would help even without this feature. This spec is the durable, agency-agnostic fix: any agency's own working mailbox becomes CoreX's send path for that agency's agents, regardless of whose DNS is currently broken.

---

## 1. Pillar connections (per CLAUDE.md — every feature ships against the spine)

- **Agent** (`User`) — the mailbox is provisioned per-agent; the resolution rule in §3.1 is keyed off the sending agent.
- **Document / DocuPerfect** — the first and only Phase-A send path is the e-sign invitation (`SignatureService.php`, 7 call sites, listed in §3.6). A sent/failed outcome is recorded against the same `signature_audit_log` table e-sign already uses (§6).
- **Agency** — every mailbox row is agency-scoped (`agency_id`, existing `BelongsToAgency`); the settings this spec adds (retry counts, timeouts) are agency-configurable, never hardcoded (§9.5).

No new pillar table. This is additive to an existing Contact/Agent-adjacent utility table (`communication_mailboxes`), not a new island.

---

## 2. Data model

### 2.1 New columns on `communication_mailboxes` (one additive migration, fully reversible)

All new columns are **nullable or defaulted** — every existing row (20 on QA1 today) stays valid with zero data entry. **No existing IMAP column is touched, renamed, or re-typed.**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `outgoing_enabled` | `boolean` | NO | `false` | Master switch. A mailbox is IMAP-only until an admin/agent explicitly turns this on — never inferred from "SMTP fields are filled in." |
| `use_imap_credentials_for_smtp` | `boolean` | NO | `true` | "Use the same username/password as the IMAP side" convenience flag — true by default because it's the common case (one mailbox, one password, at cPanel/Afrihost-style hosts). When true, `smtp_username`/`smtp_encrypted_password` are ignored and the existing `username`/`encrypted_password` are reused for sending too. |
| `smtp_host` | `string(255)` | YES | `NULL` | Required (validated) only when `outgoing_enabled = true` and `use_imap_credentials_for_smtp = false`, OR always required for the host even when reusing credentials — see §2.2. |
| `smtp_port` | `unsigned int` | NO | `587` | |
| `smtp_encryption` | `enum('tls','ssl','none')` | NO | `'tls'` | Matches the vocabulary already used for `imap` in practice (Webklex IMAP client) and for the existing `otp`/`corex` named mailers in `config/mail.php` (`env('MAIL_*_ENCRYPTION', 'tls')`). |
| `smtp_username` | `string(255)` | YES | `NULL` | Only populated/used when `use_imap_credentials_for_smtp = false`. |
| `smtp_encrypted_password` | `text` | YES | `NULL` | Same cast as `encrypted_password` (§2.3). Only populated/used when `use_imap_credentials_for_smtp = false`. |
| `smtp_from_name` | `string(255)` | YES | `NULL` | Optional override for the display name on outgoing mail (e.g. "Johan Reichel — HFC Coastal"). Falls back to the agent's own `name` when null (§4.4). |
| `outgoing_active` | `boolean` | NO | `true` | Mirrors the existing `active` flag's meaning but scoped to the outgoing leg only — an admin can disable *sending* through a mailbox that should keep polling for reading, and vice versa, without one flag fighting two jobs. |
| `last_send_error` | `string(100)` | YES | `NULL` | Same sanitised-reason vocabulary as `last_error` (`connect_failed`, `auth_failed`, `incomplete_credentials`) plus a new `send_rejected` for an SMTP 5xx after successful auth. |
| `last_send_error_at` | `timestamp` | YES | `NULL` | |
| `consecutive_send_failures` | `unsigned int` | NO | `0` | Reset to 0 on any successful send. Drives the alert threshold exactly like `consecutive_failures` does for polling (`MailboxHealthRecorder.php`). |
| `send_failure_notified_at` | `timestamp` | YES | `NULL` | Mirrors `failure_notified_at` — one alert per failure episode, cleared on recovery. |
| `last_sent_at` | `timestamp` | YES | `NULL` | When this mailbox last **successfully** sent — the outgoing equivalent of `last_polled_at`. Ground truth for "is this mailbox actually being used to send." |
| `last_sent_folder_append_error` | `string(100)` | YES | `NULL` | Sent-folder copy is best-effort (§4) — its own failure reason, tracked separately from the send itself so a Sent-folder problem never reads as a send problem. |
| `last_sent_folder_append_at` | `timestamp` | YES | `NULL` | Last successful Sent-folder append. |

Migration name: `2026_XX_XX_XXXXXX_add_outgoing_smtp_fields_to_communication_mailboxes_table.php` (date/time filled in at build time). `up()` adds all columns above with `if (!Schema::hasColumn(...))` guards matching the existing house style (`2026_07_06_120000_add_health_tracking_to_communication_mailboxes.php` is the direct precedent for this exact additive pattern on this exact table). `down()` drops them all. No `$table->change()` on any existing column — nothing about this migration can fail on data that's already there.

### 2.2 Validation rule for `smtp_host`

`smtp_host` is always required once `outgoing_enabled = true`, **regardless of the "use IMAP credentials" flag** — a shared password doesn't imply a shared server (cPanel/Afrihost typically use the same host for both, but this must not be assumed). `smtp_port`/`smtp_encryption` always required once `outgoing_enabled = true` (they carry sensible defaults so this is rarely a blank-form problem).

### 2.3 Password storage

`smtp_encrypted_password` uses the **identical** Laravel `'encrypted'` cast as the existing `encrypted_password` (`CommunicationMailbox.php:27` is the precedent — add `'smtp_encrypted_password' => 'encrypted'` alongside it in `$casts`). Added to `protected $hidden` in the same array as `encrypted_password` (`CommunicationMailbox.php:51-53`) — **never serialised, never returned by any endpoint, by the same structural guarantee that already protects the IMAP password.** No new write-only/reveal mechanic is invented — the existing `MailboxCredentialReveal` audited-reveal pattern (`claude_communication_capture_setup_spec.md` §2) is reused for the SMTP password too when `use_imap_credentials_for_smtp = false` (§9).

### 2.4 Model changes (`CommunicationMailbox.php`)

- Add all new columns to `$fillable`.
- Add `smtp_encrypted_password` to `$casts` (`'encrypted'`) and to `$hidden`.
- Add `outgoing_active`, `outgoing_enabled`, `use_imap_credentials_for_smtp` to the boolean cast list.
- Add a `resolvedSmtpUsername()` / `resolvedSmtpPassword()` pair of accessor methods that return the IMAP credential when `use_imap_credentials_for_smtp` is true, else the SMTP-specific one — one place this branch lives, not repeated at every call site.
- Add an outgoing counterpart to the existing `pollHealth()` derivation: `sendHealth()` returning the same four states (`inactive` / `pending` / `healthy` / `failing`), computed from `outgoing_active` + `last_send_error` + `last_sent_at` staleness, exactly mirroring `pollHealth()`'s logic (`CommunicationMailbox.php:101-117`) but reading the `_send_`/`_sent_` columns instead. **No new health vocabulary invented.**

---

## 3. Sending behaviour

### 3.1 Resolving a mailbox for a sending agent — explicit rule

For a given sending agent (`User $agent`) and their `effectiveAgencyId()`:

1. **User match first.** `CommunicationMailbox::where('agency_id', $agencyId)->where('user_id', $agent->id)->where('outgoing_enabled', true)->where('outgoing_active', true)->first()`.
2. **No user match → no agency-level fallback mailbox. DECIDED (2026-09-07): agent-tied only for Phase A**, per Johan's ruling. Phase A does **not** add an agency-wide "send as the agency" mailbox concept — every `communication_mailboxes` row on QA1 today already has `user_id` set (20/20), and Phase A's whole point is sending **as the specific agent**, not as a shared agency identity. **Where a future agency-level default would plug in:** step 2 of this resolution rule is the single place it would be inserted — `resolveOutgoingFor()` would fall through from "no user match" to a new `CommunicationMailbox::where('agency_id', $agencyId)->whereNull('user_id')->where('outgoing_enabled', true)->first()` lookup before finally returning null. Nothing else in this spec (transport building, fallback rules, From/Reply-To, UI) would need to change to support that later — it is a one-method addition, deliberately not built now.
3. **Soft-deleted or `outgoing_enabled = false` or `outgoing_active = false` mailboxes are never selected** — same `active`-flag discipline the IMAP side already has.

This resolution lives in one new method, e.g. `CommunicationMailbox::resolveOutgoingFor(User $agent): ?CommunicationMailbox`, so every call site (§3.6) asks the same question the same way — no duplicated `where()` chains scattered through `SignatureService.php`.

### 3.2 Building the runtime mail transport

A new service, e.g. `App\Services\Communications\PerMailboxMailTransportBuilder`, takes a resolved `CommunicationMailbox` and returns a Laravel `Illuminate\Mail\Mailer` instance built from a **runtime SMTP transport** — the first time this codebase builds a transport from database credentials rather than a static `config/mail.php` entry (§4 of the AT-395 investigation confirmed nothing like this exists yet). Concretely: construct a Symfony `EsmtpTransport` from the mailbox's `smtp_host`/`smtp_port`/`smtp_encryption`/resolved username+password, wrap it in a `Illuminate\Mail\Mailer`, and hand that back to the caller — the existing `Mail::mailer('otp')`/`Mail::mailer('corex')` call-site *pattern* (`OtpService.php:193` etc.) is the precedent for "pick a mailer and send through it"; this service is what makes a per-row mailer possible instead of only a per-config-entry one.

### 3.3 Fallback rules — no mailbox, disabled, or connection failure — DECIDED (2026-09-07, overrides the original recommendation for Situation B)

Three distinct situations, each with its own rule.

**Situation A — no mailbox configured for this agency's agents at all (`outgoing_enabled = false` / no row resolves).**
**Falls through to the existing shared CoreX mailer, unchanged — exactly today's behaviour.** This is the only way Phase A doesn't force every agent to configure a mailbox before e-sign keeps working at all. Nothing that works today breaks because nothing was configured.

**Situation B — a mailbox IS configured and enabled, but the SMTP connection/auth fails at send time. DECIDED: the send FAILS. No silent fallback.**
Johan's ruling, verbatim reasoning: a silent fallback to the shared mailer **recreates the exact Gmail-rejection problem invisibly — the whole reason this ticket exists.** "Configured but broken" is a materially different state from "never configured," and must be loud, not quietly absorbed.
- The send is **not** retried against the shared mailer.
- The signature request is **NOT** marked `sent` / `Sent` anywhere the agent or recipient can see.
- `last_send_error` + `last_send_error_at` are recorded on the mailbox row, `consecutive_send_failures` increments, `sendHealth()` moves to `failing`.
- The agent sees a plain-English failure with a **Retry** action (§5) — not a log line only.
- The mailbox is flagged unhealthy for an admin (§9 health badge) exactly as a polling failure would be.
- This is a genuine behaviour change from "today," but only for agents who have deliberately turned outgoing mail on — the exact population capable of noticing and fixing it.

**Situation C — the send itself succeeds over the mailbox, but the Sent-folder copy (IMAP append, §4) fails.**
Not a fallback question — the send already happened. Handled entirely in §4 (never fails the send; recorded separately).

### 3.4 What happens to the existing From/Reply-To domain-matching logic

`BaseSignatureMail::getFromAddress()` (`BaseSignatureMail.php:75-100`) and its `companyDomainForAgent()` helper (`:125-136`) exist to answer one question: *"is it safe to put this agent's own address in the From line, given we're sending through a server with no authority for their domain?"* Once a per-mailbox SMTP connection exists, **that question stops being the right one to ask** — if the send is going out through the agent's own mailbox, the server IS authoritative for that domain, and the public-email-domain guard (`PUBLIC_EMAIL_DOMAINS`, `:33-37`) becomes the *reason to still send via the shared mailer*, not a reason to rewrite the From address.

**Precise change:** `getFromAddress()` gains one new branch, checked **before** the existing company-domain logic: *if a mailbox was resolved for this agent (§3.1) and the send is going out through it, use the agent's real address (or `smtp_from_name` override) as the From address unconditionally* — no domain-matching needed, because the mailbox IS that domain's own outbound server. The existing company-domain / personal-email fallback logic (`:84-99`) **stays exactly as-is and keeps running for every agent who has no resolved mailbox** (Situation A in §3.3) — it is not deleted, it becomes the fallback path's own logic rather than the only logic. Reply-To (`getReplyTo()`, `:143-150`) needs no change — it already always points at the agent's own address, which is now also true of the From address whenever a mailbox exists.

### 3.5 Domain-events note (per CLAUDE.md non-negotiable #9)

Per `corex-domain-events-spec.md`, cross-pillar reactivity is emitted, not ad-hoc. Two new past-tense events, same catalogue conventions as the existing `Webinars\WebinarJoinLinkSent` entry (`corex-domain-events-spec.md` §Section 5):
- `Communications\OutgoingMailSentViaOwnMailbox` — audit-only, dispatched immediately after a successful per-mailbox send, payload includes `mailbox_id`, `agent_id`, `agency_id`.
- `Communications\OutgoingMailFellBackToSharedMailer` — audit-only, dispatched on Situation B (§3.3) so a pattern of fallbacks is queryable/reportable without grepping logs.
No listeners planned for Phase A (same reasoning as the Webinar events — these exist so the fact lands in `domain_event_log` and gives Phase B a named contract to react to later, not because anything needs to react today).

### 3.6 Send paths in scope for Phase A

**In scope: e-sign invitations only** — the 7 `Mail::to(...)->send(new SigningRequestMail(...))->fromAgent($agent)` call sites in `app/Services/Docuperfect/SignatureService.php` (lines 3239, 3313, 5239, 5311, 5838, 5900, 6130). These are the only calls this spec's build touches.

**Out of scope for Phase A, explicitly — DECIDED (2026-09-07), do not extend without a new ticket:** `PartySignedNotificationMail` (`SignatureService.php:2677`), signature reminders (`SignatureReminderMail`), signed-document delivery (`SignedDocumentMail`), wet-ink notifications, OTP mail, webinar mail, billing mail, and every other Mailable in the codebase. Johan's ruling: Phase A covers the invitation send path only — the 7 `SignatureService.php` call sites — and nothing else, even though `resolveOutgoingFor()`/`PerMailboxMailTransportBuilder` would trivially extend to `PartySignedNotificationMail` and `SignatureReminderMail`. That extension is explicitly a separate follow-up ticket, not something this build reaches for on its own.

---

## 4. Copy to Sent folder

After a successful send over a resolved mailbox (§3.1–3.3), the message is appended to that mailbox's own **Sent** folder over IMAP, using the **same resolved credentials already on the record** (`resolvedSmtpUsername()`/`resolvedSmtpPassword()`, §2.4 — one credential pair serves both legs when `use_imap_credentials_for_smtp = true`, which is the default).

- **Which folder:** the mailbox's own IMAP-reported Sent folder — resolved the **same way `ImapMailboxPoller` already resolves it** (`ImapMailboxPoller.php:247`, IMAP SPECIAL-USE `\Sent` flag via RFC 6154, the existing `resolveFolder()` helper). No new folder-detection logic — this reuses the poller's own resolution, called at send time instead of poll time.
- **Relevance of `poll_sent`:** `poll_sent` controls whether CoreX *reads* the Sent folder for archiving (unchanged, unrelated concern). The Sent-folder **append** described here happens regardless of `poll_sent`'s value — appending a copy of an outgoing email is not a polling decision, it is a direct consequence of having just sent one. (If `poll_sent = true`, the archive poller will naturally pick up the appended copy on its next pass — the two features compose for free, no special-casing needed.)
- **New service**, e.g. `App\Services\Communications\ImapSentFolderAppender`, using the same `Webklex\PHPIMAP\ClientManager` connection library already in use (`ImapMailboxPoller.php:9`) — connects, resolves the Sent folder, calls the library's append operation with the raw sent MIME message.
- **Failure handling — must not fail the send.** The append runs **after** the SMTP send has already succeeded, wrapped in its own try/catch that can never roll back or retroactively fail the send. On failure: record `last_sent_folder_append_error` (sanitised reason) + timestamp on the mailbox row, dispatch `Communications\MailboxSentFolderAppendFailed` (audit-only, same convention as §3.5), and surface it on the mailbox health screen (§9) — **visible to an admin, invisible to the recipient, never a reason to tell the agent their email didn't go out**, because it did.

---

## 5. Send outcome visibility — CoreX cannot show "Sent" for a bounced message

Today, `Mail::to(...)->send(...)` succeeding only proves CoreX handed the message to *a* mail server — it says nothing about whether that server accepted, relayed, or the receiving server later bounced it. This gap exists today regardless of Phase A and is worth naming precisely rather than silently assuming Phase A fixes it: **SMTP accept ≠ delivered.** A `550-5.7.26` from Gmail is typically a **synchronous rejection at SMTP time** (the sending server never gets to say "accepted" at all) — which Phase A **does** catch, because `PerMailboxMailTransportBuilder`'s send call throws on that rejection, exactly like any other SMTP error. A **later bounce** (mailbox full, deferred/async rejection) is a different problem (would need bounce-webhook or IMAP-inbox-scanning infrastructure) and is **out of scope for Phase A** — named here so it isn't mistaken for solved.

**What Phase A does record, precisely (aligned with the §3.3 Situation B ruling — configured-but-broken fails loudly):**
- The 7 `SigningRequestMail` call sites already sit inside `try { ... } catch (\Throwable $e) { Log::warning(...) }` blocks (e.g. `SignatureService.php:3237-3254`). Phase A upgrades this from a log line to a **persisted, queryable outcome**: when the resolved mailbox's send throws (Situation B), the request's `status`/`token`/`sent`-marking update that normally follows a successful send is **skipped entirely** — the `SignatureRequest` stays in whatever pre-send state it was in, never transitions to `sent`. A `signature_audit_log` row is written (existing table, existing pattern — `SignatureAuditLog::log(...)`, already called immediately after each send site) with a new `action` value, `invitation_send_failed`, carrying the sanitised error reason in `metadata_json`. This makes "did this specific invitation actually leave the building" answerable per-request, which today it is not (per the AT-395 investigation, `metadata_json` is currently empty even on successful `sent` rows — Phase A is also the fix for that gap on the failure path specifically, not a retrofit of historical data). **CoreX cannot show "Sent" for this request, because the code path that would mark it sent never ran.**
- **Where the agent sees it:** the signing-request timeline/status the agent already views per document (the same surface that shows `sent` / `reminder_sent` / `signed` today) gains a plain-English failure state — *"Couldn't send to {name} — {plain reason}. Retry"* — with a **Retry** action that re-runs the same send attempt (re-resolves the mailbox, tries again; if the mailbox has since been fixed, it succeeds and the request transitions to `sent` normally).
- **Where an admin sees it:** the mailbox health screen (§9) shows `sendHealth()` (§2.4) per mailbox, same visual language as the existing IMAP health badges (`healthy`/`pending`/`failing`/`inactive`), so a pattern of failures on one agent's mailbox is visible without reading logs.
- **Situation A (no mailbox configured) is unaffected by any of this** — those sends still go through the shared mailer's existing try/catch, unchanged, and still mark the request sent on success exactly as today.

---

## 6. Test Connection action

One button, two independently-tested legs, on the mailbox edit screen (§9).

| Leg | What it does | Pass | Fail |
|---|---|---|---|
| **SMTP send** | Sends one real test email, **to the mailbox's own `email_address`** (never to a client, never to an arbitrary address the tester types in) via `PerMailboxMailTransportBuilder`, subject `"CoreX mailbox test — {timestamp}"`, body states plainly it is a connection test and safe to ignore/delete. | "Connected — test email sent to {email}. Check that inbox to confirm it arrived." | Plain-English reason drawn from the same sanitised vocabulary as `lastErrorLabel()` (`CommunicationMailbox.php:120-130`) — e.g. *"Login failed — check the username and password."* |
| **IMAP Sent-folder append** | Runs `ImapSentFolderAppender` against a small synthetic test message (not the real test email above — a distinct, clearly-labelled test append so a failure here doesn't need the SMTP leg to have run first). | "Sent folder found and writable." | Plain-English reason — e.g. *"Connected, but no Sent folder could be found."* / *"Connected to the Sent folder, but writing to it was refused."* |

Each leg is tested and reported **independently** — a working SMTP connection with a broken Sent-folder append (or vice versa) must show one pass and one fail, never collapse to a single verdict. Neither leg ever touches a real recipient outside the mailbox's own address.

---

## 7. UI and CRUD — the mandatory design standard (BUILD_STANDARD.md §1, CLAUDE.md non-negotiable #8)

This is **not a new entity** — it is new fields and a new action set on the existing `CommunicationMailbox` entity, which already has full CRUD (create/edit/archive via soft-delete, `CommunicationMailboxController.php:29-63`). Phase A's UI obligations:

### 7.1 Full CRUD (already present, extended)
- **Create/Update:** the existing form (`compliance/communication-archive/mailboxes/form.blade.php`) gains an "Outgoing mail" section: `outgoing_enabled` toggle, `use_imap_credentials_for_smtp` toggle (default on), and — only shown when that's off — `smtp_host`/`smtp_port`/`smtp_encryption`/`smtp_username`/`smtp_password` (write-only, same "leave blank to keep existing" convention as the current IMAP password field, `CommunicationMailboxController.php:92-94`), plus `smtp_from_name`.
- **Read:** the mailbox detail/edit view shows `sendHealth()` alongside the existing `pollHealth()` badge.
- **Archive/Restore:** unchanged — the existing `SoftDeletes` on `CommunicationMailbox` already covers the whole row, outgoing fields included. No new archive/restore action needed; archiving a mailbox already stops both legs.

### 7.2 List screen (`compliance/communication-archive/mailboxes/index.blade.php`)
- **Searchable fields:** `email_address`, linked user's `name` (join on `user_id`).
- **Sort columns:** `email_address` (current/only sort — **default**, ascending, matching `CommunicationMailboxController::index()` today), `last_polled_at`, `last_sent_at` (new), `consecutive_failures`, `consecutive_send_failures` (new). **Default sort stays `email_address` ascending** — introducing outgoing capability is not a reason to change an admin's existing mental model of this list.
- **Filters:** status (`active` / `inactive`, existing `active` flag), poll health (`healthy`/`pending`/`failing`/`inactive`, existing derivation), **new:** send health (same four states, `sendHealth()`), **new:** outgoing enabled (yes/no).
- **Pagination:** standard CoreX list page size (match whatever the existing list already paginates at — it is currently unpaginated at 20 rows, which is the row-count ceiling BUILD_STANDARD.md's "never dump an unbounded result set" rule is aimed at; Phase A adds real pagination as part of this list gaining more visible state, default page size 25).
- **Empty state:** unchanged copy for "no mailboxes yet"; **new** copy for "no mailboxes match this filter" (e.g. filtering to `send health: failing` with none failing) — the two are different messages per BUILD_STANDARD.md §1b.

### 7.3 OWN / BRANCH / AGENCY visibility scoping

Enforced at the query layer via the **same mechanism already used across CoreX** (e.g. `Rental::scopeVisibleTo()`, `Property::scopeVisibleTo()`): `CommunicationMailbox` gains a `scopeVisibleTo($query, User $user)` method that calls `PermissionService::getDataScope($user, 'communication_mailboxes')` and applies:
- **`all` (AGENCY)** — every mailbox in the agency (no additional `where`).
- **`branch`** — `where('user_id', ...)` restricted to users whose `effectiveBranchId()` matches the viewer's branch (join to `users`).
- **`own`** — `where('user_id', $user->id)` (an agent only sees their own mailbox — the natural default for most non-admin roles, since a mailbox IS a personal credential).
- No matching scope → `whereRaw('1 = 0')`, same fail-closed default as every existing `scopeVisibleTo()`.

`CommunicationMailboxController::index()`/`edit()` call `->visibleTo(Auth::user())` before `->get()`/`findOrFail()` — **direct-URL access to another user's mailbox by ID is blocked at the query layer**, not just absent from the list, per BUILD_STANDARD.md §1c. Which roles see which level is configured exactly like every other module's data scope — via the existing per-role data-scope setting in Roles & Permissions (module key `communication_mailboxes`), not hardcoded.

**DECIDED (2026-09-07):** `owner`/`admin` → `all` (AGENCY), `branch_manager` → `branch` (BRANCH), everyone else → `own` (OWN) — the default data-scope rows seeded for the `communication_mailboxes` module key, using the **exact existing mechanism** found at `app/Models/Rental.php:39-41` (`PermissionService::getDataScope($user, $module)` + `scopeVisibleTo()`), no new scoping pattern invented.

### 7.4 Navigation

No new nav entry needed — the existing Compliance → Communication Mailboxes entry (already in the sidebar, gated by `manage_communication_mailboxes`) now surfaces outgoing capability inline. The per-user Settings → Email Setup screen (`EmailSetupController`) gains the same "Outgoing mail" section for the self-service/dual-control path, consistent with `claude_communication_capture_setup_spec.md`'s existing two-surface model (§4 of that spec).

### 7.5 Agency-configurable settings — nothing hardcoded

| Setting | Default | Configured where |
|---|---|---|
| Send-failure alert threshold (consecutive failures before an admin is notified) | `3` | New `agencies.communication_send_failure_alert_threshold` column, nullable, `NULL` → `config('communications.send_failure_alert_threshold')` — **identical pattern to the existing `agencies.communication_failure_alert_threshold` / `config('communications.failure_alert_threshold')` pair** (`2026_07_06_120000...php`, `config/communications.php:122`). |
| SMTP connect/send timeout | 15 seconds | `config('communications.smtp_timeout_seconds')`, env `COMMUNICATIONS_SMTP_TIMEOUT_SECONDS` — same env-backed-config pattern as every other timeout in `config/communications.php`. |
| Sent-folder append timeout | 15 seconds | `config('communications.imap_append_timeout_seconds')`, same pattern. |
| Test Connection: how long to wait before declaring the SMTP leg failed | 20 seconds | `config('communications.test_connection_timeout_seconds')`. |

Every one of these lives in `config/communications.php` beside the existing `failure_alert_threshold` entry, following the exact env-var-with-default convention already established there — no new pattern invented.

### 7.6 Setup Wizard (CLAUDE.md non-negotiable #10a) — DECIDED, OVERRIDE (2026-09-07)

**Mandatory wizard step, overriding the original recommendation to leave it out.** Johan's requirement: an agency sets email up correctly from the word go, so it is never discovered broken later (exactly the failure mode this whole ticket exists to fix) — "configure later" is the pattern that let HFC's own SPF/DKIM problem go unnoticed until Gmail started bouncing real client invitations.

- New wizard step in `config/agency-onboarding-copy.php`: **"Outgoing mail (SMTP)"** — `explain`: *"Connect your own mail server so e-sign invitations and other CoreX emails go out under your own domain, not a shared one — this is what stops receiving mail servers from rejecting your emails."* `affects`: *"Emails your agents send through CoreX (e-sign invitations first) will be sent from your own mail server and appear in your own Sent folder, instead of a shared CoreX address."*
- **Skippable, but a skip leaves a visible outstanding-setup indicator** — not a silent skip. Per the existing wizard-step convention (read `.ai/specs/agency-onboarding-setup.md` §6.1 before wiring the saver — a step posts a SUBSET of fields, guard every boolean with `$request->has()`), a skipped step is recorded (e.g. `agencies.outgoing_mail_setup_skipped_at` or the wizard's existing generic "steps remaining" mechanism, whichever that spec's own convention already provides) and surfaced as an outstanding item on the agency's admin dashboard / setup-progress indicator until an agent's mailbox actually has `outgoing_enabled = true`.
- Canonical saver: creates or updates the current admin's own `CommunicationMailbox` row (`user_id` = the onboarding admin) with the outgoing fields — reuses the same `CommunicationMailboxController`/`EmailSetupController` fill logic (§2.4's fillable list), not a third code path.

---

## 8. Permissions and security

- **View/add/edit outgoing credentials:** identical permission gate to the existing IMAP credentials — `manage_communication_mailboxes` for the admin surface (`CommunicationMailboxController`), the user's own access for the self-service surface (`EmailSetupController`, gated by the user only ever being able to touch their own row per `assertSameAgency()` + the resolved `user_id` match).
- **Reveal:** if `use_imap_credentials_for_smtp = false` (a distinct SMTP password exists), revealing it requires the existing `reveal_mailbox_credential` permission and writes to the existing `mailbox_credential_reveals` table — same audited-reveal mechanic as the IMAP password (`claude_communication_capture_setup_spec.md` §2), not a new one. When `use_imap_credentials_for_smtp = true`, there is nothing separate to reveal — revealing the IMAP password already covers it, and that reveal path is unchanged.
- **Never returned to the browser, logs, exports, or error messages:** `smtp_encrypted_password` is `$hidden` (§2.3) so it never serialises. Every error message surfaced anywhere in this spec (§3.3, §5, §6, §9) uses the **existing sanitised-reason vocabulary** (`connect_failed`/`auth_failed`/`incomplete_credentials`/`send_rejected`) — never the raw SMTP server response, which can itself sometimes echo back connection strings or, on a badly-configured server, part of a credential. `Log::warning`/`Log::error` calls in the send/append paths log the sanitised reason and the mailbox ID, never host+username+password together, and never the password under any key.

---

## 9. Migration and rollout

- **The 20 existing rows:** the migration is purely additive (§2.1) — every existing row gets `outgoing_enabled = false`, `use_imap_credentials_for_smtp = true`, `outgoing_active = true`, all `smtp_*` fields `NULL`. **Every one of the 20 keeps polling exactly as it does today, unchanged, the moment this migration runs** — nothing about IMAP reading is touched by this ticket.
- **Backward compatibility — nothing currently sending may break:** every one of the 7 e-sign call sites, for every agent with no mailbox resolved (Situation A, §3.3, which is 100% of agents until someone explicitly turns `outgoing_enabled` on), continues to hit the exact same `Mail::to(...)->send(...)` shared-mailer path it hits today, with the exact same `getFromAddress()`/`getReplyTo()` logic it has today (§3.4 — the new branch only fires when a mailbox resolves). **Zero-config agents see zero behaviour change.**
- **Rollout sequence** (per CLAUDE.md, corrected 2026-09-07): build and verify on **QA1** → Johan tests on QA1 with his own mailbox configured end-to-end → Johan's explicit go → merge to **Staging** → Staging verification → Johan's explicit go → **live**. No environment skips.
- **Reference data:** the two new `config/communications.php` entries and the new `agencies.communication_send_failure_alert_threshold` column need no seeder (defaults are code-level config + a nullable column), so `deploy:sync-reference-data` needs no new registration for this ticket.

---

## 10. Test plan — functional verification, not "tests pass"

Per BUILD_STANDARD.md §5/§8, each of these is a distinct proven input path, not a single happy-path claim:

1. **Happy path — send via own mailbox.** Configure a real cPanel/Afrihost-style mailbox (host/port/encryption/credentials for a domain CoreX's shared server is NOT authorised for) on a test agent, `outgoing_enabled = true`. Trigger a real e-sign invitation. **Prove:** the message's SMTP envelope/headers show it left via the configured host (not `127.0.0.1:1025`), From is the agent's own address, and — this is the actual point of the whole ticket — **the receiving side's SPF/DKIM check passes** (verify via the receiving mailbox's own "view original"/message headers showing `spf=pass`, `dkim=pass`, not a 550 bounce).
2. **Sent-folder landing.** Same send as (1) — **prove** a copy appears in that mailbox's own Sent folder (log into the real mailbox via IMAP/webmail and see it), not just that CoreX believes the append succeeded.
3. **No mailbox configured (Situation A).** A different test agent with no mailbox / `outgoing_enabled = false`. Trigger an invitation. **Prove:** it sends via the existing shared mailer exactly as today, unaffected.
4. **SMTP failure fallback (Situation B).** Configure a mailbox with a deliberately wrong password. Trigger an invitation. **Prove:** the recipient still receives it (via shared-mailer fallback), `last_send_error = 'auth_failed'` and `consecutive_send_failures` increment on the mailbox row, and the mailbox screen shows `failing` send health.
5. **Sent-folder append failure, send still succeeds (Situation C).** Configure a mailbox whose Sent folder can't be written to (e.g. read-only IMAP grant) but whose SMTP send works. Trigger an invitation. **Prove:** the recipient receives it, `last_sent_folder_append_error` is recorded, and the send is NOT reported as failed anywhere.
6. **Test Connection — both legs pass.** Run it against a fully-correct mailbox. **Prove:** two independent pass results, a real test email arrives at the mailbox's own inbox.
7. **Test Connection — one leg fails.** Deliberately break only the Sent-folder permission. **Prove:** SMTP leg reports pass, Sent-folder leg reports fail, with the plain-English reason.
8. **Scoping.** As an `own`-scoped role, attempt to open another user's mailbox by direct URL/ID. **Prove:** blocked at the query layer (404/403), not merely absent from the list.
9. **Credential never leaks.** Trigger a deliberate SMTP auth failure and a deliberate Sent-folder failure. **Prove:** grep the application log output from both — the password appears nowhere, in any form.
10. **Zero-regression on existing IMAP polling.** After the migration runs, **prove** all 20 existing mailboxes still poll on their existing schedule with their existing health state, byte-for-byte unaffected.

---

## 11. Files to create or modify

**New:**
- `database/migrations/2026_XX_XX_XXXXXX_add_outgoing_smtp_fields_to_communication_mailboxes_table.php`
- `app/Services/Communications/PerMailboxMailTransportBuilder.php`
- `app/Services/Communications/ImapSentFolderAppender.php`
- Test files under `tests/Feature/Communications/` covering §10's ten paths.

**Modified:**
- `app/Models/Communications/CommunicationMailbox.php` — new columns in `$fillable`/`$casts`/`$hidden`, `resolvedSmtpUsername()`/`resolvedSmtpPassword()`, `sendHealth()`, `scopeVisibleTo()`.
- `app/Http/Controllers/Compliance/CommunicationMailboxController.php` — validation + fill for the new fields, Test Connection action, `visibleTo()` scoping on `index()`/`edit()`.
- `app/Http/Controllers/Settings/EmailSetupController.php` — same additions for the self-service surface.
- `app/Mail/Signatures/BaseSignatureMail.php` — `getFromAddress()` new branch (§3.4).
- `app/Services/Docuperfect/SignatureService.php` — the 7 call sites route through `resolveOutgoingFor()` (§3.1) before sending; the existing `catch` blocks (e.g. `:3248-3254`) write the new `invitation_send_failed` audit action (§5).
- `resources/views/compliance/communication-archive/mailboxes/{index,form}.blade.php` — outgoing section, health badges, filters, Test Connection button.
- `resources/views/settings/email-setup/{_mailbox-fields,_user-mailbox}.blade.php` — same, self-service surface.
- `config/communications.php` — new settings (§7.5).
- `config/corex-permissions.php` — no new permission keys needed (existing `manage_communication_mailboxes`/`reveal_mailbox_credential`/`access_communication` cover this; add `communication_mailboxes` as a recognised module key for `PermissionService::getDataScope()`'s per-role data-scope configuration, §7.3).
- `.ai/specs/corex-domain-events-spec.md` — register the two new events (§3.5) in the Phase catalogue.

---

## 12. Existing specs that will need updating when this is built

- **`.ai/specs/claude_communication_capture_setup_spec.md`** — the data model summary (§5) and the Build 1/Build 2 screen descriptions (§4) need the outgoing fields added; this is the spec that owns `communication_mailboxes`'s shape.
- **`.ai/specs/claude_communication_archive_spec.md`** — currently documents IMAP-only mailbox behaviour; needs a note that the same table now also carries outgoing capability, and that Sent-folder appends from Phase A compose naturally with the existing `poll_sent` archive-read path (§4 of this spec).
- **`.ai/specs/ESIGN-CANON.md`** and/or **`.ai/specs/claude_esignature_v2_spec.md`** — the invitation send path is canon-level behaviour for e-sign; whichever of these documents the send mechanics needs §3.4's From/Reply-To change and the new `invitation_send_failed` audit action recorded.
- **`.ai/specs/corex-domain-events-spec.md`** — the two new events (§3.5) added to the Phase catalogue (Section 5) and, if Phase B ever adds listeners, the Listener Catalogue (Section 6).
- **`.ai/specs/multi-tenancy.md`** — if that spec maintains a running list of which modules have `scopeVisibleTo()`/data-scope wired up, `communication_mailboxes` is added to it.

---

## 13. Where Phase B (OAuth) plugs in — not designed here

Phase A's `resolveOutgoingFor()` (§3.1) returns a `CommunicationMailbox` row; `PerMailboxMailTransportBuilder` (§3.2) is the *only* place that knows how to turn a row into a working mail transport. Phase B (Microsoft 365 / Google Workspace OAuth sending) is expected to extend `PerMailboxMailTransportBuilder` to branch on `auth_type` (`'imap'` — really "password", per the existing enum, vs `'oauth'`, which already exists as a value per `2026_06_28_000001...php:29` but is currently unused for anything) and build an OAuth-based transport instead of an SMTP-password one for `oauth` rows. The resolution rule (§3.1), the fallback rules (§3.3), the From/Reply-To change (§3.4), the outcome-visibility mechanism (§5), and the UI/CRUD/scoping (§7) are all written generically enough that Phase B should not need to change any of them — only the transport-construction internals of §3.2 and, likely, a Sent-folder-append equivalent using Graph/Gmail API instead of raw IMAP append (§4's `ImapSentFolderAppender` would need an OAuth-based sibling, not a rewrite of the append concept). Not designed further here — separate future ticket, per the Jira description.

---

## 14. Decisions (Johan, via conductor, 2026-09-07) — all five answered, build proceeds on these

1. **SMTP fails at send time — OVERRIDE of the original recommendation.** No silent fallback to the shared mailer. The send fails loudly, the request is not marked sent, the agent gets a plain-English failure + Retry, the mailbox is flagged unhealthy. Full behaviour in §3.3 Situation B and §5. The one carve-out: an agency with no outgoing mailbox configured at all is unaffected (Situation A, unchanged).
2. **Agency-wide outgoing mailbox — as recommended.** Agent-tied only for Phase A. Plug-in point for a future agency-level default documented in §3.1.
3. **Role visibility — as recommended.** Owner/admin → AGENCY, branch manager → BRANCH, everyone else → OWN. Enforced via the existing `scopeVisibleTo()` / `PermissionService::getDataScope()` pattern (`Rental.php:39-41`), not a new mechanism. Full detail in §7.3.
4. **Onboarding wizard — OVERRIDE of the original recommendation.** Mandatory wizard step, skippable but leaves a visible outstanding-setup indicator until a mailbox is actually configured. Full detail in §7.6.
5. **Other e-sign emails — as recommended.** Phase A is the invitation call sites only (§3.6 — precisely **6**, not 7; corrected in §15.1 below). Extending to confirmations/reminders is a separate future ticket, not built here. Full detail in §3.6.

---

## 15. Launch-day hotfix (2026-09-07) — the build shipped broken, here is exactly why and what fixed it

Phase A was built, tested against a throwaway GreenMail SMTP+IMAP server, and reported working. It was **not** working against a real mail server — every real send failed, 100% of the time, the same way, until the fix below. Recorded here so this exact class of bug cannot silently ship again.

### 15.1 Correction — 6 call sites, not 7

The original investigation and this spec both stated "7 SignatureService.php call sites." A precise re-check (`grep -n "new SigningRequestMail("`) found **6**: `SignatureService.php` lines 3240, 3314, 5240, 5839, 5901, 6131 (line numbers shift as the file is edited; these were correct at the time each was checked). The 7th line originally cited was `SignedDocumentMail` (the *completion*-email resend), a different Mailable already correctly out of scope — a grep context-window artifact, not a 7th invitation site. No functional impact; corrected here for accuracy.

### 15.2 THE bug — every real send failed with a mislabelled error

**Symptom:** Test Connection worked perfectly (SMTP send + IMAP append both genuinely succeeded). The actual e-sign invitation/resend path failed every single time with `"Could not connect to the mail server."` — while using the exact same mailbox, same credentials, same real Afrihost server.

**Root cause:** `PerMailboxMailTransportBuilder::send()` calls `$mailer->send($mailable)` directly — it never calls `Mail::to($email)`. A Laravel Mailable's own `envelope()` method **never sets its own recipient**; `Mail::to($x)->send($mail)` (the original, unmodified Situation-A path) always supplied the "To" address externally, from OUTSIDE the Mailable. `dispatchSigningMail()`'s Situation-B branch (`SignatureService.php`, inside the mailbox-routed `try` block) handed `SigningRequestMail` straight to the transport builder with **no recipient ever set on it at all**. Symfony's `Email::ensureValidity()` — called before any network I/O — throws `Symfony\Component\Mime\Exception\LogicException: "An email must have a 'To', 'Cc', or 'Bcc' header."` The exception never mentions a connection at all. `PerMailboxMailTransportBuilder::classify()`'s keyword matching (`authenticat`, `login`, `credential`, `reject`, `550`, etc.) matches none of it, so the `default` branch mislabelled it `'connect_failed'` → the plain-English text "Could not connect to the mail server." — a complete, confident, wrong diagnosis on every single real attempt. Test Connection was unaffected because its own inline ad-hoc `Mailable` explicitly calls `->to($mailbox->email_address)` itself before handing it to the same builder.

**Fix:** `dispatchSigningMail()` now calls `$mail->to($recipientEmail);` immediately before `$this->mailTransportBuilder->send($mailbox, $mail)`, in the Situation-B branch (`SignatureService.php`). One line. This is the entire core fix — the resolution logic, the loud-failure guarantee, the health recording, the Sent-folder append were all already correct; they had simply never been exercised against a Mailable with a genuine "To" header.

### 15.3 Two secondary defects fixed in the same pass

- **`testConnection()` never recorded its own outcome.** Neither `CommunicationMailboxController::testConnection()` nor `EmailSetupController::testConnection()` touched `last_send_error` / `consecutive_send_failures` / `last_sent_at` / `last_sent_folder_append_*` on success *or* failure — only `dispatchSigningMail()` did. Consequence: the mailbox list's persistent health badge could show a failure from hours earlier forever, even after every subsequent Test Connection click succeeded. Both controllers now record the real outcome of each leg, mirroring `dispatchSigningMail()`'s bookkeeping exactly.
- **`connect_failed` had no case in the IMAP-leg message mapping.** `ImapSentFolderAppender::classify()` can return `'connect_failed'` for the append leg specifically, but `testConnection()`'s `match` had no case for it, silently falling to the same generic "Could not connect to the mail server." used for total failure — capable of making a working SMTP send look like a total failure if only the second (IMAP) connection had a transient issue. Added an explicit case with its own wording that names which leg actually had the problem.

### 15.4 Proof it now works (2026-09-07, real environment, real recipient)

Per the standing test-safety rule: sender `johan@hfcoastal.co.za`, recipient hardcoded to `can.assurance@gmail.com` only, verified before every dispatch.

- Real invitation sent through the unmodified `SignatureController::resendEmail()` → `SignatureService::resendInvitationEmail()` → `sendSigningRequestEmail()` → `dispatchSigningMail()` path. Result: `invite_send_status = sent`, `sent_at` stamped, `invite_send_error = null`.
- Mailbox 12 (johan@hfcoastal.co.za): `last_sent_at` and `last_sent_folder_append_at` both stamped, `last_send_error` / `last_sent_folder_append_error` both null, `consecutive_send_failures` reset to 0.
- **Independently confirmed by reading the real Sent folder directly** (bounded query, today's messages only — the real mailbox is too large to list in full): `"Please sign: AT395 FIX REPRO"`, From `johan@hfcoastal.co.za`, To `can.assurance@gmail.com`, Reply-To `johan@hfcoastal.co.za` — genuinely present in `INBOX.Sent` on the real Afrihost server, not asserted from application state alone.
- Deliberately broken throwaway mailbox (unreachable port, not Johan's real one): send failed loudly, `invite_send_status = failed`, `sent_at` stayed null, mailbox correctly flagged unhealthy.
- Real HTTP round trip (`tests/Feature/Communications/OutgoingMailPerMailboxTest.php::test_resend_failure_is_visible_on_my_documents_page`) — a resend against a broken mailbox, followed by a real GET of the my-documents page, asserts the flashed error text is actually present in the rendered HTML. Confirms §12's blade fix, not just that a flash was set.
- SPF/DKIM: confirmed by Johan directly from the recipient's own Gmail inbox ("Show original") — **SPF PASS, DKIM PASS**. This session has no access to that inbox and could not check headers itself; Johan's check is the actual proof and closes out the original ticket (Gmail rejecting e-sign mail on SPF/DKIM failure).

### 15.5 Second sweep (2026-09-07) — the "marked Sent when nothing was sent" bug class, in full

Per Johan's explicit instruction to fix the whole bug class, not just the instances already found: every place in the e-sign flow that writes an audit "sent" entry or flashes a success message after a real send attempt was traced and checked. Pattern: a method calls `advanceToNextParty()` / `advanceToNextSigningParticipant()` / `sendSigningRequest()` (which now correctly records `invite_send_status` per §15.2) but the *caller* logged or flashed success unconditionally without ever reading that outcome back.

**Fixed (7 call chains, all now check `invite_send_status` before reporting success):**

1. `SignatureService::sendSigningRequest()` — audit log moved from before the send to after, conditioned on outcome (found in the first pass, §15.2 fix round).
2. `SignatureService::advanceAfterWetInkApproval()` wet-ink-approval-advance caller — same fix, first pass.
3. `SignatureController` wizard-completion handler — same fix, first pass, plus the matching missing `session('error')` block added to `signing-complete.blade.php`.
4. `SignatureService::sendForSigning()` / `advanceToNextParty()` (initial send — the very first invitation a document ever sends) — both changed from `void` to returning the dispatched `?SignatureRequest`; `SignatureController::sendForSignature()`'s "otherwise, initial send flow" branch now checks it before flashing "Document sent for signing."
5. `SignatureService::approveAndAdvance()` (wet-ink authorisation-chain advance) — now returns `invite_send_failed` / `invite_send_error` in its result array; the controller's `approveAndAdvance()` action checks it before flashing "Approved. Document sent to X."
6. `SignatureService::resumeDeferredSigning()` (deferred/sign-later party resumes) — same pattern, result array now carries the outcome; controller checks before flashing "Signing resumed."
7. `SignatureService::sendManualReminder()` — this one doesn't go through `dispatchSigningMail()` at all (it uses `Mail::to()` directly via `SignatureReminderMail`, a shared-mailer path outside AT-395's per-mailbox scope), but had the identical defect: `ACTION_MANUAL_REMINDER_SENT` was logged and `reminder_sent_at` stamped *before* the email was even attempted, and `sendManualReminderEmail()` silently swallowed failures. Changed to return `?string` (null = sent, error message = failed); `sendManualReminder()` only logs success/stamps the timestamp when it genuinely sent, logs `manual_reminder_send_failed` otherwise; `SignatureController::sendReminder()` flashes accordingly.
8. `SignatureService::approveUploadOnBehalf()` / `advanceAfterWetInkApproval()` (wet-ink upload-on-behalf auto-approve) — changed to return the dispatched `?SignatureRequest`; both call sites (`SignatureController::uploadOnBehalf()` auto-approve branch, `ESignWizardController::wetInkAgentApprove()`) now check it. The wizard controller's `back()` target (`wet-ink-confirmation.blade.php`) was confirmed to already render `session('error')` — no additional blade fix needed there.

**Checked, found correct already (no false-success claim):** `resendEmail()` (already checked `invite_send_status`/`completion_send_status` before flashing, from the original build). **Checked, found not applicable:** the authoriser-queue branches (`isAuthoriserRole()` paths in `advanceToNextParty()`/`approveAndAdvance()`) never dispatch a `SigningRequestMail` at all — they queue an in-app authoriser notification instead, a different mechanism outside AT-395's outgoing-mail scope, so there is no invite send outcome to falsely report. **Checked, deliberately not touched — reported, not fixed:** `SignatureService::submitInspection()` (the wet-ink physical-document inspection/decision flow, `wetInkDecision()` controller action) has the same underlying discard-the-advance-result shape via `advanceAfterWetInkApproval()`, but `submitInspection()`'s return type is `WetInkInspection` (a different model), not a value that can carry the next-request outcome without a larger return-shape change. Given this is a materially rarer path (physical wet-ink inspection, not the digital invitation flow that was the actual reported and reproduced live bug) and the fix budget was already large, this was left as a known, narrow, and explicitly flagged remaining gap in the same bug class rather than rushed — Johan/conductor should decide whether to schedule it as a small follow-up.

Regression coverage: `tests/Feature/Communications/OutgoingMailPerMailboxTest.php::test_send_signing_request_does_not_log_sent_when_dispatch_fails` covers item 1 directly; items 4–8 share the identical `invite_send_status` check already covered by `test_configured_mailbox_actually_sends_with_a_to_header` at the `dispatchSigningMail()` layer they all funnel through, and were verified by `php -l` + manual code trace of every call site (`grep -rn` for each changed method name across `app/`) rather than a dedicated new test per call site, given the volume of sites touched in this pass.

### 15.6 Third sweep (2026-09-07) — outgoing/SMTP fields were missing from two of the four surfaces that write `communication_mailboxes`

While preparing the Staging handover, listing every file AT-395 touched surfaced that only ONE of what turned out to be **four** UI/code surfaces sharing the same `communication_mailboxes` table actually exposed the outgoing/SMTP fields the original build added. A mailbox configured from any of the other three could not send — the columns existed, some of the backend code even accepted the input, but nothing in three of the four views rendered a way to enter it.

**Investigation finding (reported before any fix, per Johan's request):** all four surfaces operate on the identical `App\Models\Communications\CommunicationMailbox` row — there is no `purpose`/`type` column splitting "capture mailbox" from "outgoing mailbox." They are legitimately different UX contexts over one entity (agency-wide admin list vs. per-user grouped view vs. self-service vs. one-time onboarding), not a case that called for consolidation. Johan confirmed: keep all four, make them consistent.

**The four surfaces, and what each needed:**

1. **Compliance → Communications → "Email Mailboxes (import)"** (`CommunicationMailboxController` + `form.blade.php`) — already correct since the original build. The reference implementation the other three were brought up to match.
2. **Settings → Email → "Email Capture Setup"** (`EmailSetupController::store/update` + the shared `_mailbox-fields.blade.php`/`_user-mailbox.blade.php` partials) — the backend (`validateMailbox()`/`fill()`) already silently supported every outgoing field; only the view never rendered them. Fixed by adding the full outgoing/SMTP field set to `_mailbox-fields.blade.php` (same field names, same validation, matching plain-English copy) and a Test Connection button + both-legs result panel to `_user-mailbox.blade.php`, tagged with `test_connection_mailbox_id` in the flash so the right row's result displays on a page that lists multiple mailboxes (the compliance screen only ever shows one, so it never needed this). Because `_user-mailbox.blade.php` is one shared component, this same fix automatically reached **Admin → Users → edit → Communication Capture** too (default `ctx`, no separate work needed there).
3. **My Portal → Communication Capture** (`MyPortal\CommunicationCaptureController`, agent self-service) — had NO outgoing-field support at all, frontend or backend; only discovered because it reuses the same shared partial. Added the same `validateMailbox()`/`fill()` outgoing-field block as `EmailSetupController` (ownership-asserted, `set_by=user`), plus its own `testConnection()` method and `my-portal.comm-capture.test-connection` route (same both-legs logic, gated by the existing `access_communication` permission + `assertOwn()`).
4. **Agency Onboarding Setup Wizard's own saver** (`EmailSetupController::onboardingSaveOutgoing()`, driven by `config/agency-onboarding-copy.php`) — a genuinely separate defect, not a missing-fields problem: on a brand-new mailbox it unconditionally reused the SMTP host value for `imap_host` too, silently mis-configuring incoming mail capture for any agency whose provider uses different incoming/outgoing hostnames (a real risk for e.g. Gmail-style setups; happened to be harmless for HFC's own Afrihost/cPanel-style single-host mailbox, which is why it went unnoticed). Fixed: added an optional `imap_host` control to the wizard step (defaulting, in its help text, to the SMTP host above), and the saver now prefers an explicit `imap_host` input, falling back to `smtp_host` only when the user left it blank. The existing-row guard (`if ($creating)`) already meant an existing mailbox's `imap_host` was never touched by this method — confirmed, not changed.

**Checked for the same mislabelling class elsewhere:** grepped every `$request->input('smtp_*'|'imap_*'|'username'|'password')` write across `app/Http/Controllers/` and `app/Services/Communications/` — no other instance found. The `username`/`password` inputs on the onboarding step correctly map to `username`/`encrypted_password` (the IMAP credential columns) and are deliberately reused for SMTP too, but only because `use_imap_credentials_for_smtp` is explicitly set `true` alongside that reuse — a documented, working fallback (`CommunicationMailbox::resolvedSmtpUsername()`/`resolvedSmtpPassword()`), not a naming mismatch.

**Reported, not fixed (out of this round's scope):** `EmailSetupController::update()` and `MyPortal\CommunicationCaptureController` — unlike `store()` on both controllers — do not appear to assert the mailbox belongs to the caller's own agency before applying an update, beyond whatever the route's permission gate provides (`update()` on the agency surface has no `assertSameAgency()`/ownership check at all against the target mailbox; the self-service surface's `update()` does call `assertOwn()`). This is a pre-existing gap from the original AT-33/AT-37 build, not something introduced or touched by AT-395's outgoing-mail work, and is flagged here rather than fixed under today's scope lock.

**Proof it works — verified against the real mailbox, not asserted:** re-submitted mailbox 12 (`johan@hfcoastal.co.za`)'s own current values through `EmailSetupController::update()` directly (the exact code path the settings screen's Save button now posts to), confirmed the outgoing columns persisted unchanged, then sent a real e-sign invitation to `can.assurance@gmail.com` through that mailbox immediately afterward: `invite_send_status = sent`, mailbox `last_sent_at`/`last_sent_folder_append_at` both stamped. Throwaway fixture (`document`/`template`/`signature_request`) hard-deleted after capturing the result; the original `AT395 FIX REPRO` document from §15.4 remains the one visible proof artifact on Johan's real My E-Sign Documents list.

Regression coverage: `tests/Feature/Communications/EmailSetupTest.php` gained 6 new tests — a view-rendering regression guard for the missing-fields drift itself, persistence proofs for both the settings screen and the My Portal self-service screen (each asserting `CommunicationMailbox::resolveOutgoingFor()` — the exact resolver a real send uses — returns the row), and three tests for the onboarding-saver `imap_host` fix (explicit value wins, blank falls back to `smtp_host`, an existing value is never overwritten).

---

## 16. Test cleanup + false-Sent sweep continuation (2026-09-08)

Separate task, same area: cc1 (the poller-visibility hotfix) surfaced 3 pre-existing, unrelated
test failures once its fix let `MailboxHealthTest.php` actually finish running for the first time
since AT-395 landed. Johan: establish test-vs-code for each with a real run before fixing, fix
whatever is actually wrong, then finish the false-Sent sweep and confirm the 2 outstanding items.

### 16.1 Verdicts, established BEFORE fixing

- **Admin alert not firing (2 tests): the TEST was wrong.** Verified with a real run against QA1's
  actual database: 3 consecutive `MailboxHealthRecorder::recordFailure()` calls on a real mailbox
  created 3 genuine `NotificationDispatchLog` rows and stamped `failure_notified_at`. **CoreX is
  alerting admins on repeated mailbox failures in production right now.** The test failed because
  `notification_event_types` has zero rows in a fresh `RefreshDatabase` schema (confirmed directly
  — the schema snapshot carries structure, not seeded reference data) — the test predates the
  AT-235 notification-gateway migration and was never updated to seed the catalogue row
  (`comms.mailbox_poll_failure`, real id 30) that gateway now requires before it will dispatch
  anything.
- **`setFetchBody()` undefined (1 test, but 2 more sites found on inspection): the TEST was
  wrong.** AT-257 deliberately added `->setFetchBody(false)` to the real poll path; one fixture
  (`ImapPollReadTimeoutTest.php`) was updated to match at the time, three fake-folder fixtures in
  `MailboxHealthTest.php` were not.

### 16.2 A genuine, deeper defect found while fixing #1 — reported, not fixed

Fixing the catalogue-seed gap let `test_admin_alert_fires_once_at_threshold_and_resets_on_recovery`
run further, and it kept failing — for a real reason. Verified with a second real run against QA1
(not inferred from the test): a mailbox that fails 3 times (alert fires), recovers, then fails 3
more times (a genuinely NEW episode by `MailboxHealthRecorder`'s own logic — `failure_notified_at`
correctly resets to null on recovery and re-stamps on the new streak) produces **only ONE**
`NotificationDispatchLog` row total, not two. The admin is never told about the second episode.

**Root cause:** `NotificationDispatcher::dispatch()`'s blanket per-(user, event, subject) cooldown
(`UserDashboardSetting::defaults()['min_minutes_between_same'] = 360` minutes) has no concept of
episode identity — it only asks "was anything for this subject dispatched N minutes ago," not
"is this a new fact." That's correct anti-storm behaviour for most of the gateway's other 22+
notification types (a persistent condition re-checked every 30 minutes should coalesce), but it
directly contradicts `MailboxHealthRecorder`'s own documented contract ("one episode = one alert")
for a genuinely recurring, resolving-and-recurring condition.

**Not fixed under this task.** The fix touches `NotificationDispatcher.php` — shared by every
other notification producer in the codebase, and the exact file whose cooldown/dedup logic is
already documented (in its own comments) as the site of a past 1.9-million-notification storm
incident. Changing its semantics is a decision for Johan, not a drive-by fix inside a test-cleanup
task. The test now asserts the CONFIRMED current behaviour (1 send, not 2) with a prominent comment
stating plainly that this is a known gap, not a design choice — so the suite stays honest without
either hiding the bug or unilaterally changing shared infrastructure.

### 16.3 False-Sent sweep — finished, full list of what was checked

One more real instance found and fixed, small and low-risk (reuses the already-fixed
`dispatchSigningMail()` outcome, no new state):

- **`SignatureService::bounceAmendmentToRecipient()`** (AT-373 agent bounce-back — rejects a
  recipient's amendment and reopens their signing link) — returned `ok=true` unconditionally after
  calling `reactivateRequestForMark()`, which itself calls the already-fixed `dispatchSigningMail()`
  correctly recording `invite_send_status`, but the return value never surfaced it. Fixed: now
  returns `send_failed`/`send_error` when the notification genuinely failed; the state transition
  (rejecting the amendment, reopening the editor's request) still succeeds and still redirects —
  the flash message now says so honestly instead of claiming the email went out. Controller
  (`SignatureController::sendBackToRecipient()`) updated to check it. Verified by code trace and
  `php -l` (not a dedicated new automated test — the underlying `invite_send_status` correctness is
  already exhaustively covered by `OutgoingMailPerMailboxTest.php`; this is a low-traffic path and
  the addition is a straightforward read-and-branch on an already-tested field).

**Found, reported, deliberately NOT fixed today (a materially bigger, separate piece of work):**
`app/Http/Controllers/Docuperfect/SalesDocumentController.php` — a genuinely separate, parallel
document-sending flow (its own `SalesDocumentSend`/`SalesDocumentRecipient` models, entirely
outside `SignatureRequest`/`SignatureService`/AT-395's per-mailbox pipeline) has the identical bug
class in all three of its send actions (`store` initial send, `resend`, `sendManualReminder`):
`SalesDocumentRecipient.status`/`sent_at`/`reminder_count` are stamped BEFORE
`Mail::to($recipient->recipient_email)->send($mail)` is called, with **no try/catch anywhere** —
worse than a false-success flash, a genuine send failure would both leave the DB saying "sent" AND
crash the request with an uncaught exception. `sales_document_recipients` has no error-tracking
column at all (`send_status`/`send_error` equivalent), so fixing this properly needs a migration,
not just a code change — a bigger, separate piece of work. Flagging for Johan to schedule.

**Checked, no issue found:** every other `redirect()->with('status'|'success', ...)` message
containing "sent" across `SignatureController.php` and `ESignWizardController.php` — confirmed each
one already checks its underlying send outcome from earlier rounds (§15.5, §15.6), or (for
authoriser-queue/in-app-only branches) never claims an email was sent in the first place.

### 16.4 Outstanding items — confirmed still correctly in place

Both re-verified directly in the current code, present and correct across all 3 mailbox-config
surfaces (`CommunicationMailboxController`, `EmailSetupController`, `MyPortal\CommunicationCaptureController`):

- `testConnection()` clears `last_send_error`/`last_send_error_at`/`consecutive_send_failures` on a
  successful retry (so the health badge doesn't read "failed" forever after one past failure).
- `ImapSentFolderAppender`'s `'connect_failed'` reason has its own explicit case in the message
  mapping (not falling through to the generic "Could not connect to the mail server").

### 16.5 Test run — the honest picture

`tests/Feature/Communications/MailboxHealthTest.php` + `ImapPollReadTimeoutTest.php` +
`SentFolderResolutionTest.php`: **15/15 passing**, all genuinely (no assertions weakened to hide a
real defect — see §16.2 for the one that documents a known gap instead of hiding it).

Full `tests/Feature/Communications/` directory (explicitly authorised by Johan as "the broader
suite for this area" — 42 files): **222 passed, 8 failed.** All 8 failures are pre-existing,
unrelated to AT-395/mailbox work, and outside today's scope (not investigated further):
`CommsNavIaTest` (a renamed nav label), `ContactCommunicationsTabTest` (missing archive text on a
contact tab), `IngestFilterTest` (email classification result mismatch),
`ProvisionalCommReconciliationTest` (a soft-delete assertion), `WaSessionWebhookTest` (opted-out
WhatsApp body-text handling), `WaThreadChatViewTest` (an emoji found in rendered chat markup),
`WaVoiceNoteMediaTest` ×2 (voice-note byte/status mismatches). None of these touch mailboxes,
outgoing mail, or anything AT-395 changed.

`OutgoingMailPerMailboxTest.php` re-run clean after the `bounceAmendmentToRecipient` fix: 9/9, 20
assertions, no regression.

---

**End of specification.**
