# CoreX — Communication Capture Setup Spec (SSOT)

**File:** `.ai/specs/claude_communication_capture_setup_spec.md`
**Status:** Draft for build. Extends `claude_communication_archive_spec.md`. Grounded in the capture-attachment as-built investigation (CC, 2026-06-15).
**Owner:** Johan (domain/BA) · Build: Johan + Andre
**One-line purpose:** Give every agency a way to provision a user's email + WhatsApp capture under whatever credential model fits their mail setup — agency-wide OAuth, agency-set-per-user, user-self-set, or dual — with credentials write-only by default and reveal a separately-permissioned, audited action.

---

## 1. The four credential-setup models (one architecture, four entry paths)

1. **Agency-wide single credential (OAuth domain-delegation).** Google Workspace (service account + domain-wide delegation) or Microsoft 365 (Graph API app permission `Mail.Read`). One admin consent → all mailboxes on the domain captured, no per-user passwords. For agencies on managed mail.
2. **Agency-sets-per-user (IMAP).** Admin enters each user's IMAP credentials centrally. **This is HFC** (Afrihost cPanel; principal holds all passwords, agents hold none).
3. **User-sets-own (IMAP).** The user enters their own credentials on their profile.
4. **Dual control (IMAP).** Both agency and user can set/update the credential; either can change the password. (Agency wants oversight, user controls their own password.)

All four resolve to the same `communication_mailboxes` rows and the same write-only/reveal rules; they differ only in who provisions and on which surface.

---

## 2. The credential security rule (the kicker)

**Write-only by default. No one reads back a stored password from any UI — ever.**
- Credentials encrypted at rest (Laravel `encrypted` cast / `Crypt`), never rendered to any screen, never returned by any endpoint.
- Anyone with setup rights can **set or change** a password; no one can **see** the existing one. Changing = overwrite, not view-then-edit.

**The audited-reveal exception (HFC MailWasher use case).**
A separately-permissioned `reveal_mailbox_credential` capability allows retrieving a stored password — for the principal who legitimately needs it (forgot it, setting up mail elsewhere).
- Granted **only** to owner/principal level by default; an agency that wants zero-reveal simply never grants it.
- **Every reveal is itself audit-logged** — who revealed whose credential, when, from where — in a `mailbox_credential_reveals` table. The principal's own reveals are logged too. "Principal can retrieve a credential and every retrieval is recorded" is audit-defensible; "principal can see all passwords with no trace" is not.
- Reveal is an explicit action (button → re-auth/confirm → logged → shown once), never ambient.

---

## 3. Structural changes the as-built forces

### 3.1 Email gains a user dimension
Add **`user_id` (nullable FK)** to `communication_mailboxes` — links a mailbox to the CoreX user whose address it is. Nullable because OAuth domain-delegation (model 1) captures mailboxes that may not each map to a provisioned CoreX user, and because agency-list mailboxes pre-dating this can backfill by matching `email_address → users.email`. Per-user surfaces set it; archive attribution can use it when present.

### 3.2 WhatsApp gains an admin-on-behalf path
`WaDeviceController::store()` hardcodes `Auth::id()` — self-registration only. Add an **admin-on-behalf** path so an admin provisioning a user can issue/register that user's device. The token-shown-once model must survive moving into an admin screen (shown once to the admin, who passes it to the agent, or the agent still completes device-side activation). Keep self-service intact; add admin-issue alongside it.

### 3.3 OAuth connection storage (model 1)
New `agency_mail_connections` table: `agency_id, provider enum(google_workspace, m365), status, encrypted OAuth tokens/refresh, connected_by, connected_at, scopes, active`. The IMAP adapter and an OAuth adapter both feed the same `communications` archive — channel stays `email`, only the fetch mechanism differs.

---

## 4. The two build surfaces

