# Training Videos — Spec

> **Status:** §0–§7 APPROVED and BUILT (Andre, 2026-10-27). **§8 (upload your own video files) — APPROVED (Andre: "yes add that", then "please add a video upload" to the Add video screen, 2026-10-27) and BUILT on Staging.** (Johan to countersign per the spec-sync rule.)
> Author: Claude · Requested by: Andre, 2026-10-27 · Module: Integration (platform-owner area)
> Branch: `AT-509-Multple-changes`
> Related specs: [`webinar-registration.md`](webinar-registration.md) (same owner-managed-list pattern)

---

## §0 — Decisions

### Andre's decisions (2026-10-27)

| # | Question | Andre's answer | What it means in the build |
|---|----------|----------------|----------------------------|
| Q1 | Group videos under topics, or one list? | **"My own groups that I create"** | A second record, *Training Video Group*, with its own full CRUD (§3.2, §4.2). Each video can sit in one group. The public page shows a section per group. |
| Q2 | Is `/training-videos` the right public address? | **Yes** | `GET /training-videos`. |

### Decisions Claude made

| # | Decision | What I chose | Why |
|---|----------|--------------|-----|
| D1 | **Whose videos these are** | CoreX's own (RR Technologies), not per-agency. Not tenant-scoped — same as Webinars, System Updates, Platform E-Sign. | The Integration group sits inside the owner-only part of the sidebar. No agency ever manages these. Multi-agency rule (#9): the public page carries no agency's name or branding — it is CoreX-branded, identical for every visitor. |
| D2 | **Public address** | `/training-videos` | `/training` is already taken by the internal logged-in Training courses module. This is a separate thing and must not collide. |
| D3 | **Videos play on the page** | Embedded YouTube player from `youtube-nocookie.com`, loaded only when the visitor presses play (thumbnail first), with a "Watch on YouTube" link under each one. | Visitors stay on CoreX's page. Nothing from YouTube loads until play is pressed (POPIA-friendlier, and a long page stays fast). Unlisted YouTube videos embed fine, so Andre can keep them off YouTube search. |
| D4 | **What is stored for the link** | The pasted URL **and** the 11-character YouTube video ID extracted from it. Accepted forms: `youtube.com/watch?v=`, `youtu.be/`, `youtube.com/shorts/`, `youtube.com/embed/`, `youtube.com/live/` (incl. `www.` / `m.` / `youtube-nocookie.com`). Anything else is rejected with: *"That doesn't look like a YouTube link. Paste the link from the Share button on YouTube."* | Every format YouTube hands out works; a bad paste is caught at save, not discovered on the public page. |
| D5 | **Taking a video off the public page** | Archive it. Archived = gone from the public page, still in the admin list under "Archived", restorable. | Non-negotiable #1 (no hard deletes). No separate draft/hidden switch — not asked for. |
| D6 | **Video order** | Newest first (by date added). | Not asked for a manual order. |
| D7 | **Public page look** | Same look as the existing public Privacy / Data Deletion / Support pages. | Consistent public face. |
| D8 | **A video's group is optional** | A video can be saved with "No group". Ungrouped videos show last on the public page under **"More videos"**. | A video can be added before its group is decided, and nothing published is ever invisible for want of a group. |
| D9 | **Group order on the public page** | Alphabetical by group name; videos inside a group newest first. | No manual ordering was asked for. Alphabetical is predictable — "1. Getting started" style names give full control if wanted. |
| D10 | **Archiving a group** | The group and every video in it disappear from the public page. The videos keep their group, so restoring the group brings them all back. An archived group can't be picked for a video — except a video already in it keeps it on edit (no silent move). | Archive is reversible everywhere; one click undoes one click. |
| D11 | **Group names** | Required, max 120 characters, unique among active groups. | Two sections called "Listings" on one public page would confuse every visitor. |

### Deliberately NOT in v1

- Manual drag-to-reorder of videos or groups (D9).
- Group descriptions — a group is a name only.
- View counts or watch tracking.
- ~~Uploading video files to CoreX — YouTube links only, as requested.~~ Superseded by §8 (Andre, 2026-10-27).
- An API endpoint — the public page is plain HTML; nothing else consumes this data.

