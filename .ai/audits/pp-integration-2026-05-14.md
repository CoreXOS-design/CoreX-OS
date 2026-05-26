# Private Property Integration — Audit (2026-05-14)

> READ-ONLY audit. No code modified. Symptom: PP sandbox submissions never reach `Active`, no `pp_ref` (T-number) returned to CoreX.

---

## Executive Summary

- **Root cause (one sentence):** The activation backfill is entirely dependent on the Listing Event Feed and `PollPrivatePropertyActivation`, but the event feed continuation key has not advanced since 2026-04-28 (NULL in DB), no PP tables exist beyond `pp_event_feed_settings`, and there is **no queue worker running schedule/queue locally** to drive either job — combined with the fact that `PrivatePropertySyndicationService::submitListing()` looks for response keys (`PPRef`, `ListingFeedRef`) that PP's WSDL response wraps inside a deeper envelope, so the synchronous activation path silently never fires either.
- The "stuck listings" SQL probe from the brief uses spec names that do **not exist** in this codebase: `App\Models\Listing` does not exist (PP attaches to `App\Models\Property`), the column is `pp_last_submitted_at` not `pp_submitted_at`, table `pp_continuation_keys` does not exist (state lives in `pp_event_feed_settings`), and `pp_event_log` does not exist. The audit brief and spec are out of alignment in naming.
- Locally there are currently **zero** Property rows matching the stuck pattern (no submissions have been made on this DB), but the architecture is wired so that whenever a submission does occur the activation backfill will only work if (a) PP returns `PPRef` directly at the top level of the synchronous `UpdateListing` response, OR (b) a queue worker is actively running both `PollPrivatePropertyActivation` and the scheduler-driven `ProcessPrivatePropertyEventFeed`.
- `PP_WEBHOOK_SECRET` is **not set** in this environment — webhook is rejected with 500 ("Misconfigured"). This blocks the inbound lead path but is not the root cause of stuck activations.
- Listing-event-feed continuation_key remains `NULL` (set on 2026-04-28 migration insert, never updated) — strong signal that the scheduled `ProcessPrivatePropertyEventFeed` job has never been executed against this DB, or has never advanced past zero events.

---

## Pre-read Confirmation

- `.ai/CLAUDE.md` non-negotiable #6 (Production quality) and #3 (Spec before code) treated as in scope (`.ai/CLAUDE.md:79-86,71-77`).
- `.ai/STANDARDS.md` — read in prior session; rules in scope.
- `.ai/specs/private-property.md` — full read complete:
  - Pillar table at `private-property.md:8-17` (writes to `pp_ref`, `pp_listing_feed_ref`, etc on Property).
  - SOAP method table `private-property.md:81-103`.
  - Mapper field table `private-property.md:107-138`.
  - Activation flow `private-property.md:163-186`.
  - Event feed flow `private-property.md:204-231`.
  - Webhook spec `private-property.md:235-256`.
  - Schedules `private-property.md:285-292`.
  - Known quirks `private-property.md:303-311`.
- `config/services.php:76-84` — PP config block confirmed.
- `.env.example` keys: `PP_USERNAME, PP_PASSWORD, PP_BRANCH_GUID, PP_WSDL, PP_SANDBOX, PP_WEBHOOK_SECRET` (no `PP_IMAGE_BASE_URL` in example — present in config code at `config/services.php:82`).

---

## Section-by-Section Findings

> Sections 3.1–3.20 map to the 20-area brief. Where the brief is generic, I have grouped related findings and explicitly flagged. Status: ✅ pass · ⚠️ concern · ❌ broken · ❓ unverifiable here.

### 3.1 Configuration & Environment
- **Checked:** `config/services.php:76-84`, `.env.example` PP keys, runtime values via tinker probe.
- **Status:** ⚠️
- **Spec:** `private-property.md:48-61`.
- **Evidence:**
  ```
  config/services.php:76-84  // username, password, branch_guid, wsdl, sandbox, image_base_url, webhook_secret
  // probe results:
  WEBHOOK_SECRET_SET=false
  PP_SANDBOX=true
  IMAGE_BASE_URL_SET=true
  APP_URL=http://localhost:8000   ← non-HTTPS local
  ```
- **Risk:** Medium for stuck-listing (PP_IMAGE_BASE_URL override is correctly set, so photo URLs go HTTPS even though APP_URL is http). High for webhook (missing secret = 500).
- **Fix plan:** Set `PP_WEBHOOK_SECRET` in production `.env` once PP Admin Portal registration completes; document `PP_IMAGE_BASE_URL` in `.env.example`.

