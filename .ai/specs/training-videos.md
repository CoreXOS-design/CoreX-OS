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

---

## §9 — Auto-generated how-to videos (AT-525, Johan 2026-10-31)

> **Status:** framework APPROVED by Johan (option 2, 2026-10-31); built on QA1 first, Staging after Johan's approval. Builds ON this module (§3-§8: model, admin, the `training-videos` queue and worker, the 720p ffmpeg compress) and on Johan's driver.js tours (`app/Support/Tours`, `.ai/specs/advanced-guiding.md`). **No second copy of either.**

### 9.1 What it does and why
Someone presses **Generate** on a screen. CoreX opens that screen itself in a headless browser as a **training user in a demo-data agency**, runs through a **script** (highlight, click, type), records it, speaks an AI voice-over that explains **what to click and why it matters** (what the step feeds elsewhere - e.g. booking a viewing in the diary means the buyer's feedback later goes to the seller's live link), and saves a short MP4 (max 2 minutes). Any agent opens it from that screen with a **Watch how (1:45)** button, or from a **Training library**. When a feature is built or changed, its video is flagged out of date and regenerated. Johan and Andre stop doing manual training.

### 9.2 What already exists (checked 2026-10-31 - build on it)
- **AT-509 Training Videos (Andre)** - on Staging and QA1: `TrainingVideo` model (YouTube or uploaded file), owner-only admin `admin/integrations/training-videos`, public `/training-videos`, chunked upload, `CompressTrainingVideoJob` (ffmpeg 720p, CRF 26) on queue `training-videos` with its own worker (`corex-worker-staging-training-videos`; QA1/QA2 workers added 2026-10-31), `config/training-videos.php`. It does no recording and no voice.
- **Guided Tours (Johan)** - `TourRegistry` (`app/Support/Tours/TourRegistry.php` + 22 files in `defs/`): **217 tours, 1,750 steps, 992 with a `do` action** (click / fill / choose / appear). Engine = driver.js 1.3.6 in `layouts/partials/tour-engine.blade.php` (3 modes: Guided, Advanced, Spot Help), anchors are `data-tour="..."` attributes in the views, "?" launcher via `layouts/partials/tour-header-launcher.blade.php`. A step carries `title` + `body` (the WHAT) - **never the WHY**. Biggest tours: re-property-page 46 steps, calc-hub 22, property-capture 21, calendar 18.
- **Ellie tour knowledge** (`ellie-tour-knowledge.md`) already reads the registry live - the same registry can seed scripts.

