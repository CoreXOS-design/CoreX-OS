# Atlas — Communications Capture / Archive

> **Status: DONE** · Last verified: 2026-06-22
> **⚠ LIVE SYSTEM — 4 agents are actively using this for outreach. Fragilities in §9 are operational risks.**
> Pillars: **Contact** (comms attach to contacts) × Compliance (consent/POPIA send gate).
> Cited tickets: AT-33/34 (archive + WA devices), AT-36 (triage/flags), AT-37 (email setup), AT-39 (email
> self-service), AT-44 (WA IndexedDB read), AT-49 (consent convergence), AT-59 (comms tiles).

---

## 1. WHAT IT DOES

Captures WhatsApp and email conversations into a per-agency communications archive, attributes each message
to a Contact, and surfaces counts on the contact's AT-59 comms tiles. WhatsApp is captured by a Chrome
extension reading WhatsApp Web's private IndexedDB **read-only** (AT-44) and POSTing to a token-authed
ingest endpoint; email is captured by an IMAP poller. Outbound sends respect the Compliance opt-out engine
at compose time. The archive feeds compliance review, triage, and the BM flag register.

> **⚠ CORRECTION TO A COMMON ASSUMPTION:** there is **NO "session-scoped capture permission with midnight
> reset and offboarding successor nomination"** in this codebase (grep for `midnight`/`successor`/`nominate`/
> `offboard` returns only unrelated hits). The real capture controls are three independent layers (§4):
> a per-device Bearer token, an `active` boolean on the device, and Laravel permissions. Any doc/spec
> claiming the midnight-reset/successor flow is **factually wrong** for current code — it was never built
> (or lives elsewhere). This atlas documents reality.

---

## 2. ENTRY POINTS

### Routes (`routes/web.php`)
| Route group | Name | Perm | Controller |
|-------------|------|------|------------|
| `:1304-1309` | `my-portal.comm-capture.*` (AT-39 email self-service) | `access_communication` | `MyPortal\CommunicationCaptureController` |
| `:1611-1615` | `compliance.comm-archive.*` (AT-33 viewer) | `access_communication_archive` | `Compliance\CommunicationArchiveController` |
| `:1619-1626` | `compliance.comm-mailboxes.*` (IMAP config) | `manage_communication_mailboxes` | `Compliance\CommunicationMailboxController` |
| `:1629-1633` | `communications.wa-devices.*` (AT-34 device reg) | `access_communication` | `Communications\WaDeviceController` |
| `:1636-1640` | `communications.triage.*` (AT-36) | `triage_communications` | `Communications\CommunicationTriageController` |
| `:1643-1645` | `compliance.comm-flags.*` (BM flag register) | `view_communication_flag_register` | — |
| `:1660-1674` | `settings.email-setup.*` (AT-37) + audited `reveal` | `reveal_mailbox_credential` | — |
| **machine endpoints (no session, Bearer)** | | | |
| `:3442-3443` | `communications.wa.ingest` | `auth.wa_capture` | `WaIngestController::ingest` |
| `:3450-3451` | `communications.wa.contact-check` (AT-44) | `auth.wa_capture` | `::contactCheck` |
| `:3456-3457` | `communications.wa.ping` (heartbeat) | `auth.wa_capture` | `::ping` |
| `:3437-3438` | `portal-captures.ingest` | `auth.portal_capture` | (prospecting — see `prospecting-tracked-properties.md`) |

Views: `compliance/communication-archive/{index,thread,show}`, `communications/wa-devices/index`,
`communications/triage/index`, `my-portal/communication-capture/index`, `settings/email-setup/index`.
Nav `corex-sidebar.blade.php`: WhatsApp Capture / Triage / Comm Capture `:159-161,574,601-607`; Compliance
Archive / Flag Register / Mailboxes `:1028-1034`; Email Setup `:1474-1476`.
**Chrome extensions:** `chrome-extension/wa-capture/` (content.js, background.js, popup, manifest) +
`chrome-extension/portal-capture/`.

---

## 3. THE WHATSAPP INGESTION PIPELINE (AT-44)

**Auth:** `app/Http/Middleware/AuthenticateWaCapture.php:22-44` — Bearer token, matches
`hash('sha256',$token)` against `communication_wa_devices.device_token` where `active=true` (drops
`AgencyScope`), `Auth::login($user)`, stamps `last_seen_at`. Registered `bootstrap/app.php:36`.

**Controller** `app/Http/Controllers/Communications/WaIngestController.php`: `ping()` `:26-39`;
`ingest()` `:41-75` (batch 1–500, per-msg `message_id`+`chat_id` required `:49-59`; loops calling
`WaArchiveIngestor::ingest` `:65`; **one bad message never fails the batch** — try/catch → `invalid++`
`:67-71`); `contactCheck()` `:88-114` (per-number yes/no, **does not write** — data minimisation, contact
list never leaves server).