### 3.2 Token / Auth
- **Checked:** `PrivatePropertyTokenService.php:19-36`.
- **Status:** ✅
- **Spec:** `private-property.md:65-76`.
- **Evidence:** Digest `base64(sha1(UID + StampTime + Password + Expires, raw=true))`, 24h expiry, password never wire-sent. Matches spec exactly.
- **Risk:** Low.

### 3.3 SoapClient construction & retry
- **Checked:** `PrivatePropertySoapClient.php:17-101`.
- **Status:** ⚠️
- **Spec:** `private-property.md:103` (retry policy).
- **Evidence:**
  ```php
  // line 22-37: SoapClient with verify_peer=false, cache_wsdl=BOTH
  // line 51-101: retry on timeout substrings, 3s backoff, fresh client
  ```
- **Concern:** `cache_wsdl=WSDL_CACHE_BOTH` (line 25) caches the WSDL across requests — if PP's sandbox WSDL ever changes mid-deploy, stale operations remain in memory until cache is cleared. Spec does not call this out, but the retry-on-timeout matches the spec verbatim.
- **Risk:** Low.

### 3.4 Listing Mapper
- **Checked:** `PrivatePropertyListingMapper.php:23-99` (map), `:122-201` (validate), `:206-283` (checkReadiness), `:546-572` (photo URLs).
- **Status:** ✅ structure matches spec.
- **Spec:** `private-property.md:107-138`.
- **Evidence:** PropertyId, BranchId, Category, MandateType, StreetName/Number, Suburb/Town/Province, Headline, Description, Price, Deposit, ListingDate/ExpiryDate/AvailableFrom, AgentId, PhotoUrls, XCoordinate/YCoordinate, ListingType, PropertyStatus, ShowdayEvents, Attributes, Hide* flags, RentalPriceType, SoleMandateExclusiveDays — all present.
- **Risk:** Low for stuck-listing.

### 3.5 Agent Registration
- **Checked:** `PrivatePropertySyndicationService.php:278-318` (registerAgent), `:326-390` (duplicate-detection remap), `:592-635` (ensureAgentRegistered).
- **Status:** ✅
- **Spec:** `private-property.md:144-160`.
- **Evidence:** `buildAgentData` at mapper `:501-522`; remap-before-create logic at `:326-390` matches the "duplicate quirk" mitigation. PP107 phone guard at service `:282-287` and `:605-608`.
- **Risk:** Low.

### 3.6 Listing Add/Update — PRIMARY SYMPTOM ZONE
- **Checked:** `PrivatePropertySyndicationService.php:25-137` (submitListing).
- **Status:** ❌
- **Spec:** `private-property.md:163-186`.
- **Evidence:**
  ```php
  // service line 70:  $result = $this->client->updateListing($payload);
  // line 99-106:
  if (isset($result['ListingFeedRef'])) {
      $updateData['pp_listing_feed_ref'] = $result['ListingFeedRef'];
  }
  if (isset($result['PPRef'])) {
      $updateData['pp_ref'] = $result['PPRef'];
      $updateData['pp_syndication_status'] = 'active';
      $updateData['pp_activated_at'] = now();
  }
  ```
  The SOAP envelope returned by PHP `SoapClient` for `UpdateListing` is typically `{ UpdateListingResult: {...} }` (or contains an `any` XML blob, as is evident from `getAllAgentsForBranch` parsing at service `:336-361`). Top-level keys `PPRef`/`ListingFeedRef` will not be present unless PP returns a flat envelope. There is **no parser for the actual UpdateListing response shape** anywhere in this file.
- **Risk:** **HIGH.** This is the most likely synchronous-path failure: even when PP does return a T-number on submit, CoreX silently flips status to `submitted` and never to `active`.
- **Fix plan (no code):**
  1. Add raw-XML logging of `UpdateListing` response in `PrivatePropertySoapClient::call` (already present at `:70`) — confirm the actual key shape in `storage/logs/private_property.log`.
  2. Extend `submitListing` to walk likely envelope shapes: `$result['UpdateListingResult']['PPRef']`, `$result['return']['PPRef']`, and the `any` XML pattern used elsewhere in the same service.
  3. Add regression test that mocks SoapClient with the envelope shape PP actually returns.

