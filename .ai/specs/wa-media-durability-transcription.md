# CoreX — WhatsApp Media Durability + Voice-Note Transcription (Stage 2)

**Status:** DRAFT for approval. NO build until Johan signs off. Held from live.
**Extends:** `claude_communication_archive_spec.md`, `claude_communication_capture_setup_spec.md`; builds on AT-148 (WA media), AT-149 (webhook), AT-136 (capture consent), AT-118/132 (access gate).
**Owner:** Johan (domain) · Build: CC.
**One-line:** Make every captured WhatsApp media byte permanently safe, and turn voice notes into searchable Afrikaans/English transcripts — all under the existing POPIA gates.

---

## PART 0 — AS-BUILT GROUND TRUTH (investigated 2026-07-03)

### 0.1 Media storage
- Content-addressed writer `CommunicationStorageService`: `store($agencyId,'attachment',$bytes)` → `communications/{agencyId}/attachment/{first2ofSHA256}/{full_sha256}` on the `local` disk = `storage_path('app/private')` → **`/corex/storage/app/private/...` on `/dev/sda`** (the 197 GB Hetzner data volume; staging 72% full, ~54 GB free). Filename **is** the sha256; identical bytes stored once (dedup). No extension.
- Integrity is intrinsic: the path == `sha256(bytes)`, so a byte-level audit is a re-hash-and-compare — no extra metadata needed (`content_hash` column already stores it).
- Consistency (staging): **0 orphaned DB rows** (every `communication_attachments.storage_path` resolves to a file); **9 harmless orphaned files** (content-addressed writes whose DB row was rolled back/deleted — no file GC).
- Deletion: **nothing deletes media.** `CommunicationStorageService` has no delete path; `CommunicationAttachment` SoftDeletes at the DB layer only (no `deleting` file hook); the retention prune commands are DB-index-only ("never hard-deletes"). Media is write-once.

### 0.2 Backup truth (the durability GAP)
- **Captured WhatsApp media is NOT backed up. It exists as a single copy on `/dev/sda`.**
- The only automated backup is `/etc/cron.d/nexus_os-backup` → `nexus_os-nightly-backup.sh`: a **DB-only** `mysqldump` of live `nexus_os`, gzipped, last-14, written to `/mnt/HC_Volume_103099143/db-backups` — **on the same `/dev/sda` volume**. No media, no `storage/`, and no off-volume/offsite copy (even the DB dumps share the volume they protect).
- No backup tooling installed (`restic`/`borg`/`rclone`/`s3cmd`/`aws`/`spatie/laravel-backup` all absent). A one-off `2026-03-03` files tarball exists but predates WA media (0 `communications/` entries).
- **A single `/dev/sda` failure loses 100% of captured media AND every backup.** This is the #1 durability risk — not deletion.

### 0.3 WAHA ephemerality (already mitigated)
GOWS keeps decrypted media in a short-lived container `/tmp` and emits a container-internal URL. AT-148 (2026-07-03) fixed this: synchronous host-rewrite download at ingest + `WaMediaRecoveryService` re-download (`chats/{chat}/messages?downloadMedia=true`) + retry + terminal `failed`. **Confirmed coverage:** once a byte reaches CoreX storage it is durable within the box; the remaining gap is off-box backup (0.2), not WAHA.

### 0.4 Archive search
- `CommunicationArchiveController::index()` runs an **unindexed `LIKE '%term%'`** over `communications.subject`, `from_identifier`, `body_preview` (the 160-char preview — **not** `body_text`). No FULLTEXT, no Scout. Gated by `applyArchiveVisibility` (AT-118/132) applied to the query BEFORE the search clause. A withheld body (`body_status='consent_pending'`) has `body_preview=null` so it is already excluded from body search.
- `communication_attachments` has **no text column** and is **never searched**. Voice notes are today unsearchable.

### 0.5 Transcription capability
- Box: 16 ARM cores (CAX41 Ampere), 30 GB RAM, `ffmpeg` present, **no** whisper/faster-whisper installed. WA capture is **pre-live** (0 voice notes on `nexus_os`) — no historical HFC volume to measure; sizing is modelled (§3.6).

---

## PART 1 — MEDIA DURABILITY

