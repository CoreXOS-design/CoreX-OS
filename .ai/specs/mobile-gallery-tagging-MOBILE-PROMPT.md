# Mobile App Prompt — Photo upload queue, room tags, and the Unsorted bucket

> Paste the section below into the Claude session running in the **mobile app repo**.
> The CoreX OS backend is already built and deployed. No migration, no backend work needed.
> Backend spec for reference: `.ai/specs/mobile-gallery-tagging.md`

---

## ▼▼▼ COPY-PASTE INTO MOBILE APP CLAUDE SESSION ▼▼▼

Fix three defects in the property photo upload flow. They were found by tracing a
real incident on 2026-08-28: an agent shot 35 photos on one listing and reported
"some didn't upload and the tagging didn't work". The server received and stored
all 35 with zero errors — every fault below is on the client.

### What actually happened, from the server logs

- Photos 1–8: captured 13:46:13–13:47:21, POSTed 13:47:38–13:47:41. All carried
  `room_tag: "Bathroom 3"`. Correct.
- Photos 9–35: captured 14:00:41–14:18:51, **POSTed at 15:26:07–15:26:20** — up
  to 85 minutes later, all in one 13-second burst, immediately after the agent
  re-opened the property's photo screen.
- All 27 of those arrived with **`room_tag` absent**. Not wrong — absent.
- The phone was online throughout: it made authenticated `app-config` calls at
  14:00, 14:06, 14:09, 14:10, 14:13 and 14:17 from the same device, all 200.
- `client_upload_id` ran `…_1` through `…_35` with no gaps, so nothing was lost
  between the queue and the server. Keep sending that field — it is what proves
  this and it is what de-dupes retries.

### Fix 1 — the offline queue must carry `room_tag` (highest priority)

The correlation is exact: every photo uploaded immediately kept its tag; every
photo uploaded from the queue lost it. Whatever the queue persists per item
(file path, `client_upload_id`) it is not persisting the selected room.

Persist the full upload intent with the queued item and replay it verbatim:

```
{ filePath, propertyId, clientUploadId, roomTag }   // roomTag may be null
```

`roomTag` must survive app backgrounding, app kill and device restart — the same
durability the file path already has. A queued upload that replays without its
tag is the bug; do not re-derive the tag from whatever screen happens to be open
at flush time.

### Fix 2 — the queue must be visible and must drain on its own

An hour of silent queuing is what made the agent believe the photos had failed.

- Flush on: connectivity regained, app foregrounded, and periodically while the
  app is open. **Do not** require the agent to reopen the property gallery.
- Show pending state in the gallery: a persistent "N photos waiting to upload"
  row, and a per-thumbnail pending/uploading/failed badge for queued items.
- Show queued photos in the grid immediately as local placeholders, so the count
  the agent sees matches the count they shot.
- Never present a queued photo as uploaded. Only a 2xx from the server means it
  landed.
- On permanent failure (4xx that is not 401/409), surface it with the server's
  `message` and let the agent retry — do not drop the item silently.

### Fix 3 — render the Unsorted bucket and let the agent file from it

`GET /api/v1/mobile/properties/{propertyId}` now returns an extra key:

```json
{
  "gallery_categories": {
    "categories": { "Bathroom 3": ["https://…/a.jpg"] },
    "unsorted":   ["https://…/b.jpg", "https://…/c.jpg"]
  }
}
```

`unsorted` is new. Previously the payload had only `categories`, so untagged
photos were on the property but on no screen — that is why 27 photos looked
missing even after they uploaded.

- Render `unsorted` as its own section in the room-by-room gallery view,
  labelled **Unsorted** with its count, placed first while it is non-empty.
- Let the agent multi-select photos there and assign them to a room.

**New endpoint** (Sanctum bearer token, same auth as every other mobile property call):

| Method | Path |
|---|---|
| PUT | `/api/v1/mobile/properties/{propertyId}/gallery/assign` |

Request:

```json
{ "images": ["https://…/b.jpg", "https://…/c.jpg"], "room_tag": "Kitchen" }
```

- `images` — the photo URLs exactly as the API gave them to you. Required, min 1.
- `room_tag` — a value from `available_tags` (`GET /gallery/tags`), or `null` to
  move photos back to Unsorted.

Response `200`:

```json
{
  "message": "2 photo(s) filed under 'Kitchen'.",
  "moved": 2,
  "unknown_images": [],
  "room_tag": "Kitchen",
  "gallery_categories": { "categories": { "Kitchen": ["…"] }, "unsorted": [] },
  "available_tags": ["Bedroom 1", "Kitchen", "Entrance Hall", "…"]
}
```

Re-render straight from `gallery_categories` in the response — no reload needed.

Errors:
- `422` with `errors.room_tag` + `available_tags` — the tag isn't on this
  property. Refresh the tag list and re-prompt; don't retry blind.
- `422` with `moved: 0` and a populated `unknown_images` — those URLs aren't on
  this property (stale list). Re-fetch the property and drop the stale rows.
- A partial success returns `200` with `moved > 0` **and** a populated
  `unknown_images`. Show what moved; refresh the rest.
- `401` — token expired, existing re-login flow.
- `403` — no access to this property, or an assistant account with property
  writes off. Show the server's `message`; don't retry.

### Also note

The room-tag list now includes **every** space type the Spaces editor offers
(50, not the 11 it used to be) — Entrance Hall, Scullery, Braai Room, Office,
Laundry Room and the rest. Nothing to do beyond rendering whatever
`available_tags` returns; just don't assume a fixed set of room names anywhere
in the UI.

### Definition of done

1. Kill the app mid-shoot with photos queued and a room selected; reopen — the
   photos upload on their own and land under the room that was selected.
2. Shoot with airplane mode on, turn it off without touching the gallery screen
   — the queue drains and the pending count goes to zero.
3. Photos uploaded with no tag appear under **Unsorted**, can be multi-selected,
   filed under a room, and the room's count updates without a reload.
4. A photo filed into a room leaves Unsorted, and re-filing it moves it rather
   than duplicating it.

## ▲▲▲ END COPY-PASTE ▲▲▲
