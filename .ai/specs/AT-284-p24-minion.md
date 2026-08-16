# AT-284 — Automated P24 area imports (Chrome minion)

**Status:** built on branch `AT-284` (qa1 rig only). Schedule ships DISABLED. Reporter/assignee: Johan.

## Doctrine (Johan's rulings, 18 Jul)
- **No-auth public capture.** No Property24 account, no login, no P24 arrangement needed. The minion browses P24's PUBLIC for-sale search-results pages (the same pages an agent browses) and ingests certain result data — not complete property records. The only auth in play is the capture → CoreX **ingest POST** (the extension's existing service token to our OWN `/portal-captures/ingest`).
- **Polite pacing, not evasion.** Randomised human-scale gaps between page loads are politeness/rate-limiting. Build rule: if P24 serves a block/challenge the minion STOPS and alerts — it never solves/bypasses (no CAPTCHA, no anti-bot bypass, no fingerprint spoofing).
- **Reuse the existing pipeline.** All ingest goes through the existing `PortalCaptureController::ingest` → `Property24SearchExtractorV1` → `PortalListingTrackingService` (dedup) → `TrackedPropertyMatchOrCreateService::matchOrCreate` (cross-portal match, agency stamp, `tracked_property_external_refs(agency_id,source_type,source_ref)` per-agency dedup). Deactivation = existing `last_seen_at` staleness path. MIC picks up via the existing nightly recompute. **No parallel ingester.**

## Control surface — the SETUP PAGE (`/admin/settings/minion`, `permission:access_settings`, nav "P24 Auto-Import")
- **Capture universe:** a lazy **Province → Region → Town → Suburb** tick tree built from `p24_provinces → p24_cities → p24_suburbs` (26k suburbs, loaded per-node on demand). Tick a town = all its suburbs; suburb-level ticks for partial towns (that is how "sections of Durban" works). Ticks persist incrementally (`areas.toggle`) — no bulk-save data loss. Stored in `minion_capture_areas (agency_id, p24_suburb_id)` (soft-delete).
- **Cadence settings** (all agency-configurable, sensible defaults in `config/minion_capture.php`): suburbs-per-night, cycle-days (weekly target), run-at, run-days, polite-gap min/max, failure alerts, and the **nightly schedule master switch**. Stored in `minion_capture_settings`.
- **Run-log** (`minion_capture_runs`, soft-delete): per session — area, status, captured/new/updated/deactivated, failures(+detail), started, duration.
- **Run now:** per-town button → `MinionRunAreaJob` (queue) → runner.

## Runner
- `resources/minion/p24-capture.cjs` — Puppeteer over system Chromium (`/usr/bin/chromium`, `--no-sandbox --disable-gpu`). PURE public-page fetcher: navigate → grab rendered HTML → write to temp. No login, no POST, block-detection→stop.
- `App\Services\Minion\MinionCaptureRunner` — build URL (`P24SearchUrlBuilder`, `/for-sale/{suburb}/{city}/{province}/{p24id}`), run node, POST the HTML to the existing ingest with the service bearer token, map `tracking{new,updated}` + `extraction.items_on_page` into the run-log, stamp `last_captured_at`. Polite `sleep()` between suburbs.
- `minion:capture {--suburb=|--town=|--cycle} {--agency=} {--by=}` — manual + scheduler entry (`routes/console.php`, `dailyAt(run_at)`, gated `->when(any agency enabled)` + `withoutOverlapping`+`onOneServer`). **Disabled by default** (no agency has `enabled=true`).

## Ops prerequisites (set before the proving run / enable)
1. `.env`: `MINION_INGEST_URL=https://qatesting1.corexos.co.za/portal-captures/ingest`, `MINION_INGEST_TOKEN=<an HFC service user's api_token>` (stamps HFC via that user, exactly like the extension). Optional `MINION_CHROMIUM=/usr/bin/chromium`.
2. Migrations run on deploy (3 tables). Queue worker running for "run now".
3. Confirm the alert-recipient permission slug (`access_settings` assumed) at finalization.

## Gates (standing)
- **Schedule enable = Johan's explicit word.** Ships disabled; the master switch exists on the page but is OFF.
- Nothing to Staging (Johan's gate). qa1 only.

## Proving run (post-deploy)
Seed ticked set = Margate only → "run now" → verify captured/new/deactivated counts + sample ingested listings + a run-log row, all through the existing pipeline (dedup honoured, HFC-stamped).

## Rollback
Drop 3 migrations; remove `config/minion_capture.php`, `app/{Services,Support}/Minion`, `app/Console/Commands/MinionCapture.php`, `app/Jobs/MinionRunAreaJob.php`, `app/Notifications/MinionCaptureFailedNotification.php`, `app/Http/Controllers/Admin/MinionCaptureController.php`, `app/Models/MinionCapture*`, `resources/minion`, `resources/views/admin/minion`; revert the appended blocks in `routes/web.php`, `routes/console.php`, and the one nav line in `corex-sidebar.blade.php`.