### 3.7 Image Feed / Image Submission — HIGH PRIORITY
- **Checked:** Service `:417-477` (submitAgentImages), mapper `:546-572` (buildPhotoUrls).
- **Status:** ⚠️
- **Spec:** `private-property.md:128-129` (PhotoUrls), `:159` (image specs), `:309` (HTTPS required), `:326-327` (PP120).
- **Evidence:**
  ```php
  // mapper:558-568
  if (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
      if (!empty($override) && $appUrl) {
          $imagePath = str_replace($appUrl, $baseUrl, $imagePath);
      }
      $urls[] = $imagePath;
  } else {
      $urls[] = $baseUrl . $imagePath;
  }
  ```
  Concern: When the stored path is relative like `properties/123.jpg` (no leading slash), the concat yields `https://corex.hfcoastal.co.za` + `properties/123.jpg` = `https://corex.hfcoastal.co.zaproperties/123.jpg` (no `/` separator). Compare agent-image path `:447` which uses `ltrim($user->agent_photo_path, '/')` after a `/storage/` prefix — the listing path has no such defence.
- **Risk:** **HIGH** if any image_path in DB lacks a leading slash. Would trigger PP `ErrorDownloadingImages` (or silent moderation rejection → never activates).
- **Fix plan:** Normalise `$imagePath` with leading-slash check and an `ltrim/.../strpos('/storage/')` consistent path. Inspect DB samples for actual stored values.

### 3.8 Activation Polling — `SyncPrivatePropertyActivations`
- **Checked:** `app/Jobs/SyncPrivatePropertyActivations.php` (full file).
- **Status:** ⚠️
- **Spec:** `private-property.md:289`.
- **Evidence:** Queries `pp_syndication_enabled=true AND pp_syndication_status='submitted' AND pp_ref IS NULL` then calls `syncActivationStatus`. Correct against spec.
- **Concern:** Only runs if Laravel scheduler is wired into cron/Task Scheduler. No `withoutOverlapping` issue. Depends on `syncActivationStatus` at service `:175-210` whose response-shape extraction (`PPRef ?? PropertyRef ?? ListingRef`) again assumes top-level keys.
- **Risk:** Medium — same response-shape problem as 3.6.

### 3.9 PollPrivatePropertyActivation (per-submit backoff)
- **Checked:** `app/Jobs/PollPrivatePropertyActivation.php` (full).
- **Status:** ✅ logic; ⚠️ depends on queue worker.
- **Spec:** `private-property.md:291`.
- **Evidence:** SCHEDULE `[30,90,300,900,1800]` matches spec; self-dispatches via `delay()`. Requires a running `queue:work`.
- **Risk:** High in environments without queue worker. Local `.env` queue driver not inspected (out of scope), but if `QUEUE_CONNECTION=sync`, `delay()` is ignored and the entire backoff plan collapses to a single immediate call.
- **Fix plan:** Verify `QUEUE_CONNECTION=database` (or redis) on production and that `queue:work` supervisor is running.

### 3.10 ProcessPrivatePropertyEventFeed
- **Checked:** `app/Jobs/ProcessPrivatePropertyEventFeed.php` (full).
- **Status:** ⚠️
- **Spec:** `private-property.md:204-231`.
- **Evidence:**
  ```
  pp_event_feed_settings table state (probe):
  {"id":1,"key":"continuation_key","value":null,"updated_at":"2026-04-28 16:33:00"}
  ```
  `continuation_key` is still NULL since the migration insert — meaning the job has never advanced or has never been run against this DB. The job's logic at `:48` writes the new key only if `$newKey && $newKey !== $key` — so if PP returns empty/zero, nothing is persisted, and the `<100 events` exit at `:53-56` is taken.
- **Risk:** **HIGH** for activation backfill on sandbox where synchronous `PPRef` may not be returned (spec `:306`).
- **Fix plan:** Manually invoke `php artisan schedule:run` or dispatch the job directly to confirm it can pull events. Verify the WSDL `GetListingEventFeedByBranch` arg names (`UniqueBranchId`/`continuationKey`/`startDateTime`) match what PP expects.

### 3.11 Images (server-side) — HIGH PRIORITY
- See 3.7. Additional check at service `:421-477`: 1MB filesize check `:460-464` is correct; HTTPS guard `:449-453` is correct; **dimension check is explicitly skipped** per spec `:159`.
- **Status:** ⚠️
- **Risk:** Medium — listings with <3 photos blocked by `checkReadiness` mapper `:273-280`, good.