**Ingestor** `app/Services/Communications/WaArchiveIngestor.php:41-136`: dedup on `external_id` (WA msg id)
within agency `:50,138-151`; known-contact gate `:91` — no contact → `CommunicationPending` with
`expires_at = now()+graceDays` `:94-99`; contact → transaction `:101`, outbound reconciliation `:104-117`
or create `Communication` + deterministic `CommunicationLink` (confidence 100, confirmed) `:119-130` +
`touchLastContacted` `:132`.

**Message identity (`true/false_jid_msgid`):** `chrome-extension/wa-capture/content.js:442-444,515-523` —
splits the IndexedDB `message` record `id` on `_`: `parts[0]==='true'` → fromMe (outbound),
`parts[1]` = chatJid, rest = msgId.

**IndexedDB read (read-only):** `content.js:438-630` — opens `model-storage` `:448`, read-only transactions
only `:483,507`, newest-first via `t` index (in-memory sort fallback) `:478-501`, body plaintext from
`v.body`/`v.caption` `:528-530`, timestamp `v.t` unix-sec `:525-526`. DOM scraping retained ONLY as a body
fallback `:445-446`.

---

## 4. THE CAPTURE CONTROLS (actual mechanism — NOT a session/midnight gate)

1. **Per-device Bearer token** — `AuthenticateWaCapture.php:24-29`; token stored SHA-256 in
   `communication_wa_devices.device_token`; gated on `active=true`.
2. **Device revoke (the only off-switch)** — `WaDeviceController::destroy` sets `active=false` (soft revoke,
   not delete); UI `communications/wa-devices/index.blade.php:57`.
3. **Laravel permissions** — `access_communication`, `triage_communications`, `access_communication_archive`,
   `manage_communication_mailboxes`, `reveal_mailbox_credential`, `view_communication_flag_register`.

The only **time-windowed** concept is the **inbound grace buffer** (`CommunicationPending`, default 4 days,
max 5, agency-overridable — `app/Models/Communications/CommunicationPending.php:21-23,85-90`): a pruning
window for *unmatched inbound* messages, NOT a capture-permission reset.

---

## 5. EMAIL INGESTION

`app/Services/Communications/EmailArchiveIngestor.php:46-157` — dedup on Message-ID `:49-57`; **deterministic
drop filter only when no contact matches** (`CommunicationIngestFilter::dropReasonForUnknown` — no-reply/
bank/service domains; dropped pre-storage, logged) `:67-83`; else store `.eml` `:85`, pending-buffer if no
contact `:111-120`, else transaction with outbound reconciliation `:122-156`. **IMAP poller**
`ImapMailboxPoller.php:30+` reads `communication_mailboxes` creds, polls Inbox+Sent `:58-64` since
`last_polled_at - 1 day` `:48-49`. **Schedule** `routes/console.php:97-99`: `communications:poll-mailboxes`
every 5 min `withoutOverlapping`; `PollMailboxes.php:35` dispatches `PollMailboxJob` per due mailbox.

---

## 6. HOW COMMS ATTACH TO CONTACTS

**Morph pivot `communication_links`** — `Contact::communications()` (`Contact.php:700-710`, morphToMany with
pivot `link_method`/`confirmed_at`, excludes soft-deleted links). **Resolver**
`ContactIdentifierResolver.php:27-72` — email via `LOWER(TRIM(email))` `:39-46`; phone via last-9-digits
`RIGHT(REGEXP_REPLACE(...),9)` `:48-60`; **drops ALL global scopes** (agency/branch/visibility AND
SoftDeletes — re-excludes trashed `:69-70`), filters explicit `agency_id` — a system-level gate by design.

**Provisional → reconcile (the AT-59 dedup):**
- `OutboundProvisionalLogger.php:33-80` — agent clicks WhatsApp/email on a contact → creates `Communication`
  with `provisional_at=now`, `external_id='provisional:<uuid>'`, `text_hash`, + `CommunicationLink`
  (`link_method=manual`, `confirmed_at=null`). Called from `ContactController::incrementChannel:715-734`.
- `ProvisionalReconciler.php:44-127` — when the real Sent message is ingested, finds the provisional row,
  matches by `text_hash` `:72-74` else time-window nearest `:78-90`, **promotes in place** (clears
  `provisional_at`) `:96-114`. Runs without auth → drops `AgencyScope` `:27-28`.

Links are polymorphic but in practice only `Contact::class` is written — **no Deal linking** in the ingest
paths.

---

## 7. THE AT-59 TILES

`Contact::outboundCommCount($channel)` (`Contact.php:721-736`) counts linked communications where `channel`,
`direction=outbound`, `purged_at IS NULL`. **Provisional + confirmed both count** (reconciliation promotes
in place, so click + real send = one row). Web: `ContactController.php:312-313` ($waSent/$emailSent), live
re-count `:735`. Blade `contacts/show.blade.php:240-241` (Alpine waCount/emailCount, optimistic `++` `:250`,
then server count `:259`). See `contacts.md` §2.

---

## 8. CONSENT / POPIA TIE-IN (send gate)

