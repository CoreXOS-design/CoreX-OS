# Audit — Definitive OTP Engine Sweep (go/no-go for AT-130 canonical OtpService)

**Date:** 2026-06-30
**Status:** INVESTIGATION ONLY (no code). This is the audit AT-130 says must precede the
spec ("PENDING: an investigation (in flight)… Spec follows the audit").
**Scope:** entire codebase — web app, mobile/API (`Api/V1`, `Mobile*`), shared services,
config, routes, migrations, live DB.

## VERDICT (top line)
**The field is CLEAR. There is NO canonical, reusable `OtpService`.** Build one.
- The single real OTP engine is **`ClientAuthService::issueOtp/verifyOtp` + `ClientOtp`**
  (client-portal login) — it is the **proven pattern to extract from** (6-digit, hashed,
  expiring, rate-limited, single-use, `purpose`-aware, audited, dedicated mailer).
- **Andre's "mobile OTP" is this same client-portal OTP** — it is mobile-*consumed* via
  `/api/v1/client-auth/otp/*`, not a separate system. It is NOT a different base.
- One other 6-digit generator exists (`TvAccessCode`) but it is a **reusable device-
  pairing code, not an OTP** (not single-use, not hashed, not delivered).
- **No 2FA. No SMS OTP. No esign signer OTP. No app-specific verification code.**

Recommendation: build canonical `OtpService` by generalising `ClientAuthService`'s OTP
methods (destination-agnostic, consumer-specified `purpose` + audit sink), then migrate
`ClientAuthService` to consume it. Comms-gate break-glass (AT-130 first consumer) plugs in
as a new consumer.

---

## 1. Every OTP / one-time-code generator in the codebase

### 1a. CLIENT-PORTAL LOGIN OTP — the one real OTP engine ✅
- **Generate:** `app/Services/ClientAuthService.php:177-211` `issueOtp($email,$purpose,$request)`
  — `$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)` (`:180`),
  stored **hashed** `code_hash => Hash::make($code)` (`:188`).
- **Deliver:** email only, via the dedicated **`otp` mailer** —
  `Mail::mailer(config('clientauth.mailer','otp'))->to($email)->send(new ClientAuthOtpMail(...))`
  (`:198-200`). Mailer defined `config/mail.php:100-117` (from `Otp@corexos.co.za`,
  `MAIL_OTP_FROM_ADDRESS`, SMTP creds in `.env`; staging mailer is `log`). Mail class
  `app/Mail/ClientAuthOtpMail.php` → view `emails.client-auth.otp`. **Skips fake
  `@corexclient.co.za` addresses** (`:195-196`). Failures are caught + `report()`ed, never
  fatal (`:201-203`).
- **Validate:** `verifyOtp($email,$code,$purpose,$request)` (`:216-239`) — latest unused,
  unexpired, matching `purpose`; `Hash::check` (`:231`); on miss `increment('attempts')`;
  on hit `used_at => now()` (single-use, `:236`).
- **Expire / limits:** `config/clientauth.php:25-33` — length 6, **expires 10 min**, **max 5
  attempts**, resend cooldown 60s, **5/hour per email**. Validity gate in model
  `app/Models/ClientOtp.php:44-52` (`isValid` = !expired && !used && attempts<max).
- **Store:** `app/Models/ClientOtp.php` (SoftDeletes); table `client_otps`
  (`database/migrations/2026_05_09_120003_create_client_otps_table.php`); columns
  `client_user_id, email, purpose, code_hash, expires_at, used_at, attempts, ip, user_agent`.
- **Rate-limit (endpoint):** `app/Http/Controllers/Api/V1/ClientAuthController.php:83-95`
  (`RateLimiter` cooldown + hourly per email).
- **Used by / entry points (API, mobile-consumed):**
  - `POST /api/v1/client-auth/otp/send` + `/otp/verify` — `routes/api.php:118-119` →
    `ClientAuthController::sendOtp/verifyOtp` (`:71-140`).
  - Activation token returned from verify so the client can set a password
    (`ClientAuthController.php:377`, `ClientAuthService::issueActivationToken :334-343`).
  - **AgentQR onboarding** reuses this exact path — `Api/V1/AgentQrController.php:80-145`
    returns `requires_verification: otp` and tells the app to verify "same path as
    POST /api/v1/client-auth/lookup". **It does NOT generate its own code.**
- **Purpose:** docblock `ClientAuthService.php:20-21` — *"Core service for the mobile Client
  Portal sign-in flow."* This is the mobile OTP.

### 1b. TV ACCESS CODE — 6-digit, but a PAIRING code, NOT an OTP ⚠️
- **Generate:** `app/Models/TvAccessCode.php:78-85` `generateUniqueCode()` — same
  `str_pad(random_int(0,999999),6)` primitive, retried for uniqueness among active codes.
- **NOT an OTP:** stored **plaintext** (`code` column, not hashed), **reusable** (`is_active`
  + `last_used_at`, not single-use), `expires_at` **nullable = can be permanent**
  (`:41-48,62-73`), **never emailed/delivered** (displayed for TV device pairing),
  branch-scoped. Table `tv_access_codes`
  (`2026_02_24_200000_create_tv_access_codes_table.php`). Different use-case; shares only
  the generation primitive.