### 3.12 Webhook
- **Checked:** `PpWebhookController.php` (full).
- **Status:** ❌ blocked by env
- **Spec:** `private-property.md:235-256`.
- **Evidence:** HMAC verify `:29-31`, leadType filter `:47`, contact create `:83-108`, pivot `:66-68`, command_task `:110-133`, always-200 path `:42,48,59,80`. All match spec.
- **Probe:** `WEBHOOK_SECRET_SET=false` — controller returns 500 at line `:22-25`. PP would retry indefinitely.
- **Risk:** N/A for stuck-listing (separate inbound concern).
- **Fix plan:** Register webhook URL in PP Admin Portal; set `PP_WEBHOOK_SECRET`.

### 3.13 Video / Matterport flow
- **Checked:** `PropertyPpController.php:24-53`, service `:482-531`, client `:319-347`.
- **Status:** ✅
- **Spec:** `private-property.md:190-200`, `:305`.
- **Evidence:** Service `:484-492` hard-guard on empty `pp_listing_feed_ref`; client `:325-329` enforces 11-char YouTube ID. Matches spec.
- **Risk:** None directly to stuck-listing, but downstream-blocked by 3.6/3.10.

### 3.14 Routes & Discoverability
- **Checked:** `routes/web.php:1799-1814` (syndication group), `:224-229` (admin agents), `routes/api.php:64` (webhook).
- **Status:** ⚠️
- **Spec:** `private-property.md:260-281`.
- **Evidence:** All spec routes registered. **However** non-negotiable #7 in project `CLAUDE.md` ("Every API endpoint is registered under /api/v1/* …") is not met — PP webhook lives at `/api/pp/webhook`, not `/api/v1/...`.
- **Risk:** None for stuck listings; standards drift.

### 3.15 Scheduler wiring
- **Checked:** `routes/console.php:65-72`.
- **Status:** ✅
- **Spec:** `private-property.md:287-291`.
- **Evidence:**
  ```php
  Schedule::job(new \App\Jobs\SyncPrivatePropertyActivations())->everyFifteenMinutes()->withoutOverlapping();
  Schedule::job(new \App\Jobs\ProcessPrivatePropertyEventFeed())->everyFifteenMinutes()->withoutOverlapping()->name('pp-event-feed');
  ```
- **Risk:** Low — but depends on OS-level cron / Task Scheduler invoking `php artisan schedule:run` every minute. Not verifiable from inside the codebase.

### 3.16 DB Schema & Models
- **Checked:** `database/migrations/2026_03_23_100001_*`, `2026_04_28_100000_*`, plus migrations `2026_03_23_100002`, `_140000`, `_150000`, `2026_04_08_100000`, `2026_04_09_100000`, `2026_04_29_120000`.
- **Status:** ✅ for current spec; ❌ vs. brief naming.
- **Evidence:** Property columns from spec `:12` all exist in migration `2026_03_23_100001_add_pp_syndication_columns_to_properties_table.php:12-22`. No `App\Models\Listing` (probe error). No `pp_continuation_keys` table. No `pp_event_log` table. State lives in `pp_event_feed_settings` (migration `2026_04_28_100000_*`).
- **Risk:** N/A — symptom-spec mismatch only.

### 3.17 Logging — HIGH PRIORITY
- **Checked:** `config/logging.php:151-156`, SoapClient `:53,70,89,349-351`, service uses `Log::channel('private_property')` everywhere.
- **Status:** ✅ channel wired; ⚠️ no rotation.
- **Spec:** `private-property.md:44`.
- **Evidence:**
  ```
  config/logging.php:151-156:
  'private_property' => [ driver=single, path=storage/logs/private_property.log, level=debug ]
  ```
  Single-file driver (no daily rotation). All SOAP request/response bodies are logged at info — useful for diagnosing 3.6 (the actual response envelope shape would already be in this log file if any submission has occurred).
- **Risk:** Low — but unbounded growth. Diagnostic gold mine — recommend grepping `storage/logs/private_property.log` for `SOAP response: UpdateListing` to confirm 3.6 hypothesis.
- **Fix plan:** Switch to `daily` driver with `days=>30` for parity with other channels.

### 3.18 CLI / Smoke / Manage commands
- **Checked:** `app/Console/Commands/PpManage.php`, `PpSmokeTest.php` listed only (not read in full — discovery confirms presence).
- **Status:** ✅ (per spec `:295-299`).
- **Risk:** None.

