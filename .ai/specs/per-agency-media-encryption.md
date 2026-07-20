# CoreX — Media Encryption at Rest (AT-173)

> **DECISION (2026-07-20, Johan): build APPLICATION-LEVEL media encryption with an
> app-managed key — NOT the full per-agency envelope / seal-unseal / consent-keystore
> design below (that remains the future upgrade path).** The envelope spec (from §1 on)
> is retained as the deeper design of record.

---

## DELIVERED — App-Level Media Encryption at Rest (Phase 1)

**What it does.** Client-sensitive files are encrypted with an app-managed key (AES-256-GCM,
authenticated) BEFORE being written to disk, and decrypted transparently on serve. Protects
a stolen/decommissioned disk, the off-box backups, a DB/volume dump, and casual file browsing
(POPIA §19). Honest limit: the key lives in the server environment, so it does NOT defend a
live-root attacker who can read it — documented, accepted.

**Key management.** A dedicated `MEDIA_ENCRYPTION_KEY` (base64, 32 bytes), SEPARATE from
`APP_KEY`, per environment in `.env` (never committed). `MEDIA_ENCRYPTION_KEY_PREVIOUS` slot +
per-file key-version header support rotation. Generate with `php artisan media:key:generate`
(refuses to overwrite an existing key — never orphans encrypted media).

**Envelope.** `CXE1 | keyVersion(1) | nonce(12) | tag(16) | ciphertext`. The magic header lets
read paths distinguish ciphertext from legacy plaintext, so a half-migrated store reads correctly.

**Scope (Phase 1).**
- **Communication media** — encrypt/decrypt seam in `CommunicationStorageService` (store/get).
  Content hash stays the PLAINTEXT sha256, so dedup + integrity are unchanged; ciphertext sits at
  the plaintext-addressed path.
- **FICA documents** (ID copies, proof of address, FICA forms) — `App\Services\Compliance\FicaDocumentStorage`
  writes encrypted to the PRIVATE disk (moved off the public disk) and serves via a decrypting
  stream route `compliance.fica.documents.view` (replaces the direct `Storage::url`). Migration-safe
  reads: legacy public/plaintext files still stream.
- **OUT:** public property/agent marketing photos (public by design). **Phase 2 (separate ticket):**
  DocuPerfect/e-sign working files (read by external PDF/image tools by raw path — need a decrypt-to-temp
  shim) and deal documents.

**Backfill.** `media:encrypt-backfill --scope=comms|fica [--dry-run]` — idempotent; per file:
in-memory round-trip proof BEFORE writing, temp-write + verify then atomic replace (comms) / write-to-private
+ verify then drop the public copy (fica), post-write read-back proof. No plaintext removed until its
ciphertext round-trips byte-for-byte. `--dry-run` writes nothing.

**Status indicator.** Compliance → **Media Encryption** (`compliance.media-encryption.status`): ON/OFF,
key configured, algorithm, covered scopes, FICA doc count, the backfill commands, and the honest
threat-model note.

**Performance.** AES-256-GCM on small files (voice notes, PDFs, ID scans) is sub-millisecond per
read/write; negligible on the serve path.

**Files.** `config/media-encryption.php`, `app/Services/Security/MediaCipher.php`,
`app/Services/Compliance/FicaDocumentStorage.php`, `app/Console/Commands/GenerateMediaKey.php`,
`app/Console/Commands/EncryptMediaBackfill.php`, `MediaEncryptionStatusController` + view + nav,
seams in `CommunicationStorageService` / `FicaController` / `FicaPublicController` / `FicaWetInkService`,
route `compliance.fica.documents.view`. Tests: `MediaCipherTest`, `CommMediaEncryptionTest`,
`EncryptMediaBackfillTest`, `FicaDocumentEncryptionTest`.

---

# (Original design of record — Per-Agency Envelope + Seal/Unseal + Consent-Gated Key Release)