### 4.1 Build 1 — Settings → Email Setup (agency/admin)
One settings screen, the agency's capture control centre:
- **Choose model** for the agency: OAuth (connect Google/M365) **or** IMAP-per-user.
- **OAuth path:** a connect button → provider consent flow → stores `agency_mail_connections` → status shown. One action, all mailboxes.
- **IMAP-per-user path:** a user list with per-user mailbox credential management (add/edit credentials, poll flags, active toggle) — this is model 2, and the admin side of model 4. Sets `communication_mailboxes.user_id`.
- **Reveal:** where `reveal_mailbox_credential` is held, a logged reveal action per mailbox.
- **WhatsApp (admin-on-behalf):** issue/manage a user's WA device from here (the §3.2 path).
- Gated by `manage_communication_mailboxes` (+ `reveal_mailbox_credential` for reveals).

### 4.2 Build 2 — Profile → Communication Capture (user)
On the user's own profile/account:
- **Email:** set/change their own IMAP credentials (model 3; user side of model 4). Write-only — they set a password, never see the stored one.
- **WhatsApp:** the existing self-service device registration, surfaced here too.
- Gated by the user's own access (`access_communication`); a user can only ever set their own.

### 4.3 Unified per-user view (the "set up a user and enable capture" ask)
Add a **Communication Capture** section to the Admin → Users edit screen (`create-edit.blade.php`) so provisioning a user and enabling their email + WhatsApp capture happens in one place. It surfaces the same `communication_mailboxes` (user-linked) + `communication_wa_devices` rows, both channels, on the user record — reusing Build 1's components, not a fourth code path.

---

## 5. Data model summary

- `communication_mailboxes`: **+ `user_id` nullable FK**, + `auth_type` enum(`imap`,`oauth`) default `imap`, + `set_by` enum(`agency`,`user`) for dual-control provenance.
- `communication_wa_devices`: add an admin-issued provenance flag (`issued_by` nullable FK) alongside the existing `user_id`.
- `agency_mail_connections` (NEW): OAuth domain connections (§3.3).
- `mailbox_credential_reveals` (NEW): `agency_id, mailbox_id, revealed_by, revealed_for_user_id, revealed_at, ip_address` — the reveal audit log.
- Permissions: `manage_communication_mailboxes` (exists), **new `reveal_mailbox_credential`** (owner/principal only by default), `access_communication` (exists, user self-service).

---

## 6. Build order

- **Phase 1 — IMAP per-user + structural spine:** `user_id` on mailboxes; `set_by`/`auth_type`; `mailbox_credential_reveals` + `reveal_mailbox_credential` permission with write-only enforcement + logged reveal; Build 1's IMAP-per-user management; the Admin→Users Communication Capture section (email). **Covers HFC end-to-end (model 2 + reveal).**
- **Phase 2 — User self-service + dual:** Build 2 (Profile → Communication Capture, email); `set_by` provenance so agency + user both manage (model 4); WA self-service surfaced on profile.
- **Phase 3 — WhatsApp admin-on-behalf:** the §3.2 admin-issue device path; WA in the Admin→Users section and Build 1.
- **Phase 4 — OAuth domain-delegation:** `agency_mail_connections`; Google Workspace service-account + domain-wide delegation adapter; M365 Graph adapter; OAuth connect UI in Build 1; OAuth fetch feeding the same archive (model 1).

Each phase is independently shippable and feeds the same archive.

---

## 7. Done-criteria (every build prompt)

`php -l` · `php artisan migrate` + `schema:dump` · `view:clear` · documented test command, full-suite failures stay at the 220 baseline (no new) · explicit short FK names · BelongsToAgency + SoftDeletes on new models · permissions added + granted (`reveal_mailbox_credential` owner-only default) · `corex:sync-permissions --merge-defaults` · nav present · **security tests: stored password never returned by any endpoint/view; reveal blocked without `reveal_mailbox_credential`; every reveal writes a `mailbox_credential_reveals` row; a user can only set their own credentials.** Report results, files, line counts. Update Jira.
