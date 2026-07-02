# CoreX — Communication Archive Spec (SSOT)

**File:** `.ai/specs/claude_communication_archive_spec.md`
**Status:** Draft for build + **AS-BUILT ADDENDUM (2026-07-02, AT-155)** — §§12–14 below document features shipped to Staging (HELD from live) ahead of this spec: AT-148 media capture/playback, AT-150 chat-bubble thread view, AT-151/152 group/broadcast noise filter. Supersedes scattered notes in the "Integrating email data into Corex" and "Real estate software market gap" threads.
**Owner:** Johan (domain/BA) · Build: Johan + Andre
**One-line purpose:** The agency holds an immutable, indexed copy of every client communication (email + WhatsApp) for 5 years, independent of what any agent does to their own mailbox or phone.

> **AS-BUILT NOTE (AT-155).** Sections 1–11 are the original design SSOT. Sections 12–14 record the code that actually shipped to Staging and were verified against it on branch `spec-remediation-calendar-comms` (origin/Staging `740b9c18`). Where the two differ, §§12–14 are the current truth. The WhatsApp **capture** transport has since gained a server-side WAHA path (AT-149) — that lives in the sibling `claude_communication_capture_setup_spec.md`; this file owns the **archive/index/render** side.

---

## 1. Why this exists (the liability it covers)

South African law (FICA, PPA, POPIA) requires an agency to retain all client communication for 5 years. Today HFC relies on a cPanel forwarder copying mail to a backup mailbox — which has already crashed under volume. When an agent leaves, they wipe their email and WhatsApp; two years later a client makes a claim and the agency has zero evidence to defend itself.

This module removes that exposure. It is the **evidence backbone the compliance policy doc describes**, and it is a launch-gating dependency for the template-locked outbound module (separate spec).

---

## 2. The one architectural rule that governs everything

**Decouple the Archive layer from the Intelligence layer. Never couple them.**

- **Archive layer (legal backbone):** captures and retains *everything*, immutably, regardless of whether it is ever tagged to a contact/deal/property. If matching fails, the legal record is still intact.
- **Intelligence layer (bonus):** links communications to contacts/deals/properties and runs Ellie suggestions. Sits *on top*. Its failure can never compromise the archive.

A capture must succeed and be retained even if every tagging step fails.

---

## 3. Locked decisions (do not re-litigate at build time)