### 9.3 Decisions
| # | Decision | Why |
|---|---|---|
| T1 | A generated video is a `TrainingVideo` row with `source = 'generated'` (additive columns: `screen_key`, `source_hash`, `generated_at`, `tts_voice`, `captions_file`, `chapters`, `generated_by`). | Reuse the table, the admin, the queue and the compress code; one place for every training video. |
| T2 | Generated videos are **private**: stored on the local (private) disk under `storage/app/training-videos-auto/` and streamed through an authenticated route (HTTP range supported). They never appear on the public `/training-videos` page (`scopePubliclyVisible` excludes them). | They are for agents; the library page is signed-in only. |
| T3 | A screen's **script** is a PHP file `resources/training/scripts/<screen-key>.php` (format in 9.4). Claude drafts it from the spec + the code + the tour, Johan approves (`approved => true`); only approved scripts are generated automatically. `php artisan training-videos:draft <tour-key>` writes a skeleton from a tour (its title/body become the narration, the `why` is left for Claude to fill from the specs). | The tour already knows the selectors and the order; what it lacks is the why - that needs the specs, which is a writing job, not a recording job. |
| T4 | Voice: **Piper** (free, offline, MIT code), provider-swappable behind `TtsProvider`. Default voice `en_GB-jenny_dioco-medium`. A paid voice is one more class + one `match` branch + `TRAINING_TTS_DRIVER`. | See 9.6. No data leaves the server, no per-minute cost. |
| T5 | **Sync by construction:** the voice is generated FIRST, each clip's length measured, then the recorder holds every step for its clip's length (+ padding). The audio track is the clips placed at the step start times the recorder reports. | No guessing, no drift. |
| T6 | The recorder logs in as a **training-agency user** only (`agencies.is_training = true`): `TrainingSessionService` refuses any other user. Demo data only (9.8). | POPIA. |
| T7 | **Out of date** = the stored `source_hash` (script file + the tour definition + the spec files named in the script + the voice) no longer matches. Computed live in the library; `training-videos:check-outdated` lists them; Regenerate re-makes it. | Touch the tour / script / spec when the feature changes and the video flags itself. |
| T8 | Hard cap **120 s**. The validator estimates the length from the word count BEFORE recording and refuses a script that would overrun, in a sentence; the assembler checks the real length again. | Max ~2 minutes (Johan). |
| T9 | Permissions: `training_videos.view` (everyone who can use a screen sees its video) and `training_videos.generate` (agency admin / Command-Center / platform owner). | Non-negotiable #5. |
| T10 | Nothing here is an agency setting, so nothing is added to the Setup Wizard (#10a). The training agency itself is platform data. | |

### 9.4 Script format
```php
return [
    'key' => 'book-a-viewing',            // kebab-case = the file name
    'title' => 'Book a viewing and capture the buyer's feedback',
    'route' => 'command-center.calendar', // where the "Watch how" button appears (null = library only)
    'url'   => '/corex/command-center/calendar',
    'tour'  => 'calendar',                // changed tour => video out of date
    'specs' => ['.ai/specs/viewing-packs.md'],   // the why came from these => changed spec => out of date
    'audience' => [],                     // roles; empty = every signed-in user
    'related' => ['capture-a-buyer'],     // "watch next"
    'login_as' => 'agent',
    'approved' => true,                   // Johan's sign-off
    'intro' => 'In this video: ...', 'outro' => 'Next: ...',
    'steps' => [
        ['selector' => '[data-tour="cal-add"]', 'action' => 'click',          // highlight | click | type | scroll | wait | goto | press
         'narration' => 'Click Add Event.',                                    // WHAT
         'why' => 'A viewing booked here is linked to the buyer and the property, so the feedback you capture later goes to the seller's live link.', // WHY - required
         'hold' => 0],
    ],
];
```

### 9.5 Pipeline (`GenerateTrainingVideoJob`, queue `training-videos`)
validate script -> speak every line (TtsProvider) and measure -> forge a session for the training user -> `scripts/training-video-record.mjs` (puppeteer-core + system Chromium, `page.screencast`) plays the steps (spotlight + cursor + click ripple injected, title cards for intro/outro) and writes a timeline JSON -> `VideoAssembler`: mux the audio clips at the timeline offsets, H.264 + AAC (CRF 26, faststart), burn-in captions (libass) and write a `.vtt` sidecar, poster frame at 3 s, enforce 120 s -> store, set `ready`, record `source_hash`. Failure at any stage leaves the previous video playing and the reason on the row.

### 9.6 Findings from the investigation (2026-10-31, this box)
- **Recording is proven.** puppeteer-core 24 (already in `package.json`) + `/usr/bin/chromium` 156 (a snap wrapper; box is **aarch64**), logged in to QA1 with a forged database session, drove the `calendar` tour with `?tour=calendar`, `page.screencast` -> 1280x720, 25 fps, 23.6 s, **3.2 MB raw VP9 -> 0.57 MB** H.264 CRF 26 (about **1.5 MB per minute**), ffmpeg 6.1 encode 2.8 s; the whole run 36 s of which 13 s was the page loading. ffmpeg has libx264, aac, libass (`subtitles`) and drawtext.
- **Voice options.** Browser `speechSynthesis` cannot be captured headless. *Piper* (offline, MIT code, aarch64 wheel installs cleanly in a venv on the data volume): 11.7 s of speech in 4.3 s including model load (about 0.4x real time), 63 MB per voice; English voices are British/US (no South African voice exists in Piper); medium voices are clear and neutral but audibly synthetic - **not ear-checked by me; Johan to listen to the two samples** in `/mnt/HC_Volume_103099143/training-videos/sample-*.wav`. Voice licences differ per voice (jenny_dioco and alba are Creative Commons - confirm the exact licence on the model card before publishing outside CoreX). Paid options (for later, pluggable): OpenAI TTS about US$15 per million characters (a 2-minute video is about 1,500 characters = about US$0.02), ElevenLabs from about US$5-22 a month (most natural; has accent choice), Azure Neural TTS has **en-ZA voices (Leah, Luke)** at about US$16 per million characters, Google Cloud TTS has en-ZA (Standard/WaveNet) at US$4-16 per million. Cost per video with a paid voice: **under 5 cents**; Piper: free.
- **Storage.** About 1.5 MB per minute of 720p: a 2-minute video is about 3 MB, 200 videos about 600 MB. The app storage already lives on the data volume (bind mount), so no object storage is needed until the library passes tens of GB; if it does, the `disk` in `config/training-videos.php` can point at S3-compatible storage with no other change.
- **The WHY.** It does not exist in the tours. It lives in the specs (`.ai/specs/*.md` - e.g. `viewing-packs.md`, `buyer-pipeline`, `rental-owner-live-link.md`, `agency-tracker.md`, ...) and in the code (what a save dispatches: domain events in `corex-domain-events-spec.md`). Per-screen script = tour steps (selectors, order) + a `why` line per step written from the spec/events catalogue.
- **Real client data.** QA1 and Staging hold copies of real HFC data (names, addresses visible in the first recording). The recorder therefore only ever uses the training agency (9.8).

### 9.7 Build list and cost
S1 schema + script format + validator + out-of-date hash + voice provider (done) - S2 recorder + assembler + job + training session + training agency seeder - S3 library page + "Watch how" button + generate/regenerate + permissions + sidebar - S4 one sample video on QA1. Later: scripts for the top 5 screens (Johan's call), `training-videos:draft`, a nightly "out of date" digest, Staging rollout. Compute per video: about 1-3 minutes of one CPU core (record 1-2 min, voice 20-40 s, encode 5-10 s); TTS Piper free / paid under 5 cents. Writing a script: about 30-60 minutes of Claude time per screen (spec + code reading), Johan approves in a few minutes.

### 9.8 Training agency
`php artisan training:seed-agency` creates (idempotently) the agency **CoreX Training Academy** (`is_training = true`), a branch, an agent, a branch manager and an admin, 12 demo properties, buyers and sellers, a booked viewing with feedback and a seller live link - all invented. `--reset` deletes and re-seeds only that agency's rows, so it stays fresh (run weekly and before every recording). The recorder refuses any user outside it. The agency is hidden from agency pickers and reports (`is_training` scope) and gets no real mail (the non-production mail guard + no real addresses).


### 9.9 Library, "Watch how" button, Regenerate (S3 - built 2026-10-31)
- **Library** `/corex/training-library` (`corex.training-library.index`, sidebar "Training library" under Guided Tours). Cards with poster, length, "Made <date> - voice-over <voice>". A viewer sees only screens that HAVE a video; a user with `training_videos.generate` also sees approved scripts still waiting for their first video (button "Generate"). Empty state: "No how-to videos yet." Search/sort are not built - the list is one card per screen script (about 5 at first); add when it passes ~20.
- **Player** `/corex/training-library/{screen}`: captions on by default, poster, chapters (click to seek), "Watch next" from the script's `related`, "Open this screen". The mp4/poster/vtt are streamed by `.../file/{mp4|jpg|vtt}` (authenticated, range requests, private disk - never a public URL).
- **Scoping:** a script's `audience` (roles; empty = everyone) decides who may watch; a screen they may not watch is a 404, not a hidden link. Generated videos are excluded from the public `/training-videos` scope. The videos carry no real client data (9.8).
- **"Watch how (m:ss)" button** (`layouts/partials/watch-how.blade.php`, included by both layouts after the tour engine): found by the current route name against the scripts' `route`; shows only when a ready video exists and the user holds `training_videos.view`; opens the player in a dialog on the same screen. A screen without a video shows nothing.
- **Regenerate / Generate:** POST `.../generate` (`training_videos.generate`), refuses an unapproved script, queues `GenerateTrainingVideoJob` on `training-videos`; the old video keeps playing until the new one is ready.
- **Out-of-date flag:** `SourceHasher` (script + tour definition + spec files + voice) compared with the hash stored at generation; amber "Out of date" on the card and the player.
- **Permissions:** `training_videos.view` (admin/owner, branch manager, office admin, agent, viewer) and `training_videos.generate` (admin/owner, branch manager, office admin). Not in the Setup Wizard: it is a per-person permission, not an agency setting.
- **Tests:** `tests/Feature/TrainingVideos/TrainingLibraryTest.php` (needs seeded grants - an unseeded test DB allows everything).

### 9.10 Fix log - library 404 for HFC users (31 Oct, conductor's check as Johan)
Cause: the feature registry, not the route cache or scoping. `training_videos.view/.generate` carried `module => 'training'`, so they were owned by the **Training (LMS)** feature; HFC has that feature OFF, and `CheckPermission` 404s any permission whose owning feature is off (the training-agency session, which has it on, got 200 - which is why the first proof missed it). Fix: the two keys are claimed by the **Guided Tours** feature (`permission_keys`, which win over the module) together with the routes (`route_names`, `route_prefixes`) - the how-to videos are the same family as the tours and default ON. Rule for the next screen: a how-to video is never gated by the LMS feature. Test: `TrainingLibraryTest` - a non-training agency with `training` off and `guided-tours` on gets 200 on index/show/mp4/vtt; with `guided-tours` off it is 404. `FeaturePermissionOwnershipTest` fails on `attorney_portal` and `desk` modules (other lanes' keys, fails before this change).

### 9.11 Second video: Market Intelligence Work tab (`mic-work`, 31 Oct)
- **Script** `resources/training/scripts/mic-work.php`, route `market-intelligence.work`, 8 steps, about 100 s, a WHY on each: tiles (where to start today) - open a property (everything we know on one card) - Claim (becomes yours, feeds the prospecting pipeline and Road to Sold) - the envelope (a P24 alert email matched; arrives before the next portal read) - Neighbours of a sale (the canvass-the-neighbours rule after every sale; agency-neutral wording, no person's name) - open a trigger (who to call, nobody phoned twice) - Data refreshed (refresh weekly: a listing gone from a fresh read has probably sold) - the oldest suburb in red.
- **Data: invented only.** `TrainingMicSeeder` (called by `training:seed-agency`, cleared by `--reset`) writes straight into the training agency's own tables: 32 files and 8 adverts - a stuck property (two cuts, on both portals), an owner selling privately, an under-offer and a sold trigger, a deeds-registered trigger, a P24-alert match with a price drop, a property a colleague claimed, and 22 neighbouring files (some with a deeds owner, some without, one already phoned). Streets that exist nowhere (Academy Lane, Tutor Crescent, Lesson Close, Example Drive - checked against the portal street register and every agency's files), owners with the surname Example, agencies Sample Realty / Harbour Demo Properties / Example Estates, advert links on `.invalid`. Nothing here is visible to or counted for another agency; `mic:refresh-stats` computes the tiles/scores. Tests: `TrainingMicSeederTest`.
- **"Watch how" on the MIC Work header:** `<x-watch-how inline />` in `_header-actions` (white on navy beside Setup); the floating pill of the layout is then not drawn (`request()->attributes watch_how_rendered`). Same component everywhere: a screen with a script + ready video gets it, one without gets nothing.
- **Regenerate:** the out-of-date flag covers changes after the generation; regenerate after the cc4 step 4.8 / cc6 step 6.8 changes land.
- **After step 4.8 (31 Oct 16:36):** tile 11 and its panel now count only the agency's WORKING suburbs (stock, claims, leads, or areas listed in MIC Settings). The training seed therefore lists the four suburbs as the agency's areas (`mic_agency_settings.areas`), so the Data refreshed panel shows four rows, Margate (12 d, overdue) first. Regenerated `mic-work` after cc6 6.8 and cc4 4.8.


### 9.12 Voice picker, captions (31 Oct, Johan)
- **Voice picker** in the Training library (people with `training_videos.generate`): 8 British female Piper voices (free, offline; models on the DATA volume, `PIPER_DATA_DIR`) - Jenny (`en_GB-jenny_dioco-medium`, the voice so far), Cori (`en_GB-cori-high`), Southern (`en_GB-southern_english_female-low`), Prudence and Poppy (`en_GB-semaine-medium`, speakers 0 and 3), Clara, Grace and Heather (`en_GB-vctk-medium`, speakers p225, p229, p257 - the plain-English female speakers of the multi-speaker set; regional accents left out). Each has a name, a one-line description, a play button for the SAME two sentences (`training-videos.auto.sample_text`) and "Use this voice". The current voice is marked. The choice is `agencies.training_video_voice` (default `training-videos.auto.default_voice`; nothing hard-coded). "Regenerate all videos with this voice" queues every approved script. The voice is not part of the out-of-date hash (videos are shared by every agency); a generator sees "(not your chosen voice)" on a video made in another voice. Not in the Setup Wizard: it is a platform content choice made once in the Training library, not an agency operating setting - **Johan's call** (rule 10a).
- **Voice licences:** Piper code MIT; each voice keeps its dataset licence (LJSpeech-style / VCTK CC BY 4.0 / SEMAINE / Cori - see the voice cards on huggingface.co/rhasspy/piper-voices). Check the card of the chosen voice before it is used in a marketing cut (section 9.15), and credit where its licence asks.
- **Captions, once only:** the captions are the `.vtt` track the player shows - on by default, with a **CC** switch (remembered in the browser) - and are never burned into the picture (they were, so Johan saw every line twice). The assembler's `$burn` flag exists only for the social cuts (9.15), which have no player to show a track.

### 9.13 Standing rule: plain words (Johan, 31 Oct)
These are HELP videos for the average estate agent. Every narration and caption line uses the plainest words possible.
- Talk like a colleague at the next desk: "Click the house to see everything we know about it", not "Expand the record card".
- No software or internal words (list below). Say what the agent sees and what to do, then one short "why" in agent terms ("so you know who to phone first", "so the seller sees the feedback on their live link").
- "Price per square metre", never "R/m²". "Evaluation", never "valuation".
- Short sentences, about 15 words or fewer, one idea per line. The 2-minute maximum still applies.
- **Validator** (`TrainingScriptValidator::plainWordProblems`, config `training-videos.auto.plain_words`): flags any banned word below (with what to say instead) and any line over 20 words. A test keeps this list and the config in step.
- **Banned words (and what to say):** tile (box), tiles (boxes), panel (list / box), record card (everything we know about the house), score reason / score reasons (why it ranks high), provider (who supplies it), filter chip (button), chip (button), queue (list of waiting jobs), sync (update), toggle (switch on or off), expand (open), dropdown / drop-down (list), modal (box that opens), widget (box), checkbox (tick box), database (our records), API (connection), backend (behind the scenes), workflow (steps), valuation (evaluation), R/m² / R/m2 (price per square metre).
- Both shipped scripts (`book-a-viewing`, `mic-work`) were rewritten to this rule on 31 Oct.

### 9.14 Priority screens (Johan, 31 Oct)
After the two pilots work, the next videos and tours, in this order, are the screens Johan calls critical: **1. agency take-on / the Setup Wizard, 2. Settings, 3. Role Manager.** Training-agency data stays invented only.
- **Claim wording checked against the code (31 Oct):** the Claim button on the MIC Work list opens the property and `MarketIntelligenceController::claim` (`app/Http/Controllers/CoreX/MarketIntelligenceController.php:3836-3845`) only creates a `prospecting_claims` row - it does NOT put the property on Road to Sold. (Only a deeds-capture auto-claim does: `CaptureClaimService::claim` -> `placeOnRoadToSold`, `app/Services/Prospecting/CaptureClaimService.php:113`. `ClaimCreated` is not in `AdvanceProspectsFromDomainEvent::HANDLED`; it only earns the `mic.claim_taken` daily point, `CreditInstantActionListener::handleClaimCreated`, and can complete a bound task.) So the `mic-work` step 3 "why" says only what is true: "Then it is yours, and no colleague phones the same owner." Re-check this when cc6 lands Pitch.

### 9.15 One script, three outputs: the guided tour, the narrated tour and the video (DRAFT for cc2 to agree, 31 Oct)
**Rule: there is no second format.** The training script (`resources/training/scripts/<key>.php`) is the only place a screen's walkthrough is written. It drives (1) the **guided tour** (the driver.js popovers today, `app/Support/Tours/defs/*`), (2) the **narrated guided tour** (the same tour, with each step's voice-over playing as its popover appears - cc2 builds the in-page player, a new ticket linked to AT-525), and (3) the **video** (section 9.5) and its marketing cuts (9.16). The existing tour definitions stay until each screen's script replaces them; a screen is never described twice.

**Step fields.** Today's script step is `selector, action, value, narration, why`. The tour needs three more, and the existing tour fields map onto the script's:

| Script step field | Meaning | Existing tour field it replaces |
|---|---|---|
| `selector` | CSS selector of the element the step is about (spotlight / popover anchor) | `element` |
| `action` | `highlight` (just look), `click`, `type`, `select`, `scroll`, `wait`, `goto`, `press` | `do.action` (`click`/`fill`/`choose`/`appear`) - `type`->`fill`, `select`->`choose`, `highlight`->none |
| `target` (new, optional) | the element the agent must act on, when it differs from `selector` | `do.target` |
| `value` | what is typed / chosen (the video types it; the tour shows it as the example) | - |
| `narration` | what the agent sees and does, plain words (<=15 words) | `do.say` + `title` |
| `why` | one short reason in agent terms | `body` |
| `done` (new) | how the tour knows the agent did the step, so Next unlocks. One of: `click` (the `target` was clicked), `input` (the field is non-empty), `change` (the select changed), `appear:<selector>` (that element is now on screen), `url:<route-name>` (the page changed to it), `none` (just read it - the default for `highlight`). The video ignores it. | `do.until` |
| `wait_for` (new, optional) | a selector that must be on screen BEFORE the step starts (a panel that opens after the previous click, a page that is loading). The tour waits for it (spinner after 3 s, then "still loading" and Skip); the recorder waits for it before it spotlights. | the recorder's ad-hoc waits |
| `section`, `advanced_only` (optional) | tour grouping and "advanced guide only" steps (unchanged) | `section`, `advanced_only` |
| `hold` (optional) | extra seconds the VIDEO holds on the step | - |

Script level (unchanged, shared): `key, title, route, url, tour, specs, audience, related, login_as, approved, intro, outro`, plus `permission` (the tour is offered only to users holding it) and `setup` (e.g. `scrollTop`) lifted from the tour defs.

**Per-step audio, in the agency's chosen voice (section 9.12).**
- One mp3 per step: `narration` then `why` spoken as one clip, stored on the private disk under `training-videos-auto/tour-audio/<screen>/<voice-key>/<n>.mp3`, with a `manifest.json` (step index, text, seconds, content hash). A clip is made once and re-made only when its text or the voice changes (the hash is the file name suffix); the intro and outro are steps 0 and N+1.
- Made on demand by `php artisan training-videos:tour-audio <screen|--all> [--voice=key]` and by the Regenerate / voice-change actions, on the `training-videos` queue.
- **API (cc2's player reads this; registered under `/api/v1`, named, visible in Admin -> API):** `GET /api/v1/training-tours/{screen}` returns `{key, title, route, voice, steps:[{n, selector, target, action, value, narration, why, done, wait_for, section, advanced_only, audio_url, seconds}]}` for the signed-in user's agency voice, only for a screen the user may watch (audience, permission, Guided Tours feature) - a 404 otherwise; `GET /api/v1/training-tours/{screen}/audio/{n}` streams the clip (authenticated, range). If a clip is not made yet the step comes back with `audio_url: null` and the player simply shows the text (it never blocks on audio).
- Playing the audio is the player's job (cc2); the narrated tour is user-paced: Next stays locked until `done` is met, and the audio can be muted per user (remembered, like the CC switch).

**Open points for cc2 to confirm** before code: (a) is the popover title taken from the first sentence of `narration`, or does the script need a short `title`? (b) does the player keep the `advanced_only` split? (c) which of the 217 existing tour defs convert first - proposal: only the screens that get a video, in the 9.14 order. (d) the player must read `wait_for` itself; the API only passes it through.

### 9.16 Marketing cuts (Johan, 31 Oct) - design
The same script also makes a **marketing variant** of its video, for CoreX's own YouTube and social channels, "with extra spice" so agencies want to join. Nothing is posted anywhere by the system: files are **downloaded from the Training library by admins only (`training_videos.generate`), and Johan posts them himself.**
- **Script block** `marketing` (optional, per script): `hook` (a punchy 3-5 second line about the agent's problem, e.g. "Still losing buyers between viewings?"), per-step `benefit` (benefit-led narration that replaces `narration`/`why` in the cut: "Never lose a buyer again", not "Press Add Event"), and `cta` (the end-card line). Without a `marketing` block a script has no cut.
- **Order of a cut:** hook (card + voice) -> the screen, narrated benefit-first, with a branded corner mark -> end card: CoreX wordmark, the call to action, and the contact **johan@corexos.co.za** (the only contact; it lives in config `training-videos.marketing.contact`, not in the script). Branding = the CoreX wordmark and brand colours.
- **Wording rules (validator, marketing lines):** the plain-words rule (9.13) applies; plus never "build / building / built / developing" about CoreX - the line is "HFC runs on CoreX, and we keep expanding it"; no real client, agent or address (invented training data only, 9.8 - the recorder only ever signs in to the training agency); no price or discount promises; no claim that cannot be shown on the screen.
- **Formats (one recording, three exports):** 16:9 1920x1080 for YouTube; 9:16 1080x1920 for Reels / Shorts / TikTok, the screen cropped and zoomed to the action (the recorder writes each step's spotlight rectangle in the timeline and the export follows it, step by step); 1:1 1080x1080 for Facebook and LinkedIn feeds, cropped the same way. Marketing cuts are recorded at 1920x1080. Captions ARE burned in on the social cuts (there is no player to show a track, and feeds autoplay muted); the 16:9 cut keeps the `.vtt` and burns nothing.
- **Music (optional):** a royalty-free track licensed for commercial use, stored on the DATA volume in `training-videos/music/` with its licence text beside it (`<file>.LICENCE.txt`) and recorded in the table below; mixed low under the voice (voice ducks the music). Off when no track is configured (`training-videos.marketing.music`). **No track is bundled yet - a track and its licence need Johan's OK before it is used.**

| Track | Licence | Source | Credit required | Approved by |
|---|---|---|---|---|
| (none yet) | | | | |
- **Storage / library:** table `training_marketing_cuts` (screen, format, file, duration, size, voice, source hash, status); the library shows, to admins only, a "Marketing cuts" row per screen with Generate / Regenerate and three **Download** buttons (`Content-Disposition: attachment`). A cut goes out of date with its script like a video does.
- **Not done by this feature:** posting, scheduling, captions in other languages, analytics.

### 9.17 BUILD_STANDARD addition - DRAFT, effective after pilot sign-off (do not copy into `.ai/BUILD_STANDARD.md` yet)
> **§X Every page ships with its help.** From the day Johan signs off the two pilots (`book-a-viewing`, `mic-work`), no page is done until it has: (1) a **training script** (`resources/training/scripts/<key>.php`, section 9.4 / 9.15) written in plain words (9.13) - the single source; (2) a **guided tour** and a **narrated guided tour**, both generated from that script; (3) a **how-to video**, generated from it in the agency's chosen voice, no longer than two minutes; and (4) the **"Watch how" button** on the page (automatic once the script's `route` matches). Rules that go with it: the script and the page change in the same commit (the out-of-date flag is the safety net, not the plan); every step has a WHY in agent terms; the training agency's data is invented only - a real client, agent or address in a recording is a POPIA breach; a page that is only a settings form still gets the script (what to set, and what it changes for the agency). A page without these is not "done" under §1 of this document. Existing pages are brought in by priority (9.14), not all at once.
>
> **Checklist line for the prompt template:** "Script `<key>` added/updated, validator clean, video regenerated (or flagged out of date with the reason)."

### 9.18 TTS provider switch, South African voices (DESIGN ONLY - not coded; Johan rejected all 8 Piper voices, 31 Oct)
Piper stays as the **free fallback and the default**. The pipeline gains a provider switch so a cloud voice - ideally South African - can be chosen in the Training library's voice picker exactly like a Piper voice. Nothing here is built or ordered; there are no accounts and no keys, and **we never create or enter credentials - Johan creates the account and key and keeps ownership of it**. (Numbered 9.18 because 9.15-9.17 were taken by the tour format, the marketing cuts and the BUILD_STANDARD draft.)

**Design**
- **Seam:** `TtsProvider` (`synthesize($text, $outWav): float seconds`, `name()`) already exists; `TtsManager` already switches on `training-videos.auto.tts.driver` and picks a voice by key (9.12). Add three classes, one per cloud provider, each implementing `TtsProvider` and a new small `VoiceCatalogue` interface (`voices(): array` of `{key, label, about, locale, gender}`). A voice key becomes `<provider>:<voice-id>` (`piper:cori` stays; `azure:en-ZA-LeahNeural`, `polly:Ayanda`, `eleven:<voice_id>`); `agencies.training_video_voice` already stores a key, so nothing changes in the agency setting. Provider output (mp3 / pcm) is converted to wav by ffmpeg so the recorder, assembler, tour-audio and marketing code never know which provider spoke.
- **Picker:** the Voice section groups voices by provider. A provider that is not connected shows "Not connected - ask an owner to add the key in System settings" and no voices; a connected one lists its voices (South African first; the list is filtered to `en-ZA` plus any curated ids in config, cached 24 h, so we never show hundreds). Every voice keeps the same buttons: play the SAME two sample sentences, "Use this voice", "Current voice" mark. A sample costs ~80 characters (a fraction of a cent) and is cached by text + voice, so each voice is paid for once however often it is played.
- **Where the key lives:** one **system-level** key per provider (videos are shared by every agency, so the voice account is CoreX's, not an agency's). Stored encrypted at rest (Laravel `encrypted` cast; or the server `.env` for the first cut - Andre's choice, never in the repo), entered by an owner-role user on a System settings screen in a password field, **never shown again, never logged, never returned by any API** (the screen shows only "Connected, key ends ..ab12" and a Remove button). The Azure region / AWS region are plain settings beside it. Least privilege: an AWS IAM user that may only call `polly:SynthesizeSpeech`; an Azure Speech resource key (not the subscription); an ElevenLabs key restricted to text-to-speech.
- **Cost control (generated once, never per play):** audio is made when a script's text or voice changes and stored (the mp4 for the video, the per-step mp3 for the tour, 9.15); playing, watching and the narrated tour cost nothing. The existing hash cache decides "changed". Before "Regenerate all" the library shows the characters about to be spent and the estimated dollars; a **monthly character cap** (a setting, default 200,000, like the report recogniser's caps) stops a runaway; a provider error keeps the old video playing, and there is **no silent switch to Piper** (a video must not change voice half way) unless an owner ticks "fall back to the free voice".
- **Licence:** each provider's terms allow using generated speech in videos, but the marketing cuts (9.16) are public: check the chosen voice's terms before it is used there (ElevenLabs library voices carry a per-voice licence set by their creator).

**What each needs, and what a 2-minute script costs** (about 1,500 characters; narration plus why for both pilot scripts is 1,300-1,700). Prices are the providers' published list prices, fetched 31 Oct 2026; the Azure page rendered without its dollar figures, so its rate is from secondary sources and must be confirmed on the live page or the Azure pricing calculator.

| Provider / voice | Needs from Johan | Price (published) | One 1,500-character script | Notes |
|---|---|---|---|---|
| **Azure Speech** - `en-ZA-LeahNeural` (F), `en-ZA-LukeNeural` (M), both "Standard" neural, no styles | An Azure account, a Speech resource (region e.g. South Africa North), its key | Neural: about $15-16 per 1 million characters (secondary sources; official page shows no figure). **Free F0 tier: 0.5 million neural characters a month** | about **$0.023**, and **free** inside the 0.5M tier (about 330 scripts a month) | https://azure.microsoft.com/en-us/pricing/details/cognitive-services/speech-services/ ; voices: https://learn.microsoft.com/en-us/azure/ai-services/speech-service/language-support?tabs=tts |
| **Amazon Polly** - `Ayanda` (F, en-ZA), Neural and Generative (no Standard / Long-Form) | An AWS account, an IAM user limited to `polly:SynthesizeSpeech`, access key + secret, a region where Ayanda is offered | Neural **$16** per 1M characters; Generative **$30** per 1M. Free tier for new accounts, first 12 months: 1M neural characters a month, 100K generative | Neural about **$0.024**; Generative about **$0.045**; free inside the free tier | https://aws.amazon.com/polly/pricing/ ; voices: https://docs.aws.amazon.com/polly/latest/dg/available-voices.html |
| **ElevenLabs** - a Voice Library voice with a South African accent (chosen by listening; accent tags vary) | An ElevenLabs account on a plan with the commercial licence (Starter or above), an API key | Credits per month: Free 10,000 (no commercial licence listed), Starter 30,000 (about $6), Creator 121,000 ($11), Pro 600,000 ($99); text-to-speech is 1 credit per character (0.5-1 on the Flash / Turbo models) | 1,500 credits: 5% of Starter's month; about **$0.14** on Creator ($11 / 121,000 x 1,500); about 20 scripts a month on Starter, 80 on Creator | https://elevenlabs.io/pricing ; check the chosen library voice allows commercial use |

Both pilot videos (about 1,500 characters each) re-made in a new voice cost **about 5 US cents on Azure or Polly, about 30 cents on ElevenLabs Creator**; a voice change is therefore cheap, and the real cost is Johan's listening time. Providers likely to sound most natural for plain South African English: Azure Leah / Luke and Polly Ayanda (generative) are the two that are explicitly `en-ZA`; ElevenLabs gives the most natural result but is the only paid-monthly option.

**Build order when Johan chooses** (each step its own commit, QA1 only): (1) provider classes + `VoiceCatalogue` + usage counter + cap (no UI, tested with faked HTTP); (2) System settings screen for the keys (owner role, encrypted, masked); (3) picker grouped by provider with the cost note; (4) then regenerate in the chosen voice. Nothing is regenerated until Johan picks.

### 9.19 Voices: provider, licence, cost (31 Oct, Johan: CoreX has no paying customers yet, so voices are FREE wherever possible)
Johan rejected all eight Piper voices. ElevenLabs ("Ava", "Thandi" were liked) is **parked**: its free plan bans commercial use and the cheapest commercial plan is about $6 a month. All voice engines live on the DATA volume. Provider is part of the voice: the picker groups voices by provider and shows its licence and cost above the group.

| Provider | Voices | Licence | Cost | Where | Status (31 Oct) |
|---|---|---|---|---|---|
| **Kokoro-82M** (kokoro-onnx, CPU) | British female `bf_emma`, `bf_isabella`, `bf_alice`, `bf_lily`; American female `af_heart`, `af_bella` (for comparison) | Apache-2.0, commercial use OK | Free, our own server; about 0.7-0.9 s of work per second of speech | venv `/mnt/HC_Volume_103099143/training-videos/kokoro-venv`, model `.../kokoro/kokoro-v1.0.onnx` (326 MB) + `voices-v1.0.bin`, `scripts/kokoro-tts.py` | **LIVE in the picker; the default engine** |
| **Chatterbox** (Resemble AI) | Your own voice, cloned from a 5-10 s recording (admin, consent tick) | MIT, commercial use OK | Free, own server | own venv on the data volume | next - speed on this CPU to be measured first |
| **Azure Speech free tier** | `en-ZA-LeahNeural` (F), `en-ZA-LukeNeural` (M), plus Afrikaans and Zulu | Microsoft Azure terms | 0.5 million characters a month free, ongoing (about 330 scripts a month); about $15-16 per million after | cloud; needs Johan's own Azure key | stub only: shown greyed "needs an Azure key", disabled by a setting |
| Piper (en_GB, 8 voices) | Jenny, Cori, ... | MIT code, per-voice dataset licences | Free | `.../voices` | **removed from the picker (rejected); kept as the last-resort fallback** |
| ElevenLabs | Ava, Thandi (library) | commercial use needs a paid plan | from about $6 a month | cloud | **parked** |

- **Fallback rule:** the default voice is `bf_emma` (Kokoro). If a chosen voice's engine is not installed on a server, the fallback voice (`jenny`, Piper) speaks instead and a warning is logged; a runtime error is never swapped for another voice (a video must not change voice half way).
- **Chatterbox consent rule (when built):** admin only; "Record or upload your own voice" needs the tick "I confirm the person in this recording agreed to their voice being used"; the reference clip is stored privately on the data volume and used only to read the samples and videos; CoreX never clones a real person, celebrity or third-party sample itself.
- **Azure key:** Johan creates the Azure account and key; we never create accounts or enter keys. Stored encrypted in a System setting (9.18).

### 9.20 CoreX voices (platform voice library), platform default, audio made once per voice (Johan, 31 Oct)
**Engine: Chatterbox** (Resemble AI, MIT, commercial use OK) in its own venv on the DATA volume (`training-videos/chatterbox-venv`, weights in `training-videos/chatterbox/hf`; `scripts/chatterbox-tts.py`; needs `setuptools<81` or its watermark module fails to load). **Measured on this box** (16 CPU cores, aarch64, no GPU; the box was also running other lanes): model load about 17 s once per run; **about 14-15 seconds of work per second of speech** (a 2.3 s line took 34 s, a 2.0 s line 29 s). A 100-second video therefore needs about 25 minutes of CPU; a 4-second picker sample about a minute. That is fine for one-off generation on the `training-videos` queue (nice 19), and it is why every cache below matters: **a CoreX voice's audio is never made inside a web request** and a video is made in one engine run (the generator hands Chatterbox all its lines at once so the model loads once - `BatchTtsProvider`).

**The rules (Johan):**
1. **CoreX voices.** The SYSTEM OWNER (the existing `owner_only` middleware + `isOwnerRole()` on every action) makes voices in **Training library -> Voices** (`/corex/training-library/voices`): records in the browser or uploads 5-20 s of clear speech, ticks "I confirm the person in this recording agreed to their voice being used" (required; the date and user are stored), and names the voice. Table `training_platform_voices`; the clip is converted to one mono 24 kHz wav and kept **private** on the data volume (`training-videos-auto/voice-refs/`), never served by any route; an agency only ever hears the generated samples and videos. Voices are archived (soft delete), never hard deleted; the reference clip stays on disk. CoreX itself never clones a real person, celebrity or third-party sample.
2. **Platform default.** The owner picks one CoreX voice as the platform default (the first one made becomes it). Every agency uses it unless its admin chooses another. Until the owner makes a voice, the default is Kokoro `bf_emma` (config `default_voice`).
3. **Agency choice.** The agency admin's picker (Training library, `training_videos.generate`) shows the CoreX voices first, then Kokoro, each with Play sample and "Use this voice"; the current voice and the platform default are marked. The agency stores only a key (`agencies.training_video_voice`); null = the platform default. Almost every agency is expected to stay on the default. **An agency's own-voice upload is NOT built** - a possible later option (same engine, per-agency table and consent).
4. **Audio is made once per voice and shared.** Generated videos are one per **(screen, voice)** (`training_videos.voice_key`). The default voice's videos serve every agency on it. When an agency switches voice, only the screens that have no video in that voice yet are queued (once); an agency that has just switched keeps watching the default's video until its own is ready; a second agency choosing the same voice queues nothing. `TrainingVideoResolver` decides which video an agency is shown (its voice, else the platform default's, else an older video made before voices were per video, else any). Cached by script text (the source hash) plus voice - never per agency.
3b. **Samples** (the same two plain sentences) are made in the background once per voice (`MakeVoiceSampleJob`, queue `training-videos`); Play appears when the sample exists.
- **Legacy:** the two videos made before this change (`voice_key` null, Piper Jenny) stay playable for everyone until regenerated; the first regeneration in the default voice adopts them.
- **Not in the Setup Wizard:** a platform content choice, not an agency operating setting (Johan's call, rule 10a).
- **Consent / POPIA:** the consent tick is the control; the reference clip is personal information of the speaker - private disk, owner-only creation, never exposed to agencies, deletable on request (archive plus file removal by an admin).

### 9.16.1 Ad render contract v1 - DRAFT for cc5 to agree (Johan's "big play": one-click video ADS from the CoreX owners menu, 31 Oct)
**Split of ownership.** cc5 builds the **Ad Studio** page, the **brand settings** and the **ad scripts** (a new ticket linked to AT-525). The training-video pipeline owns the **render side**: it turns an ad script into mp4s. The two meet at one PHP service call and one table - nothing else is shared. Ads **sell**, training videos **explain**; but the screen actions inside an ad are NOT a second format: a beat points at a step of a training script (9.15), so a screen is described once.

**Input - `AdRenderRequest`** (a plain array / DTO passed to `App\Services\TrainingVideos\Ads\AdRenderer::queue($request): string $batchId`; in-process, no HTTP):
```
ad_ref        string|int   cc5's id for the ad (we only echo it back)
length        15 | 30 | 60 seconds (target; the validator rejects a script whose beats cannot fit)
formats       subset of ["9:16", "1:1", "16:9"]
voice         a voice key (9.12 / 9.20); null = the platform default
captions      bool (default true) - burned in on every ad format
music         null | track_id  (null until Johan approves a track - 9.16)
script
  hook        { text: <= 12 words about the AGENT'S PROBLEM, seconds: 3-4 }
  beats[]     { benefit: <= 15 words, benefit-led ("Never lose a buyer again", never "Press Add Event"),
                screen: { script_key, step }      the training-script step to show (selector / action / value / wait_for are read from it), or
                        { route, selector, action, value? } when no training step exists yet,
                zoom:   { selector?, scale: 1.0-2.5 }   what to crop / zoom to (default = the step's selector),
                seconds: 2-6 }                    fast cuts: 2-6 s a beat
  proof       { text: "HFC runs on CoreX, and we keep expanding it" style line, seconds: 2-3 }
  cta         { line: "Join CoreX", follow: "Follow us" }
brand         { name, logo (path on the private disk), colours {primary, accent, text, background}, font?, cta_url, website, contact_email (johan@corexos.co.za), phone?,
                socials { facebook?, instagram?, linkedin?, youtube?, tiktok? } }
```
**Output - one row per format in `ad_renders`** (`id, batch_id, ad_ref, format, status queued|rendering|ready|failed, file_name, poster_name, duration_seconds, size_bytes, width, height, voice_key, source_hash, failure_reason, rendered_at, deleted_at`; files on the private disk under `training-videos-auto/ads/<batch>/`). **`ready` = an mp4 (H.264/AAC, faststart) AND a poster jpg for that format.** cc5 polls `AdRenderer::status($batchId)` (rows + `percent`) or listens for the `AdRenderFinished` event, and serves downloads through its own authenticated routes (admin / owners menu only; nothing is posted anywhere - Johan posts the files himself). Re-queueing an ad with the same script, voice, brand and formats returns the existing renders (cache by `source_hash`; audio is made once per script text + voice).

**What the renderer does**
- **One recording per ad, at 1920x1080**, signed in only to the CoreX Training Academy (invented data only - no real client, agent or address can appear; the recorder refuses any other login). Each beat's screen step is performed live; the recorder writes each beat's target rectangle (the step's selector, or `zoom.selector`) in the timeline.
- **Fast cuts, zoom and crop on the action:** each beat is cut from the recording and cropped to the format around its target rectangle - **9:16 1080x1920** (zoomed hard to the action), **1:1 1080x1080**, **16:9 1920x1080** (full frame, a slow push-in on the target). Beats are 2-6 s with hard cuts or 6-frame crossfades.
- **Captions burned in, inside the safe zones:** in 9:16 the platform's own buttons cover roughly the top 14% and bottom 22% and the right 12% edge, so captions sit between 62% and 78% of the height with 6% side margins, in the brand font; 1:1 and 16:9 use the lower third. Large, high-contrast, at most two lines.
- **Voice:** `hook`, every `benefit`, `proof` and `cta.line` are spoken in the chosen voice (cached per text + voice, shared by every agency); the hook is 3-4 s, the whole ad fits `length`.
- **End card, 3-4 s:** brand logo and colours, **"Join CoreX"**, **"Follow us"** with the social handles, the website and `contact_email`.
- **Music:** optional; a royalty-free track licensed for commercial use and ads **without attribution**, stored on the data volume with its licence beside it and listed in the table in 9.16; mixed low under the voice. **No track is used until Johan approves one.**
- **Brand:** logo, colours and font come from cc5's brand settings (per agency - an ad for the second agency carries ITS brand, never HFC's); the renderer has no hard-coded brand.
- **Validator (before anything is rendered):** length and beat budget fit; hook <= 4 s; plain words (9.13); never "build / building / built / developing" about CoreX ("HFC runs on CoreX, and we keep expanding it"); no price or discount promise; no claim that the screen cannot show; every `screen` step exists and its selector resolves on the training agency; the formats are known. Problems come back as plain sentences to cc5 so the Ad Studio can show them.
- **Out of scope here:** posting or scheduling, captions in other languages, analytics, editing an ad's footage by hand.

**Open points for cc5:** (a) is `ad_ref` an integer id or a uuid? (b) does cc5 want a `webhook`-style callback or is the `AdRenderFinished` event plus polling enough? (c) logo format (png with transparency; svg is rasterised) and the minimum size; (d) which fonts the brand settings allow (the renderer needs the font file on the server - proposal: a fixed list of 6 free fonts installed once); (e) the ad script's authoring UI: free text per beat with the validator's messages shown inline?

### 9.16.3 Ad renderer - built (cc1, 31 Oct) - agrees 9.16.2
Built as agreed in 9.16.1 / 9.16.2 (`App\Services\TrainingVideos\Ads\AdRenderer::queue($request): string` and `status($batchId): array`; `AdRenderFinished` event; table `ad_renders`; `ad_ref` = the integer `video_ads.id`). Request fields added by cc5 are honoured: `script.hook.accent` (the one word drawn in the brand accent, text sent without asterisks, drawn in capitals), `script.cta.sub` (the call-to-action line, spoken when 10 words or fewer and drawn on the end card), brand = CoreX's own with `tagline`, `phone`, `join_line`, `cta_line` (the tagline is drawn small on the end card and as the optional `proof` line). A request that fails the renderer's check throws `AdRequestInvalid` (plain-sentence `problems`) and nothing is queued; the renderer's list wins on screen steps ("Shot 2: that screen has no step number 10"). `status()` = `{batch_id, ad_ref, status queued|rendering|ready|failed, percent, stage, failure_reason, files: {format: {mp4, poster, seconds, width, height, size}}}` - `mp4` / `poster` are private-disk paths (`training-videos-auto/ads/<batch>/<9x16|1x1|16x9>.mp4|jpg`); Ad Studio streams them through its own owner-only routes. The same ad (words, shots, the training steps they point at, voice, brand, formats, length, music, engine) is never rendered twice: a batch with every requested format ready is returned as it is.
- **Files:** `AdRequestValidator`, `AdRenderer`, `AdRequestInvalid`, `AdComposer` (speak -> film -> cards -> mix -> one mp4 + poster per format), `AdCards` + `scripts/ad-cards.mjs` (hook / brand line / end card PNGs from brand-coloured HTML in Inter), `AdCaptions` (ASS), `AdFormat` (shapes, crop windows, safe zones), `Jobs\TrainingVideos\RenderAdJob`, recorder `ad` mode (no spotlight or cursor; per shot: the target rectangle and when the action settled), `php artisan training-videos:ad-sample <screen> [--sync]` (renders a sample ad of a training script through the door).
- **Limits and rules enforced by the renderer:** length 15 / 30 / 60 (the finished ad may not exceed the chosen length by more than 15%, else it fails with a plain sentence); hook <= 12 words; 1-8 shots of 2-6 s; zoom 1.0-2.5; plain words (9.13); never build / building / built / developing about CoreX; no price or discount promise.
- **Music:** none unless an approved file is named in `request.music` AND exists in `training-videos.ads.music_dir` on the data volume (9.21.6). None is bundled.
- **Cost:** the voice is the free engines of 9.19/9.20; a 30 s ad is about 12 short lines. A CoreX (Chatterbox) voice is about 14 s of CPU per second of speech, so such an ad needs several minutes of voice work; Kokoro about 1 s per second.