> **Status: SUPERSEDED for v1 by the app-level design above; retained as the future upgrade path.** Jira **AT-173**.
> Slots into the media pipeline where the "encryption" step sat (supersedes plain app-level encryption). Depends on nothing that isn't already built; interacts with AT-163 (media + transcripts + restic off-box backup) and the switch-user consent design (Andre).

---

## DIGEST (read this first — the decision Johan is approving)

**What it does.** Every agency gets its own encryption key that CoreX cannot use on its own. WhatsApp media (voice notes, images), voice-note transcripts, and — later — sensitive document classes are encrypted at rest with that key. The key is only ever held in memory, and only after an agency admin unlocks the vault by re-entering their password. CoreX developers and super-admins **cannot** decrypt an agency's media without that agency's participation. This is the strongest at-rest + insider-access posture we can honestly offer on a single self-hosted box.

**How the key is protected (envelope encryption).** A random per-agency **Data Encryption Key (DEK)** does the actual encryption. The DEK is never written to disk in the clear. It is stored only in **wrapped** (encrypted) form:
1. wrapped by a key derived (Argon2id) from an **agency admin's password**, re-entered explicitly at unlock — even if already logged in; and
2. wrapped by a **one-time recovery code**, generated at setup, shown **once**, and held offline by the agency.

**The three trade-offs you are accepting** (each is a deliberate, documented consequence — not a defect):

1. **Reboot re-unlock ritual.** The DEK lives in server memory only. Every server reboot (or worker restart) **seals** all vaults. Until an agency admin re-unlocks, that agency's media won't play, nightly transcription for that agency pauses, and info-pack generation that embeds media is deferred — **with a clear "Vault sealed — an administrator must unlock it" affordance everywhere media would render, never a crash.** This is the price of not keeping the key on disk. Expected frequency: rare (planned reboots, deploys, crashes). Mitigation: an admin unlock takes seconds; we alert admins on seal; a configurable auto-seal-on-idle is optional, off by default.

2. **Forgotten password = the recovery code is the only way back.** Because CoreX can't decrypt without the agency, if **all** authorized admins forget their vault password, the **one-time recovery code is the only remaining way** to unlock and re-wrap with a new password. **If both are lost, that agency's encrypted media is permanently unrecoverable — by design.** The FICA envelope (who/when/thread — unencrypted metadata) survives; only the encrypted bodies are lost. At setup the agency signs an acknowledgement of this (stored, audited). This is the same bargain as any zero-knowledge vault.

3. **Root-on-the-running-box honesty.** The claim we make is precise: **encrypted at rest, sealed after reboot, consent-gated for CoreX staff, and fully audit-logged.** The claim we do **not** make is root-proof: while a vault is **unlocked**, the DEK is in the box's RAM, and anyone with live root on the running box could in principle extract it from memory or hook the decrypt path. Encryption protects the disk, the off-box backups, a stolen volume, and casual insider access — it does not defend against a live root adversary on the running host while a vault is open. We state this plainly rather than overselling.

**Consent for CoreX support.** When CoreX staff need to see an agency's media (support/debugging), key release rides the **same switch-user consent gate** as agency support access — the agency admin issues an OTP grant (canonical `OtpService`); no standing back-door. Every unlock, seal, and decrypt-serving access is written to an **immutable audit log** (the `BackupPasswordReveal` pattern).

