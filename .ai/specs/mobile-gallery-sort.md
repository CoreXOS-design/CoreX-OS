# Mobile Gallery Sort — Drag-Reorder Photos & Tags

> Status: built (backend) — mobile app side to be handed off via a MOBILE-PROMPT once Andre/Johan confirm the app UI.
> Raised by: Johan Reichel, 2026-09-09 (chat request: sort by tag, drag/reorder tags, drag/reorder images, delete images from the mobile app).
> Extends: `.ai/specs/mobile-gallery-tagging.md` (same controller, same JSON columns — this is additive, not a new module).

## 1. What this adds (the business requirement)

Johan asked for the mobile app to let an agent, from their phone:
- Sort/filter the gallery by tag (already possible — `GET .../gallery/tags` + `gallery_categories` on `GET .../{property}` already group photos by room/tag).
- **Drag to reorder the tags themselves** (new).
- **Drag to reorder photos** — both the master grid and within one tag's bucket (new).
- Delete images (already possible — `POST .../images/delete`, shipped under `.ai/specs/mobile-gallery-tagging.md`).

Investigation found the web gallery sorter (`resources/views/corex/properties/show.blade.php` →
`CoreX\PropertyController::reorderImages`) already does all of this, but mobile had no equivalent
write endpoint for photo order or tag order — only tag *assignment* (`gallery/assign`) and tag
*membership* (`gallery/tags` POST/DELETE) existed on mobile. This spec adds the two missing writes
as their own single-purpose mobile endpoints, matching the existing mobile pattern (one action per
endpoint) rather than the web's one "save everything" endpoint.

## 2. Pillars

**Property** only. Photos are JSON array columns on `properties`, not a separate model — no new
pillar linkage. Authorization is the existing per-property `authorizeProperty()` gate (mutation
scope: own/branch/all), same as every other mobile property-write endpoint.

## 3. Data model

No migration. Both endpoints write existing columns:
- `properties.gallery_images_json` — master ordered photo list (order = cover photo + portal order).
- `properties.gallery_categories_json` — `{categories: [{name, images: []}], unsorted: []}`; each
  category's `images` array has its own independent order.
- `properties.gallery_tag_order` — full ordered tag-name list, applied on read by
  `Property::applyGalleryTagOrder()`. Already existed (written only by the web sorter before this).

## 4. API

| Method | Path | Purpose |
|---|---|---|
| PUT | `/api/v1/mobile/properties/{property}/gallery/reorder` | Drag-reorder the master photo grid, or the photos inside one tag |
| PUT | `/api/v1/mobile/properties/{property}/gallery/tags/reorder` | Drag-reorder the tag list itself |
| POST | `/api/v1/mobile/properties/{property}/images/delete` | *(already shipped, unchanged)* delete photos |

### `gallery/reorder`

Request: `{ "images": ["<url>", …], "room_tag": "Kitchen" | null, "gallery_fingerprint": "<sha1>" (optional) }`

- `room_tag` omitted/null → reorders `gallery_images_json` (the master grid — this order decides
  the cover photo and the order sent to portals).
- `room_tag` given → reorders only that tag's `images` bucket in `gallery_categories_json`, leaving
  the master grid order untouched. `room_tag` is resolved through the same `canonicalGalleryTag()`
  every other tag-write endpoint uses — an unknown tag 422s.
