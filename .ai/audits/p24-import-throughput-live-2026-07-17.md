# P24 Importer — live measurement before re-architecting the import

**Date:** 2026-07-17
**Author:** Claude (read-only investigation, QA2 lane)
**Scope:** Live measurement only. No code changed, no migrations, no re-import,
no re-confirm, no test suite run, no CDN probe (see Q3 for why).

---

## What was measured

| | |
|---|---|
| Host | `ubuntu-4gb-nbg1-2` (the production box — same host runs live workers) |
| Install measured | **`/corex`** (`APP_ENV=production`, `APP_URL=https://corexos.co.za`) |
| Database | **`nexus_os`** (MySQL 8, 127.0.0.1:3306) |
| Method | Read-only `SELECT`s against `nexus_os`, disk inspection under `/corex/storage/app/public/properties/`, code reads under `/corex/app`, log inspection under `/corex/storage/logs`, Supervisor/systemd/PM2 inspection |

The "~4000 import" is **run 7** in `p24_import_runs`: 4,753 listing rows, all
confirmed with a `target_id`, on **2026-06-25**. An earlier **run 4** added 506
listings the previous day. Both are included where noted. Numbers below are from
the actual rows, not estimates.

Two runs matter:

| run | date | listing rows | confirmed | character |
|-----|------|-------------|-----------|-----------|
| 4 | 2026-06-24 | 506 | 506 | real galleries (5–166 images each) |
| 7 | 2026-06-25 | 4,753 | 4,753 | **bulk CSV import — 90% carry only 1 image URL** (see Q1) |

Total confirmed listing rows with a target: **5,259**.

---

## Q1 verdict — YES, there is live photo loss. Two separate defects.

**586 imported listings are live on CoreX right now with zero photos even though
P24 offered them at least one image.** This is agencies looking at half-empty or
fully-empty listings today. Lead with this.

The comparison is `count(p24_import_rows.image_urls_json)` (what P24 offered) vs
`count(properties.images_json)` (what we stored and render). Across all 5,259
confirmed listings:

| bucket | count |
|--------|-------|
| stored == offered (perfect) | 4,606 |
| P24 offered 0 (nothing to lose) | 36 |
| short by exactly 1 | 585 |
| short by 2 | 0 |
| short by ≥3 | 68 |
| **stored ≤1 when P24 offered ≥5 (whole gallery gone)** | **51** |

Aggregate: **2,549 of 23,124 offered images never stored = 11.0% silently lost.**

### Once segmented, it is two distinct problems

Run 7's `payload_json` is a P24 **listings CSV export** — its keys are
`ListingNumber`, `Description`, `Latitude`, … with **no photo array at all**.
Image URLs were attached from a separate source, and for **4,279 of the 4,753
rows only ONE url was ever captured** (all 4,279 point at `images.prop24.com`).
So there are two layers of loss, and they must not be conflated:

**(a) Download-stage loss** — the failure mode Johan is worried about for the
fan-out. Measured cleanly on rows that offered a real gallery (`offered ≥ 2`),
944 listings:

| | |
|---|---|
| gallery listings (offered ≥ 2) | 944 |
| perfect | 860 |
| short by any amount | 84 |
| — short by 1 | 16 |
| — short by 2–4 | 3 |
| — short by ≥5 | 65 |
| near-total loss (stored ≤1 of a 5+ gallery) | 51 |
| **images lost** | **1,980 of 18,845 = 10.5%** |

**(b) Upstream capture loss** — the 4,279 bulk rows that carry only 1 image URL.
This is *not* the download job; the download faithfully stored the single URL it
was handed. Whether those listings truly have one photo on P24 or the capture
only grabbed one is a separate question I could not answer without probing P24
(out of scope, see Q3). It is flagged here because in listing-count it dwarfs the
download-stage loss and deserves its own investigation. Of these 4,279
single-URL listings, **569 lost even that one image** (13.3%) — the same
per-image failure rate as the galleries, which is a useful cross-check that the
download stage drops ≈1 in 8–10 images regardless of gallery size.

### The off-by-one is the same mechanism, not a separate bug

The 585 "short by exactly 1" rows are dominated by `offered=1 → got=0`
(single-image failures). The 16 that had real galleries show the tell: files are
named sequentially (`1.jpg`, `2.jpg`, …) and a *middle* index is simply absent
(e.g. property 974: offered 12, stored 11, files run 1.jpg–12.jpg with one gap).
That is one image out of the gallery failing to download — the same drop, at low
intensity — not a floorplan/duplicate quirk.