1. **Channel-agnostic core.** One archive, one index, two capture *adapters* (email, WhatsApp). Adding a third channel later (SMS, etc.) = a new adapter, not a new archive.
2. **Raw message to object storage, never MySQL blobs.** Email → raw `.eml`. WhatsApp → raw `.json` payload. MySQL holds the **index only**.
3. **Attachment/media dedup by content hash.** The same brochure emailed 200 times is stored once. Single biggest storage saver on an agency.
4. **Append-only, soft-delete only.** Once captured, immutable. No hard deletes, ever (CoreX rule). 5-year auto-prune via `purged_at`/`purged_reason`, not deletion.
5. **5-year retention across the board** for communication records and their logs (FICA + PPA + POPIA evidence). Prune is a recorded, soft event.
6. **Storage destination is swappable; start on the existing CAX41 volume.** We do **not** currently run a Storage Box — so it is not assumed. The content-addressed writer (§7) abstracts the destination: raw payloads begin on the current volume and move to bulk storage by **config change, not rebuild**. Provisioning bulk storage (a Storage Box / expanded volume / S3-compatible bucket) is a near-term infra decision to make **before** the archive nears volume limits — not a blocker to building now. (Sizing: ~24 mailboxes × ~100 mails/day × 5yr ≈ 4M+ messages, ~1TB+ before dedup — eventually well past the 100GB volume.) MySQL index always lives on the CAX41 volume.
7. **WhatsApp capture is client-side, read-only.** The extension only *reads* already-rendered (already-decrypted) WhatsApp Web DOM and POSTs it to CoreX. It sends nothing through WhatsApp and automates no sending. This minimises ToS/ban risk (see §8) and is the *only* clean way to capture an agent's personal number.
8. **Credentials are agency-held.** Johan and Andre set all email credentials; agents never know them. IMAP polling needs no per-agent setup and no "password expiring" friction.
9. **Ingestion is gated to known CoreX contacts (POPIA data-minimisation).** A communication is archived only if its counterpart identifier (email address / WhatsApp number) resolves to a contact loaded in CoreX. No matching contact → not ingested into the permanent archive. This keeps the archive to legitimate business records (not agents' personal traffic) and is the privacy guarantee to staff. **Corollary obligation (policy-enforced, see policy doc):** every business contact MUST be loaded on CoreX; conducting business with an unloaded contact is an explicit breach — this closes the loophole where not-loading a contact would dodge the archive.

---

## 4. Unified data model

### 4.1 `communications` (the index — channel-agnostic)
| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| agency_id | fk | BelongsToAgency / BranchScope |
| channel | enum | `email` \| `whatsapp` |
| direction | enum | `inbound` \| `outbound` |
| external_id | string | email `Message-ID`; WA message id. **Dedup key** (unique per agency_id) |
| thread_key | string idx | email References/In-Reply-To root; WA chat id (per contact/group) |
| from_identifier | string | email address / wa number |
| participant_identifiers | json | all to/cc/group participants |
| occurred_at | datetime idx | when the message was sent/received |
| captured_at | datetime | when CoreX ingested it |
| subject | string null | email only |
| body_text | mediumtext | plain-text body for search |
| body_preview | string | first ~160 chars |
| raw_path | string | path on Storage Box to `.eml` / `.json` |
| has_attachments | bool | |
| content_hash | char(64) | hash of normalised message (secondary dedup safety) |
| source_ref | string | source mailbox address / capturing user_id+device |
| deleted_at | datetime null | soft delete |
| purged_at / purged_reason | datetime null / string null | 5-yr prune audit |

Indexes: `(agency_id, external_id)` unique · `thread_key` · `occurred_at` · `(agency_id, channel)`.

### 4.2 `communication_attachments`
`id, communication_id fk, filename, mime, size_bytes, content_hash char(64) idx, storage_path, agency_id`.
Content-hash dedup: identical files share one stored object; rows reference it.

**AS-BUILT (AT-148, migration `2026_07_02_000001_add_media_state_to_communication_attachments`):** this table gained media state so downloaded-but-not-yet-stored media is never lost —
- `storage_path` → **nullable** (a pending/failed attachment has no bytes on disk yet).
- `media_status` enum-ish string, default `'stored'` — values **`stored` / `pending` / `failed`** (`CommunicationAttachment::MEDIA_STORED|MEDIA_PENDING|MEDIA_FAILED`). *Do not confuse with `Communication.body_status` (`captured`/`unreadable`/`consent_pending`/null) — a different column on the parent index row.*
- `remote_ref` nullable — the source media URL (WAHA `media.url`), retained for retry when a download failed.
- `duration_seconds` unsigned nullable — voice-note length.
- Index `comm_att_media_status_idx`. `CommunicationAttachment::isPlayable()` = `media_status === 'stored' && storage_path` present. Full detail in §12.

### 4.3 `communication_links` (Intelligence layer — decoupled)
`id, communication_id fk, linkable_type (contact|deal|property), linkable_id, link_method (deterministic|attorney_ref|ellie_suggested|manual), confidence, confirmed_by fk null, confirmed_at null, agency_id`.
A communication may link to several entities. Archive does not depend on any row here existing.

### 4.4 `communication_mailboxes` (Email adapter config)
`id, agency_id, email_address, imap_host, imap_port, username, encrypted_password, poll_inbox bool, poll_sent bool, poll_interval_minutes, last_polled_at, last_uid_seen, active bool`.

### 4.5 `communication_wa_devices` (WhatsApp adapter registration)
`id, agency_id, user_id fk, wa_number, device_token (used by extension to auth), last_seen_at, active bool`.

---

## 5. Email adapter (IMAP)

- Library: `webklex/php-imap`.
- One queued poll job **per mailbox**, scheduled at the agency's `poll_interval_minutes`.
- Polls **Inbox and Sent** → both directions captured.
- For each message: dedup on `Message-ID` (skip if `external_id` exists for agency) → write raw `.eml` to Storage Box → insert `communications` row → extract attachments, content-hash dedup → store new objects only.
- Resilience (BUILD_STANDARD): malformed MIME, odd encodings, oversized attachments, dropped connections must not crash the worker or block the next message. Failures logged, message re-queued, never silently dropped.
- No per-agent setup; credentials agency-held.

**Reuses existing:** queue/scheduler infra already in CoreX. Storage writer is shared with the WA adapter (§7).

---

## 6. WhatsApp adapter (Web extension — reuse `portal-capture` pattern)

The existing `portal-capture` Chrome extension already reads a page's DOM and POSTs to `PortalCaptureController::ingest` (`/portal-captures/ingest`) authed by a per-user API token. The WA adapter is the **same pattern pointed at `web.whatsapp.com`.**

- **Content script** runs on `web.whatsapp.com`, observes the open chat DOM (messages are already decrypted in-browser), and extracts per message: chat id, message id, direction (in/out), sender, timestamp, text, media-present flag.
- **Incremental capture:** extension stores `last_message_id` per chat; only new messages are sent. A periodic sweep walks recently-active chats.
- **Background worker** POSTs batches to a new endpoint `/communications/wa/ingest`, authed by the agent's `device_token` (mirrors the portal-capture token flow).
- **Server** dedups on WA message id → writes raw `.json` payload to Storage Box → inserts `communications` row (`channel=whatsapp`, `thread_key=chat id`) → for media, the extension uploads the blob (already accessible in-session); server content-hash dedups.
- **Read-only.** The extension never sends WhatsApp messages and never automates the compose box. Outbound stays manual / `wa.me` (see template module).

**Packaging/distribution:** same as portal-capture — zipped, served from `public/downloads/`, install guide WhatsApp'd to agents. Can ship as a new mode inside the existing extension or a sibling extension; decide at build (lean: sibling, to isolate selector churn).

---

## 7. Shared storage service

One content-addressed writer used by both adapters:
- `store(agencyId, kind, bytes) -> {path, content_hash}` where identical bytes return the existing path (dedup).
- Destination: **pluggable**. Phase 1 writes to a path on the existing CAX41 volume; swap to bulk storage (Storage Box / expanded volume / S3-compatible) by config when volume pressure approaches. MySQL index references `raw_path` / `storage_path` only.
- Retention engine: nightly job soft-purges objects+rows past 5 years from `occurred_at` (or end of business relationship where linkable), writing `purged_at`/`purged_reason`. Never hard-deletes.

---

## 7.5 Ingestion gate (known-contact filter)

Both adapters resolve the counterpart identifier against CoreX contacts **at capture time** (deterministic email / WhatsApp-number match). Only matches are written to the permanent archive. This is a lightweight identifier lookup — not the full intelligence layer (§4.3 deal/property/Ellie tagging stays in Phase 4).

**AS-BUILT — noise gate runs BEFORE the known-contact gate (AT-151/152).** WhatsApp group chats (`…@g.us`), status broadcasts (`status@broadcast`), and any adapter-flagged `is_group` message are dropped at the very top of `WaArchiveIngestor::ingest()` (`isNoiseChat()`) — before matching, before any disk write, returning `RESULT_DROPPED`. This is a server-side gate that covers **every** capture path (Chrome-extension POST and the AT-149 WAHA webhook adapter), so a 1:1 thread key is never polluted by a group the agent merely participates in. Full detail + the soft-purge remediation command in §14.

**Inbound grace buffer (locked).** The gate's only failure case is **inbound**: a contact reaches out to us *before* the agent has loaded them (a new lead emails/WhatsApps cold). A pure gate loses that first message permanently — exactly the evidence that matters in a new-lead dispute. So unmatched **inbound** communications park in a `communication_pending` table for a grace window (agency setting, **default 4 calendar days, maximum 5** — sized for a Friday-afternoon lead surviving the weekend and a busy Monday).

- Contact loaded within the window → buffered items attach retroactively to the permanent archive.
- Window expires unmatched → item prunes (non-business inbound is not retained — POPIA-minimisation), **and** if it was genuine agency business, that expiry is the breach trigger under the contact rule (a business contact went un-loaded past the deadline).
- One mechanism, three jobs: protects first-contact evidence · enforces data-minimisation · operationalises the load-deadline.
- **Optional near-expiry nudge:** alert the responsible agent ~24h before a pending inbound item expires ("load this contact or its record won't be retained"). Recommended — it turns the prune into a deliberate choice, not silent loss.

**Outbound needs no buffer.** By definition we only contact a party we have already loaded, so an outbound message always has a known counterpart and writes straight to the archive.

**POPIA basis differs by direction** (reflected in the policy doc): inbound = data subject initiated, lawful basis to process and respond, *not* direct marketing under s69. Outbound first-contact marketing = the strict s69 / CPA regime, template-locked (§9 / outbound spec).

## 8. The one risk I won't bury (WhatsApp ToS)

Automating WhatsApp Web is against WhatsApp's Terms; aggressive automation can get a number banned. Our exposure is **low but non-zero** because the extension is **read-only** — it observes rendered DOM and sends nothing through WhatsApp, which is far less detectable/punishable than automated sending. Mitigations baked into the spec:
- Read-only capture; no auto-send, no compose automation.
- Human-paced sweeps (no aggressive polling of WhatsApp's servers — we read the DOM the user already loaded).
- This risk is **named in the compliance policy doc**, not hidden.

**Clean-but-costly alternative (documented, not chosen):** move agents onto agency-owned WhatsApp Business API numbers via a BSP. Captures cleanly and is ToS-safe, but costs per-conversation and does **not** capture an agent's existing personal number. We are not choosing this for personal-number capture; it remains the future option if/when agents move to agency numbers.

---

## 9. Relationship to the template-locked outbound module (downstream)

This archive is the dependency that makes the outbound rule enforceable:
- "First-contact = approved template only; free-form only on response" needs a **conversation-state flag** per contact/channel.
- **Inbound capture (this module) is what flips that flag** to "responded → free-form unlocked." Without capture, the lock can't release correctly.
Outbound template-lock is specced separately; it consumes `communications` inbound rows.

---

## 10. Build order (prompt sequence)

- **Phase 0 — Investigation (CC, read-only, no changes):** map `portal-capture` extension + `PortalCaptureController::ingest`; existing queue/scheduler/storage; contact/deal/property match points (`tracked_properties` first per Universal Match-or-Create); any WA scaffolding. Report files/lines. *(~1 prompt)*
- **Phase 1 — Archive spine:** migrations (5 tables), models (SoftDeletes, BelongsToAgency, scopes), shared content-addressed storage service, retention engine. *(~4–6)*
- **Phase 2 — Email adapter:** IMAP poller + scheduler, Message-ID dedup, `.eml` writer, attachment dedup, archive viewer UI + search + sidebar nav. **Shippable compliance milestone — kills the crashed backup mailbox.** *(~6–8)*
- **Phase 3 — WhatsApp adapter:** WA Web capture extension (read-only), `/communications/wa/ingest` endpoint + device-token auth, media dedup, WA threads in the archive viewer. *(~6–8)*
- **Phase 4 — Intelligence/tagging (decoupled, can trail):** deterministic match (contact email / wa number), attorney learn-once ref→deal, manual tag UI, Ellie suggest-don't-commit. *(~7–10)*

**Total ~24–32 prompts** across both channels, runnable as parallel tracks (email = one track, WA = the other). Phases 1–2 alone make HFC legally compliant on email.

---

## 11. Done-criteria (per CoreX standards, every build prompt)

`php -l` on changed files · `php artisan migrate` + `schema:dump` · `view:clear` · `scripts/dev-check.ps1` (0 new failures) · routes registered + verified · sidebar/menu nav entry present · SoftDeletes + BelongsToAgency on new models · permissions added to `corex-permissions.php` · snapshot/feature test for dedup (same message/attachment ingested twice = one stored object) · Tinker functional verification reported.

---

# AS-BUILT ADDENDUM (2026-07-02, AT-155)

The following three sections document code that shipped to **Staging (HELD from live)** ahead of this spec and were verified line-by-line against branch `spec-remediation-calendar-comms` (origin/Staging `740b9c18`). Ticket refs: AT-148, AT-150, AT-151/152. Where a statement here conflicts with §§1–11, this addendum is the current truth.

## 12. WhatsApp media capture — voice-note download → store-on-volume → playable (AT-148)

**Goal.** Capture WhatsApp voice notes (and other media) end to end: download the bytes, store the decrypted file on the **mounted data volume** (never `/`), attach it to the archived `Communication`, and play it inline in the archive thread via an **authenticated** route. Transcription is out of scope.

### 12.1 Download client — `WahaMediaClient`
`app/Services/Communications/WahaMediaClient.php` · `download(string $url): array`.
- Authenticated `GET` of the media URL with header `X-Api-Key: config('communications.waha.api_key')`. WAHA (GOWS engine) stores the **already-decrypted** bytes and serves cleartext on the URL — there is no separate download-media RPC and no inline base64 for the server path.
- Returns `['bytes' => …, 'mime' => …, 'size' => …]`.
- Guard rails (BUILD_STANDARD prevent-or-absorb): SSRF host allow-list `assertHostAllowed()` against `communications.waha.allowed_media_hosts` + the configured base-url host; `download_timeout_seconds` bound; `max_media_bytes` ceiling (default 50 MB) rejected early on `Content-Length` then re-checked on the received body. **Any failure throws `RuntimeException`** — the caller catches it and archives the attachment as pending (never drops the message).

### 12.2 Storage — `WaArchiveIngestor::storeMedia()`
- **Path A (inline base64):** the Chrome-extension transport passes `data_base64`; decoded and persisted directly.
- **Path B (URL, the WAHA seam):** a `media[]` item carrying `url` is downloaded via `WahaMediaClient` and persisted to the mounted volume through the shared `CommunicationStorageService` (§7 content-addressed writer) — writing `content_hash`, `storage_path`, `media_status = 'stored'`, `remote_ref = <url>`, `duration_seconds`.
- **Fail path (never lose the message):** if the download throws, the message and its envelope are still archived and a **pending** attachment row is written (`size_bytes = 0`, `content_hash = ''`, `storage_path = null`, `media_status = 'pending'`, `remote_ref = <url>` kept for retry). A body-less media message is therefore never dropped.
- Called at both ingest branches (fresh-create and promoted/reconciled outbound).

### 12.3 Data model
See the amended §4.2 — migration `2026_07_02_000001_add_media_state_to_communication_attachments` makes `storage_path` nullable and adds `media_status` (`stored`/`pending`/`failed`), `remote_ref`, `duration_seconds`. **`media_status` (on the attachment) is distinct from `body_status` (on the `communications` index row).** `CommunicationAttachment::isPlayable()` = `media_status === 'stored' && storage_path` present.

### 12.4 Authenticated serve route + player
- Route: `GET compliance/communication-archive/attachment/{attachment}` · name **`compliance.comm-archive.attachment`** · middleware `permission:access_communication_archive` + `agency.required` (`routes/web.php`).
- Controller `CommunicationArchiveController@attachment`: enforces the BelongsToAgency binding, the per-thread `applyArchiveVisibility(... notPurged ...)` gate (else `404`), `abort_unless($attachment->isPlayable(), 404)`, a disk-exists check, then streams via `response()->file(...)` with `Content-Disposition: inline`, `Cache-Control: private … no-store`, `X-Content-Type-Options: nosniff`. **Media is served through Laravel from the mounted volume — never from a public docroot path.**
- Player: in `thread.blade.php`, playable audio renders `<audio controls preload="none">` pointed at the authenticated route; a non-playable (pending) voice note shows a muted "Voice note — processing" chip.

## 13. Communication Archive — WhatsApp thread as a real chat (AT-150)

Single file: `resources/views/compliance/communication-archive/thread.blade.php` (view-only change; the per-thread visibility gate stays enforced upstream in the controller).

- **Direction-aligned bubbles:** `direction === 'outbound'` → **right** (`justify-end`); inbound → **left**. WhatsApp convention.
- **Palette conformed to the CoreX design system (dark-safe):** outbound = a green tint mixed from `--ds-green` over `--surface` via `color-mix`, with a **solid `#e6f4ec` fallback declared first** so browsers without `color-mix` (and dark mode) stay legible; inbound = neutral `--surface` with `--border`. Asymmetric bubble radius (tail: outbound bottom-right `4px`, inbound bottom-left `4px`). **No emojis.**
- **Metadata preserved per bubble:** sender label (`from_display`), timestamp (`occurred_at`), direction word, WhatsApp channel tag (`ds-badge-success`).
- **Never blank:** a media-only message renders the AT-148 player (or the pending chip), never an empty bubble; a genuinely empty message shows an italic "No message content captured" placeholder guarded by `@unless($hasBody || $hasAttachments)`.
- **Blade gotcha recorded (do not regress):** `@if` glued directly after a word character (e.g. `note@if`) is **not** parsed as a directive — the previous emoji byte had been satisfying that boundary. Fix pattern: **precompute label strings in PHP** and echo them (`$durSuffix`, `$backLabel`, etc.), never `word@if`.

## 14. Group / broadcast noise filter + soft-purge (AT-151/152)

**Prevent — `WaArchiveIngestor::isNoiseChat(string $chatId, array $msg): bool`.** Returns true (→ `RESULT_DROPPED`) for a chat id containing `status@broadcast`, ending `@g.us`, or a message flagged `is_group`. Called at the very top of `ingest()`, so it is the single server-side gate for **every** capture transport (extension + AT-149 WAHA adapter, which also drops these earlier as defence-in-depth). This closes the "Elize four-thread" fragmentation class — a 1:1 `@lid`/phone thread is never merged with groups the agent posts in.

**Remediate — `communications:purge-wa-noise {--agency=} {--dry-run}`** (`app/Console/Commands/Communications/PurgeWaGroupBroadcastNoise.php`). Soft-purge only: sets `purged_at = now()` + `purged_reason = 'group_broadcast_noise'` on `whereNull('purged_at')` WhatsApp rows whose `thread_key` is `%@g.us%` / `%status@broadcast%` (agency-scoped when `--agency` given; runs `withoutGlobalScope(AgencyScope)` for the sweep). **No hard delete — rows stay recoverable and content bytes are untouched;** the archive viewer hides them via `Communication::scopeNotPurged` (`whereNull('purged_at')`). `--dry-run` reports counts and writes nothing. Reusable so the same tool can remediate live once inbound capture runs there. 1:1 `@lid`/phone threads never match the purge predicate.

> **Deploy/live status:** all of §§12–14 are on **Staging, HELD** — not promoted to live. Promotion is gated on Johan's explicit authorization.
