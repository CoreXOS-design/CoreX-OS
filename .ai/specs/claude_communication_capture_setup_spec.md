# CoreX — Communication Capture Setup Spec (SSOT)

**File:** `.ai/specs/claude_communication_capture_setup_spec.md`
**Status:** Draft for build + **AS-BUILT ADDENDUM (2026-07-02, AT-155)** — §8 documents the WAHA **server-side WhatsApp capture** path (AT-149, built on the AT-138/143 decisions) shipped to Staging (HELD from live). Extends `claude_communication_archive_spec.md`. Grounded in the capture-attachment as-built investigation (CC, 2026-06-15).
**Owner:** Johan (domain/BA) · Build: Johan + Andre
**One-line purpose:** Give every agency a way to provision a user's email + WhatsApp capture under whatever credential model fits their mail setup — agency-wide OAuth, agency-set-per-user, user-self-set, or dual — with credentials write-only by default and reveal a separately-permissioned, audited action.

> **AS-BUILT NOTE (AT-155).** §§1–7 describe the original credential-provisioning design. §8 records the WhatsApp capture **transport** that actually shipped: a server-side WAHA session replaces the foreground Chrome-extension DOM-scrape as the primary WhatsApp capture path. Verified against branch `spec-remediation-calendar-comms` (origin/Staging `740b9c18`). STAGING, HELD from live.

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

# AS-BUILT ADDENDUM (2026-07-02, AT-155)

## 8. WhatsApp capture transport — server-side WAHA session (AT-149; decisions from AT-138/143)

### 8.1 Why the transport changed (AT-138 doctrine — recorded decisions)
The original WhatsApp adapter (§6 of the archive spec) reads the rendered `web.whatsapp.com` DOM from a **foreground** Chrome tab. That inverts the CoreX founding principle — it needs an agent to keep a tab open and idle, and it scroll-scrapes. The AT-138 investigation (`.ai/audits/2026-06-30-at138-wa-server-side-session.md`) and the AT-149 build audit (`.ai/audits/2026-07-02-at149-waha-webhook-adapter.md`) locked these decisions:

1. **Move capture to a server-held WhatsApp session.** The agent links once via QR; capture then runs 24/7 on the Hetzner box — no tab, no foreground, no idle-watching, no DOM scrape.
2. **Engine = WAHA (self-hosted Docker) on the GOWS engine (whatsmeow / Go).** GOWS gives **native `@lid` ↔ phone-number resolution** (retiring the hand-rolled AT-133 JS resolver to a fallback), a history head-start on link, and owns session storage / reconnection / multi-session. Deployed as WAHA Core `gows-arm-2026.6.2`, isolated Docker, bound to `127.0.0.1:3111`, API-key ON (AT-143).
3. **Only the transport changes.** A thin Laravel webhook adapter maps the session payload into the **existing** `WaArchiveIngestor` contract — AT-122/132/133/135/136/137 + the tz fix, the known-contact gate, per-agent consent, and media download all reused unchanged. Bodies arrive cleartext, which retires the DOM-scrape / IndexedDB backfill / `body_status=unreadable` churn.
4. **Read-only, never send** (ban-risk mitigation — §8.5). History **accrues forward** from link date; it is **not** a retroactive 5-year pull (WhatsApp serves only a bounded recent window to a newly-linked device — true 5-year FICA retention is achieved by archiving forward).
5. **Migrate via a transitional hybrid** — run the WAHA session alongside the extension behind a per-agent flag, prove parity via `external_id` dedup, then retire the extension.

