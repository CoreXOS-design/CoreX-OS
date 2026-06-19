# Gallery Image Rotation

**Status:** Built (2026-06-19) — implementation note below supersedes the original "in-place" draft
**Date:** 2026-06-19
**Author:** Claude + Andre
**Origin:** Testing feedback — "In gallery a user needs to be able to rotate an image. When they click an image there should be a rotate option."

---

## 1. Goal

An agent viewing a property photo in the gallery lightbox can rotate it 90° (left/right) and the rotation is **persisted to the stored file** — so the corrected orientation shows everywhere the photo is consumed: the gallery, the public website, Property24/PrivateProperty syndication, presentations, and PDF/print exports.

A view-only CSS rotation that is lost on reload is explicitly **rejected** — per the CoreX Operating Principle (no "good enough for now"). Photos uploaded sideways from a phone must be fixable once, permanently.

## 2. Pillar connection

- **Property** — reads/writes `gallery_images_json` (and the time-of-day variants) on the `Property` model. The rotated file is the same asset every downstream consumer already reads, so the fix propagates with zero extra wiring. Integration is the moat.

## 3. Why server-side, new filename (BUILT decision)

| Option | Verdict |
|--------|---------|
| CSS `transform` in lightbox only | ❌ Not persisted; lost on reload; never reaches portals/exports. A screen trap. |
| Rotate the stored file **in place** | ❌ Same URL → every browser/CDN keeps serving the stale orientation. Render-time `?v=filemtime` busting collides with the URL-as-key tag map, so it's fragile across the gallery/portal/export surfaces. |
| **Rotate → write a NEW filename + remap the URL (chosen, built)** | ✅ A new URL is uncacheable-stale by construction — correct everywhere (gallery, public site, portals, presentations, exports) the instant it's saved. The URL is swapped across all image JSON fields in one server transaction, so the URL-keyed category/tag map stays valid. |

The original draft chose in-place; during build the URL-as-key coupling (`gallery_categories_json` keys images by URL) made in-place + cache-bust the fragile option. New-filename + a contained server-side remap is strictly more robust, so the build took that path.

`imagerotate()` (GD) is confirmed available **locally and on production** (already used by `app/Services/Images/AgentPhotoNormalizer.php`). No imagick dependency.

## 4. Data model / migrations

**None.** No new columns. Rotation mutates the file on the `public` disk in place. Cache invalidation is handled at render time (see §7), not by storing a version.

## 5. Backend

New service `app/Services/Images/PropertyImageRotator.php` (BUILT):
- `rotate(Property $property, string $imageUrl, int $degrees): string` — `$degrees ∈ {90, -90, 180}`.
- Resolves the storage path from the public URL, asserts the file belongs to this property's directory (`properties/{id}/…`) — reject path-traversal / cross-property / cross-agency / externally-hosted paths.
- Loads via GD, `imagerotate()` with the requested angle, re-encodes preserving format (JPEG q90, PNG/WebP alpha-preserved), writes a **new filename**, deletes the original.
- Returns the new public URL.
- Fails loudly (throws) if GD is unavailable so the controller returns a real error — never silently no-ops.

Endpoint (BUILT — **web route**, not `/api/v1`):
- `POST /corex/properties/{property}/rotate-image` → `name('corex.properties.rotate-image')`
- Body: `{ image_url, degrees }`. Returns `{ ok, url }`.
- **Why web, not /api/v1 (deviation from non-negotiable #7):** `bootstrap/app.php` deliberately removes `EnsureFrontendRequestsAreStateful` from the `api` group (mobile is bearer-token only), so `/api/v1/*` cannot authenticate a first-party browser **session** — a cookie POST there returns 401 "Unauthenticated". This is a browser-only, page-coupled mutation, so it lives with its siblings `upload-images` / `delete-image` / `reorder-images`, which are all `/corex/properties/...` web routes (session + CSRF). Flagged for Johan as a justified #7 exception.
- Authorize identically to the existing image endpoints: web group `permission:access_properties` + `agency.required` + `AuthorizesPropertyAccess` scope. Rotating a photo is editing the listing.

## 6. Frontend (lightbox)

In `resources/views/corex/properties/show.blade.php` lightbox (`#lightbox`, ~line 3824):
- Add two controls next to the close button: **Rotate left** (−90°) and **Rotate right** (+90°).
- On click: call the endpoint for the current image; on success, swap the returned new URL into the lightbox copy and the live gallery state (remapping the URL-keyed tag entry). Show the server's error message on failure.
- Disable the buttons while a rotation request is in flight (no double-fire).
- Wire into the existing `smartGallery()` Alpine component (~line 5941) so the thumbnail grid reflects the change without a full reload.

## 7. Cache invalidation (resolved by the new-filename approach)

No cache-busting needed: each rotation produces a brand-new URL, which no browser/CDN has cached. The frontend swaps the old URL → new URL in the lightbox copy and the live gallery state (remapping the URL-keyed tag entry); the server has already swapped it in the persisted JSON. Reloads, portals, presentations and exports all read the new URL directly.

## 8. Permissions

No new permission key — reuse the property image-edit permission already gating `uploadImages` / `deleteImage`. Sidebar/route/controller checks inherit from that gate.

## 9. Acceptance criteria

1. Clicking an image in the gallery opens the lightbox with visible Rotate-left / Rotate-right controls.
2. Rotating updates the displayed image immediately and the change survives a hard reload.
3. The rotated orientation appears on the public agency website listing for the same photo.
4. Per-image tags, categories, cover selection, and ordering are unchanged after rotation (URL-keyed metadata intact).
5. The endpoint rejects an `image_url` that does not resolve inside `properties/{property->id}/`.
6. Endpoint is a named web route (`corex.properties.rotate-image`) alongside the other image endpoints; authenticates via the browser session (see §5 for the #7 deviation rationale).
7. Unauthorized users (lacking the property-edit permission) get 403.
8. `scripts/dev-check.ps1` passes with 0 new failures; a feature test covers the endpoint (happy path + path-traversal rejection + permission denial).

## 10. Files to create / modify

- **Create:** `app/Services/Images/PropertyImageRotator.php`
- **Create:** `tests/Feature/Properties/RotateImageTest.php`
- **Modify:** `app/Http/Controllers/CoreX/PropertyController.php` (add `rotateImage` action; mirror `uploadImages`/`deleteImage` auth)
- **Modify:** `routes/web.php` — register `corex.properties.rotate-image` alongside upload/delete/reorder-images
- **Modify:** `resources/views/corex/properties/show.blade.php` (lightbox controls + `smartGallery` method + `?v=` cache-bust on gallery/lightbox srcs)

## 11. Open question

- Confirm angle set: just ±90° (cover the sideways-phone case), or also 180°? Default in this draft: offer left/right (±90°); 180° achievable by two clicks.
