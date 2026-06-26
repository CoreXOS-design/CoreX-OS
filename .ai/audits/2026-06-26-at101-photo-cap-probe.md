# AT-101 — P24 photo cap probe result (150 photos on base64)

**Date:** 2026-06-26
**Branch:** AT-101-p24-photo-cap (base infra = commit 5b49c3e9)
**Goal:** Raise HFC's P24 photo cap toward 150 and PROVE whether the base64 transport survives it.

## 1. Defaults (single source of truth)

- **Migration default:** `database/migrations/2026_06_26_160000_add_p24_photo_cap_and_timeout_settings.php:23`
  → `p24_max_photos … ->default(30)` (and `:24` `p24_http_read_timeout ->default(120)`).
- **Agency model constant:** `app/Models/Agency.php:52` `P24_DEFAULT_MAX_PHOTOS = 30`,
  `:53` `P24_DEFAULT_HTTP_READ_TIMEOUT = 120`. Accessors `p24MaxPhotos()` / `p24HttpReadTimeout()`.
- **Job timeout derivation:** `app/Jobs/SubmitListingToProperty24.php:31` → `$this->timeout = $readTimeout + 60`.
  The HTTP read timeout is applied in `Property24ApiClient::request()` (`->timeout($this->readTimeout)`),
  and `round_trip_ms` is measured around the P24 POST there, logged via `logToDb()`.

The global migration default was **NOT** changed (recommend-only — see §4).

## 2. Probe setup

- **Where:** corex_dev DB via an isolated AT-101 git worktree, submitting to the P24 **TEST** portal
  (agency `p24_agency_id=31357`, label "Home Finders Coastal — HFC1 (test)"). No live listing touched.
- **Why seeded:** the mapper reads images from the **local** `Storage::disk('public')`
  (`Property24ListingMapper.php:435-447`, `urlToDiskPath()`), not over HTTP. corex_dev had no property
  over 30 photos, so a real 166-image set (from staging clone prop #1433, public URLs all HTTP 200)
  was downloaded locally and seeded onto corex_dev prop #19 (restored to its original 30 afterwards).
- **Config:** agency 1 `p24_max_photos = 150`, `p24_http_read_timeout = 600` (deliberately generous so
  the probe itself could not be the limiter — there was no `round_trip_ms` history to size from; all 9
  prior log rows predate that brand-new column).

## 3. Probe result (real submit, 2026-06-26)

| Metric | Value |
|---|---|
| Photos sent (capped from 166) | **150** |
| HTTP status | **200** |
| `round_trip_ms` (pure P24 POST) | **21,709 ms** |
| Wall-clock total (local image read + base64 + POST) | 26,956 ms (≈5.2 s read/encode + 21.7 s POST) |
| P24 response | `{"reasons": [], "isOnPortal": true, "listingNumber": 100314593}` |
| Photos landed | All 150 — empty `reasons[]` (no rejections), `isOnPortal: true`, listing number issued |

Payload ≈ 150 × ~230 KB raw ≈ 35 MB → ~46 MB base64 in one POST.

## 4. Math + VERDICT

- 150 photos → 21,709 ms ⇒ **144.7 ms/photo** (gross, incl. fixed P24 overhead).
- Default read timeout **120 s** ⇒ **5.5× headroom** at 150 photos. Probe's 600 s ⇒ 27.6× (never near).
- Theoretical base64 ceiling at the 120 s read timeout ≈ **829 photos** (gross) — so 150 is nowhere
  near the transport limit; the practical cap will be P24's own per-listing photo maximum, not our base64 transport.

**VERDICT: 150 photos is SAFE on base64.** Round trip (21.7 s) sits 5.5× under the existing 120 s read
timeout; P24 accepted all 150 with no rejections. **No timeout increase is needed for 150** — the default
120 s read / 180 s job already covers it amply.

### Recommendations (Johan decides — not applied)
1. **Global default → 150:** safe to raise `P24_DEFAULT_MAX_PHOTOS` (and the migration default) from 30 to 150.
2. **read_timeout:** keep at the default **120 s** (job 180 s) even at 150 photos — proven sufficient.
3. **Going beyond ~150:** base64 transport has plenty of headroom, but the real gate above this is P24's
   per-listing photo maximum — confirm with P24 before raising the cap much higher (URL-transport not required at 150).

### Caveat
Single sample against the P24 **test** portal. Payload size + our upload bandwidth are identical to prod;
P24 prod processing could differ in magnitude but the 5.5× headroom absorbs wide variance. Live rollout
(set live agency 1 → 150) requires deploying AT-101 to live + running the migration there — a separate step.
