# Mobile gallery upload — server-side persistence audit

**Date:** 2026-07-20
**Endpoint:** `POST /api/mobile/properties/{id}/images` (legacy) and `POST /api/v1/mobile/properties/{id}/images` (canonical) → `MobilePropertyController::uploadImage`
**Trigger:** mobile app rebuilt for reliable delivery (≤3 concurrent, 3× retry w/ backoff, 60s timeout, new `client_upload_id` idempotency key). Server audited to match so a 100-photo batch fully persists with no duplicates.

**Verdict:** the endpoint had **two defects that drop or duplicate rows under exactly the traffic the new client produces** — a concurrent lost-update race and no idempotency. Both fixed in this change, plus a swallowed-storage-failure hole. Areas 1, 3, 6, 7 pass as-is (with two documented caveats).

---

## 1. Endpoint & routing — PASS

- `POST /api/mobile/properties/{property}/images` exists (`routes/api.php:604`, name `legacy.mobile.properties.images.upload`) and `POST /api/v1/mobile/properties/{property}/images` (`routes/api.php:372`, `v1.mobile.properties.images.upload`). **Both dispatch the identical controller method** `MobilePropertyController::uploadImage` → identical validation, limits and behaviour. No divergence.
- Both sit under `Route::middleware('auth:sanctum')` (`api.php:264` for v1; the legacy `auth:sanctum` block for `/api/mobile`). Field name `image`; text fields `room_tag`, `client_upload_id`.
- ⚠️ The `/api/mobile/...` (no-`/v1`) group is explicitly marked **"LEGACY: remove after 2026-08-21"** (`api.php` legacy block). The client uses this path. **Action for the app team:** migrate to `/api/v1/mobile/...` before that date, or the deprecation window must be extended. Behaviourally identical today, so no rush beyond the date.

## 2. Idempotency — WAS BROKEN → FIXED

- **Before:** `client_upload_id` was neither validated nor used. A client retry (same key) stored a **second file** and appended a **second gallery row**. Guaranteed duplicates given the client's retry-on-timeout behaviour.
- **Fix:** `client_upload_id` is now accepted and persisted per-property in a new `properties.gallery_upload_keys` JSON map (`{client_upload_id => stored_url}`). On upload:
  - **Fast path:** if the key is already recorded, return the existing record immediately (HTTP 200, `duplicate:true`) — no disk write, no row.
  - **Race path:** the key is re-checked and set **inside the row-locked transaction** (below), so two retries arriving concurrently cannot both insert. The loser deletes its just-stored file + thumb and returns the canonical URL (2xx).
- A duplicate POST now yields exactly one row, one file, and never 500s.

## 3. Size / body limits — PASS (two caveats)

Effective on the live **php8.4-fpm** pool (`/etc/php/8.4/fpm/php.ini`) and `corexos.co.za` nginx vhost:

| Limit | Value | vs. client (~1–3 MB, headroom to 10 MB) |
|---|---|---|
| nginx `client_max_body_size` | **1000 MB** | ✅ never trips |
| PHP `upload_max_filesize` | **60 MB** | ✅ |
| PHP `post_max_size` | **60 MB** | ✅ (one file + text fields) |
| PHP `memory_limit` | 1024 MB | ✅ |
| endpoint validation `max:` | 51200 KB (50 MB) | ✅ |

- **413 behaviour:** nginx (1000 MB) won't fire; a body over PHP's 60 MB `post_max_size` is rejected by Laravel's `ValidatePostSize` → **413**, rendered as JSON **only if the request sends `Accept: application/json`** (the exception handler branches on `expectsJson()`, `bootstrap/app.php:127/154`). **Action for the app team:** always send `Accept: application/json` so 413/422/500 come back as JSON, not an HTML error page. Over 50 MB but under 60 MB returns a JSON **422** (validation) before the 413 threshold — also fine.
- ⚠️ **Caveat (not this endpoint):** PHP `max_file_uploads = 20`. The gallery endpoint sends **one** file per request, so it's unaffected. But the **sibling batch endpoint** `POST .../rental-images/upload` accepts `images[]` and would **silently cap at 20 files per request**. If the app ever batches >20 rental images in one POST, raise `max_file_uploads` or chunk client-side. Flagged for follow-up; out of scope here.

## 4. Concurrency & throttling — (a) PASS · (b) WAS BROKEN → FIXED · (c) PASS