- `images` is a **permutation** of the URLs already in that scope. This endpoint never adds or
  removes a photo — upload and `images/delete` own those. A submitted URL not currently in scope is
  dropped and reported in `unknown_images` rather than silently accepted. A URL that IS in scope but
  missing from the submission is never dropped — it's kept, appended at the end in its prior
  relative order, so a stale/partial client array can never delete a photo through this endpoint
  (non-negotiable #1 — no hard deletes — this endpoint categorically cannot cause one).
- `gallery_fingerprint` is optional, same staleness guard as the web sorter
  (`Property::galleryFingerprint()`): if sent and it doesn't match the current fingerprint, 409 with
  `{stale: true}` instead of silently reverting a concurrent change.

Response: `{ message, room_tag, unknown_images[], gallery_images, gallery_categories, gallery_fingerprint }`

### `gallery/tags/reorder`

Request: `{ "tags": ["Kitchen", "Lounge", …] }` — full or partial order.

- Every submitted name must case-insensitively match a tag from `getAvailableGalleryTags()`; any
  that doesn't → 422 with the invalid names listed, `available_tags` returned so the client can
  resync. This endpoint reorders tags, it does not create them (`POST gallery/tags` does that).
- A currently-available tag omitted from the list is **not** stranded — `applyGalleryTagOrder()`
  appends anything missing from `gallery_tag_order` at the end on every read, so a partial/stale
  list from the client can't drop a tag out of the picker.
- Writes `gallery_tag_order` directly (canonical casing, de-duped) — no lock/transaction needed,
  same as `addCustomTag`/`removeCustomTag`, which write the same class of small independent column.

Response: `{ message, available_tags }`

Both routes are inside the existing `mobile/properties` group (`routes/api.php`), so they inherit
Sanctum auth and `deny_assistant_property_write` automatically (non-negotiable #5 and #7 satisfied:
versioned `api/v1`, named, appears in Admin → API automatically, permission-gated).

## 5. Behaviour rules

- `gallery/reorder` takes the same `lockForUpdate()` row lock as `uploadImage()`/`assignGalleryTag()`
  — `gallery_images_json`/`gallery_categories_json` are JSON columns, so a concurrent read-modify-
  write is a lost-update hazard.
- Reordering is never destructive: neither endpoint can remove a photo or a tag from the property.
  Deletion stays exclusively `images/delete` (photos) and `DELETE gallery/tags` (custom tags).
- Tag/room resolution is shared with every other tag-write endpoint via `canonicalGalleryTag()` —
  one resolver, so a tag name accepted by `assign` is guaranteed to also be accepted by `reorder`.

## 6. Acceptance criteria

- [x] `PUT gallery/reorder` with no `room_tag` reorders `gallery_images_json` to match a submitted
      permutation; the returned `gallery_images` reflects the new order.
- [x] `PUT gallery/reorder` with `room_tag` reorders only that category's `images`, leaving
      `gallery_images_json` and every other category untouched.
- [x] A URL missing from the submitted array is kept (appended, not deleted); a URL in the
      submission that isn't currently in scope comes back in `unknown_images`, not silently dropped.
- [x] A stale `gallery_fingerprint` returns 409 `{stale: true}` instead of applying the reorder.
- [x] `PUT gallery/tags/reorder` persists `gallery_tag_order`; `GET gallery/tags` and
      `GET /{property}` reflect the new order afterwards via `getAvailableGalleryTags()`.
- [x] An unknown tag name in `gallery/tags/reorder` 422s and changes nothing.
- [x] Assistants are still blocked from every write in this group by the existing
      `deny_assistant_property_write` middleware (AT-267 parity — no separate check needed, the
      group-level gate already covers new routes added under it).
- [ ] Mobile app UI built against these endpoints (pending MOBILE-PROMPT handoff).

## 7. Files changed

- `app/Http/Controllers/Api/MobilePropertyController.php` — new `reorderImages()`,
  `reorderGalleryTags()`. Reuses `canonicalGalleryTag()`, `imageMatchKey()`, `absoluteImageUrls()`,
  `buildGalleryCategories()` — no new helpers needed.
- `routes/api.php` — the two new routes, inside the existing `mobile/properties` group.
- `tests/Feature/Api/MobileGallerySortTest.php` (new)

## 8. Deliberately NOT in this change

- **Existing hard-delete behaviour on `images/delete` was NOT touched.** Investigation for this spec
  found that photo deletion (both web `PropertyController::deleteImages` and mobile
  `MobilePropertyController::deleteImages`) removes the file from disk with no soft-delete/recovery
  mechanism — a literal-reading conflict with non-negotiable #1 ("no hard deletes... not for
  documents, deals, contacts, templates, users, or any other model"). This predates this change,
  is shared by both web and mobile, and fixing it (trash/restore UX, retention policy, storage
  cost of keeping deleted originals) is a materially different and larger piece of work than "add
  reorder endpoints" — reported here per non-negotiable #2 (report, don't drive-by fix) rather than
  silently addressed. **Needs Johan's explicit call**: should deleted property photos be
  recoverable, and if so for how long?
- No new UI on web — the web sorter already has drag/reorder for both images and tags; this spec
  only closes the mobile gap.
- No change to `gallery_categories_json`'s "empty categories are dropped unless derived" rule
  (inherited from `assignGalleryTag`) — reorder never creates or empties a category.