**Rollout.** HFC first (single agency, Johan's own — safe to iterate), behind a per-agency `vault_enabled` flag. Existing plain media is encrypted by a background job at vault setup; new media is encrypted from day one. Once HFC is stable, offer it per-agency.

**What I need from you:** approve this envelope model + the three trade-offs (or adjust), and confirm the two open decisions in §12 (per-admin vs shared vault passphrase; auto-seal-on-idle default). Then I build.

---

## 1. Purpose & threat model

**Business requirement.** Client WhatsApp media and transcripts are sensitive personal data (POPIA). Today they sit as **plaintext files** on the `/dev/sda` volume (`storage/app/private/communications/**`) and as **plaintext DB text** (`communications.transcript_text`). Anyone with volume access (a stolen/miscarried disk, a backup leak, a broad DB dump, a CoreX developer with server access) can read them. The agency has no cryptographic control over its own data.

**Goal.** Give each agency cryptographic ownership of its media: encrypted at rest with a key CoreX cannot unilaterally use.

**In scope (what encryption protects):**
- A stolen or decommissioned `/dev/sda` volume → ciphertext only.
- The off-box restic backups (already encrypted in transit/at-rest by restic; now the media *inside* is also agency-encrypted → double-wrapped).
- A broad DB dump → transcripts are ciphertext.
- Casual/curious insider access (a developer browsing `storage/app`) → ciphertext.
- CoreX staff support access → gated behind an agency OTP grant, audited.

**Explicitly OUT of scope (stated honestly, not hidden):**
- A **live-root adversary on the running box while a vault is unlocked** — the DEK is in RAM; this is not defended. (§Digest trade-off 3.)
- Traffic in flight to the browser (that's TLS's job — already in place).
- The unencrypted **FICA envelope** (identity, timestamp, thread, direction) — kept plaintext deliberately so the archive is searchable/attributable even when sealed; only bodies/media/transcripts are encrypted.

---

## 2. Cryptographic architecture (envelope encryption)

```
                         ┌─────────────────────────────┐
   admin password ──Argon2id──▶ KEK_pw ──AES-256-GCM──▶ │  wrapped_dek (per admin) │
                                                         └─────────────────────────────┘
   recovery code  ──Argon2id──▶ KEK_rc ──AES-256-GCM──▶ │  wrapped_dek (recovery)  │
                                                         └─────────────────────────────┘
                                          unwrap ▼ (only in memory, only when unlocked)
                                   ┌──────────────────────────┐
                                   │  DEK  (256-bit, per agency) │  ── AES-256-GCM ──▶ media/transcript ciphertext
                                   └──────────────────────────┘
```

- **DEK** — a 256-bit random key, one per agency. It is the only key that touches media. Generated once at vault setup with a CSPRNG (`random_bytes(32)`).
- **Data encryption** — **AES-256-GCM**, per-file/per-value: each encrypted item gets a fresh random 96-bit nonce; the GCM auth tag is stored with the ciphertext (integrity + tamper detection). Envelope format: `v1 || nonce || tag || ciphertext` (a small versioned header so we can rotate algorithm later). Media files are encrypted whole (they're small — voice notes are KB); a streaming/chunked GCM variant is a documented future option if large files appear.
- **KEK derivation** — **Argon2id** (memory-hard) over the admin password / recovery code + a per-wrap random salt. Params (memory, iterations, parallelism) stored alongside each wrap so they can be tuned/upgraded. PHP: `sodium_crypto_pwhash` (libsodium, already available) — NOT bcrypt/PBKDF2.
- **DEK wrapping** — the derived KEK AES-256-GCM-encrypts the DEK. Stored wraps: **N password-wraps (one per authorized admin)** + **one recovery-code wrap**. The plaintext DEK is never persisted.
- **libsodium** is the crypto library throughout (constant-time, modern, bundled with PHP on the box). No home-rolled crypto.

**Why per-admin wraps (recommended default, see §12 open decision):** each authorized admin wraps the same DEK under *their own* vault passphrase, so any one admin can unlock, no shared secret is passed around, and granting/revoking an admin just adds/removes a wrap row (never re-encrypts media). The alternative (one shared agency passphrase) is simpler but reintroduces a shared secret.

---

## 3. Key lifecycle

### 3.1 Vault setup (once per agency, principal admin)
1. Admin opens **Communications → (Admin) Media Vault → Set up**, re-enters their password (step-up).
2. Server generates DEK (`random_bytes(32)`) **in memory**.
3. Server generates a **recovery code** (e.g. 8 groups of 5 Crockford-base32 chars, ~200 bits) **in memory**, wraps the DEK under it, stores `wrapped_dek_recovery` + KDF params + salt.
4. Server wraps the DEK under the setting admin's vault passphrase, stores that admin's `vault_key_wrap` row.
5. Server shows the recovery code **once** (copy + "I have stored this offline" confirm) and records a **signed acknowledgement** of the permanence trade-off (§Digest 2) — `vault_acknowledgements` row (agency, user, text version, timestamp, ip).
6. DEK is zeroed from memory (the vault is now *sealed* until an explicit unlock, or stays *unsealed* for the session per §3.3).
7. `agencies.vault_enabled = true`, `vault_status = 'active'`. A background **migration job** (§8) begins encrypting existing plaintext media for that agency.

### 3.2 Adding / removing an authorized admin
- **Add:** must be done while the vault is **unlocked** (DEK in memory). The DEK is wrapped under the new admin's chosen vault passphrase → new `vault_key_wrap` row. No media re-encryption.
- **Remove:** delete that admin's wrap row (soft-delete/audit). Media untouched. If it was the last password-wrap, the recovery code remains the only key — warn hard.

### 3.3 Unlock (seal → unsealed)
- Admin opens any sealed surface (or the Vault page), re-enters their **vault passphrase** (explicit, even when logged in).
- Server: derive KEK (Argon2id, stored params) → AES-GCM-unwrap the admin's `wrapped_dek` → **DEK now in the in-memory keystore** (§4), keyed by agency id.
- Audit row written (`vault_access_log`, event `unlock`).
- Vault is **unsealed** until sealed (§3.5).

### 3.4 Recovery-code unlock (forgotten password / disaster)
- On the Vault page, "Unlock with recovery code" → enter the code → unwrap DEK → unseal + **force re-wrap** under a new admin passphrase (so the agency regains a password path). Audited.

### 3.5 Seal (unsealed → sealed)
- **Server reboot / php-fpm restart / worker restart** → memory cleared → **all vaults sealed** automatically (the keystore is memory-only, §4). This is the reboot ritual (§Digest 1).
- **Optional auto-seal-on-idle** (configurable per agency, default OFF): seal after N minutes of no decrypt activity. Trade-off: tighter exposure window vs more frequent re-unlocks.
- **Manual seal** button (admin) for immediate lock-down.
- On any seal event: an **alert to agency admins** (in-app bell + email) — "Media vault sealed; unlock to restore playback/transcription."

---

## 4. Seal/unseal keystore (memory-only) — mechanics, honestly

- **Store.** The unsealed DEKs live in a process-reachable in-memory store: a dedicated small **keystore daemon** (recommended) OR the cache when backed by a **memory-only** store. **NOT** the DB, **NOT** the `local` disk, **NOT** `.env`, **NOT** the file/database cache (those persist to disk).
  - **Recommended:** a tiny long-lived local **keystore service** (Unix-socket, `127.0.0.1`-only, root-owned, no swap via `mlock`) that holds `{agency_id → DEK}` in locked memory and answers "encrypt/decrypt this blob for agency X" — so the DEK never even enters the PHP-FPM worker heap. Web/worker processes call it over the socket. This narrows the live-root exposure (only the daemon holds keys) and gives one seal-on-reboot point.
  - **Simpler v1 (documented fallback):** Redis configured **without persistence** (no RDB/AOF) as the DEK cache, `maxmemory-policy noeviction`, bound to localhost. Reboot clears it → auto-seal. Weaker than the daemon (DEK transits PHP heap) but far less to build. **Decision for Johan (§12).**
- **What a live root user can still reach (stated):** while unsealed, the DEK is in the daemon's (or Redis') memory; root can read process memory / the socket / hook the decrypt path. `mlock` prevents it hitting swap/disk but not a determined live-root. **This is trade-off 3.** We do not claim otherwise.
- **Seal = memory eviction.** No persistence means reboot = sealed, which is the security property we want (and the ritual we accept).

---

## 5. Encryption at rest — what and how

| Data | Today | After |
|---|---|---|
| WhatsApp media bytes (`storage/app/private/communications/{agency}/attachment/**`) | plaintext file | AES-256-GCM under the agency DEK; file holds `v1‖nonce‖tag‖ciphertext` |
| Voice-note transcripts (`communications.transcript_text` / `transcript_preview`) | plaintext DB text | ciphertext blob in a sibling column (`transcript_cipher`), plaintext columns nulled once migrated |
| Future doc classes (opt-in per type) | plaintext | same envelope; out of scope for v1 build, designed-for |

- **Content-addressed storage interaction (important).** Media is stored content-addressed by `sha256(plaintext)` (dedup). Encryption must preserve dedup and integrity: keep the **path = sha256(plaintext)** (compute the hash on the *plaintext* before encrypting, as today) so dedup still works and `content_hash` still verifies the decrypted bytes; store the **ciphertext** at that path. A per-agency nonce means identical plaintext across agencies yields different ciphertext (no cross-agency correlation) while still deduping within the agency by plaintext hash. `CommunicationStorageService` gains encrypt-on-write / decrypt-on-read seams (the single chokepoint — every reader already goes through it).
- **Transcripts** are encrypted at write (`TranscriptionService`) and decrypted at read; **search** over encrypted transcripts is the known cost — a sealed or encrypted transcript is not `LIKE`-searchable. Options (decide at build): (a) accept that transcript search only works while unsealed by decrypting server-side per query (slow, but correct), or (b) keep a **blind index** (HMAC of normalised tokens under a search-subkey) for equality/token search without revealing plaintext. v1 recommends (a) with a flagged follow-up for (b). This is the honest cost of encrypting the transcript.

---

## 6. Read path & sealed-state UX (No Silent Locks)

Every surface that renders media/transcripts must handle **sealed** gracefully — never a 500, always an explanation + the unlock path (STANDARDS "No Silent Locks"):
- **Thread bubble / archive** — a sealed voice note shows a "🔒 Vault sealed — an administrator must unlock media to play this" chip with an **Unlock** action (admin) or "ask your administrator" (non-admin). The envelope (sender/time) still renders.
- **Transcription** — nightly batch **skips sealed agencies** (logs it) and resumes after unlock; "Transcribe now" on a sealed vault returns "Vault sealed — unlock first."
- **Info-pack / document generation** that embeds media — deferred with a clear sealed notice, not a broken PDF.
- **Serving** (`compliance.comm-archive.attachment`) — sealed → 423 (Locked) with a friendly page; unsealed → decrypt-on-serve (via the keystore) and stream, **decrypt-serve audited**.

---

## 7. Consent integration (CoreX-staff access rides the switch-user gate)

- **Principle:** CoreX developers/super-admins cannot decrypt an agency's media without that agency's consent. There is no standing platform back-door key.
- **Mechanism:** align with the **switch-user / support-access consent** design (Andre). When CoreX staff need decrypted media for support, they must be operating under an **active agency support-access grant** — the agency admin issues an **OTP grant** (canonical `App\Services\Otp\OtpService`, a dedicated purpose e.g. `vault_support_access`) authorising a time-boxed support session. Only within that grant does the keystore release decryption for CoreX-staff context, and only if the vault is already unsealed by an agency admin (staff cannot themselves unlock — they have no wrap).
- **Interplay to nail down with Andre's design at build:** the support-access grant is the *authorisation*; the agency admin's unlock is the *key availability*. Both must hold. A grant without an unsealed vault = no media (staff still can't see it). This composes cleanly with the existing impersonation (`ImpersonateController` + `impersonation_logs`) — the vault gate is an *additional* layer on top of impersonation, not a replacement.
- Every staff decrypt-serve under a grant is audited with the grant id + the impersonation id.

---

## 8. Migration (existing plaintext → encrypted)

- At **vault setup**, a background job `communications:encrypt-agency-media {agency}` walks that agency's plaintext media + transcripts and re-writes them as ciphertext under the DEK (which must be unsealed for the run — setup keeps it unsealed for the job, or the job runs in a keystore-authorised context). Idempotent, resumable, rate-limited (nice), progress surfaced to the admin. A file already in `v1‖…` envelope form is skipped.
- **New media** is encrypted from first write once `vault_enabled` (encrypt-on-write in `CommunicationStorageService` / `TranscriptionService`).
- **No plaintext left behind:** after a file is re-written as ciphertext, the plaintext is overwritten in place (same content-addressed path) so no plaintext copy lingers; transcript plaintext columns are nulled after `transcript_cipher` is written.
- **Rollback:** while migrating, a partially-migrated agency mixes plain + ciphertext — the storage seam detects the envelope header (`v1‖`) per file and only decrypts ciphertext, so reads are correct throughout the migration.

---

## 9. Backup interaction (restic — AT-163)

- The nightly restic off-box backup already encrypts the whole snapshot (repo password) in transit + at rest on the Hetzner Storage Box. Once per-agency encryption lands, the media **inside** the snapshot is **also** agency-DEK-encrypted → **double-wrapped**.
- **Restore consequence (runbook):** to read an agency's media from a restored backup you need **(a)** the restic repo password (existing runbook) **and (b)** that agency's vault unlock (admin passphrase or recovery code). Restoring the volume alone yields ciphertext. This is stronger (a stolen backup is useless without the agency key) but adds a step to disaster recovery — documented in `scripts/backup/RESTORE-PROCEDURE.md` (a new "Decrypting agency media after restore" section) and cross-referenced here.
- The DEK wraps + recovery-code acknowledgement live in the DB → they ride the nightly DB dump → they survive with the backup. The recovery code itself is **not** in the backup (agency holds it offline), so a backup thief still can't decrypt.

---

## 10. Data model (migrations needed — build phase)

- `agencies`: `vault_enabled bool default false`, `vault_status enum(none,active,sealed,migrating)`, `vault_auto_seal_idle_minutes smallint null`.
- `agency_vault_keys` (the recovery + metadata, one per agency): `agency_id, wrapped_dek_recovery blob, recovery_kdf_params json, recovery_salt blob, dek_algo varchar, created_by, created_at` — **no plaintext DEK ever**.
- `agency_vault_key_wraps` (per authorized admin): `agency_id, user_id, wrapped_dek blob, kdf_params json, salt blob, created_by, created_at, deleted_at` (soft-delete on revoke).
- `agency_vault_acknowledgements`: `agency_id, user_id, ack_text_version, ip, user_agent, acknowledged_at` (the signed permanence acknowledgement — append-only).
- `vault_access_log` (immutable, mirrors `BackupPasswordReveal`): `agency_id, actor_user_id, event enum(setup,unlock,recovery_unlock,seal,auto_seal,decrypt_serve,support_decrypt,rewrap,admin_added,admin_removed), impersonation_id null, otp_grant_id null, ip, user_agent, meta json, created_at` — no update/delete (model `booted()` throws, like `deal_document_access_log`).
- `communications`: `transcript_cipher blob null` (ciphertext), plaintext `transcript_text`/`transcript_preview` nulled post-migration. Media files carry their envelope in-file (no schema change for the bytes).

All new tables `BelongsToAgency` except the box-global audit conventions already established; secrets are **blobs**, never logged.

---

## 11. Nav, permissions, robustness

- **Nav:** Communications → **Media Vault** (admin-only): status (active/sealed/migrating), Unlock, Seal, Manage authorized admins, Recovery-code re-issue-warning, migration progress, access-log view.
- **Permissions:** new `vault.manage` (setup / add-remove admins / seal), `vault.unlock` (unlock/decrypt) — owner + agency-admin default; **super-admin does NOT get decrypt by default** (that's the whole point — they get it only via the consent grant). Registered in `config/corex-permissions.php` + role_defaults + `sync-permissions --merge-defaults`.
- **Robustness / prevent-or-absorb:** wrong passphrase → clear reject, rate-limited (`OtpService::throttle` pattern), never reveals whether the DEK unwrapped vs the password was wrong beyond "incorrect passphrase"; sealed everywhere → graceful (§6); tamper (GCM tag mismatch) → "media integrity check failed," logged, never a silent wrong-bytes; a deleted admin's wrap → gone, others unaffected.

---

## 12. Open decisions for Johan (needed before build)

1. **Keystore:** the **root-owned mlock'd keystore daemon** (stronger, more to build) vs **non-persistent localhost Redis** (simpler, DEK transits PHP heap). Recommend the daemon for the real security story; Redis-no-persist is an acceptable v1 if you want it faster. — *your call.*
2. **Vault passphrase source:** a **dedicated vault passphrase per admin** (recommended — decoupled from login rotation) vs **derive from the admin's login password** (one fewer password, but must re-wrap on every password change and needs the old password/an unsealed session at change time). — *your call.*
3. **Auto-seal-on-idle default:** OFF (fewer re-unlocks, wider open window) vs a default like 8h. Recommend OFF for v1, configurable. — confirm.
4. **Transcript search while encrypted:** decrypt-per-query (v1, correct but slow, unsealed-only) vs a blind-index follow-up. Confirm v1 = decrypt-per-query.

---

## 13. Build sequence (on approval — one continuous build, gated)

1. Crypto core (`AgencyVaultService`: DEK gen, Argon2id KDF, GCM wrap/unwrap, envelope encode/decode) + keystore seam + unit tests (test vectors, tamper, wrong-key).
2. Data model + vault setup flow (recovery code once + signed ack) + `vault.*` permissions + nav.
3. Storage/transcription encrypt-on-write + decrypt-on-read seams (the single chokepoints) + sealed-state read UX everywhere.
4. Seal/unseal keystore + reboot-seal + admin unlock + alerts + immutable `vault_access_log`.
5. Consent integration (support-access OTP grant → keystore release) aligned with Andre's switch-user design.
6. Migration job (encrypt existing) + backup runbook update + demo/HFC rollout behind `vault_enabled`.
7. Full robustness/POPIA/nav/permission sweep; each threshold configurable.

**Gate per phase; nothing to live until HFC has run encrypted end-to-end on staging and the disaster runbook is rehearsed.**

---

## 14. Rollout plan (HFC first)

1. Land behind `agencies.vault_enabled` (default false) — zero effect on other agencies.
2. Enable for **HFC only** on staging; run the migration job; verify playback/transcription/info-pack through unlock/seal cycles; rehearse a reboot (auto-seal → admin unlock) and a recovery-code unlock; rehearse a restic restore + agency decrypt.
3. Johan signs off the disaster runbook.
4. Promote to live for HFC (Johan's agency — safe to be first); monitor the access log.
5. Offer per-agency thereafter; each agency does its own setup + acknowledgement.

---

## 15. What this deliberately does NOT do (honesty ledger)
- Not root-proof on a running, unlocked box (§Digest 3).
- Not a substitute for TLS in transit (already have it).
- Not encrypting the FICA envelope (kept plaintext for search/attribution/sealed-state usability, by design).
- Not zero-downtime across reboots for media (the re-unlock ritual is the accepted cost, §Digest 1).
- Not recoverable if both the admin passwords and the recovery code are lost (§Digest 2).