**Requirement:** every captured media byte is permanently safe, verifiable, and recoverable.

### 1.1 Retention guarantee (make the as-built explicit + enforced)
- Media is **write-once, no hard delete** (already true). Spec makes it a tested invariant: a regression guard asserting no code path unlinks a file under `communications/**/attachment/**`. Soft-delete/purge stays DB-index-only. Configurable retention window only ever sets `purged_at` — never removes bytes.

### 1.2 Backup inclusion (CLOSE THE GAP — highest priority)
- **New nightly media backup**, alongside the DB dump, that includes `storage/app/private/communications/**`.
- **Off-box + offsite** is mandatory (the current DB backup sharing `/dev/sda` is itself a latent single-point-of-failure — spec recommends moving BOTH media and DB dumps off-volume).
- Recommended mechanism (approval choice): **`restic` to an offsite object store** (S3-compatible / Hetzner Storage Box / Backblaze B2) — dedup + incremental + encrypted, ideal for content-addressed immutable files (near-zero re-upload since filenames are hashes). Fallback if no offsite budget yet: a second **physical volume** on the box (still same-box risk, but survives `/dev/sda` loss) + `rclone`/`rsync` incremental. Either way: encrypted at rest, retention policy, restore-tested.
- The backup job is a `communications:backup-media` artisan command (or a shell wrapper) on a nightly cron, **CPU/IO-nice'd**, with a run log + a health check (last-success timestamp surfaced to Admin). Content-addressed files make incremental backup trivial (only new hashes upload).
- **Report/alert** if a backup run fails or the last-success is stale (> N hours) — a silent backup failure is the real danger.

### 1.3 Integrity verification (periodic audit)
- New `communications:audit-media` command (agency-scoped, `--fix-orphans`, `--dry-run`, schedulable):
  1. **Byte integrity** — for each `stored` attachment, re-hash the file and assert it equals `content_hash` (and equals the path). Flag mismatches (corruption).
  2. **Row→file** — flag DB rows whose file is missing (should be 0; if found, trigger the AT-148 re-download recovery).
  3. **File→row** — count orphaned files (files with no live row); `--fix-orphans` archives/removes only with an explicit, audited, dry-run-first action (never silent).
  - Runs weekly by default (configurable). Results surfaced to Admin + logged. Thresholds configurable.

### 1.4 No-data-loss paths (confirm coverage)
- Ingest: synchronous download (host-rewritten) → CoreX storage (AT-148). Fail → `pending` + queued retry → re-download from WAHA → terminal `failed` + visible Retry. **A media message is never dropped** (the envelope always archives; the media recovers or is retryable). Confirmed.
- The only residual loss vector was backup (1.2).

---

## PART 2 — VOICE-NOTE TRANSCRIPTION

**Requirement:** local (on-box) transcription of Afrikaans/English/mixed voice notes into searchable text, nightly + on-demand, under the same gates.

### 2.1 Engine (local, on-box, CPU)
- **faster-whisper (CTranslate2)** on the 16-core ARM CPU, **int8** quantization. Chosen over `whisper.cpp` for ~4× throughput + first-class multilingual + Python batch ergonomics; over cloud APIs for POPIA (client conversations never leave the box) and cost.
- Invoked as an isolated worker (a small Python service/CLI at e.g. `/opt/corex-transcribe/`), called by CoreX via a thin PHP `TranscriptionService` (shell-out with a strict timeout + resource cap), mirroring the `WahaMediaClient` seam. `ffmpeg` decodes the `.oga/.opus` to 16 kHz mono PCM first.
- Model files live on the box (one-time download), path configurable.

### 2.2 Language + model quality test (REQUIRED before model lock)
- Notes are **Afrikaans / English / code-mixed** → a **multilingual** model is mandatory (English-only models are disqualified).
- **Model-quality test methodology** (run on REAL HFC voice notes before choosing):
  - Corpus: ≥ 20 real HFC voice notes spanning Afrikaans, English, and mixed (the AT-148-recovered staging notes are a seed set; gather more once capture is live).
  - Candidates: `small`, `medium`, `large-v3` (all multilingual, int8). Optionally an Afrikaans-tuned checkpoint if one measurably wins.
  - Metric: human-rated adequacy + WER against a hand transcript, **per language bucket**; plus wall-clock RTF on the box. Language auto-detect vs forced-`af`/`en` compared.
  - **Deliverable of the test:** a short table (model × language × WER × RTF) that justifies the chosen model. Bias to the smallest model that clears a usable quality bar for Afrikaans (medium is the likely floor; large-v3 if Afrikaans WER on medium is too high).