### Clustering — partial burst signature, mid-run

Whole-gallery zero losses cluster in a ~7-minute window **11:09–11:16** (5, 4, 4
zeros in successive minutes) against an import that ran 10:37–12:06. The rest of
the losses are scattered thin (1–2/min) across the whole hour. Reading:
intermittent CDN throttling throughout, spiking into a burst mid-run — exactly
the mechanism the job's own comment describes.

### Disk verification (worst offenders)

- **No zero-byte or sub-500-byte files anywhere** in the 68 loss-row
  directories (843 files scanned). The `<500 bytes → reject` placeholder guard
  at `DownloadP24RowImagesJob.php:97` works; what is missing is simply *absent*,
  not truncated.
- **12 of the 68 loss-row target dirs have no directory at all** → those
  properties got literally nothing.
- **Recovery wrinkle worth knowing:** some galleries were re-fetched to disk
  hours later but never healed in the DB. Property 2937 (`images_json` = NULL,
  UI shows 0 photos) has **55 hash-named files on disk dated 2026-06-25 21:02** —
  10 hours after its 11:09 import. A later process (hash naming, not the
  importer's sequential `{ordinal}.jpg`) pulled the bytes down but never wrote
  them back into `images_json`. So "bytes on disk" is **not** a safe proxy for
  "listing shows photos" — the DB is the source of truth for the UI, and the DB
  is what is short.

### Root cause (from the code)

`DownloadP24RowImagesJob` fetches 10-wide via `Http::pool` and
`->retry(3, 400, throw: false)`. Failures are collected into `$failures` and
**only `Log::info`'d** — never thrown, and `$tries = 1`. When all images fail,
`$stored` is empty and `images_json` is left untouched (NULL). The job returns
"success" regardless. Confirmed empirically: **`failed_jobs` contains zero
`DownloadP24RowImagesJob` rows** despite 2,549 lost images. The failure is
100% silent, exactly as feared. The current code's own comment
(lines 54–57) states the June import ran `tries=1` with no retry; the retry was
added *after*, but the swallow-and-log-only behaviour remains.

---

## Q2 — What a real row costs

**Gallery size (`image_urls_json` count):**

- All confirmed listings: mean 4.4, max 166.
- Bimodal because of run 7. Real-gallery listings (offered ≥ 2, n=944): the
  bulk sit in 5–24, tail to 166 (run 4 alone: 314 rows at 5–9, 190 at 10–24, 2 at 25+).
- Run 7 offered-count buckets: 36 zero, **4,279 exactly one**, 1 in 2–4, 19 in
  5–9, 131 in 10–24, 287 at 25+. (The "I've been modelling 10–25" assumption
  holds for genuine galleries; it does **not** describe the bulk CSV rows.)

**Elapsed / throughput (run 7, from `confirmed_at` min→max):**

- Window: 10:37:06 → 12:06:26 = **5,360 s** for 4,753 confirmed rows.
- **0.887 rows/sec** (≈ 53 rows/min).
- **16,205 images stored → 3.02 images/sec** (inline download).
- Per-image latency: **not measured** — the job records no per-image timing, and
  the June-25 logs have rotated out (logs retain ~14 days; earliest on disk is
  2026-07-03).

**Was it steady or decaying?** Variable, **not** a clean decay: 1.9 → 5.3 → 3.7 →
**17.7 → 14.3 → 18.2** → 12.2 → 3.3 → 1.3 → 1.5 rows/sec across 10-min buckets.
The mid-run peak is the single-image (fast) rows clearing, not a health signal;
the rate tracks gallery-size mix, not a metering curve. **I cannot claim a
decaying-throughput CDN-metering signature from the rate data** — the honest read
is "variable, dominated by per-row gallery size." The metering evidence is the
loss clustering (Q1), not the throughput curve.

---

## Q3 — P24's ceiling: NOT probed. Deliberate.

**I did not run the 5/10/20/40 escalation probe, and I recommend against running
it from this box.** This host is the **live P24 syndication origin**:
`SubmitListingToProperty24` and `SyncAgentToP24Job` run on the `default` queue
drained by `corex-worker-live` on this same machine and IP. The entire failure
under investigation is P24 rate-limiting by source IP. Deliberately escalating
concurrency against `images.prop24.com` from here risks throttling or temporarily
banning the IP that agents' live listing pushes depend on — trading a known,
bounded data-quality problem for a live outage on the syndication path. Per the
prompt's own guidance, that is the case where skipping is the correct answer.

**What I could recover of the wire signature instead:** nothing usable. The
June-25 import logs have rotated out, and the current `worker.log` contains no
`DownloadP24RowImagesJob` failures (no gallery imports ran in the retained
window). The `property24-*.log` files are the syndication-*push* channel, not the
image-download channel. So the exact status code of a rate-limited response
(429 vs 403 vs 200-with-short-body vs reset) is **not measured** and cannot be
recovered from this box's history.

**What the code will do with each, so the eventual probe is interpretable:**
`->retry(3, 400, throw:false)` retries on connection errors and (by Laravel
default) on HTTP failures; after 3 attempts a non-2xx is recorded as
`http_status={code}`, a short body as `body_too_small len={n}`, a transport error
as `exception: …`, all swallowed. If/when the ceiling is probed, do it **from a
different IP** (a scratch box or CI runner not on the syndication path), start at
concurrency 5, and stop the instant non-200s or a latency cliff appear.

---

## Q4 — Can this box run a queued import? Yes, with caveats.

- **Queue worker alive:** yes. Managed by **Supervisor** (not systemd — systemd
  only carries `corex-qa1-queue`, a QA worker; PM2 carries only Node apps).
  Live workers (`supervisorctl status`, all RUNNING):

  | program | numprocs | `--queue=` |
  |---------|----------|-----------|
  | `corex-worker-live` | **2** | `default` |
  | `corex-worker-live-matching` | 1 | `matching` |
  | `corex-worker-live-mail` | 1 | `mail` |
  | `corex-worker-staging` | 1 | `default,matching,mail` (staging install) |

  `--tries=3 --max-time=3600`. Config at
  `/etc/supervisor/conf.d/corex-worker.conf`.
- **Which queue the import uses:** `ConfirmP24PropertyRowJob` has **no
  `onQueue`** → `default`, drained by the 2 `corex-worker-live` procs. Image
  download is **not** a separate queued job — `ConfirmP24PropertyRowJob` calls
  `DownloadP24RowImagesJob::dispatchSync(...)` **inline** (comment: "Keeps things
  working without a queue worker"). So a straight port to `Bus::batch` on a named
  queue would strand unless a worker drains that name.
- **`QUEUE_CONNECTION`:** `database`.
- **`jobs` table depth:** 1 (no backlog).
- **`failed_jobs`:** **6,043** — still dominated by mail, matching the prior
  audit's ~6,000:

  | job class | failed |
  |-----------|--------|
  | `OversightNudgeMail` | 3,905 |
  | `SyncProperty24Activations` | 938 |
  | `OversightDigestJob` | 529 |
  | `RegenerateBuyerMatchesJob` | 472 |
  | `DesyndicatePropertyFromPortalsJob` | 150 |
  | (11 others) | ≤ 11 each |

  **Zero `DownloadP24RowImagesJob` failures** — the silent-failure proof from Q1.
- **`job_batches`:** exists, **empty** (`Bus::batch` has never been used here).
- **Capacity:** **16 CPU**, **30 GiB RAM (≈16 GiB available; 13 GiB used, swap
  2.3/4 GiB in use)**. Comfortable for more workers; the DB queue driver (not
  Redis) means high fan-out concurrency contends on `jobs`-table row locks —
  workable at tens of workers, not hundreds.

---

## Q5 — Re-import cost: full re-fetch, no skip.

**A re-import re-downloads every gallery from P24.** Confirmed by reading the
path, not running it:

- A re-import creates a **new run** with new `pending` rows.
  `ConfirmP24PropertyRowJob` matches an existing `Property` by
  `p24_listing_number` + `agency_id` and **updates it**, then unconditionally:
  `if ($propertyId && !empty($imageUrls)) DownloadP24RowImagesJob::dispatchSync(...)`.
- `DownloadP24RowImagesJob` has **no skip-if-present check** — it GETs every URL
  in `image_urls_json` from `images.prop24.com` and re-`Storage::put`s
  `{ordinal}.{ext}`, overwriting. No "already on disk", no conditional GET, no
  ETag/If-Modified-Since.
- So re-running an already-imported agency = 100% of galleries re-fetched =
  full re-exposure to the same rate-limit loss.

**Reusable signature?** Partially. `properties.p24_image_signature` exists and
`Property::p24ImageSignature()` computes `md5(syndicationImages() +
gallery_categories_json)` — but that is the **outbound** fingerprint (what *we*
last pushed *to* P24, so a refresh can send `photos: null` when unchanged). It
does not transfer directly to the import side because it hashes local stored
paths, not the P24 source URLs. The clean fix is the mirror-image idea: at import
time store an **inbound** signature — `md5(image_urls_json)` — on the property (or
on the import row), and skip the download when the incoming URL set matches what
was already stored. The pattern is proven; only the input to the hash changes.

---

## Recommendation on safe concurrency

The evidence says the bottleneck is **not** CoreX throughput — it is P24's
per-IP tolerance, and exceeding it fails **silently**. So the re-architecture's
first job is to make the failure **loud**, and only then to raise concurrency.

1. **Fix the silent failure before touching concurrency (blocking).** In
   `DownloadP24RowImagesJob`: when `count($stored) < count($urls)`, do not
   return success. Persist the shortfall (a `missing`/`incomplete` marker or a
   retryable follow-up job for the missing indices) and surface it — a WARNING
   log at minimum, ideally a per-property "gallery incomplete" flag the importer
   UI can show. Today a fan-out at higher concurrency would lose more photos and
   `failed_jobs` would still read clean. This is the single most important change
   and it is independent of the queue rewrite.

2. **Backfill the 586 (+68 partial) already-broken listings.** These are live and
   wrong now. A one-off repair job that re-fetches only the missing indices for
   confirmed rows where `got < offered` (throttled — see below) will heal them.
   Note property 2937-class cases: bytes may already be on disk under hash names;
   the repair must rewrite `images_json`, not just re-download.

3. **Separate the property write from the gallery download** (the intended
   change) — land properties immediately, stream galleries behind on a
   dedicated named queue with a worker assigned to it. Good architecture and it
   makes the download independently retryable/observable.

4. **Add the inbound image signature (Q5)** so re-imports and refreshes skip
   unchanged galleries. This removes most re-fetch load and shrinks the CDN
   exposure permanently.

5. **On concurrency: do not raise it blind.** We have zero measured wire-truth on
   P24's ceiling (Q3 not probed; June logs rotated). The one hard data point is
   that ≈80 concurrent (8 tabs × 10-wide) produced 10.5% silent loss with a
   mid-run burst. Until the ceiling is measured **from an off-path IP**, the safe
   move is to keep effective CDN concurrency **at or below the current inline
   level and add retry-with-shortfall-detection**, not to fan out wider. Concretely:
   keep the `Http::pool` at ~10, cap parallel gallery jobs so total concurrent
   CDN requests stay ≈10–20 (2 workers × 10), and let step 1's shortfall
   detection re-queue the misses with backoff. Raise the ceiling only after a
   real probe shows headroom — and even then, gate every increase on the
   post-import `got == offered` reconciliation from Q1 as the acceptance test.

**Bottom line:** the box can run a queued/batched import today (16 cores, RAM to
spare, workers alive, `job_batches` ready). But "properties in minutes, galleries
behind" only becomes safe once the download job stops lying about success.
Ship #1 before #3.

---

## Appendix — how to reproduce these numbers

All against `nexus_os` on `/corex`. Key query (download-stage loss, real galleries):

```sql
SELECT COUNT(*) gallery_listings, SUM(off=got) perfect, SUM(got<off) any_short,
       SUM(got<=1 AND off>=5) near_total_loss,
       SUM(off)-SUM(got) missing, ROUND(100*(SUM(off)-SUM(got))/SUM(off),2) pct
FROM (SELECT COALESCE(JSON_LENGTH(pr.image_urls_json),0) off,
             COALESCE(JSON_LENGTH(p.images_json),0) got
      FROM p24_import_rows pr JOIN properties p ON p.id=pr.target_id
      WHERE pr.row_type='listing' AND pr.status='confirmed'
        AND pr.target_id IS NOT NULL
        AND COALESCE(JSON_LENGTH(pr.image_urls_json),0) >= 2) t;
```

Live-broken listings (render zero, were offered ≥1):

```sql
SELECT SUM(got=0 AND off>=1) zero_but_offered
FROM (SELECT COALESCE(JSON_LENGTH(pr.image_urls_json),0) off,
             COALESCE(JSON_LENGTH(p.images_json),0) got
      FROM p24_import_rows pr JOIN properties p ON p.id=pr.target_id
      WHERE pr.row_type='listing' AND pr.status='confirmed'
        AND pr.target_id IS NOT NULL) t;   -- = 586
```