### 8.2 Webhook endpoint + verifier
- Route: `POST /communications/wa/webhook` · name **`communications.wa.webhook`** · middleware alias **`waha.webhook`** → `App\Http\Middleware\VerifyWahaWebhook`. Placed in `web.php` beside the extension `wa/*` machine endpoints (same doctrine as the existing capture routes), not under `/api/v1/*`.
- `VerifyWahaWebhook` is **fail-closed**, keyed off `config('communications.waha.webhook_secret')` (`WAHA_WEBHOOK_SECRET`):
  - No secret configured → `401 {"error":"Webhook not configured"}` (never accepts an unauthenticated POST).
  - **HMAC** — reads `X-Webhook-Hmac`; algorithm from `X-Webhook-Hmac-Algorithm` or `config('communications.waha.webhook_hmac_algo', 'sha512')`; `hash_hmac($algo, $rawBody, $secret)` over the **raw request body**; constant-time `hash_equals`.
  - **Shared secret** — `X-Webhook-Secret` header or `Bearer` token, constant-time compared.
  - Neither → `401`. This is the **only** non-200 the endpoint ever returns (§8.4).

### 8.3 `WahaWebhookAdapter::map()` — thin mapper, reimplements nothing
`app/Services/Communications/WahaWebhookAdapter.php` · `map(array $payload): ?array`. One WAHA payload → one ingestor item; it only reshapes fields, then `WaSessionWebhookController@handle` hands the result to the existing `WaArchiveIngestor::ingest($device, $item)`.
- **`SenderAlt` → phone PRIMARY, `@lid` FALLBACK:** inbound counterpart chat = `payload.from` (the `@lid`), phone = `phoneFromJid(_data.Info.SenderAlt)` (returns `''` for empty/`@lid`, requires ≥9 digits). Emits `counterpart_phone` (primary, blank when no real number) + `counterpart_lid` (fallback) — the ingestor's AT-133 `@lid` resolver only fires when the real phone is absent. Outbound (`fromMe`) takes the counterpart from `payload.to`.
- **Media → the AT-148 seam:** maps `payload.media {url, mimetype, filename}` + `_data.Message.audioMessage.seconds` → a `media[]` item with `url` — the exact download seam §12 of the archive spec consumes.
- Other keys: `message_id` (`payload.id`), `chat_id` (thread = counterpart `@lid`), `direction`, `sender`, `timestamp`, `text` (`body ?? _data.Message.conversation`), `name` (`_data.Info.PushName`), `is_group` (always false — groups already dropped).

### 8.4 WAHA delivers ONE message per webhook + robust 200-skip
- WAHA (GOWS) posts **one message per webhook** (envelope `{event, session, payload}`), **not** a `messages[]` batch — the controller unwraps a single `payload`. (Contrast: the extension path validated a `messages[]` array.)
- **Never 500 (retry-storm guard):** anything that is not a clean attributable message is logged and skipped with a **200** — non-JSON body, event not in `{message, message.any}`, missing session/payload, no matching device, adapter throw, adapter-returned-null (noise/malformed), or ingest throw. The only non-200 is the middleware `401`.

### 8.5 Session → agent binding
- Migration `2026_07_02_000002_add_waha_session_to_communication_wa_devices`: makes `device_token` **nullable** (a session link has no bearer token) and adds `waha_session` (string, nullable, **unique** `comm_wa_session_uq`). A `communication_wa_devices` row is now **either** an extension device (`device_token` set) **or** a WAHA session link (`waha_session` set).
- Resolution: `CommunicationWaDevice::withoutGlobalScope(AgencyScope)->forWahaSession($session)->first()` (the machine webhook has no auth context, mirroring extension-capture middleware). The device yields the owning agent (`user_id`) + `agency_id`; **no matching device → skip** (never archives to a wrong agency).

### 8.6 Noise filter (shared with §14 of the archive spec)
`status@broadcast` / `@g.us` / `IsGroup` are dropped in **two layers**: `WahaWebhookAdapter::isNoise()` (first, in `map()`) and `WaArchiveIngestor::isNoiseChat()` (defence-in-depth at ingest entry). The `communications:purge-wa-noise` soft-purge command remediates any that predate the filter.

