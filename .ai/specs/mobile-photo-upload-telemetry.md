# Mobile Photo Upload Telemetry ("Photo Upload Report")

> Status: web side built. Mobile side handed off via
> `.ai/specs/mobile-photo-upload-telemetry-MOBILE-PROMPT.md`
> Requested by Johan, 2026-08-31, after the second photo-loss report in four days.

## 1. Why (business requirement)

Twice in four days an agent reported losing photos, and both investigations were
archaeology — reconstructing events afterwards from nginx access logs and
whatever happened to survive in the database:

| Date | Listing | Shot | Reached CoreX | What it actually was |
|---|---|---|---|---|
| 2026-08-28 | 15936 | 35 | 35 | Nothing lost. 27 sat in the app queue 68 min and arrived untagged |
| 2026-08-31 | 15753 | ~40 | 28 | 12 never entered the upload queue at all |

The second answer was **luck**. The app's `client_upload_id` happens to be
sequential (`…_1`…`_28`), so a gap-free run of 1–28 proved the app only ever
knew about 28 photos. That key is an idempotency token, not a diagnostic — it
owes us nothing, and the next app build could reasonably change its shape.

The structural problem: **the server only ever sees the survivors.** A photo that
dies between the camera and the upload queue leaves no trace anywhere, so "how
many did the agent actually take?" is unanswerable. Every future photo-loss
report would restart the same archaeology.

This makes it a lookup instead.

## 2. Pillars

**Property** — every event is anchored to a listing. **Agent** — the acting user
is recorded, and the ingest is inside the existing Sanctum + `app_access` group.
No new pillar linkage; this is diagnostics about a Property workflow.

## 3. The one design rule

**This feature must never cost an agent a photo.** It is flushed opportunistically
alongside real uploads. Therefore:

- The ingest returns **200 with a tally**, never 4xx/5xx, for junk, unknown
  phases, or a listing the user cannot see. A failing diagnostics endpoint that
  makes the client retry forever is a worse bug than the one being diagnosed.
- The server-side write inside `uploadImage()` goes through
  `MobilePhotoEvent::recordQuietly()`, which swallows every exception.
- `bytes` is read from the STORED file, never `$file->getSize()` — `store()` has
  already moved the temp upload by that point.

## 4. Data model

New table `mobile_photo_events`. No soft deletes (a deleted diagnostic row is not
a business record) and no change to any existing table.

| Column | Purpose |
|---|---|
| `agency_id`, `user_id`, `property_id` | scope + who + which listing |
| `client_upload_id` | the app's existing per-photo idempotency key (191 chars) |
| `batch_id` | one shoot, so a batch can be judged whole |
| `phase` | `captured` / `queued` / `upload_started` / `upload_ok` / `upload_failed` / `dropped` / `received` |
| `occurred_at` | the **phone's** clock — server time cannot show capture→arrival lag |
| `meta` | error text, attempt, bytes, room_tag, app build, network |

`unique(property_id, client_upload_id, phase)` — the client replays its local log,
and a replay must not multiply rows. Scoped by property so two devices cannot
collide on a same-millisecond key.

### `received` is server-only

The client may report the six phases it can observe. **`received` is written by
the server** in `uploadImage()` and is refused from a client. "Did the bytes
actually arrive?" is the one question the client cannot be trusted to answer, and
it is the question the table exists to settle.

## 5. API

| Method | Path | Name |
|---|---|---|
| POST | `/api/v1/mobile/photo-events` | `v1.mobile.photo-events.store` |

Inside the existing `auth:sanctum` + `app_access` group. `throttle:60,1`. Max 200
events per call. Body: `{ events: [ {property_id, client_upload_id, phase,
occurred_at?, batch_id?, meta?}, … ] }`. Response: `{ message, recorded, skipped }`.

Registered under `/api/v1/*` with a name, so it appears in Admin → API
automatically (non-negotiable #7).

## 6. UI

**Photo Uploads** — `/corex/diagnostics/photo-uploads`, sidebar under the system
section beside Server Health and API (non-negotiable #2, same prompt).
Permission `view_photo_upload_report` (non-negotiable #5).

- No listing selected → recent shoots, one row per listing per day: taken /
  reached CoreX / never arrived, worst surfaced by being listed at all.
- Listing selected → four numbers (taken, queued, reached CoreX, never arrived)
  and a row per photo in shutter order: taken, queued, arrived, delay, room, and
  a verdict.

The verdicts are deliberately distinct: **"never queued"** (died before the
queue — a capture-path bug) is a different fault in a different place from
**"queued, never arrived"** (a delivery bug). Collapsing them into "missing"
would have hidden the 2026-08-31 finding.

## 7. Acceptance criteria

- [x] Client events are recorded; epoch-ms and ISO timestamps both parse.
- [x] A client cannot assert `received`.
- [x] A replayed batch does not multiply rows.
- [x] Junk rows are skipped and counted; the batch still returns 200.
- [x] Another agency's listing is refused.
- [x] The server records `received` itself on every successful mobile upload.
- [x] The report page is reachable from the sidebar and permission-gated.
- [x] A photo the agent deleted in review counts as `dropped`, not as
      "never arrived" — added 2026-08-31 once shutter-time enqueue made
      `dropped` a NORMAL outcome. Counting deliberate deletions as losses would
      paint a healthy shoot as broken and bury the real losses in noise.
- [ ] **Mobile app**: reports the six client phases, durably, replayed on failure.

## 8. Deliberately NOT in this pass

- **No retention prune yet.** The table is small (~7 rows per photo) and this is
  a live investigation; a prune before we know the query patterns would be
  guesswork. Add one once the shape settles — it belongs with the other nightly
  prunes in `routes/console.php`.
- **No agency-configurable setting**, so nothing for the Setup Wizard
  (non-negotiable #10a does not apply). This is internal diagnostics, not an
  agency-facing behaviour switch.
