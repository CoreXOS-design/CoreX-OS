# WhatsApp voice-note media capture — download, store-on-volume, playable

**Jira:** AT-148 · **Date:** 2026-07-02 · **Type:** build (STAGING, HOLD for Johan) · **Relates:** AT-138 (server-side session), AT-143 (WAHA Core on live box), AT-135/136 (body/consent), AT-133 (@lid)
**Scope:** voice notes (ptt/audio) only. Transcription OUT OF SCOPE.

---

## STEP 1 — Investigation findings (reported before build)

### 1a. WAHA gows voice-note payload shape (from WAHA docs + AT-138 closeout)

WAHA (GOWS / whatsmeow engine, `gows-arm-2026.6.2` on the live box at `127.0.0.1:3111`,
API-key ON) delivers one webhook POST **per message** (`event: "message"`, not a
`messages[]` batch — the AT-138 closeout empirical note). A voice note surfaces as:

| Field | Value for a voice note |
|---|---|
| `payload.hasMedia` | `true` |
| `payload.media` | object `{ url, mimetype, filename, error }` (or `null` if WAHA didn't download) |
| `payload.media.url` | WAHA-hosted file URL, e.g. `http://127.0.0.1:3111/api/files/<session>/PTT-<id>.opus` |
| `payload.media.mimetype` | `audio/ogg; codecs=opus` (voice notes are Opus) |
| `payload.media.filename` | begins `PTT-…` (Push-To-Talk marker) |
| `payload.media.error` | `null` on success; populated if WAHA's own download failed |
| `_data.Info.MediaType` / message type | `ptt` (voice note) vs `audio` (attached audio file) — both are audio; ptt = mic note |
| `_data.Message.audioMessage.seconds` | duration in seconds (gows raw; NOT in the normalised `media` object) |
| `_data.Info.SenderAlt` | resolves to the real **phone** (AT-138: @lid solved server-side) |

**Noise filter (preserved):** skip `status@broadcast` and any `IsGroup == true` message —
per the AT-138 empirical closeout. The ingestor already drops non-contact / @lid-only;
the group/status filter is applied at the adapter/ingest boundary.

### 1b. Media-download mechanism — **downloadable URL, NOT a separate download-media call**

WAHA **stores the decrypted media itself** and hands us a **direct authenticated URL** in
`payload.media.url`. There is **no separate "download-media" RPC** to invoke for the GOWS
engine — you GET `media.url` with the WAHA API key:

```
GET  {media.url}
Header: X-Api-Key: <WAHA_API_KEY>     (or ?x-api-key=… query param)
→ 200, body = decrypted .ogg/.opus bytes
```

So CoreX's job is: **authenticated GET of `media.url` → bytes → store on the volume.**
WhatsApp's encryption is handled inside WAHA — we receive cleartext Opus. If `hasMedia`
is true but `media` is `null` (WAHA media-download disabled) or `media.error` is set, we
treat it as a **download failure → archive the message, mark the attachment media-pending**
(never drop a bodyless media message).

### 1c. Existing WA archive schema — is there a media column?

Yes — **`communication_attachments`** (AT-32) already stores media:
`filename, mime, size_bytes, content_hash, storage_path` + soft-deletes, FK to
`communications`. `WaArchiveIngestor::storeMedia()` currently writes it from an **inline
`data_base64`** (the Chrome-extension path). The gap for WAHA:

- Media arrives as a **URL to fetch**, not inline base64 → need a downloader.
- No **pending / failed** state for a media that couldn't be fetched yet (robustness rule).
- `storage_path` is **NOT NULL** → a pending attachment (no file yet) can't be recorded.
- No **duration** and no **remote reference** (to retry the fetch later).

**Migration (additive, no hard deletes, follows existing nullable-column pattern):**
add to `communication_attachments`:
- `media_status` VARCHAR nullable, default `'stored'` — `stored` | `pending` | `failed`
- `remote_ref` VARCHAR(1024) nullable — the WAHA `media.url` to (re)fetch
- `duration_seconds` INT UNSIGNED nullable — voice-note length for the UI
- make `storage_path` **nullable** (a pending row has no file yet)

### 1d. Mounted-volume media path + authenticated serve route

- **Storage disk:** `communications.disk` (`local`) → `storage_path('app/private')`. On this
  worktree that resolves to `/mnt/HC_Volume_103099143/corex-dev-2/storage/app/private`,
  which `df` confirms sits on **`/dev/sda` mounted at `/mnt/HC_Volume_103099143`** — the
  **mounted volume**. `/` is `/dev/sdb1` (38 G) and is **never** written. Content-addressed
  path: `communications/{agencyId}/attachment/{ab}/{sha256}`. Reusing
  `CommunicationStorageService` guarantees media lands on the volume with **no code path to `/`**.
- **Authenticated serve route (NEW):** `GET /compliance/communication-archive/attachment/{attachment}`
  (name `compliance.comm-archive.attachment`) inside the **existing
  `permission:access_communication_archive` group**, and additionally gated **per-thread** by
  `CommsAccessGrantService::applyArchiveVisibility` on the parent communication (the same gate
  the thread/show views use — no body-surface bypass). It streams the bytes from the volume
  disk with the stored mime — **served through Laravel, never a public docroot path.**

---

## STEP 2 / STEP 3 — build (see commit)

- `WahaMediaClient` — authenticated GET of `media.url` (config: `communications.waha.base_url`,
  `communications.waha.api_key`), size-capped, timeout-bounded.
- `WaArchiveIngestor::storeMedia()` extended: inline `data_base64` still works; a `media[]`
  item carrying `url` (+ `mimetype`/`duration`) is downloaded via the client and stored on the
  volume. Download failure → attachment row `media_status = pending`, `remote_ref = url`, no
  file, message still archived.
- Authenticated serve route + inline `<audio controls>` player rendered on voice-note
  attachments in the thread view (falls back to a "Voice note — processing" chip when pending).

## Verification summary (staging build — HOLD for Johan)

**Test:** `tests/Feature/Communications/WaVoiceNoteMediaTest.php` — **9 passed, 31 assertions.**
- download from WAHA → `.ogg` stored on the volume path `communications/{agency}/attachment/…`, `media_status=stored`, `duration_seconds=7`, mime `audio/…`, bytes round-trip.
- **failure path:** WAHA returns HTTP 500 → message STILL `RESULT_ARCHIVED`, attachment `media_status=pending`, `storage_path=null`, `remote_ref` = the WAHA url (retryable) — **bodyless media never dropped.**
- noise filter: `status@broadcast`, `…@g.us`, and `is_group=true` all `RESULT_DROPPED` — 0 rows archived.
- authenticated serve route: owner gets **200 + `Content-Type: audio/…` + `Content-Disposition: inline`**; pending attachment → **404**; other-agency user → **404** (BelongsToAgency global scope).
- `WahaMediaClient` guards: host outside allow-list (SSRF) → throws; size-cap exceeded → throws; HTTP error → throws.

**Mounted-volume proof (real disk, via tinker):**
`CommunicationStorageService::store()` → `/mnt/HC_Volume_103099143/corex-dev-2/storage/app/private/communications/999999/attachment/58/58565…` — `df` reports `MOUNT: /dev/sda /mnt/HC_Volume_103099143`. **`/` (`/dev/sdb1`) free space 6.8 G before and after — unchanged.** Media is on the volume, never `/`.

**Schema:** `communication_attachments` now has `media_status, remote_ref, duration_seconds`; `storage_path` nullable. Route `compliance.comm-archive.attachment` resolves. Schema snapshot regenerated (`schema:dump`, contains the new columns).

**Live send test:** PENDING JOHAN — the WAHA capture session is held by another task; per the ticket we did NOT restart it or surface a QR. The WAHA webhook→adapter that produces `media[].url` is the parallel AT-138/143 work; this build handles that `media[].url` shape at the ingestion seam and is proven end-to-end with a fixture. When the adapter lands and a real voice note arrives, the player renders with no further change.

## Jira
AT-148 — "WhatsApp media capture — voice-note download, store-on-volume, playable in archive". Relates → AT-138, AT-143. Held on staging (branch `AT-148-wa-voice-note-media`).