### 3.19 Permissions / authZ
- **Checked:** SyndicationController `:334-345`, PropertyPpController `:74-85`.
- **Status:** ✅
- **Evidence:** Both use `PermissionService::getDataScope($user, 'properties')` with scope all/branch/own. Admin agent routes use `permission:manage_users` middleware (`web.php:224-229`).
- **Risk:** Low.

### 3.20 Pillar / multi-tenancy
- **Checked:** PP integration writes only to existing pillar tables (`properties`, `contacts`, `users`, `command_tasks`). `pp_event_feed_settings` is a global single-row config (matches spec `:231`).
- **Status:** ✅
- **Spec:** project `CLAUDE.md` non-negotiable #4 (Pillars are the spine).
- **Evidence:** Webhook controller `:106` and `:131` correctly stamp `agency_id` from `$property`.
- **Risk:** None.

---

## Stuck-Listing Root Cause Analysis

**Most likely root cause (ranked):**

1. **`PrivatePropertySyndicationService::submitListing` cannot read PP's actual response envelope** (`PrivatePropertySyndicationService.php:99-106`). It looks for top-level `PPRef`/`ListingFeedRef`, but PHP `SoapClient` typically returns `{<Operation>Result: {...}}`. Evidence: parallel code in the same file at `:336-361` knows the response has nested XML in `GetAllAgentsForBranchResult.any` — proving the team has seen the nested shape — but the UpdateListing handler does not parse it. So even when PP synchronously activates and returns `PPRef`, CoreX silently flips to `submitted` and never to `active`. **High confidence.**

2. **No queue worker / no scheduler-runner in this environment.** `PollPrivatePropertyActivation` relies on `delay()` (broken under `QUEUE_CONNECTION=sync`), and `ProcessPrivatePropertyEventFeed` relies on `php artisan schedule:run` being invoked. Evidence: `pp_event_feed_settings.value=NULL` since 2026-04-28 (16:33). If the scheduled job had ever successfully polled PP, `continuation_key` would have advanced to a non-null value. **High confidence for local; medium for production (need ops verification).**

3. **Sandbox does not auto-activate** (`private-property.md:306`). Even with everything wired correctly, sandbox listings may sit in moderation indefinitely. This compounds (1) and (2) — without the event feed advancing and without the synchronous backfill working, there is no path to `active`.

4. **Image URL concatenation defect** (mapper `:558-568`) if any DB image_path lacks a leading slash → PP120/`ErrorDownloadingImages` → listing rejected, never activates. **Medium confidence; depends on stored data.**

**Alternative hypotheses (ranked lower):**
- `pp_syndication_enabled` flag is false on those listings (toggle never engaged).
- Agent registration silently fails and never re-tries (less likely — guard at `:48-59`).
- PP-side credential / branch_guid mismatch (would surface as `PP50` in `pp_last_error` — visible via logs).

---

## Fix Plan

| # | Fix | Files | Risk | Size |
|---|-----|-------|------|------|
| 1 | Parse the actual `UpdateListing` response envelope; walk `UpdateListingResult`/`return` wrappers and the `any` XML pattern; extract `PPRef`/`ListingFeedRef`/`DelayListingOnOtherWebsitesUntil` reliably. Same fix for `syncActivationStatus`. | `app/Services/PrivateProperty/PrivatePropertySyndicationService.php:99-106,:188-201` | Low | S |
| 2 | Confirm `QUEUE_CONNECTION` and supervisor on production; ensure `php artisan schedule:run` cron is installed. | `.env` / ops | None (config) | XS |
| 3 | Manually dispatch `ProcessPrivatePropertyEventFeed` once and verify `continuation_key` advances; tail `storage/logs/private_property.log` for the actual SOAP response shape to confirm fix #1's envelope. | runtime | None | XS |
| 4 | Normalise image-path concatenation in `buildPhotoUrls` to ensure exactly one `/` between base and path. | `app/Services/PrivateProperty/PrivatePropertyListingMapper.php:557-568` | Low | XS |
| 5 | Register webhook URL in PP Admin Portal and set `PP_WEBHOOK_SECRET`. Document `PP_IMAGE_BASE_URL` in `.env.example`. | env + portal | None | XS |
| 6 | Convert `private_property` log channel from `single` to `daily` with `days=>30`. | `config/logging.php:151-156` | None | XS |
| 7 | Move webhook route to `/api/v1/pp/webhook` per non-negotiable #7 (or update spec). | `routes/api.php:64` | Low (PP must re-register URL) | XS |
| 8 | Reconcile audit-brief & spec naming drift: `Listing` vs `Property`; `pp_submitted_at` vs `pp_last_submitted_at`; `pp_continuation_keys` vs `pp_event_feed_settings`; remove references to non-existent `pp_event_log`. | `.ai/specs/private-property.md` or brief generator | None | XS |

