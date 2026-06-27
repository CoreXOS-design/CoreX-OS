# Rental Images — Inspection galleries for rental properties

**Status:** Spec
**Date:** 2026-06-24
**Author:** Claude + Andre
**Pillar:** Property (`Property`) — reads from and writes back to the property record.

---

## 1. Goal

Rental properties carry obligations that sale listings do not: a landlord/tenant **in-inspection**
(move-in condition record) and **out-inspection** (move-out condition record), plus assorted ad-hoc
photo evidence captured during a tenancy (handover snags, damage, garden/pool condition, etc.).
CoreX has only a single marketing **Gallery** tab on a property — there is nowhere to keep
inspection evidence, separated by event and stamped with the date it was taken.

This adds a **Rental Images** tab, visible only when `Property.listing_type === 'rental'`, holding:

1. **In Inspection** — a date + image gallery.
2. **Out Inspection** — a date + image gallery.
3. **+ Add section** — a reusable control that creates further named, dated galleries on demand
   (e.g. "Garden handover", "Damage — kitchen"). Unlimited.

Every section is a collapsible card that **stays collapsed until the user opens it**.

---

## 2. Pillar connection

- **Property** — the new data is stored on the `Property` record (`rental_images_json` column).
  No new pillar, no new island; this is property-scoped evidence read and written through the
  existing property show/edit page. Tenancy/lease context already lives on the Property
  (`lease_start_date`, `lease_end_date`, `rental_amount`).

---

## 3. Data model

One new nullable JSON column on `properties`, mirroring the existing `*_images_json` /
`gallery_categories_json` convention. No new table, no new model — inspection photos are property
attachments, not first-class entities.

```jsonc
// properties.rental_images_json
{
  "in_inspection":  { "date": "2026-06-24", "images": ["/storage/properties/12/ri_a.jpg"] },
  "out_inspection": { "date": null, "images": [] },
  "custom": [
    { "id": "g7x2c1", "name": "Garden handover", "date": "2026-06-30", "images": ["/storage/..."] }
  ]
}
```

- `date` is a nullable `Y-m-d` string (one date per section — confirmed decision).
- `images` is a flat array of public storage URLs, identical in shape to `gallery_images_json`.
- Custom sections carry a server-minted short `id` (`Str::random(6)`) used as the stable handle
  for upload / delete / rename; clients never mint ids.
- Image files are stored exactly like gallery images via
  `PropertyController::storeImages()` → `storage/app/public/properties/{id}/`, downscaled to
  2560px / JPEG 85.

`Property::rentalImagesStructure()` returns the normalised default shape (empty in/out/custom) when
the column is null, so the controller and view never deal with missing keys.

---

## 4. UI placement & navigation

- A new tab **Rental Images** is appended to the property show-page tab bar
  (`resources/views/corex/properties/show.blade.php`), guarded so it renders **only** when
  `!$isNew && strtolower($property->listing_type) === 'rental'` — same guard style as the
  conditional `core-matches` tab. This satisfies non-negotiable #2 (every page/area gets its
  navigation entry the same day): the tab *is* the navigation entry.
- The tab panel renders In Inspection, Out Inspection, then each custom section as collapsible
  cards reusing the existing `prop-section-toggle` / `x-collapse` accordion pattern. All start
  collapsed.

---

## 5. User flow

1. Agent opens a rental property → **Rental Images** tab is present (absent on sale properties).
2. Agent expands **In Inspection** → sets the inspection date, uploads photos (a **progress bar**
   tracks the upload). Repeats for **Out Inspection**.
3. Agent clicks **+ Add section** (a CoreX-styled modal, not a browser prompt), names it
   (e.g. "Damage — kitchen"), and the new collapsed section appears with its own date + gallery.
4. Within any section the agent can:
   - **Click a photo** to open a full-screen viewer (prev/next, keyboard arrows, Esc, Download).
   - **Download** a single photo (hover icon), **Download all**, or **Select** several and
     **Download selected**.
   - Upload more images or delete individual images.
5. All state persists to `rental_images_json`; dates and galleries survive reload.

---

## 6. Permissions

Inherits the existing `access_properties` permission and `agency.required` middleware via the
`corex.properties.` route group — confirmed decision, no new permission key. The controller calls
`authorizeProperty()` (the same guard used by `uploadImages` / `deleteImage`) so a user can only
touch properties within their agency scope.

---

## 7. Endpoints

All under the `corex.properties.` route group (inherit `permission:access_properties` +
`agency.required`):

| Method & URI | Name | Purpose |
|---|---|---|
| `POST /{property}/rental-images/upload` | `rental-images.upload` | multipart — append images to a section |
| `POST /{property}/rental-images/save`   | `rental-images.save`   | JSON — set section dates, add/rename custom sections |
| `POST /{property}/rental-images/delete` | `rental-images.delete` | JSON — delete one image from a section |

---

## 7a. Mobile API (added 2026-06-27)