- **(a) Throttling:** neither mobile route group carries a `throttle` middleware, and no nginx/WAF rate-limit applies to the vhost. A 3-concurrent burst / 100-photo batch is **not** rejected — no 429. (The client handles 429 if one ever appears, but none does.) ✅
- **(b) Race — the critical bug:** the gallery is a **JSON column** on `properties`, mutated by read-modify-write (`$gallery = $property->gallery_images_json; $gallery[] = $url; save()`). Three concurrent uploads to one property each load the array, append one URL, and save the whole thing → **last write wins, the others' URLs vanish**. This drops rows under precisely the client's ≤3-concurrent pattern and is a real contributor to "uploaded 30, only 14 landed." **Fix:** the mutation now runs inside `DB::transaction` after `Property::…->lockForUpdate()->firstOrFail()`, re-reading the JSON under the lock. Concurrent uploads to the **same** property serialise; different properties never contend.
- **(c) Per-property cap:** none in code — 100+ images persist fine. ✅

## 5. Persistence integrity — WAS WEAK → FIXED

- **Commit-before-2xx:** the image write (file + JSON row) is fully **synchronous** and committed before the response; only the downstream AI-vision analysis is queued (async), and that is not part of image persistence. ✅ (unchanged)
- **Hole 1 — lost update:** a committed 201 could be silently overwritten by a concurrent request (§4b). **Fixed** by the row lock.
- **Hole 2 — swallowed storage failure:** `$file->store()`'s return was **unchecked**. On a disk-write failure it returns `false`, and the old code proceeded to build a bogus URL and return **201** — the client then drops the photo from its retry queue → **permanent loss**. **Fix:** after `store()` we assert `is_string($path) && Storage::exists($path)`; otherwise return **500** so the client retries. Combined with §2 idempotency, the retry is safe (no duplicate).

## 6. Content-type / format — PASS

- Validation is `mimes:jpg,jpeg,png,webp,heic,heif`, which checks the **actual file content/extension**, not the multipart part's `Content-Type` header. So `image/jpeg`, `image/heic`, and an `application/octet-stream` fallback all pass provided the bytes are a real supported image. ✅
- HEIC: stored as-is; GD can't thumbnail it, so the thumbnailer falls back to the original — it never 500s (existing behaviour, commit `92bffddc`). Low-risk caveat: `mimes:heic` depends on the host `fileinfo` recognising HEIC; the app normally transcodes to JPEG anyway.

## 7. Orientation — PASS

- The client now bakes EXIF orientation into pixels and strips the flag. The server's `ImageOrientationNormalizer` (added earlier today) is a **no-op when there is no orientation tag**, so it does not touch an already-upright, flag-stripped image. The thumbnail/downscale re-encode with GD operates on upright pixels and **cannot re-introduce rotation** (GD writes no orientation flag). Upright in → upright out. ✅ If an older client sends a still-tagged image, the server corrects it. Robust both ways.

---

## Changes shipped in this audit

| File | Change |
|---|---|
| `database/migrations/2026_08_06_000001_add_gallery_upload_keys_to_properties_table.php` | New nullable JSON column `properties.gallery_upload_keys` (idempotency map) |
| `app/Models/Property.php` | `gallery_upload_keys` added to `$fillable` + `$casts` (array) |
| `app/Http/Controllers/Api/MobilePropertyController.php` | Accept/validate `client_upload_id`; fast-path + in-lock idempotency; `DB::transaction` + `lockForUpdate` around the gallery mutation; storage-failure → 500; shared `uploadedImageResponse()` helper (201 new / 200 duplicate) |
| `app/Services/Images/PropertyThumbnailService.php` | `deleteForUrl()` — clean up the thumb of a discarded duplicate |
| `tests/Feature/Api/MobileGalleryUploadTest.php` | +idempotent-retry-deduped, +two-distinct-uploads-persist |

## Acceptance test (must run on QA, not prod)

A load script POSTing 100 photos to one property at concurrency 3, forcing a duplicate retry on ~10%, must yield **exactly 100 distinct gallery rows, 0 duplicates, 0 5xx, all upright, every 2xx only post-commit**. The row lock (§4b) + idempotency (§2) + storage guard (§5) are what make this pass. This requires a live app + sanctum token against a QA clone (`qatesting1/2.corexos.co.za`) — it cannot run in PHPUnit (needs true parallel connections) and must not run against production data.

## Deploy notes

- Schema change → run `php artisan migrate --force` on **prod and demo**, then `php artisan schema:dump` + commit the refreshed `database/schema/mysql-schema.sql` (rule 12a), and reload **php8.4-fpm** (rule: the live pool is 8.4, not 8.3).
- No new GLOBAL reference rows → `deploy:sync-reference-data` not required for this change.