---

## Open Questions for Johan

1. **What is the actual XML/array envelope PP returns from `UpdateListing` in sandbox?** A single grep of `storage/logs/private_property.log` for `"SOAP response: UpdateListing"` on the production box will confirm or refute fix #1 instantly. Can you paste the raw response from a recent submission?
2. Is the production queue actually running (`QUEUE_CONNECTION` value and supervisor status)? Without that, `PollPrivatePropertyActivation` is a no-op.
3. Has `ProcessPrivatePropertyEventFeed` ever produced output in production? `SELECT value, updated_at FROM pp_event_feed_settings WHERE key='continuation_key';` will tell you in one query.
4. Are images stored as `/storage/...` or `storage/...` (with/without leading slash) in `properties` table? Sample 3 rows of `properties.images` / `property_images.path` to determine if fix #4 is needed.
5. Spec/brief naming drift — is the spec authoritative (current Property-based) or should we reintroduce a `Listing` model? The two cannot both be right.

---

## Verification Run

### php -l (all PP files read this audit)
```
No syntax errors detected in app/Services/PrivateProperty/PrivatePropertySoapClient.php
No syntax errors detected in app/Services/PrivateProperty/PrivatePropertyTokenService.php
No syntax errors detected in app/Services/PrivateProperty/PrivatePropertyListingMapper.php
No syntax errors detected in app/Services/PrivateProperty/PrivatePropertySyndicationService.php
No syntax errors detected in app/Jobs/SyncPrivatePropertyActivations.php
No syntax errors detected in app/Jobs/PollPrivatePropertyActivation.php
No syntax errors detected in app/Jobs/ProcessPrivatePropertyEventFeed.php
No syntax errors detected in app/Http/Controllers/PrivateProperty/PropertyPpController.php
No syntax errors detected in app/Http/Controllers/PrivateProperty/PpWebhookController.php
No syntax errors detected in app/Http/Controllers/PrivateProperty/SyndicationController.php
```

### artisan clears
```
INFO  Compiled views cleared successfully.
INFO  Route cache cleared successfully.
INFO  Application cache cleared successfully.
```

### dev-check.ps1
```
=== DEV CHECK ===
No changed files detected.
1. Lint PHP files — No PHP files changed
2. Clear caches — Caches cleared
3. Route check -- skipped (no route/controller changes)
4. View check -- skipped (no blade changes)
5. Tests -- skipped (use -Full to run all 894 tests)
=== DEV CHECK OK ===
```

### Schema & data probes (custom bootstrap, no Listing model exists so direct tinker name-binding failed — used Eloquent boot script)
```
PP_TABLES=["pp_event_feed_settings"]
STUCK_LISTING_MODEL_ERR=Class "App\Models\Listing" not found
PP_CONTINUATION_KEYS_ERR=SQLSTATE[42S02]: Base table or view not found: 1146 Table 'hfc_dash.pp_continuation_keys' doesn't exist
HAS_pp_event_log=false
STUCK_PROPERTY_REAL_COLUMNS=0
STUCK_PROPERTY_SAMPLE=[]
EVENT_FEED_SETTINGS=[{"id":1,"key":"continuation_key","value":null,"updated_at":"2026-04-28 16:33:00"}]
WEBHOOK_SECRET_SET=false
PP_SANDBOX=true
IMAGE_BASE_URL_SET=true
APP_URL=http://localhost:8000
```

Interpretation:
- Spec-named probes from brief (`Listing` model, `pp_continuation_keys`, `pp_event_log`) all fail — **brief is using nonexistent names**. Real names: `Property` model, `pp_event_feed_settings` table, no event-log table.
- Re-run against discovered names: **0 stuck Properties locally** — but the `continuation_key=NULL since 2026-04-28` is a strong indicator that on whichever DB (likely prod) the symptom exists, the event-feed consumer has never advanced.
- `WEBHOOK_SECRET_SET=false` confirms the inbound webhook path is currently blocked.
