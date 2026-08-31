# Mobile Gallery Tagging & Untagged Photo Recovery

> Status: built (web side) — mobile app side handed off via
> `.ai/specs/mobile-gallery-tagging-MOBILE-PROMPT.md`
> Raised by: Barbara Jackson, 2026-08-28. Investigated 2026-08-31.

## 1. What happened (the business requirement)

An agent shot a new Margate listing on the mobile app and reported that **some
photos never uploaded and the tagging didn't work.**

What the server records actually show:

| | |
|---|---|
| Photos her phone sent | 35 |
| Photos CoreX stored | 35 |
| Uploads rejected / errored | 0 |
| Photos tagged to a room | 8 |
| Photos with no tag | 27 |

Nothing was lost. Three separate defects combined to make it look like it was:

1. **Photos 9–35 sat in the app's offline queue for 68 minutes** (shot
   14:00–14:19, uploaded 15:26) even though the phone was demonstrably online
   the whole time — it was making other authenticated calls every few minutes.
   The queue only flushed when she reopened the property's photo screen. For
   over an hour the listing genuinely showed 8 of 35.
2. **Every photo that went up via that queue arrived with `room_tag` absent.**
   The correlation is exact: uploaded immediately → tag kept; uploaded from the
   queue → tag gone. The queue does not persist the tag alongside the bytes.
3. **Untagged photos were invisible.** `buildGalleryCategories()` returned the
   categories map and silently dropped the `unsorted` bucket, so a photo with no
   room tag appeared under no room in the app. There was also **no endpoint to
   tag a photo after upload**, so the 27 could not be rescued from the phone at
   all — only from a desktop browser.

Two adjacent defects surfaced during the same investigation:

4. **39 of the 50 space types could not be used as a photo tag.**
   `derivedGalleryTags()` filtered spaces through a hard-coded 11-name
   whitelist while the Spaces editor offers the full catalogue. She added an
   Entrance Hall; no Entrance Hall tag ever appeared.
5. **The public agency website showed no photos for this listing** (and 52
   other active ones). Its blades read `images_json` — a column no agent-facing
   upload path writes — and then double-prefixed the value with
   `asset('storage/'.…)`. No customer traffic has hit those pages in the log
   window, so this is a latent break, not an active outage.

## 2. Pillars

**Property** — reads and writes `gallery_categories_json`, `gallery_images_json`,
`spaces_json`. **Agent** — the acting user is authorised per property via the
existing `authorizeProperty()` gate. No new pillar linkage.

## 3. Data model

No migration. Existing columns only:

- `properties.gallery_categories_json` → `{categories: [{name, images[]}], unsorted: []}`
- `properties.gallery_images_json` → the master ordered photo list
- `properties.spaces_json` → source of derived room tags

## 4. API

| Method | Path | Purpose |
|---|---|---|
| PUT | `/api/v1/mobile/properties/{property}/gallery/assign` | File already-uploaded photos under a room, or return them to unsorted |
| POST | `/api/v1/mobile/properties/{property}/images/delete` | Take already-uploaded photos back off the listing |

### Why delete exists (added 2026-08-31, second pass)

The mobile app now enqueues at the shutter and drains without waiting for the
camera to close, so **a photo the agent deletes in review may already be on the
server**. Before this endpoint the app could add photos and never remove them —
the agent was told to go and open the web app, which is the same "put in, can't
take out" gap the assign endpoint fixed for tagging.

Mirrors the web `deleteImages()`: assistants refused (AT-267), references dropped
inside the row lock, files unlinked only AFTER the references are gone so a failed
update cannot leave a dangling reference (a dangling reference blocks the entire
PrivateProperty listing update — see `RepairGalleryReferences`).

`gallery_upload_keys` is deliberately **left intact** on delete, exactly as the web
delete leaves it. That key is what makes `uploadImage()` short-circuit a retry;
clearing it would let an in-flight retry of the just-deleted photo re-upload and
resurrect it. A deleted photo stays deleted even if the phone tries again.

Named `v1.mobile.properties.gallery.assign`, inside the existing
`mobile/properties` group, so it inherits Sanctum auth and the
`deny_assistant_property_write` middleware (non-negotiable #7 and #5 satisfied:
versioned, named, appears in Admin → API automatically, permission-gated).

Request: `{ "images": ["<url>", …], "room_tag": "Kitchen" | null }`
Response: `{ message, moved, unknown_images[], room_tag, gallery_categories, available_tags }`

`GET /api/v1/mobile/properties/{id}` now additionally returns
`gallery_categories.unsorted: []` — **additive**, so older app builds are
unaffected.

## 5. Behaviour rules

- A photo lives in exactly one bucket. Assigning lifts it out of every category
  and out of unsorted before filing it, so it can never appear under two rooms.
- Incoming URLs are matched to stored ones on path, so the absolute URL handed
  to the client matches a host-relative stored value.
- The write takes the same `lockForUpdate()` on the property row that
  `uploadImage()` takes — `gallery_categories_json` is a JSON column and
  concurrent read-modify-write is a lost-update hazard.
- Categories emptied by a move are dropped, **unless** they are derived from
  `spaces_json` (those must survive an empty state — the room still exists).
- Tag resolution is shared with `uploadImage()` via `canonicalGalleryTag()`;
  two copies would drift and a tag accepted by one path and rejected by the
  other is what makes tagging feel broken.

## 6. Acceptance criteria

- [x] An untagged photo is returned to the app in `gallery_categories.unsorted`.
- [x] An already-uploaded photo can be filed under a room from the phone.
- [x] Re-filing moves it; it never lives in two rooms.
- [x] It can be sent back to unsorted.
- [x] An unknown tag 422s with `available_tags`; an image not on the property is
      reported in `unknown_images` rather than silently ignored.
- [x] Every space type in `config('property-spaces.all_space_types')` is taggable.
- [x] The public listing page and grid render agent-uploaded photos.
- [ ] **Mobile app**: room_tag survives the offline queue.
- [ ] **Mobile app**: queued photos are visible as pending and flush without
      reopening the gallery screen.
- [ ] **Mobile app**: the Unsorted bucket is rendered and photos can be filed
      from it.
- [x] An already-uploaded photo can be deleted from the phone, by url or by
      `client_upload_id`, and a retry does not resurrect it.

## 7. Files changed

- `app/Http/Controllers/Api/MobilePropertyController.php` — `assignGalleryTag()`,
  `canonicalGalleryTag()`, `imageMatchKey()`; `buildGalleryCategories()` returns
  `unsorted`; `uploadImage()` reuses the shared resolver.
- `app/Models/Property.php` — `derivedGalleryTags()` derives its whitelist from
  the space catalogue; new `publicGalleryUrls()`.
- `routes/api.php` — the assign route.
- `resources/views/public/agency-properties/{index,show}.blade.php` — use
  `publicGalleryUrls()`.
- `tests/Feature/Api/MobileGalleryTaggingTest.php` (new, 7 tests)
- `tests/Feature/Properties/PublicGalleryUrlsTest.php` (new, 3 tests)

## 8. Deliberately NOT done

- **No backfill of the 27 photos on property 15936.** They are safe and
  visible; once the app ships the Unsorted view the agent files them herself in
  seconds. Guessing rooms on her behalf would be inventing data.
- **No new setting**, so nothing to add to the Setup Wizard (non-negotiable #10a
  does not apply).