### 8.7 Config reference
`config/communications.php` `waha` block: `base_url` (`WAHA_BASE_URL`, default `http://127.0.0.1:3111`), `api_key` (`WAHA_API_KEY`, `X-Api-Key`), `webhook_secret` (`WAHA_WEBHOOK_SECRET`), `webhook_hmac_algo` (default `sha512`), `download_timeout_seconds` (30), `max_media_bytes` (50 MB), `allowed_media_hosts` (`WAHA_ALLOWED_MEDIA_HOSTS`, default `127.0.0.1,localhost`).

### 8.8 Go-live prerequisites (pending Johan — not done in the build)
The webhook path is built and tested (`WaSessionWebhookTest` 8/8) but **no number is linked**. To go live Johan must: set `WAHA_WEBHOOK_SECRET`, point WAHA's webhook at `/communications/wa/webhook`, and set `communication_wa_devices.waha_session` for the linked agent's device.

## 9. DOCTRINE — the three lanes (capture, manual outreach, automated sending)

The earlier blunt rule ("capture number must NEVER equal the outreach number") was **wrong** and is retired. The correct model has **three lanes**, and only the third requires number separation:

| Lane | What it is | Sends programmatically? | Number rule |
|------|-----------|-------------------------|-------------|
| **Capture** | WAHA read-only session archiving the agent's WhatsApp threads for FICA | **No** — read-only, no send path exists | The agent's **own** number |
| **Manual outreach** | `wa.me` deep-link; the agent **physically taps send** on their own device | **No** — CoreX never transmits; the human sends | **Same number is fine** |
| **Automated sending** (future, NOT built) | Meta Cloud API / bulk programmatic send | **Yes** | **Must** be a separate dedicated number |

**Why capture + manual outreach may share one number.** CoreX has **no programmatic WhatsApp send** — the Outreach Queue's locked architectural truth is that outreach is a `wa.me` deep link and the agent taps send themselves (`SellerOutreachSenderService` redirects the agent; no server-side transmit). So there are no bulk/robotic-send patterns on the agent's number from CoreX. Capture (read-only) and manual outreach (human-driven) coexisting on one number carries **no added ban risk** — the ban trigger is *automated* bulk sending, which does not exist in CoreX today.

**The one real separation rule.** If/when the **automated** sending lane (Meta Cloud API) is built, that lane MUST use a **separate, dedicated number** — never the capture number and never an agent's personal number. That is a future concern; nothing in CoreX sends automatically now.

**How CoreX honours this today (as-built):**
- **Capture never sends (structural):** no WAHA `sendText`/send call anywhere in `app/`; the WAHA session is read-only.
- **Manual outreach is human-driven:** delivery is the agent tapping send on a `wa.me` link — a different lane, not a CoreX transmission.
- **Ownership enforced (AT-153):** `WaDeviceController::store()` (and the AT-156 in-app link flow) refuse a platform/owner-role or agency-less registrant, so a capture device always belongs to a real agency agent (attributable `owner_user_id`).

**In-app guidance (AT-156).** The WhatsApp Link page tells the agent, calmly: capture is read-only, CoreX never sends from this number, it's fine to use the same number as their manual outreach, and only an external **automated/bulk-sending bot/service** must be kept off this number. No "NEVER use your outreach number" framing.

---

## 10. Done-criteria (every build prompt)

`php -l` · `php artisan migrate` + `schema:dump` · `view:clear` · documented test command, full-suite failures stay at the 220 baseline (no new) · explicit short FK names · BelongsToAgency + SoftDeletes on new models · permissions added + granted (`reveal_mailbox_credential` owner-only default) · `corex:sync-permissions --merge-defaults` · nav present · **security tests: stored password never returned by any endpoint/view; reveal blocked without `reveal_mailbox_credential`; every reveal writes a `mailbox_credential_reveals` row; a user can only set their own credentials.** Report results, files, line counts. Update Jira.