- Language is stored per transcript (detected or forced); mixed handled by whisper's segment-level detection.

### 2.3 Nightly batch + on-demand
- **Nightly batch** default **22:00**, **agency-configurable schedule** (cron expression / time per agency) + a global **CPU cap** (thread count + `nice`/`cpulimit`) so transcription never starves the app. Transcribes the day's ingested, not-yet-transcribed, consent-eligible voice notes.
- **On-demand "Transcribe now"** per voice note in the thread — **CPU-guarded** (refused/queued if the box is under load or a nightly run is active) for urgent notes.
- **Per-note "View transcription"** affordance in the thread bubble (AT-150), next to the AT-148 player.

### 2.4 States + retry (mirror AT-148)
- `transcript_status`: `pending` → `processing` → `done` / `failed`, with `transcript_retry_count` + terminal `failed` after configurable max, and a visible **Retry** affordance — exactly the AT-148 media pattern. A voice note never sits on "transcribing" forever. Queued via a `TranscribeVoiceNoteJob` with backoff.

### 2.5 Data model
- On **`communications`** (1 voice note = 1 message row → 1:1, inherits gates for free — the search agent's Option A):
  - `transcript_text mediumText null`, `transcript_preview varchar(255) null` (searchable short form, mirrors `body_preview`),
  - `transcript_status varchar(16) null`, `transcript_retry_count tinyint default 0`, `transcript_lang varchar(8) null`, `transcript_model varchar(32) null`, `transcript_at timestamp null`.
- No new table (a transcript is 1:1 with the message; a separate table adds a join for no isolation benefit). Migration + `schema:dump`.

---

## PART 3 — SEARCH INTEGRATION

- **Option A (approved direction):** fold the transcript into the existing archive search by adding **one clause** at `CommunicationArchiveController::index()` (currently lines ~43–47):
  ```php
  $q->where('subject','like',"%{$search}%")
    ->orWhere('from_identifier','like',"%{$search}%")
    ->orWhere('body_preview','like',"%{$search}%")
    ->orWhere('transcript_text','like',"%{$search}%");   // NEW
  ```
  A transcript match surfaces the source voice-note message row → the thread → the player. Inherits the AT-118/132 visibility gate (applied before the clause) and the AT-136 consent rule (transcript written only when the body/media is captured — see §4) so a withheld note has no transcript to match, exactly like `body_preview`.
- **Perf note:** archive search is today an **unindexed `LIKE '%…%'`** across all fields — adding `transcript_text` extends that scan. If transcript volume warrants it, a **MySQL FULLTEXT** migration (converting the search to `MATCH…AGAINST` across subject/body/transcript) is the right follow-up — but that is a **search-architecture change affecting all fields** and is scoped as a **separate ticket**; this spec mirrors the existing `LIKE`+preview pattern to stay minimal. Flagged, not silently deferred.

---

## PART 4 — POPIA / DOCTRINE

- **Consent (AT-136):** a transcript is body content. It is produced/stored **only when the message's body is captured** (`body_status='captured'`) — **never** for a `consent_pending` / opted-out note (the raw media is already withheld for those; transcribing it would be a backdoor). The nightly batch selects only consent-eligible notes. If consent is later granted, the note becomes transcribable on the next run / on-demand.
- **Access gate (AT-118/132):** the transcript rides the message row, so it inherits `applyArchiveVisibility` for viewing AND search — no separate gate, no leak path.
- **Redaction:** if a note's body is redacted/withheld, its transcript is withheld/removed in lockstep (transcript follows `body_status`). No transcript persists where the body doesn't.
- **No hard deletes:** transcript columns follow the message soft-delete/purge; purging a message nulls/withholds its transcript, never a hard file delete (transcripts are DB text, not files).
- **Configurability:** every threshold — nightly time, CPU cap, model, max retries, backoff, audit cadence, backup schedule/retention — is an agency or global setting, never hardcoded.
- **Nav:** "View transcription" + "Transcribe now" on the thread bubble; transcription status/health + the media-audit + backup-health surfaced under the Communications area (per the AT-161 IA); no orphaned pages.

---

## PART 5 — SIZING (CPU / disk) — modelled (no live volume yet)

Assumptions (small agency, ~10–15 agents once WA capture is live): **~100 voice notes/day**, avg ~25 s each → **~40 min of audio/day**. (Scale linearly for higher volume.)

- **CPU (faster-whisper int8, 16 ARM cores):**
  - `medium` RTF ≈ 0.3–0.5 → ~40 min audio ≈ **12–20 min** CPU/night.
  - `large-v3` RTF ≈ 0.6–0.9 → **~24–36 min**/night.
  - Even at **4× volume** (~160 min audio/day): medium ≈ 50–80 min, large-v3 ≈ 1.6–2.4 h — comfortably inside a nightly window with a CPU cap. On-demand single note (~25 s) = a few seconds (medium) to ~20 s (large-v3).
- **Disk:**
  - Transcripts: plain text, ~0.5–2 KB each → **~50–200 KB/day** (negligible).
  - Model files (one-time): `small` ~0.5 GB, `medium` ~1.5 GB, `large-v3` ~3 GB (int8 smaller). Fits easily in the 54 GB free.
  - RAM: `large-v3` int8 ≈ 2–4 GB resident — fine within 30 GB.
- **Net:** transcription is not resource-constrained on this box; the CPU cap is about protecting app latency, not capacity.

---

## Build order (on approval)
1. **Durability first** (media is un-backed-up NOW): nightly media backup (off-box/offsite) + `communications:audit-media` + backup-health surface. (Independent of transcription.)
2. Transcription engine install + `TranscriptionService` seam + the model-quality test → lock the model.
3. Data model (transcript columns) + `TranscribeVoiceNoteJob` + states/retry + nightly batch + on-demand + thread affordances.
4. Search integration (Option A clause) + gates.
5. Robustness/consent/nav sweep; config for every threshold.

## Open decisions for Johan
- Backup target: offsite object store (recommended) vs second on-box volume (interim)?
- Model: default to `medium` and only escalate to `large-v3` if the Afrikaans quality test demands it?
- Nightly time/CPU-cap defaults (22:00 / N threads) — confirm.

---

## PART 6 — DISK / BACKUP TRUTH TABLE (as-built facts, 2026-07-03)

**Disk layout (this box hosts BOTH live `/corex` and staging `/corex-staging`):**
- **`/dev/sda` = 200 GB ext4 = the mounted HC Volume** (`/mnt/HC_Volume_103099143`), 54 GB free. **All app code, all media, the MySQL datadir, the DB dumps, and all document/e-sign files live here.**
- **`/dev/sdb1` = 38 GB = the OS root `/`**, 6.2 GB free (OS + a stale 7 GB `/var/lib/mysql.OLD` + a March one-off tarball in `/root`).
- MySQL `@@datadir = /mnt/HC_Volume_103099143/mysql-data/` (on the volume, NOT the OS default).
- **`/dev/sda` is plain ext4 — NO LUKS/dm-crypt (unencrypted at rest).**
- **Fact (Johan): Hetzner SERVER backups snapshot the server disk (`/dev/sdb1`) ONLY — not mounted volumes.**

| Data class | Physical disk | Hetzner server backup? | Our nightly DB dump? | If OS disk (/dev/sdb1) dies | If volume (/dev/sda) dies |
|---|---|---|---|---|---|
| App code (`/corex`, `/corex-staging`) | **/dev/sda (volume)** | No | No | Intact | Lost (recoverable from git) |
| `nexus_os` MySQL datadir (LIVE) | **/dev/sda (volume)** | No | **Yes** (nightly `nexus_os` dump) | Intact | Lost — recoverable ONLY from the last nightly dump, **but the dump is on /dev/sda too → also lost** |
| `hfc_staging` MySQL | **/dev/sda (volume)** | No | No (ad-hoc only) | Intact | Lost |
| **WA media — current** | **/dev/sda (volume)** | No | No | Intact | **Lost (single copy, no backup)** |
| **WA media — "after the move"** | **/dev/sda (SAME — already on the volume; nothing to move)** | No | No | Intact | Lost (unchanged) |
| Nightly DB dumps | **/dev/sda** (`/mnt/.../db-backups`) | No | n/a | Intact | **Lost (on the same volume they protect)** |
| e-sign / document / property files | **/dev/sda (volume)** | No | No | Intact | Lost |

**Net exposure:**
- **OS disk (`/dev/sdb1`) dies:** Hetzner server backup restores the OS; the data volume is untouched → near-zero data loss.
- **Volume (`/dev/sda`) dies:** **TOTAL loss** — every DB, all WA media, all app data, all document/e-sign files, AND every nightly DB dump (they live on the same volume). The Hetzner server backup (OS disk only) contains **none of the data**. The only off-volume artifact is the March `/root/hfc_files_backup` tarball — ~4 months stale and predates WA media entirely.

**Correction to Task A:** WA media is **already on the mounted 200 GB volume** (`/dev/sda`). "Move media from /dev/sda to the mounted volume" is a no-op — `/dev/sda` **is** the mounted HC volume. No copy was performed (nothing to move); the media has always ingested to the volume. (Facts only — Johan judges Monday.)

---

## PART 7 — ENCRYPTION AT REST (as-built + options)

**As-built truth:** WA media — and everything else on `/dev/sda` — is stored **PLAIN / UNENCRYPTED** (ext4, no LUKS). Anyone with block-level access to the volume (a stolen/decommissioned disk, Hetzner-side storage access, or root on the box) can read the `.oga`/`.jpg`/`.pdf` bytes directly off disk.

| Option | What it encrypts | Protects against | Does NOT protect against | Key management | Perf cost | Migration cost |
|---|---|---|---|---|---|---|
| **A. Volume-level LUKS** (dm-crypt on `/dev/sda`) | The whole data volume (DB + media + everything) | **Stolen/discarded physical disk; Hetzner-side disk access** | Root on the running box (volume is mounted/unlocked); app compromise | LUKS passphrase or keyfile. Keyfile-on-OS-disk = auto-unlock at boot but weaker (attacker with both disks wins); passphrase = manual unlock → reboot downtime | ~low on AES-capable ARM (Ampere has AES extensions) — a few % | **High one-time:** encrypt-in-place (`cryptsetup reencrypt`) or migrate to a new encrypted volume; needs a maintenance window |
| **B. Application-level file encryption** (encrypt media bytes before store, decrypt on serve) | The media files only | Stolen disk **and** partially root/other-app (files are ciphertext without the app key) | App compromise (the app holds the key); metadata in the DB | App key in `.env`/KMS; rotation is a re-encrypt of all files | Per-serve encrypt/decrypt (voice notes tiny → negligible), but **breaks `response()->file()` streaming** (must decrypt to a stream) and **breaks content-addressed dedup** (ciphertext differs unless deterministic) | Medium code; re-encrypt existing files; touches the AT-32 storage layer |
| **C. No disk encryption** (rely on OS/app access control + POPIA gates) | Nothing at rest | — (only in-app access control) | Any disk-level access | n/a | none | none |

**POPIA rationale:** encryption at rest is a recognised technical safeguard for personal information (client conversations = personal info). It specifically hardens the "stolen/decommissioned disk" and "cloud-provider disk access" vectors that in-app access gates (AT-118/136) do not cover.

**Recommendation (Johan picks):** **Option A (volume-level LUKS)** — it protects **all** data at rest (DB + media + documents), keeps the content-addressed storage + `response()->file()` streaming + dedup exactly as-is (zero app change), and costs only a few % CPU on the AES-capable ARM. Option B adds real complexity (breaks streaming + dedup, more code, key rotation pain) for little extra protection — root/app compromise still reads plaintext through the app. The real trade-off for A is operational: the one-time volume re-encryption window and the boot-unlock key policy (keyfile-auto-unlock vs passphrase-manual). Recommend LUKS with a keyfile stored on the OS disk (`/dev/sdb1`) so a stolen *volume* alone can't be read, accepting that an attacker with *both* disks (or root) can — which matches the realistic threat model (disk theft/decommission), while a stronger passphrase-at-boot posture is available if Johan wants it. **No build until Johan picks A / B / C.**