### 1c. Everything else flagged by keyword = NOT an OTP (disambiguation)
- **"OTP" in Docuperfect/esign = "Offer To Purchase"** (legal document type), not
  one-time-password: `app/Models/Docuperfect/Template.php:303,314,334,351-352`,
  `ESignWizardController.php:472,4378`, `TemplateController.php:918`,
  `PdfSplitterController.php:495`, tours `Support/Tours/defs/pdf-splitter.php:41,75`,
  `ImporterAiService.php:317`, migration `2026_05_21_220002_classify_otp_templates.php`,
  `KnowledgeSearchService.php:218`. **Pure noise.**
- **esign "delivery_mode"** = `esign | wet_ink | download` (how a doc is sent), NOT an OTP —
  `TemplateController.php:147,567,1317`, `ESignWizardController.php:926-928,1505,4208,4514`.
  **No signer OTP / signer PIN exists** — signer access is via unguessable
  `Str::random(64)` links (`SignatureService.php:2917`, `SalesDocumentController.php:105…`).
- **Unguessable tokens (not OTPs):** outreach opt-out `SellerOutreachSenderService.php:259`
  (48-char), presentation snapshot `SnapshotLinkService.php:162`, share tokens
  `ContactMatch.php:95`, API keys `AgencyApiKey.php:127-128`, sanctum tokens, file-name
  randomness. Tokens, not codes.

## 2. Confirm / deny
- **Client-portal login OTP (ClientAuthService — 6-digit, hashed, 10-min, .env otp mailer):
  CONFIRMED, and it is the ONLY true OTP.** ✅
- **Separate mobile OTP (mobile login / mobile FICA / app verification): DENIED.** No
  `Mobile*` controller generates a code — `app/Http/Controllers/Api/Mobile*.php` (9 files:
  Calendar, ContactCompliance, CoreMatch, Contact, EllieVoice, FeatureFlag, Property,
  RentalImages, Visibility) contain **zero** code-gen. Andre's "mobile OTP" = the
  client-portal OTP above, consumed by the app via the API. ✅ (resolves the uncertainty)
- **2FA / SMS OTP / app-verification path anywhere: DENIED.** No `*2fa*`/`*two_factor*`/
  `*verification*` OTP table; the `*_verified_at` migrations (PPRA users, P24 suburbs/
  provinces) are status timestamps, not OTPs. All delivery is **email only**. ✅
- **Live DB confirms:** only two relevant tables exist — `client_otps`, `tv_access_codes`
  (verified on `nexus_os`). ✅

## 3. Reusable or bespoke?
**All bespoke / endpoint-specific.**
- `ClientAuthService` OTP methods are clean and self-contained, but **coupled to the client
  portal**: keyed on `email` (+ nullable `client_user_id`), the fake-email domain skip, and
  `ClientAccessLog` as the only audit sink. It cannot today issue an OTP to an *arbitrary*
  destination for an *arbitrary* consumer with that consumer's own audit sink. The
  `ClientOtp.purpose` column is the only extensibility seam (currently login/forgot only).
- `TvAccessCode` is bespoke to TV pairing.
- **No shared `OtpService` / `OtpController` / OTP trait exists.**

## 4. Go/no-go
**GO — build the canonical `OtpService`.** No existing reusable engine to extend; extract
from `ClientAuthService::issueOtp/verifyOtp` + `ClientOtp` (the proven, hardened pattern).

**Build shape (for the AT-130 spec):**
1. `OtpService` owns: generate (6-digit, hashed), deliver (email via the existing `otp`
   mailer now; SMS later), validate (Hash::check, single-use `used_at`), expiry, rate-limit,
   and **audit every issue + use**. Each consumer declares: `purpose`, destination, who may
   trigger (capability), what unlocking grants, and its audit sink.
2. Generalise `client_otps` (or a new `otps` table) to be destination-agnostic: keep
   `purpose`, `code_hash`, `expires_at`, `used_at`, `attempts`, `ip`, `user_agent`; make the
   subject reference generic (not `client_user_id`-only).
3. **Migrate `ClientAuthService` to consume `OtpService`** (don't leave two engines).
   Optionally fold `TvAccessCode` later (lower priority — different semantics).
4. **AT-130 first consumer = comms-gate break-glass** (admin/`communications.grant_access`
   only; destination = requester's OWN verified email, NOT the fixed `.env` address;
   produces a session-scoped, midnight-reset, thread-scoped grant; events `otp_issued` +
   `otp_unlock` into `comms_access_audit_log` — note `CommsAccessAuditLog::EVENT_TYPES`
   must gain those two or `record()` throws). See the per-thread gate investigation
   (`2026-06-30-comms-gate-per-thread-redesign-investigation.md` §C2).

**Outstanding for the spec:** AT-130 asks to confirm how the `.env` OTP mailer is meant to
be used (sender vs destination). Confirmed here: `config/mail.php:100-103` defines it as the
**sender** mailer (`from` = `Otp@corexos.co.za`); the **destination** is the recipient's
email passed to `->to()`. The break-glass requirement (deliver to the requester's verified
address, not a fixed mailbox) is therefore satisfiable without changing the mailer.