The mobile app exposes the same three inspection galleries (In Inspection, Out
Inspection, unlimited custom sections) as the web — but with **two extra gates**:
the feature is **rental-only AND live-only**. It appears in the app **only once a
property is a rental listing that has been listed and made live**
(`listing_type === 'rental'` AND `Property::isOnMarket()` — i.e. status NOT in
`OFF_MARKET_STATUSES`: not draft/withdrawn/sold/expired/etc).

**Discovery flag.** `rental_inspections_available: bool` is exposed on BOTH
property payloads:
- `GET /api/v1/mobile/properties/{property}` — under `property`.
- `GET /api/v1/mobile/properties/{property}/overview` — at top level (overview
  has no `property` wrapper). This is the endpoint the detail screen reads.

Both come from the single source of truth `Property::rentalInspectionsAvailable()`
(rental AND `isOnMarket()`), computed server-side — the app trusts it blindly. The
app shows the Inspections entry when `true`, hides it otherwise. The dedicated
endpoints enforce the identical gate server-side, so a crafted request can never
reach a sale or off-market property (422 `not_a_rental` / `not_live`).

**Upload formats.** `upload` accepts `jpg, jpeg, png, webp, heic, heif`, max 50 MB
each (HEIC/HEIF are accepted explicitly — Laravel's `image` rule excludes them;
GD can't downscale HEIC so those are stored as-is). A stale `custom_id` returns
404 consistently across upload / save(rename) / delete. Section `date` is
normalised to `Y-m-d` (or null) on save.

**Controller:** `app/Http/Controllers/Api/MobileRentalImagesController.php`
(token-authenticated mirror of the web `PropertyController` methods). Image
storage is shared with the web via `app/Services/Images/PropertyImageStorer.php`
(store → `properties/{id}/`, downscale 2560px / JPEG 85). Every image URL the
mobile API returns is **absolutised** against `APP_URL`.

| Method & URI | Name | Purpose |
|---|---|---|
| `GET /api/v1/mobile/properties/{property}/rental-images` | `v1.mobile.properties.rental-images.index` | Fetch normalised structure (absolute URLs) |
| `POST /api/v1/mobile/properties/{property}/rental-images/upload` | `v1.mobile.properties.rental-images.upload` | multipart — append images to a section |
| `POST /api/v1/mobile/properties/{property}/rental-images/save` | `v1.mobile.properties.rental-images.save` | JSON — set date / add / rename custom section |
| `POST /api/v1/mobile/properties/{property}/rental-images/delete` | `v1.mobile.properties.rental-images.delete` | JSON — delete one image by index |

Every successful response returns the full state:
`{ property_id, listing_type, is_live, available, rental_images: { in_inspection, out_inspection, custom[] } }`
(upload additionally returns `uploaded: [absolute urls]`). Scope is enforced by
the shared `ResolvesMobileDataScope::authorizePropertyAccess()` trait gate
(own listing always; branch/agency per role scope; else 403). Cross-agency access
is a 404 (agency global scope). Tests: `tests/Feature/Api/MobileRentalImagesTest.php`.

---

## 8. Acceptance criteria

- Rental Images tab is **hidden** for `listing_type = 'sale'` and **shown** for `'rental'`.
- All sections render collapsed on load; expanding one does not expand the others.
- Uploading to In Inspection / Out Inspection / a custom section appends the images to the correct
  section and they persist across reload.
- A section date can be set and persists; it is independent per section.
- **+ Add section** creates a new named, dated, collapsed gallery with a server-minted id and can be
  used unlimited times.
- Deleting an image removes exactly that image (and its file from disk) and leaves the rest intact.
- A user outside the property's agency scope is rejected (403/redirect) on every endpoint.
- Files land under `storage/app/public/properties/{id}/`.

---

## 9. Files to create / modify

- **Create** `database/migrations/xxxx_add_rental_images_json_to_properties.php`
- **Modify** `app/Models/Property.php` — fillable + cast + `rentalImagesStructure()`
- **Modify** `app/Http/Controllers/CoreX/PropertyController.php` — `uploadRentalImages`,
  `saveRentalImagesMeta`, `deleteRentalImage`
- **Modify** `routes/web.php` — three routes in the properties group
- **Modify** `resources/views/corex/properties/show.blade.php` — tab + panel + Alpine component
  (`rentalImages()`), CoreX add/rename modal, and full-image viewer (lightbox)
- **Create** `resources/views/corex/properties/partials/rental-section-body.blade.php` — the
  shared per-section body (date, select/download toolbar, thumbnail grid, upload + progress bar)
- **Create** `tests/Feature/CoreX/RentalImagesTest.php`

> Note: `custom_id` is validated `nullable|string|required_if:...` on every endpoint — the global
> `ConvertEmptyStringsToNull` middleware turns the empty `custom_id` sent for the fixed in/out
> sections into `null`, which a bare `string` rule would reject (was the cause of the date-save 422).
- Re-run `php artisan schema:dump`, commit refreshed `database/schema/mysql-schema.sql` (NN #12a)

---

## 10. Out of scope

- Surfacing rental inspection images on the public website / portals / presentations — these are
  internal compliance records, not marketing photos.
- Reordering images within a section (can be added later with the existing reorder pattern).
- A formal inspection PDF report (future enhancement; the data model already supports it).