### Not a Setup Wizard setting (non-negotiable #10a checked)

Nothing here is an agency setting. No agency sees or configures it.

---

## §1 — What this feature does and why

The CoreX owner needs one public place to publish training videos (hosted on YouTube) so anyone — current agents, new agencies being onboarded, prospects — can watch them without logging in. The owner creates groups, adds a video by pasting a YouTube link with a title, description and group; it appears on the public page immediately.

## §2 — Pillar connections

**None — stated deliberately.** This is platform content owned by CoreX itself, in the same family as Webinars and System Updates. It reads from and writes to no Property, Contact, Deal or Agent record. No domain events emitted or consumed (non-negotiable #9 not triggered). *Approval of this spec is approval of this exception to non-negotiable #4.*

## §3 — Data model

Two new tables, one migration (`schema:dump` from the test DB + DEFINER strip in the same commit).

### §3.1 `training_video_groups`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `name` | varchar(120) | required; unique among active groups (D11, enforced in validation) |
| `created_by` / `updated_by` | FK users, nullable, null on delete | |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | soft delete | archive |

### §3.2 `training_videos`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `training_video_group_id` | FK `training_video_groups`, nullable | D8 |
| `title` | varchar(200) | required |
| `description` | text, nullable | plain text, max 5000; line breaks preserved on display, no HTML |
| `youtube_url` | varchar(500) | as pasted |
| `youtube_video_id` | char(11) | extracted, indexed |
| `created_by` / `updated_by` | FK users, nullable, null on delete | |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | soft delete | archive |

No `agency_id` on either table — platform records (D1). Models `App\Models\TrainingVideo` and `App\Models\TrainingVideoGroup`, both `SoftDeletes`, no `BelongsToAgency`.

## §4 — UI placement and navigation

**Sidebar (owner-only Integration panel):** new sub-item **"Training Videos"** (admin), plus **"Training Videos ↗"** opening the public page in a new tab beside Privacy Policy / Data Deletion / Support. The Integration group is highlighted on all admin routes below.

The Training Videos screen has a **"Groups"** button in its header; the groups screen has "Back to Training Videos".

**Admin routes** — `owner_only`, under `/corex/admin/integrations/training-videos`, names `admin.training-videos.*`:

| Method | URI | Name |
|--------|-----|------|
| GET | `/` | index |
| GET | `/create` | create |
| POST | `/` | store |
| GET | `/{video}/edit` | edit |
| PUT | `/{video}` | update |
| DELETE | `/{video}` | destroy (archives) |
| POST | `/{video}/restore` | restore |
| GET | `/groups` | groups.index |
| GET | `/groups/create` | groups.create |
| POST | `/groups` | groups.store |
| GET | `/groups/{group}/edit` | groups.edit |
| PUT | `/groups/{group}` | groups.update |
| DELETE | `/groups/{group}` | groups.destroy (archives) |
| POST | `/groups/{group}/restore` | groups.restore |

**Public route:** `GET /training-videos` → `public.training-videos`, no auth, `throttle:60,1`, beside the other public legal routes.

### §4.1 Admin video list (BUILD_STANDARD §1a)

- **Search:** title, description, group name.
- **Sort:** title, group, date added, last updated. **Default: date added, newest first.**
- **Filters:** status (Active / Archived / All, default Active); group (all / a group / no group); date-added range (from / to).
- **Pagination:** 25 per page, query string preserved.
- **Row:** YouTube thumbnail, title, first part of description, group, date added, actions (Edit, Archive / Restore).
- **Empty state:** "No training videos yet" + "Add your first video". Filtered-empty: "No videos match these filters" + clear-filters link.
- **Create / Edit form:** Title, YouTube link, Group (active groups + "No group"), Description. Edit shows a preview of the saved video.

### §4.2 Admin group list (BUILD_STANDARD §1a)

- **Search:** name.
- **Sort:** name, number of videos, date added. **Default: name A–Z** (matches the public page, D9).
- **Filters:** status (Active / Archived / All, default Active); date-added range.
- **Pagination:** 25 per page.
- **Row:** name, number of active videos, date added, actions (Edit, Archive / Restore).
- **Empty state:** "No groups yet" + "Create a group"; filtered-empty with clear-filters link.
- **Create / Edit form:** Name.

### §4.3 Public page

- Header: "Training Videos".
- Jump links at the top, one per section.
- One section per active group (alphabetical, D9) with at least one active video, then "More videos" for ungrouped ones (D8). Videos in archived groups are not shown (D10).
- Each video: title, click-to-play player (D3), description, "Watch on YouTube" link. Newest first within a section.
- Search box (`?q=`) filtering by title/description; sections left empty are hidden; "No videos match" + clear link when nothing matches.
- No pagination — a catalogue read top to bottom; players load only when pressed, so length is cheap.
- Empty state: "Training videos are coming soon."
- No per-video public URL, so nothing archived is reachable.

## §5 — Permissions and scoping

- Admin: `owner_only` middleware on every route + an owner assertion in every action — same as Webinars. **No permission key by design:** a key would be grantable to an agency admin, who must not manage CoreX's own public content.
- OWN / BRANCH / AGENCY scoping: **not applicable** — not tenant data (D1). Owner sees all; nobody else reaches the admin routes, including by direct URL (403).
- Public page: read-only, active videos in active (or no) groups only.

## §6 — Files

**Create**
- `database/migrations/2026_12_05_100000_create_training_videos_tables_at509.php`
- `app/Models/TrainingVideo.php` (incl. `static extractYoutubeId(string $url): ?string`)
- `app/Models/TrainingVideoGroup.php`
- `app/Http/Controllers/Admin/TrainingVideoController.php` (validation inline, as WebinarController)
- `app/Http/Controllers/Admin/TrainingVideoGroupController.php`
- `app/Http/Controllers/Public/TrainingVideoController.php`
- `resources/views/admin/training-videos/{index,create,edit,_form}.blade.php`
- `resources/views/admin/training-videos/groups/{index,form}.blade.php`
- `resources/views/public/training-videos.blade.php` (standalone, styled like `public/legal/layout.blade.php`, which always prints a "Last updated" line and so is not extended)
- `tests/Feature/TrainingVideos/TrainingVideoTest.php`

**Modify**
- `routes/web.php` — admin group + public route
- `resources/views/layouts/corex-sidebar.blade.php` — two links in the Integration panel + active-group match
- `database/schema/mysql-schema.sql` — snapshot
- `.ai/CHAT_STARTER.md`

## §7 — Acceptance criteria

1. Owner adds a video with each accepted URL form → saved, correct 11-char ID stored, appears on `/training-videos` logged out.
2. Non-YouTube / malformed URL → rejected with the D4 message, nothing saved.
3. Edit changes title/description/link/group; public page reflects it.
4. Archive → gone from public page, listed under Archived; Restore → back. Row never physically deleted.
5. Non-owner (agency admin) → 403 on every admin route, including direct URL to edit an existing video or group.
6. Both admin lists: search, every sort, filters, pagination and both empty states work.
7. Public page works logged out, shows no agency branding, embeds via `youtube-nocookie.com`, empty state when there are no active videos.
8. Description containing HTML/script renders as plain text (escaped).
9. Groups: create, rename, archive, restore. Duplicate active name rejected. Archiving a group hides its videos from the public page; restoring brings them back. Public sections are alphabetical, then "More videos".
10. Integration sidebar shows "Training Videos" and "Training Videos ↗"; the group is highlighted on the admin pages.

---

## §8 — AMENDMENT: upload your own video files, compressed automatically (APPROVED and BUILT 2026-10-27)

> Requested by Andre, 2026-10-27: *"is there a way when I upload a video of 180mb that it can be reduced because I would rather upload them myself onto the page"* → *"yes add that"*.

### §8.1 What it does

On Add video, the owner chooses **YouTube link** or **Upload a file**. An uploaded file is sent to CoreX in pieces (with a progress bar), then shrunk on the server in the background. While that runs the video shows as **Processing** in the admin list and is not on the public page. When it finishes it appears on the public page and plays there, with no YouTube involved. A 180 MB screen recording typically comes out around 20–40 MB. Groups, descriptions, search, archive and restore work exactly as for YouTube videos. Both kinds can sit side by side in the same group.

### §8.2 Decisions Claude made

| # | Decision | What I chose | Why |
|---|----------|--------------|-----|
| U1 | **Compression** | ffmpeg → MP4 (H.264 video + AAC audio), longest side capped at 1280 px (720p; never upscaled), frame rate capped at 30, quality-based encoding (CRF 26, preset `medium`), audio 96 kbps, `+faststart` so playback starts before the whole file has downloaded. Values live in `config/training-videos.php`. | 720p keeps on-screen text in screen recordings readable at a fraction of the size. H.264/AAC MP4 plays in every browser and phone. ffmpeg is already part of CoreX's server toolset (voice-note transcription, `scripts/transcribe/setup.sh`). These are platform settings, not agency ones, so no Setup Wizard entry (#10a). |
| U2 | **Original file** | Discarded once the compressed copy is safely written. Only the compressed copy is kept. | Halves-or-better the storage the feature ever uses. Andre keeps the original on his own computer. **Business consequence: CoreX cannot re-make a higher-quality version later — re-upload the original if that's ever wanted.** |
| U3 | **Upload in pieces** | The browser sends the file in chunks (size picked by the server, safely under its own per-request limit), then asks CoreX to stitch them. Max file size 2 GB. | A single 180 MB request fails on any server whose PHP upload limit is lower, and a dropped connection would restart from zero. Chunks avoid both and give a real progress bar. |
| U4 | **Accepted files** | Anything ffmpeg can read that contains a video stream (MP4, MOV, M4V, WebM, MKV, AVI…). Checked by ffprobe on arrival, not by file extension. A file that isn't a video fails with: *"That file isn't a video CoreX can read. Try exporting it as MP4."* | Phone and screen-recorder formats vary; the honest test is "can we read it". |
| U5 | **Background processing** | A dedicated `training-videos` queue with its own Supervisor worker (1 process, lowest CPU priority, ffmpeg limited to half the cores, job timeout 1 hour, 2 tries). Config file `docs/infra/supervisor/corex-worker-training-videos.conf`. | Compressing a long video works the server hard for minutes. On its own low-priority queue it can never hold up e-mail, matching or anything agents are waiting on (same reasoning as the dedicated `transcription` queue). |
| U6 | **If processing fails** | Status **Failed** with a plain reason in the admin list, a **Try again** button, and the video stays off the public page. If a video has been Processing for over an hour, the admin list says *"Still processing after an hour — the video worker may not be running on this server."* | A silent stuck video is worse than a clear failure. |
| U7 | **Where files live** | `storage/app/public/training-videos/{random-id}.mp4` plus a poster image (`{random-id}.jpg`, a frame from ~3 seconds in). Random, unguessable file names. | That folder is inside the nightly encrypted off-server backup (`scripts/backup/corex-offbox-backup.sh` covers `/corex/storage/app`), and the web server streams it directly — PHP never sits in the middle of a 30 MB stream, and skipping forward/back works. |
| U8 | **Archived = unreachable** | Archiving an uploaded video moves its files to private storage (`storage/app/private/training-videos/`); restoring moves them back. Same disk, so it's instant. | Keeps §4.3's promise — nothing archived is reachable — even for someone who saved the file's address. Private storage is in the same nightly backup. |
| U9 | **Replacing the file** | Editing an uploaded video can upload a new file. The old one keeps playing until the new one is ready, then is discarded. | No gap on the public page. |
| U10 | **Public player** | The browser's own video player (`<video controls preload="none">`) showing the poster image until play is pressed. | Nothing downloads until someone presses play, same as the YouTube click-to-play (D3). |
| U11 | **Abandoned uploads** | Pieces of an upload never finished are cleaned up after 24 hours by a scheduled command. | Temporary pieces, not records — they would otherwise fill the disk. No video record is created until the upload completes. |
| U12 | **Migration** | **Superseded at build time.** The original create migration had already run on Staging (batch 276), so it was NOT amended: a new additive migration `2026_12_13_100000_add_upload_columns_to_training_videos` adds the §8.4 columns. Every existing row stays `source = youtube`, `status = ready`. One extra column beyond §8.4: `pending_upload_id` (varchar 32, nullable) — the finished upload waiting to be shrunk. It is what lets "Replace file" (U9) leave the old copy playing and lets "Try again" (U6) work without a re-upload. | The first migration had shipped, so editing it would have left Staging and a fresh install with different tables. |

### §8.3 What it costs (stated plainly, for the record)

1. **Every view streams from CoreX's server.** The page is public; if a video is shared widely, that traffic is ours. YouTube carries it free.
2. **One quality only.** No automatic drop to lower quality on a weak phone signal; it buffers instead. 720p keeps this modest.
3. **Server work while compressing** — contained by U5.
4. **Storage** — compressed copies only (U2), inside the existing nightly backup (U7).

### §8.4 Data model (changes to §3.2 `training_videos`)

| Column | Change |
|--------|--------|
| `source` | new, varchar(10): `youtube` \| `upload` |
| `youtube_url`, `youtube_video_id` | become nullable (required only when `source = youtube`) |
| `status` | new, varchar(12): `ready` \| `processing` \| `failed` (YouTube videos are always `ready`) |
| `failure_reason` | new, varchar(255), nullable |
| `file_name` | new, varchar(64), nullable — the random id; files are `{file_name}.mp4` / `.jpg` |
| `original_size_bytes`, `size_bytes` | new, unsigned bigint, nullable — shown as "180 MB → 32 MB" in the admin list |
| `duration_seconds`, `width`, `height` | new, unsigned int, nullable |
| `processing_started_at` | new, timestamp, nullable — drives the "over an hour" warning (U6) |

Public page shows only `status = ready`.

### §8.5 Routes

Admin (owner-only, inside the existing `admin.training-videos.*` group): `POST /{video}/retry` → `retry`.

Upload pieces are JSON, so per non-negotiable #7 they live under `/api/v1`, named, session-authenticated, owner-only:

| Method | URI | Name |
|--------|-----|------|
| POST | `/api/v1/admin/training-video-uploads` | `api.v1.training-video-uploads.start` — returns an upload id + chunk size |
| POST | `/api/v1/admin/training-video-uploads/{upload}/chunks` | `api.v1.training-video-uploads.chunk` |
| POST | `/api/v1/admin/training-video-uploads/{upload}/complete` | `api.v1.training-video-uploads.complete` — stitches, then the normal form save creates/updates the video and queues processing |

### §8.6 Admin screens

- **Form:** a "YouTube link / Upload a file" switch above the link field. Upload shows a file picker, a progress bar, and *"You can leave this page once the upload reaches 100% — CoreX shrinks the video in the background."* Save is disabled until the upload finishes.
- **List:** uploaded videos show their poster as the thumbnail, a status badge (Processing / Failed / Ready), and "180 MB → 32 MB · 4:12". Failed rows have **Try again**. New filter: source (all / YouTube / uploaded).
- **Edit:** a preview player for uploaded videos; "Replace file" (U9).

### §8.7 Deployment (per environment, before this is used there)

1. `ffmpeg` + `ffprobe` installed (already present wherever voice-note transcription was set up; verify with `ffmpeg -version`).
2. Install and start the Supervisor worker from `docs/infra/supervisor/corex-worker-training-videos.conf`.
3. `php artisan storage:link` present (it already is on every environment that serves photos).
4. Locally on Windows: `winget install ffmpeg` to see real compression; automated tests don't need it (the compressor is swapped for a fake).

### §8.8 Files

**Create**
- `config/training-videos.php`
- `app/Services/TrainingVideos/VideoCompressor.php` (interface) + `FfmpegVideoCompressor.php`
- `app/Services/TrainingVideos/ChunkedUploadService.php`
- `app/Jobs/TrainingVideos/CompressTrainingVideoJob.php`
- `app/Http/Controllers/Api/V1/TrainingVideoUploadController.php`
- `app/Console/Commands/TrainingVideos/PurgeAbandonedUploads.php` (+ schedule entry)
- `docs/infra/supervisor/corex-worker-training-videos.conf`
- `tests/Feature/TrainingVideos/TrainingVideoUploadTest.php`

**Modify**
- the §6 migration (U12), `TrainingVideo` model, admin `TrainingVideoController`, `_form` / `index` / `edit` views, public controller + view, `routes/web.php`, `routes/api.php`, `routes/console.php` (schedule), `database/schema/mysql-schema.sql` (surgical, as before), `.ai/CHAT_STARTER.md`.

### §8.9 Acceptance criteria

1. A 180 MB MP4/MOV uploads with a visible progress bar, shows Processing, then Ready, and plays on the public page logged out; the stored file is smaller than the original and the original is gone.
2. A non-video file → Failed with the U4 message; never on the public page.
3. A dropped piece can be re-sent; the upload completes.
4. Archive moves the files out of the public folder (the old address returns 404); Restore brings them back and the video plays again.
5. Replace file: the old video keeps playing until the new one is Ready.
6. Processing for over an hour shows the U6 warning; Try again re-queues a Failed video.
7. Unfinished uploads older than 24 hours are cleaned up; no video record exists for them.
8. A non-owner is refused on every upload endpoint (403), including with a valid upload id.
9. YouTube videos behave exactly as before (§7 still passes).

### §8.10 As built (2026-10-27)

- **Queue lane:** `training-videos`, registered in `config/queue_alerting.php` as a BATCH lane (alarm = head not advancing for 65 min, not depth). Supervisor: `docs/infra/supervisor/corex-worker-training-videos.conf` (live); Staging has its own program, `corex-worker-staging-training-videos`.
- **Container binding:** `VideoCompressor` is bound to `FfmpegVideoCompressor` with the `#[Bind]` attribute on the interface, so no service-provider edit; tests swap in a fake.
- **Verified for real, not just faked:** a generated 1080p/60fps clip (27 MB) through the real compressor came out 720p/30fps H.264+AAC, `+faststart`, 0.7 MB, with a poster; a text file renamed `.mov` was refused with the U4 message.
- **A failed first-time upload** shows Failed + reason + Try again; **a failed replacement** leaves the old video playing and shows the reason with Try again on the edit screen and list.
- **Not in this build:** no automatic quality ladder (§8.3 #2), no resumable-after-page-close upload — a closed tab means starting that file again (the unfinished pieces are cleaned up after 24h, U11).
- **Tests:** `tests/Feature/TrainingVideos/TrainingVideoUploadTest.php` (acceptance 1–9 with a fake compressor).

### §8.11 Shrink progress on the admin list (2026-10-27, Johan's request)

**What the owner sees:** a video that is being shrunk shows a progress bar under its title on the Training Videos list — "Waiting in line…" until the video worker picks it up, then "Shrinking… 37% · about 6 min left", and the line "It goes live on the public page the moment this reaches 100%." The bar updates by itself every 5 seconds; when the video finishes, the list refreshes and it shows Ready. A replacement file shows the same bar with "The current video keeps playing until the new one is ready."

**How:** new nullable column `training_videos.progress_percent` (NULL = waiting, 0–99 = shrinking, cleared when done or failed). `FfmpegVideoCompressor::compress()` takes an optional progress callback fed from ffmpeg's `-progress` output (position ÷ clip length); the job writes it at most every 2 seconds and restarts `processing_started_at` when the shrink actually begins, so the time-left estimate (`TrainingVideo::etaSeconds()`, shown only after 3%) ignores queue wait. Polled endpoint: `GET /api/v1/admin/training-video-uploads/progress` (`api.v1.training-video-uploads.progress`, owner-only). Migration `2026_12_14_100000_add_progress_to_training_videos`. No setting added, so no Setup Wizard entry. Test: `TrainingVideoUploadTest::test_progress_endpoint_reports_videos_being_shrunk_and_is_owner_only`.
