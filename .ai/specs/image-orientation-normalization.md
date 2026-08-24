# Image Orientation Normalization (ingest-time)

**Status:** Built (2026-07-20, `ImageOrientationNormalizer`) — this spec formalizes it
retroactively and documents the 2026-08-03 fix for the orientation-0 gap, extended
same-day to a second confirmed device (HONOR).
**Date:** 2026-08-03
**Author:** Johan + Claude
**Origin:** Property 6118 (2026-07-20) — mobile-captured gallery photos landed sideways.
Property 6142 (2026-08-03) — Johan reported the same symptom recurring on the
mobile app despite the July fix; investigation found a specific device
(HUAWEI Mate X6) falling through a gap the original fix didn't cover. Hours
later, a second device (HONOR BRP-NX1) hit the same gap on a fresh upload to
the same property — caught immediately by this fix's own new logging, and
extended the same day using the same evidence-first process.

---

## 1. Goal

Every photo uploaded to a property gallery — from the mobile app or the web —
renders upright everywhere it is shown: the gallery, the public agency
website, Property24/PrivateProperty syndication, presentations, and PDF/print
exports. The fix must be applied once, at ingest, so no downstream surface or
client needs to understand EXIF orientation itself.

This is distinct from `.ai/specs/gallery-image-rotation.md` (the manual
rotate-left/rotate-right lightbox buttons an agent can click on an
already-stored photo). This spec covers the automatic, ingest-time correction
that should mean an agent never needs to reach for that button in the first
place.

## 2. Pillar connection

- **Property** — operates on files referenced by `gallery_images_json` (and
  the rental-inspection image variants) on the `Property` model, before any
  thumbnail/downscale pass runs. No schema changes.

## 3. The problem

Phone cameras capture in the sensor's native orientation and record an EXIF
`Orientation` tag telling viewers how to rotate the pixels for display. CoreX
re-encodes uploaded images with GD downstream (`PropertyThumbnailService`,
`PropertyImageStorer::downscale()`), and **GD drops the EXIF orientation tag
without rotating the pixels** — so a photo that relied on the tag renders
sideways everywhere the tag is lost (thumbnails, portal feeds, brochures).

## 4. Design (BUILT, 2026-07-20)

`app/Services/Images/ImageOrientationNormalizer::normalizeInPlace(string $absPath): bool`
absorbs the problem once, at ingest: it reads the EXIF `Orientation` tag,
rotates the GD pixel buffer to match, re-saves, and the tag is naturally gone
after a GD re-encode. Every downstream surface then shows the photo upright
regardless of whether it reads EXIF.

Wired into both ingest paths so mobile and web can never drift:
- `App\Http\Controllers\Api\MobilePropertyController::uploadImage` — mobile gallery upload.
- `App\Services\Images\PropertyImageStorer::store()` — web gallery upload + rental images
  (used by both `PropertyController::uploadImages` and `MobileRentalImagesController`).

Both call `normalizeInPlace()` **before** any thumbnail/downscale GD re-encode,
so every derived size is generated from already-upright pixels.

GD only (present locally and on production, unlike Imagick) — see
`AgentPhotoNormalizer.php` for the sibling pattern used on agent headshots.

## 5. The orientation-0 gap (2026-08-03 fix)

A valid EXIF `Orientation` tag is 1–8. The original implementation treated
**any value outside that range — including 0 and a fully absent tag — as
"nothing to do,"** the same bucket as a genuinely upright photo:

```php
$orientation = (int) ($exif['Orientation'] ?? 1);
if ($orientation < 2 || $orientation > 8) {
    return false; // treated 0 and "absent" identically to "upright" — wrong
}
```

That assumption was never re-verified after the July fix shipped — the
follow-up mobile-upload hardening audit
(`.ai/audits/2026-07-20-mobile-gallery-upload-persistence-audit.md`, §7) signed
off orientation as "PASS" on the assumption that a mobile client either sends
a correct 1–8 tag or has already baked rotation into the pixels itself. Neither
held for property 6142's photos (all uploaded 2026-08-03, i.e. **after** the
July fix was live): a HUAWEI Mate X6 (software `ICL-L29 15.0.0.209`) writes
`Orientation => 0` — not a valid EXIF value — while its pixel buffer is still
stored in the sensor's non-upright orientation. 33/33 photos in that property's
gallery from this device were confirmed sideways by direct visual inspection,
all needing the identical 90° CCW correction (the same transform normally
applied for EXIF value 8).

### 5.1 Fix

`normalizeInPlace()` now distinguishes three states instead of two:

1. **Orientation 1** — confirmed upright. No-op (unchanged).
2. **Orientation 2–8** — a known transform. Applied with certainty (unchanged).
3. **Absent or invalid** (0, out of range, non-numeric) — **unknown**, not
   "assumed upright." There is no metadata signal for a rotation direction in
   the general case, so this is not guessed at blindly. One narrow,
   evidence-backed exception applies: when the file's `Make` is `HUAWEI` **and**
   the canvas is portrait-shaped (`width < height` — a landscape scene could
   never legitimately fill a portrait canvas without rotation, so this shape is
   itself part of the defect's signature), the verified 90° CCW correction is
   applied. Every other absent/invalid combination is left untouched, exactly
   as before — but now logged (`Log::warning`, best-effort, guarded so it can
   never break normalization or the plain-PHPUnit unit tests that exercise this
   class without an application container) instead of silently disappearing,
   so the next unknown device/value shows up in production logs instead of
   shipping sideways for months before someone notices.

This is a narrow fix scoped to real, verified evidence — not a blanket "treat
any invalid value as needing rotation" rule, which would risk mis-rotating an
unrelated device/scenario we have no evidence about. The verified makes live
in `ImageOrientationNormalizer::HEURISTIC_MAKES` (currently `HUAWEI`, `HONOR`)
so extending the list is a one-line, evidence-gated change, not a rewrite.

### 5.2 Same-day extension: HONOR (confirmed, not speculative)

Within hours of the fix going live, a fresh upload to property 6142 from a
HONOR BRP-NX1 hit the exact same gap — this time with the Orientation tag
**entirely absent** rather than `0`. The new `Log::warning` this fix added
caught it immediately (visible in `storage/logs/laravel.log` within minutes),
which is exactly the detection mechanism §8 describes. Two of the two sampled
photos were visually confirmed sideways, needing the identical 90° CCW
correction. HUAWEI and HONOR share pre-2020-split camera-stack lineage, so
this is treated as the same verified defect class rather than a coincidence.
`HEURISTIC_MAKES` was extended to include `HONOR`, covering both the `0` and
absent-tag variants of the same underlying signature (the numeric-validity
check already treats both identically as "no actionable tag").

### 5.3 Retroactive repair

Property 6142's already-stored gallery photos (uploaded before each fix
landed — 33 for the HUAWEI pass, 2 more for the HONOR pass) were repaired by
re-running the fixed `normalizeInPlace()` against each file in place, then
regenerating their list-view thumbnails
(`PropertyThumbnailService::generateForProperty($property, force: true)`).
No schema/data change — file-level repair only, same pattern as
`properties:repair-gallery-references`.

### 5.4 The real fix belongs on the mobile side

This server-side heuristic is an ABSORB, not a PREVENT — it only fires for
device signatures already seen in production, after the fact. The root cause
is client-side: the OEM camera pipeline hands the app a JPEG whose pixels
don't match reality and whose metadata can't be trusted to say so. A
mobile-side fix (bake the correct rotation into the image before it is ever
uploaded, using a source of truth the OEM camera app can't corrupt) closes the
gap for every device, known or not — not just the ones logged so far. See
`.ai/prompts/mobile-image-orientation-fix.md` for the brief handed to the
mobile app team/session. Both fixes should stay in place together: the mobile
fix prevents the defect at the source; this one keeps absorbing whatever slips
through (a new device, an app version regression) so a sideways photo is never
the only line of defence away from reaching a listing.

## 6. Files touched

- **Modified:** `app/Services/Images/ImageOrientationNormalizer.php` — three-state
  orientation handling + HUAWEI orientation-0 heuristic + best-effort logging.
- **Modified:** `tests/Unit/Services/Images/ImageOrientationNormalizerTest.php` — added
  coverage for the HUAWEI orientation-0 heuristic (asserts actual rotation
  direction via a corner-marked fixture, not just "some" rotation) and for the
  unresolved/left-untouched case.
- **Created:** `tests/Fixtures/Images/huawei-orientation0.jpg`,
  `tests/Fixtures/Images/honor-no-orientation-tag.jpg`,
  `tests/Fixtures/Images/unknown-orientation-no-make.jpg`.
- **Created:** `.ai/prompts/mobile-image-orientation-fix.md` — brief for the
  mobile app team/session to fix the client-side root cause.
- **No migration, no route, no UI change** — this is a backend ingest-path fix.

## 7. Acceptance criteria

1. A JPEG with EXIF `Orientation` 2–8 is corrected exactly as before (regression-safe).
2. A JPEG with `Orientation => 0`, `Make => HUAWEI`, portrait canvas is rotated
   90° CCW and matches the verified device signature.
3. A JPEG with no usable orientation signal (no Make match, no valid tag) is
   left untouched — no blind guess — and a warning is logged.
4. Property 6142's existing gallery photos render upright after the retroactive
   repair.
5. `tests/Unit/Services/Images/ImageOrientationNormalizerTest.php` passes.

## 8. Known follow-up (not in this fix)

If another device/value combination is later confirmed sideways in production,
the new `Log::warning('Image orientation: EXIF orientation missing or
invalid...')` entries (searchable by `path`/`make`/`model`) are the intended
detection mechanism — extend §5.1's exception list with the same
evidence-first approach (visually confirm the direction across multiple real
samples before encoding a correction), rather than broadening the HUAWEI
gate speculatively.