`MarketingConsentService` (AT-49 "one opt-out, suppressed everywhere") — pre-send gate
`isContactSuppressed()` `:216-227`. `SellerOutreachComposerService.php:99-102`:
`optOutBlocks = messaging_opt_out_at !== null || isContactSuppressed()`;
`SellerOutreachSenderService.php:41-49` throws `DomainException` if `!isSendable()`. See `compliance.md`
§4/§6. **⚠ The gate blocks COMPOSING/recording the send, not transmission** — actual delivery is the agent's
own WhatsApp/email client (wa.me/mailto). See §9.5.

---

## 9. DATA READ/WRITTEN + FRAGILITIES

### Data
Tables: `communications` (`2026_06_26_000001` — channel/direction enums, `external_id`, unique
`(agency_id, external_id)`, **SoftDeletes** + `purged_at`/`purged_reason`; `provisional_at`+`text_hash`
added `2026_06_29_000001`), `communication_attachments`, `communication_links`, `communication_mailboxes`
(+credential reveals `2026_06_28_000002`), `communication_wa_devices`, `communication_pending`,
`communication_flags`+`_flag_alerts` (`2026_06_27_*`), `marketing_suppressions` (`2026_06_16_190002` —
`lifted_at`, never hard-deleted). **SoftDeletes on ALL comms models.** **Audited:** no formal audit-package
trait — auditing is structural (append-only + soft-delete + `mailbox_credential_reveals` table + `Log::info`
drops). State that explicitly so no one assumes row-level audit history.

### Fragilities (LIVE — 4 agents)
1. **IndexedDB schema dependence (HIGH).** `content.js` reads WA Web's private `model-storage`. If WhatsApp
   renames stores or changes the `true/false_jid_msgid` id format, `idbExtract` returns null `:523` and
   **zero messages capture with no error surfaced** — only a console `warn`. The `ping` heartbeat still
   succeeds, so "last_seen" looks healthy while nothing is captured. Mitigations: fuzzy store-match `:472-475`,
   index-optional fallback `:478-501`, DOM body fallback `:445-446` — but the id-format break is unguarded.
   This class of break already bit them once (DOM obfuscation, `content.js:11-13`).
2. **CORS wildcard (MEDIUM).** `config/cors.php:32` `allowed_origins => ['*']` covers `communications/wa/*`,
   `portal-captures/*`, AND `api/*`. Justified for token-auth machine endpoints (`supports_credentials=false`
   `:43`), but the `api/*` wildcard is broad — unsafe if cookie/session auth is ever added to `api/*`.
3. **No real capture permission gate beyond the device `active` flag (MEDIUM).** The only revocation is
   `WaDeviceController::destroy → active=false`. No per-session consent, no token auto-expiry, no successor
   handoff on offboarding. **A departed agent's still-`active` device + token keeps capturing until manually
   revoked.** (This is the gap the "midnight-reset/successor" assumption wrongly thought was solved.)
4. **Provisional reconciliation gaps (MEDIUM).** Reconciliation fires only for **outbound**
   (`WaArchiveIngestor.php:104`, `EmailArchiveIngestor.php:125`); needs the provisional row already
   contact-linked `:51-61`; time-window default 48h `:80` — a compose-now / Sent-ingested-later (>48h) with
   edited text (hash differs) creates a **duplicate** archive row, **inflating the AT-59 tile**. Provisional
   rows whose real send is never ingested persist forever and still count in the tile.
5. **Consent suppression is on COMPOSE, not transmission (POPIA nuance).** The gate (§8) blocks building the
   wa.me/mailto send. Since delivery is the agent's own client, **nothing technically stops an agent
   messaging a suppressed contact directly outside CoreX** — and that out-of-band message can later be
   ingested by capture, which has **no opt-out check** (`WaArchiveIngestor`/`EmailArchiveIngestor` gate only
   on contact-match, not suppression). State honestly in any compliance assertion.
6. **Batch error swallowing (LOW).** `WaIngestController.php:67-71` counts failures as `invalid` and logs,
   but the extension only sees a stats object — a systematically failing message type is invisible to agents.
7. **IMAP `since` window (LOW).** Poller backs off to `last_polled_at - 1 day` `:48-49`; a mailbox unpolled
   >1 day (poller down) can miss messages older than the window on resume (dedup prevents double-capture, not
   the gap).

---

## Key file:line index
- `app/Http/Middleware/AuthenticateWaCapture.php:22-44`; `app/Http/Controllers/Communications/WaIngestController.php:26-114`.
- `app/Services/Communications/WaArchiveIngestor.php:41-151`, `EmailArchiveIngestor.php:46-157`, `ContactIdentifierResolver.php:27-72`, `OutboundProvisionalLogger.php:33-80`, `ProvisionalReconciler.php:44-127`, `ImapMailboxPoller.php:30+`.
- `chrome-extension/wa-capture/content.js:438-630`.
- `app/Models/Contact.php:700-736` (links + tiles); `app/Models/Communications/CommunicationPending.php:21-90`.
- Consent: `app/Services/SellerOutreach/MarketingConsentService.php:216-227`, `SellerOutreachComposerService.php:99-102`, `SellerOutreachSenderService.php:41-49` (cross-ref `compliance.md`).
- `config/cors.php:32`; `routes/console.php:97-99`.
