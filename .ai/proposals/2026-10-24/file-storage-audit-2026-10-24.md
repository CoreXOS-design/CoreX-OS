# CoreX file-storage audit — 24 Oct 2026 (READ-ONLY)

Scope: QA1 (`/corex-qa1`, branch QA1 = origin/QA1 at `cebc9f960`) and Staging (`/corex-staging`, `hfc_staging` DB), read-only.
Nothing in any repo, database, config or storage was changed. Sample files were copied to a scratch folder and deleted afterwards.
Real live (62.238.31.82) was NOT touched. See "What I read outside QA1/Staging" at the bottom.

**How to read the numbers.** QA1 and Staging are *partial copies* of a live-like data set: the database knows about far more files than are on disk
(documents 52–55% present, FICA 0–43%, comms attachments 21–46%). So "on disk" understates a real agency, and "DB-recorded" is the better guide to real volume.
Both boxes hold the same DB snapshot (same row counts); agency 1 (Home Finders Coastal) is >95% of the data. Agency 20 is a demo agency.

---------------------------------------------------------------------------------------------------------
## 1. INVENTORY

### 1a. What is on disk (storage/app, all disks)

| | QA1 | Staging |
|---|---|---|
| Files / size | 62,515 files / 18.4 GB | 83,160 files / 18.8 GB |
| By type | jpg 6.8 GB, png 6.4 GB, pdf 4.5 GB | jpg 13.7 GB, pdf 3.2 GB, png 1.3 GB, html 0.3 GB |

By feature (count, GB, median and 90th-percentile file size). Full per-month table is in Appendix C.

| Feature | QA1 files | QA1 GB | median / p90 KB | Staging files | Staging GB | median / p90 KB |
|---|---:|---:|---|---:|---:|---|
| Property photos (galleries; includes stored thumbs and property `files/` PDFs) | 39,729 | 7.90 | 70 / 351 | 69,502 | 15.32 | 130 / 398 |
| PDF splitter TEMP page renders (png/jpg) | 15,312 | **6.53** | 204 / 1,190 | 1,699 | 1.06 | 232 / 1,646 |
| PDF splitter originals (uploaded scans) | 410 | 1.32 | 2,271 / 6,553 | 63 | 0.24 | 2,398 / 8,288 |
| PDF splitter outputs + zips | 1,279 | 0.88 | 327 / 1,487 | 116 | 0.10 | 341 / 1,980 |
| Communications (email/WhatsApp, **encrypted**) | 1,118 | 0.58 | 7 / 874 | 2,955 | 0.24 | 0 / 114 |
| FICA documents (**encrypted**) | 406 | 0.30 | 405 / 1,756 | 36 | 0.03 | 328 / 1,970 |
| Rental application docs (incl. highlighted copies) | 1,253 | 0.39 | 7–110 / 357–951 | 5 | ~0 | – |
| Viewing packs (redacted PDFs) | 77 | 0.16 | 1,910 / 4,331 | 16 | 0.03 | 2,178 / 3,440 |
| Rentals: inspections, inventory, notices, lease copies | 752 | 0.14 | 38 / 463 | 2 | ~0 | – |
| Deal / sales / contact / property documents | 80 | 0.08 | 948 / 2,127 | 193 | 0.43 | 1,261 / 6,944 |
| E-sign originals, templates, signed PDFs | 30 | 0.01 | 183 / 1,190 | 1,105 | 0.59 | 251 / 1,445 |
| Generated reports / CMAs / presentations / exports | 1 | ~0 | – | 333 | 0.40 | 462 / 2,608 |
| Prospecting captures, thumbnails, imports | 1,682 | 0.04 | 19 / 26 | 6,890 | 0.31 | 18 / 39 |
| HR / agent profile / knowledge / branding | 202 | 0.02 | 68 / 176 | 203 | 0.09 | 93 / 840 |
| Temp files (storage/app/temp, pdf-suite/tmp) | 19 | 0.02 | 1,362 / 1,522 | 18 | 0.02 | 1,224 / 1,351 |
| Stray scripts/test artefacts at storage/app root | 119 | 0.03 | – | 17 | ~0 | – |

Notes
- QA1 rentals data is mostly test data (QA1 is where rentals were built); Staging has almost none. Real rentals volume is therefore not measurable today.
- File modified-dates are **not** a reliable growth measure here: most photos carry restore/import dates (Jun–Aug 2026). Real growth below comes from DB `created_at`.
- 60.7k photos on the `/corex` "live-testing" copy: median 127 KB, p90 390 KB (from a helper's read-only listing).

### 1b. What the database says (more representative of a real agency)

DB-recorded size vs what is actually on disk (Staging / QA1):

| Table | Rows | DB-recorded GB | On disk (Staging / QA1) |
|---|---:|---:|---|
| documents (deal, contact, e-sign filings, FICA copies) | ~3,575 | 2.82 | 1.68 / 1.80 GB (52% / 55% of rows present) |
| fica_documents | ~860 | 0.53 | 0 / 0.26 GB |
| communication_attachments (encrypted) | 5,389 | 1.73 | 0.08 / 0.44 GB |
| shared_drive_files | 125 | 0.26 | 0 / 0.26 GB |
| user_documents (HR) | 83 | 0.05 | ~0 |
| market_reports | 435 | – | 0.0 / 0.11 GB |

Staging property photos: `properties.images_json` etc. reference 34,659 distinct paths; only 20,053 exist on disk (14,606 missing in this copy).

### 1c. Real growth per month (DB created_at, agency 1)

| Month | `documents` rows / GB | FICA uploads / MB | Comms attachments GB | New listings with photos |
|---|---|---|---|---|
| Jun 2026 | 1,114 / 1.15 | 96 / 62 | – | 4,375 (bulk P24 import) |
| Jul | 800 / 0.65 | 221 / 155 | 0.65 | 63 |
| Aug | 814 / 0.52 | 174 / 111 | 0.67 | 40 |
| Sep | 537 / 0.36 | 164 / 69 | 0.46 | 35 |
| Oct (to 23rd) | 330 / 0.22 | 86 / 50 | 0.01 | 21 |

Steady-state for one HFC-sized agency (excluding the Jun import): documents ≈ 0.4 GB/month, FICA ≈ 0.1 GB/month, comms attachments ≈ 0.6 GB/month, new-listing photos ≈ 0.1 GB/month (≈40 listings × ~2 MB).
**The PDF splitter is the biggest grower**: 11 GB on the live-testing copy since it started on 25 Jun (≈2.7 GB/month), 8.7 GB on QA1 (testing-heavy).

### 1d. Scanned PDFs

Method: for every PDF (excl. splitter temp), `pdfinfo` pages + `pdftotext` characters + `pdfimages -list` on the first 3 pages. "Scan" = has images and < 80 text chars per page.

| | PDFs | Scans (image-only) | Share | Scan GB | Avg size per scanned page |
|---|---:|---:|---:|---:|---:|
| QA1 | 4,081 (4.52 GB) | 2,974 | 73% | 3.49 | **200 KB** (17,427 pages) |
| Staging | 3,405 (3.22 GB) | 1,934 | 57% | 2.23 | **211 KB** (10,568 pages) |

Other classes (QA1): mixed text+image 479 files / 0.73 GB (351 KB/page); true text/vector 204 files / 0.03 GB (49 KB/page); **424 files (0.27 GB) unreadable because they are media-encrypted** (FICA).
Where the scans sit: filed property documents (mandates, disclosures: `properties/{id}/files`) 1,336–1,701 files ≈ 1.4–1.6 GB; splitter originals + outputs ≈ 1.9 GB (QA1); rental application docs; viewing packs; deal documents.
Embedded image formats seen: most are **already 1-bit CCITT G4 at 200 or 300 ppi** (≈50 KB per A4 page); the heavier ones are 200 ppi colour/greyscale JPEG.

### 1e. Exact duplicates (I computed SHA-256 of every file whose size collides with another; no hash is stored for most files)

| | Duplicate groups | Redundant copies | Redundant GB | Biggest contributors |
|---|---:|---:|---:|---|
| Staging | 16,067 | 24,838 | **5.40** | property photos 4.38 GB (22,552 copies), splitter temp 0.52, deal/sales docs 0.27 |
| QA1 | 5,674 | 10,041 | **5.08** | splitter temp renders 3.17 GB, property photos 1.24, splitter originals 0.41 |

Also on Staging: **5,477 property photo folders (22,172 photos, 4.2 GB) belong to property IDs that no longer exist in the properties table** — orphan candidates (not yet verified one by one; overlaps with the duplicate figure above, not netted).
Property folders for agency 1: 4,279 properties, 8.67 GB photos + 0.68 GB stored thumbs + 1.76 GB filed documents ⇒ **≈ 2.0 MB per listing photo set**, thumbs 0.16 MB.

---------------------------------------------------------------------------------------------------------
## 2. HOW UPLOADS ARE HANDLED TODAY (summary; full file:line detail in Appendix A)

| Upload | Resized/compressed? | Original kept? | Disk | Hash stored? |
|---|---|---|---|---|
| Property gallery (`PropertyImageStorer.php:23-25,147-193`) | Capped 2560 px, JPEG q85 via GD — **only if larger or not JPEG**; a small JPEG is stored byte-identical (EXIF/GPS kept) | No — the true original is never kept | public (web-served) | No (P24 "signature" is md5 of the path list, not file bytes) |
| Property thumbs (`PropertyThumbnailService`) | 500 px q80, generated **once at upload**, stored in `thumbs/` | – | public | – |
| P24/portal imports (`DownloadP24RowImagesJob`) | Raw bytes, no resize, no thumb in that job | raw is the only copy | public | md5 dedupe within a run only on one importer |
| Rental inspection photos (`PrivateImageStore.php:104-157`) | Always re-encoded: 2560 px, q85, EXIF stripped (second pipeline) | No | local (private), signed URLs | No |
| Inspection portal copies (`PortalImageService`) | 480 px / 1600 px copies generated on first view, **stored, never cleaned** | – | local | – |
| Site-form scans | None | Yes (raw) | local | No |
| FICA documents (`FicaDocumentStorage`) | None; HEIC stored as HEIC | The only copy | local, **encrypted** | **No** |
| FICA "filing" | Copies each file again into `contact-documents/` (re-encrypted, different bytes) | – | local | – |
| Contact / deal / HR / quote docs | None (size/type limits only) | Yes | mostly local; some on **public** | No |
| DocuPerfect e-sign | `client_signed.pdf` + `final_signed.pdf` (two renders of the same content) + per-pack PDFs + lossless flattened page PNGs | Yes | local | sha256 of field data only — **not of the signed PDF** |
| PDF splitter | 200 dpi page PNGs in tmp; originals, outputs, zips all kept; a 3rd copy on filing | Yes | local (`private/private/splitter`) | No |
| Communications media | None | Yes, write-once | local, **encrypted** | sha256 of plaintext (also dedupes) |
| Market reports / SG docs / presentation uploads | None | Yes | local | sha256 with dedupe |

Key facts
- **Tooling:** GD for nearly everything; Imagick only for HEIC; Poppler for PDF rasterising; qpdf for split/merge. Ghostscript is used only by the user-run "Compress PDF" tool — **no stored upload is ever compressed**. No Intervention/Spatie image package.
- **S3 disk:** defined but unusable: AWS credentials empty in all `.env` files, `league/flysystem-aws-s3-v3` is not installed, no `Storage::disk('s3')` anywhere. Much code uses `Storage::disk('local')->path()` and would break on an object-storage disk.
- **Media Encryption (AT-173):** AES-256-GCM, whole-file in memory, `CXE1` envelope, key in `.env` (`MEDIA_ENCRYPTION_KEY`; auto-on whenever a key exists). Covers **only** communications media and FICA documents (plus the filed FICA copy). Effects: ciphertext cannot be compressed or deduplicated afterwards; two copies of one FICA file never match by hash; any optimisation must go decrypt → optimise → re-encrypt through `MediaCipher`. Comms are the exception — path is sha256 of the plaintext, so duplicates are caught at write time. A `media:rotate-key` command is mentioned in comments but does not exist.
- **Thumbnails:** property photos — generated once, stored. Inspection/portal photos — generated on first view, stored, never cleaned. Orphan thumbs are left behind when a photo is deleted on the web path.
- **Cleanup jobs:** only three scheduled jobs remove files (AI template reference images 7 days, scheduled-post images, embargoed comms). Nothing removes splitter tmp/originals/outputs, pdf-suite in/out, `storage/app/temp`, derived portal copies, flattened e-sign PNGs, or imports. `db:purge-soft-deleted` is unscheduled and DB-only.
- **Upload limits on Staging/QA1:** PHP 8.2 60 MB/file, 20 files/request; nginx 64 MB. Several validators have only `file|max` and no type allow-list (deal docs, onboarding, screening, mobile compliance).
- **Offsite backup:** nightly restic to a Hetzner Storage Box covers `/corex/storage/app`, `/corex-staging/storage/app` and `corex-storage`; excludes temp and imports. The FICA encryption key has its own backup folder (`at173-KEY-BACKUP`) on the data volume — **losing that key means losing every encrypted FICA file and comms attachment.**

---------------------------------------------------------------------------------------------------------
## 3. PHOTOS ACROSS SITES (full detail in Appendix B)

- **hfcoastal.co.za keeps its OWN copy.** `corex:warm-images` (cron every 10 min) downloads every CoreX listing photo, trims white borders, re-encodes JPEG q85 and stores it forever at `/var/www/home-finders-coastal/storage/app/private/trimmed`, then serves visitors from that copy (480/960/1600 px variants). **19,477 files, 6.35 GB, on `/` (the 38 GB disk, 81% used, 7 GB free).** No pruning; withdrawn listings stay; it grows until the backlog clears. Proven duplicate of CoreX photos: 10,169 files / 3.85 GB (verified by hashing the source URL); none are byte-identical because they are re-encoded; 2,664 more (1.66 GB) are probably newer than this box's snapshot.
- **corexweb.co.za and performance.hfcoastal.co.za:** marketing images only (0.7 MB / 2.8 MB), no CoreX photos.
- **themandatecompany.co.za:** 228 legacy images (50 MB), 57 byte-identical to CoreX files; its DB holds 0 listings — nothing synced.
- **Inside CoreX:** Staging, QA1, QA2 and the live-testing copy each hold their own full copy of `properties/` (15.3 / 7.9 / 6.2 / 14.1 GB). That is test-environment duplication, not production.
- **Portals:**
  - **Property24 receives file bytes** (base64 in the JSON; `Property24ListingMapper.php:1177-1184`). Cap 60 MB of image data per submit (57 MB budget); if over, each photo is re-encoded at JPEG q80→40 and scaled down to 25%. Up to 150 photos per agency. Photos are re-sent only when the gallery changed. No per-image pixel/format rule in code or spec — P24's own per-image limits: **needs verification**.
  - **Private Property receives URLs** and fetches them itself (`PrivatePropertyListingMapper.php:156-159`); one 404 fails the whole listing update, so originals must stay publicly reachable over HTTPS at a stable URL. Min 3 photos (sale) / 1 (rental), cap 150. PP's own photo limits: **needs verification**. The spec says "first 20" photos while the code allows 150 — spec and code disagree.
  - Consequence: any move of photos to another host/CDN or an AVIF/WebP-only store must keep the JPEG file on the CoreX disk (for P24) and a public URL (for PP).
- nginx serves `/storage/*` straight from disk through the `public/storage` symlink with no PHP and no login.

---------------------------------------------------------------------------------------------------------
## 4. SAMPLE SAVINGS (20 real files of each kind, copied to scratch; originals untouched)

### 4a. Photos — resize to 2560 px long edge, WebP q80 and AVIF q50 (ImageMagick 6.9.12, libwebp 1.3.2, libheif/AV1 1.17.6; JPEG re-encode q85 shown as the "do what the app already does" baseline)

Source: Staging `properties/{id}/*.jpg`. Set A = 20 random photos; Set B = 20 random photos over 1 MB (28% of all photo bytes live in files over 1 MB). Quality measured as PSNR vs a 2560 px decode of the original (≥37 dB = very good; 33–37 dB = good, small visible loss on close inspection; PSNR is a rough proxy).

| Set | Original | JPEG q85 | WebP q80 | AVIF q50 |
|---|---|---|---|---|
| A random 20 (median 182 KB) | 4.4 MB | **+12.6% bigger**, 0.05 s | **−29.9%**, 0.26 s, 37.1 dB | **−53.0%**, 0.57 s, 36.6 dB |
| B 20 files >1 MB (median 2,035 KB; dims 2592–8160 px) | 61.1 MB | −77.7%, 0.26 s, 40.6 dB | **−90.1%**, 0.80 s, 37.3 dB | **−94.0%**, 0.82 s, 36.4 dB |

Reading it: ordinary photos are already small (JPEG q85 ≤2560 px — re-encoding makes them *bigger*). The big win is the over-1 MB band: these are photos above 2560 px (4000×3000, 8160×6120) that escaped the app's 2560 cap (older uploads / imports, and the cap leaves small JPEGs alone). Extrapolated to Staging's 15.3 GB (low confidence, n=20 per set): JPEG cap on big files ≈ −3.3 GB (22%); WebP on everything ≈ −7.2 GB (47%); AVIF on everything ≈ −9.9 GB (65%). AVIF/WebP cannot be the *only* copy while P24 gets bytes and PP gets URLs unless a JPEG is regenerated on demand.

### 4b. Scanned PDFs — Ghostscript 10.02.1 / qpdf at 200 dpi, JBIG2, PDF/A-2b (20 random scanned PDFs: 30.6 MB, 193 pages, 159 KB/page average)

| Variant | Total | Saved | s/file | Notes |
|---|---:|---:|---:|---|
| qpdf lossless (object streams, flate recompress) | 30.0 MB | **1.9%** | 0.03 | Nothing got bigger; scans have little to squeeze |
| Ghostscript 200 dpi, colour kept | 25.3 MB | **17.3%** | 0.28 | 7 of 20 files shrank >20%; **13 of 20 got slightly bigger** (already ≤200 dpi) |
| Ghostscript 200 dpi, greyscale | 18.5 MB | **39.6%** | 1.39 (max 8.3) | 11 of 20 shrank >20%; 7 got slightly bigger |
| Ghostscript 200 dpi + PDF/A-2b | 25.4 MB | 17.1% | 0.29 | File carries the PDF/A-2b markers; **not validated** (no veraPDF here) |
| JBIG2 | – | – | – | **Not available**: `jbig2enc` is not installed and Ghostscript cannot write JBIG2 |

All 193 pages survived in every variant. Where the savings came from: 300 dpi 1-bit scans down-sampled to 200 dpi (e.g. 276→103 KB, 5,108→1,330 KB, −60 to −74%), and colour/greyscale scans at 200–300 ppi. Scans that are already 200 dpi 1-bit CCITT are essentially at the floor (≈50 KB/page).
Legibility: page 1 rendered to PNG before and after for a 300 dpi bilevel mandate and a colour rental-application page. Both stay fully readable (typed text, handwriting, stamps, logos). The bilevel sub-sampled version is visibly rougher on fine strokes and small print; greyscale loses only colour. (Automatic PSNR on text pages was meaningless at 70 dpi, so this was judged by eye on two files, not 20 — **a person should look at more before any bulk run, especially ID documents and handwriting.**)

---------------------------------------------------------------------------------------------------------
## 5. PROJECTION

Unit: **one agency-year of use**. Assumptions (measured where possible):
- Sales-type agency like HFC (from §1c): new-listing photos ≈ 1.5 GB/yr, documents ≈ 5 GB/yr, FICA ≈ 1.2 GB/yr, comms attachments ≈ 7 GB/yr, PDF-splitter clutter ≈ 30 GB/yr (extrapolated from 11 GB in ~4 months on the live-testing copy — **the least certain and biggest line**) ⇒ **≈ 45 GB/yr today**.
- Optimised = splitter tmp deleted after 14 days (keep originals + outputs ≈ 3 GB/yr), exact-duplicate and orphan clean-up, photos −35%, scanned documents −25% (only for unsigned scans; **signed PDFs untouched**), comms/FICA unchanged (encrypted — conservative) ⇒ **≈ 16 GB/yr**.
- Rentals add-on, per 300 rental properties (my assumption for a rentals-led agency; adjust to the Cape Town agency's real count): spec model = 200 inspection photos/property-year × 348 KB ≈ 70 MB ⇒ **≈ 21 GB/yr today**, ≈ 10 GB/yr with AVIF (−53% on typical photos; 2560 px kept).

| Agencies | Sales-type today | Sales-type optimised | Rentals add-on today | Rentals add-on optimised |
|---|---:|---:|---:|---:|
| 5 | 225 GB/yr | 80 GB/yr | 105 GB/yr | 53 GB/yr |
| 20 | 900 GB/yr | 320 GB/yr | 420 GB/yr | 210 GB/yr |
| 100 | 4.5 TB/yr | 1.6 TB/yr | 2.1 TB/yr | 1.05 TB/yr |
| 100 agencies, **5 years** (FICA retention) | ~22 TB | ~8 TB | ~10.5 TB | ~5.3 TB |

Today the data volume is 197 GB, 85% used, **29 GB free**. The PDF splitter alone holds ≈ 21 GB on this box (QA1 8.7 + Staging 1.4 + live-testing 11), of which ≈ 16 GB is regenerable page renders (6.5 + 1.1 + 8.1).

List prices to price it (**all from memory — NEEDS VERIFICATION against Hetzner's current price list before anyone relies on them**):
- Hetzner Cloud Volume (block storage, what we use now): ≈ €0.044 per GB-month ≈ €44 per TB-month; max 10 TB per volume.
- Hetzner Object Storage (S3-compatible): ≈ €6 per month including 1 TB stored and 1 TB egress; extra storage ≈ €6 per TB-month.
- Hetzner Storage Box (what restic backups use): roughly €3–4 per TB-month for 1–5 TB plans.
Illustration (volume pricing, sales-type only): 100 agencies year 1 ≈ €200/month today vs ≈ €70/month optimised; 5-year stock ≈ €1,000/month vs ≈ €350/month. On object storage the optimised 8 TB would be ≈ €50/month. **The money is small; the real constraints are the 10 TB/volume cap, the 29 GB headroom now, and that S3 is not wired up in the code.**

---------------------------------------------------------------------------------------------------------
## 6. LEGAL AND INTEGRITY POINTS FOR JOHAN TO RULE ON
(I am not a lawyer; the statute references below are what the specs/notes say or what I recall and need an attorney's confirmation.)

1. **Raw original or optimised copy only?**
   - Today: property photos and inspection photos already keep *only* the processed copy (original discarded). FICA documents and scans are stored exactly as uploaded (original only).
   - Option: keep the optimised copy as the working file and record the original's SHA-256 (and size) in the database. Open question: is a re-compressed scan still an acceptable "true copy" of an ID or proof of address for FICA/PPRA purposes? That is a legal call, not an engineering one.
   - Practical middle path to rule on: never recompress ID documents, FICA documents, mandates or anything signed; optimise only working scans, splitter intermediates and photos.
2. **Signed e-sign PDFs must never be altered.** Today nothing alters a PDF after signing (flattening happens on page images *before* the PDF is made). But: no SHA-256 of the signed PDF is stored (only a hash of the field data), and each signing leaves two near-identical PDFs plus lossless flattened PNGs plus per-pack PDFs. Ruling needed: (a) the signed PDFs are write-once and exempt from every optimiser (recommended), (b) store the PDF's SHA-256 at the moment it is created so tampering is detectable, (c) whether the PNG working pages may be compressed or archived once all parties have signed.
3. **Retention end versus "never lose anything" versus POPIA.**
   - Specs already say: FICA = 5 years (implied), communications/contacts = 5 years, e-sign documents = 7 years, inspection photos = "never deleted".
   - POPIA limits keeping personal information longer than needed (a legal retention duty is a valid reason to keep it until that duty ends, not beyond).
   - Today no code defines "relationship ended", nothing deletes any FICA/deal/contact file, and non-negotiable #1 (no hard deletes) forbids it. Ruling needed: for each class, the retention clock, what happens at the end (delete? anonymise? archive?), and a written exception to "no hard deletes" for end-of-retention purges — plus who approves each purge.
   - "Never lose anything" should be restated as "never lose anything *before* its retention end, and never keep it silently after".
4. **Moving old closed files to cheaper archive storage.** Feasible later, not today: the S3 driver package and credentials are missing and code assumes local paths. Rulings needed: which classes qualify (suggest closed deals/leases older than N years and FICA after the relationship has ended), whether archived items may be slower to open, where it is hosted (Hetzner — South Africa data-residency/cross-border position under POPIA s72 should be confirmed), and that encrypted FICA stays encrypted with the key kept separately and backed up.

### Other findings worth a ruling (report only)
- **Documents on the web-served disk.** Filed mandate/disclosure PDFs under `properties/{id}/files/` (1.8 GB on Staging), PDF-splitter contact filings, and staff take-on documents are written to the *public* disk. nginx serves that with no login. File names contain a random prefix but they are not behind access control.
- **The splitter keeps everything forever** (≈ 2.7 GB/month), and the splitter page images are regenerable — the single largest safe saving.
- HFC website cache is unbounded and on the nearly-full `/` disk.
- Spec/code mismatch: Private Property "first 20 photos" vs code cap 150.
- Staging/QA1 are partial copies, so the real live completeness (are all files for all DB rows present?) is unknown from here.

---------------------------------------------------------------------------------------------------------
## What I read outside QA1/Staging (disclosure)
You asked for QA1 and Staging only. Two helper agents also looked, read-only, at: (a) `/corex` on this server — that is the **live-testing copy** (`live-testing.corexos.co.za`, php8.3), not production — for directory sizes and to sample the first bytes of up to 400 files per folder to see whether they carry the encryption header (no file content was copied or opened as a document); (b) the HFC and other websites under `/var/www` (file listings, sizes, config, hashing of image bytes to prove duplication, one SQLite SELECT). Production at 62.238.31.82 was not touched. Nothing was changed anywhere; the scratch copies of 40 sample files were deleted.

Raw output, scripts and helper reports are appended below.

---------------------------------------------------------------------------------------------------------
## APPENDIX A — upload code paths (helper report, file:line detail)

# CoreX upload handling today: code-path audit for storage optimisation (read-only)

Audited: /corex-staging (branch Staging, HEAD 7d4837ad7). QA1 (/corex-qa1) and /corex were only sampled for config and on-disk state.
Nothing in any repo was changed. No tests were run. Paths are relative to /corex-staging unless stated.
Date of audit: 2026-10-24.

NOTE ON HOST: this server (hostname ubuntu-4gb-nbg1-2, 91.99.130.85) hosts /corex-staging (APP_ENV=staging, DB hfc_staging), /corex-qa1
(APP_ENV=qa, DB corex_qa1) AND /corex (APP_ENV=production, APP_URL=https://corexos.co.za, DB nexus_os, branch `main`). CLAUDE.md says live is
62.238.31.82; the sizes quoted below for /corex are from THIS box. That mismatch is worth a human check but is outside this audit.

----------------------------------------------------------------------------------------------------------------------------------------
## 0. Cross-cutting facts (read first)

Disks (config/filesystems.php:19-84)
- `local`  = storage/app/private (root line 25). FILESYSTEM_DISK=local in staging, QA1 and /corex .env. This is the default disk for any `->store()` with no disk argument.
- `public` = storage/app/public, served via public/storage symlink (lines 33-39).
- `data_volume` = {DATA_VOLUME_ROOT=/mnt/HC_Volume_103099143}/corex-data-volume (line 76-82). Used by ONE class only: RentalApplicationPdfService::CACHE_DISK (app/Services/RentalApplications/RentalApplicationPdfService.php:51).
- `s3` is defined (line 50-61) but see section B: not usable.
- On the box, several dirs under /corex/storage/app/private are SYMLINKS to /mnt/HC_Volume_103099143/corex-storage/app/private/* (contact-documents, docuperfect,
  document_library, feedback, image-converter, knowledge-documents, listings-imports, market-reports, pdf-suite, portal_captures, presentations, sales-documents).
  `du -x` on /corex/storage/app does NOT follow them, so sizes must be added from both places (section 12).

Image tooling actually in use
- GD only for almost everything (`imagecreatefromstring`, `imagecopyresampled`, `imagejpeg`, `imagepng`, `imagewebp`). Comment in app/Services/Images/PortalImageService.php:19-20
  and PropertyThumbnailService.php:20-22 says the queue/CLI PHP has GD but not Imagick.
- Imagick is used in: PrivateImageStore.php:149-157 (HEIC fallback, q85), HeicConverter.php:62-78 (HEIC to JPEG), RentalInspectionScanReaderService.php (in-memory OMR rasterise, 200 DPI),
  Tools/PdfSuiteController.php (user tools), Tools/PdfSplitterController.php:2154 (fallback crop).
- Poppler `pdftoppm` is used to rasterise PDFs (DocumentFlattener.php:458-466 -r 200; WebTemplatePdfService.php:414 -r 200; PlatformEsign/EsignService.php:263 -r 110;
  RentalApplicationDocumentHighlightService; ViewingPackRedactionService.php:183 per-agency DPI 72-300 default 150; PdfSplitterController.php:~2096 200 DPI).
- `qpdf` for split/merge (PdfSplitterController, PdfSuiteController). `pdfunite` configured (config/splitter.php).
- Ghostscript: ONLY in Tools/PdfSuiteController.php:44-47 (user-run "Compress PDF" tool; output is streamed back, not stored in a record) and
  WebTemplatePdfService.php:455-470 (Windows-only fallback for page PNGs, -r200). No Ghostscript pass is applied to any stored upload.
- Intervention Image: NOT FOUND (composer.json has barryvdh/laravel-dompdf only; no intervention/image, no spatie/image).
- Chromium via Puppeteer: scripts/html-to-pdf.mjs (A4, printBackground, margin 0, preferCSSPageSize) via SigningController::generatePdfFromHtml (app/Http/Controllers/Docuperfect/SigningController.php:3126). Not compressed.
- dompdf (barryvdh) for inspection report PDFs, ID-photo PDFs, platform e-sign sealed PDFs.
- ffmpeg: referenced in a spec (voice-note transcription) but no image/video/audio re-encode call found in app/.

Hash columns that exist (DB column : what it hashes)
- communications.content_hash / communication_attachments.content_hash : sha256 of PLAINTEXT bytes (migrations 2026_06_26_000001/000002). Also drives content-addressed path = dedupe.
- market_reports.file_hash : sha256 of uploaded PDF, UNIQUE(agency_id,file_hash) = dedupe (2026_05_21_120003).
- property_sg_documents.sha256 : sha256 per SG file, dedupe by sha+property (SgSearchService.php:249-254).
- presentation_uploads.content_hash : hash of upload bytes (UploadProcessor.php:~35).
- portal_captures.dom_hash_sha256 : sha256 of captured HTML (PortalCaptureController).
- platform_esign_documents.document_hash (sha256 of sealed PDF, EsignService.php:~808), .content_hash (what signers were shown), platform_esign wetink files .sha256.
- rental_inspection_forms.content_hash : sha256 of form layout inputs, makes generate() idempotent (RentalInspectionFormPdfService.php:132).
- signature_templates.document_hash / signature_audit_log.document_hash / esign_consent_log.document_hash : sha256 of fields_json or HTML, NOT of any PDF/file (SignatureService.php:255-258; SigningController.php:941).
- document_sealed_versions.content_hash : sha256 chain over sealed HTML (DocumentSealedVersion.php:89).
- properties.p24_image_signature (varchar 64) : md5 of json(syndicationImages + gallery_categories_json). PATH LIST ONLY, no file bytes (Property.php:3615-3619). properties.p24_source_image_signature = md5 of inbound P24 URL set (DownloadP24RowImagesJob.php:~300).
- users.p24_photo_signature / p24_profile_signature : agent push gates (migration 2026_07_14_100000).
- NO hash column on: fica_documents, documents (the unified table), user_documents, deal documents, rental_inspection_photos, rental_inspection_scans, work-order photos/invoices/quotes, rental_application documents, shared_drive_files, contact documents.
  => no content-based dedupe is possible for those today without a backfill.

----------------------------------------------------------------------------------------------------------------------------------------
## 1. FICA documents (contacts / agents / entity)

Writers (all funnel through App\Services\Compliance\FicaDocumentStorage)
- app/Services/Compliance/FicaDocumentStorage.php:33 putUploaded(), :42 putBytes(). Disk 'local' (private) constant DISK (line 24). LEGACY_DISK 'public' for pre-AT-173 reads.
- Callers: FicaController.php:1176 (agent upload to fica/{submission}/{random40}.ext; validation line 1159 `file|max:10240|mimes:pdf,jpg,jpeg,png,heic`),
  FicaPublicController.php:253 (public applicant link, same rule line 244, dir fica/{submission}/...),
  FicaWetInkService.php:121 and :95 (fica/wet-ink/{submission}/..., includes copy of splitter output, addStoredDocument()).
- Path pattern: fica/{submission_id}/{Str::random(40)}.{client ext}. Not content-addressed.
- Resize / compress / re-encode: NONE. file_get_contents of the upload is (optionally) AES-GCM encrypted then written byte-for-byte. HEIC is accepted by the rule and stored as HEIC (no conversion).
- Original kept: yes (the only copy). DB row fica_documents: file_path, file_name, file_size, mime_type. No hash. No thumbnail (served through a decrypting stream route, FicaDocumentStorage::stream() :70-). No cache.
- Encryption: yes, when MEDIA_ENCRYPTION is on (section A). On-disk bytes are `CXE1` ciphertext (sampled: /corex fica 400/400 encrypted; QA1 391/393; staging 5/7).
- DUPLICATE COPY on filing: FicaController.php:1414-1437 copies every FicaDocument into contact-documents/{contactId}/{uuid}.ext (disk 'local', encrypted again with a NEW nonce) and creates a `documents` row (source_type='fica').
  So each FICA file exists twice on disk, and because it is re-encrypted with a random nonce the two ciphertexts are never byte-identical (no after-the-fact dedupe possible).
- Other FICA-adjacent files: FicaCompletionReportService.php:59-88 generates a PDF (Chromium) and Storage::disk('local')->put; PdfSplitter can file split PDFs to FICA via FicaWetInkService::addStoredDocument (copy, not link; the link-only variant is `linkContactDocument`, AT-361).
- Legacy: /corex/storage/app/public/fica 572K and private/fica-legacy-orphans 15M remain.

Contact documents (Drive tab) and agent/HR identity docs are in sections 2 and 8.

----------------------------------------------------------------------------------------------------------------------------------------
## 2. Contact / property documents and Shared Drive (general document uploads)

- ContactDocumentController.php:33-37 (rule via ValidatesDocumentUploads::documentUploadRule(20480), mimes list in app/Http/Controllers/Concerns/ValidatesDocumentUploads.php:19-27 incl. zip, heic, tiff, bmp).
  Disk 'local', path contact-documents/{contact}/{uuid}.ext. No resize, original kept, `documents` row (original_name, storage_path, disk, mime_type, size) no hash. Not encrypted (only source_type='fica' is).
- Api/MobileContactComplianceController.php:181 same path, `file|max:20480` with NO mimes rule.
- CoreX/PropertyFileController.php:60 : properties/{id}/files on 'local' (private). BUT CoreX/PropertyController.php:1597 : `properties/{id}/files` on 'public' (world-addressable path). Two different disks for the same logical "property files".
- DocumentLibraryController.php:85 (`document_library/...` local), SharedDriveService.php:156-163 (shared_drive/{agency}/{Y}/{m}/{slug}-{rand8}.ext local; allow-list SharedDriveService.php:30-47; DB shared_drive_files: stored_path, bytes; no hash).
- PDF Splitter filing (Tools/PdfSplitterController.php): writes to the PUBLIC disk for contact filing (:786-834, dir contacts/{id}/files, disk 'public'); see section 11 for its tmp/originals.
- Delete: soft delete only at DB layer; SharedDriveService::deleteFolderRecursive keeps physical files (line ~190 docblock "Physical files on disk are KEPT"). Document model has no deleting hook that unlinks.

----------------------------------------------------------------------------------------------------------------------------------------
## 3. Deal documents

- Dr2/DealDocumentController.php:31-34 : validation `file|max:20480` (no mimes), disk 'local', path deal-documents/{agency}/{deal}/{hash}.ext. Then DealDocumentService::fileDealDocumentFromDeal creates the `documents` row (source_type='deal'). No resize, original kept, no hash. Not encrypted.
- DealV2/DealV2Controller.php:466-470 (storeDocument, `file|max:10240`), DealV2/DealStepController.php:91 and :181 (step uploads, max 10240): disk = config('filesystems.default') = 'local' on every env; path deals/{deal}/documents or deals/{deal}/steps/{step}/. Row via DealDocumentService::createDealDocument (app/Services/DealV2/DealDocumentService.php:49-130). source_type deal_upload / deal_step. No mimes rule, no hash, no encryption.
- DealDocumentService links by path (no file copy) to deal twin/property/contacts: `file_path => $doc->storage_path` (lines 337, 351) = reference only.
- Generated deal PDFs: DealV2/CocRequestGenerator.php:85 and WorkAuthorisationGenerator.php:121 (Storage::disk($disk)->put of dompdf output; no hash).
- Disk note: DealV2 uses config('filesystems.default') so it would silently follow FILESYSTEM_DISK=s3 if ever set (section B).

----------------------------------------------------------------------------------------------------------------------------------------
## 4. DocuPerfect / e-sign (originals, signed PDFs, flattening, certificate)

Two separate e-sign systems exist: (a) DocuPerfect agency e-sign (app/Services/Docuperfect, tables signature_templates, docuperfect_documents), (b) Platform E-Sign for the CoreX subscription agreement (app/Services/PlatformEsign).

### 4a. DocuPerfect originals / templates
- Template PDF/page images: TemplateController.php:430 `Storage::put("docuperfect/templates/{id}/page-{i}.png")` (default disk = local) from client-rendered base64 PNGs. No server-side compression (PNG as sent).
  Copying a template duplicates the directory (TemplateController.php:493-494).
- Web (HTML) templates flattened to page images for the signing UI: WebTemplatePdfService.php:93 `docuperfect/documents/{docId}/page-{n}.png`, pdftoppm -png -r 200 (line 414), one PNG per page.
- Uploaded documents sent for signing: SignatureController.php:103 (`docuperfect/rental-upload-send`, rule `pdf,doc,docx|max:20480`, line 93); SalesDocumentController.php:95 (`sales-documents`, same rule line 82). Original kept, not re-encoded, no hash.
- Wet-ink uploads: SigningController.php:2679 and SignatureController.php:2717, ESignWizardController.php:8475, SignatureService.php:4854: `docuperfect/wet-ink-uploads/{request}` local; rule `pdf,jpg,jpeg,png|max:20480`; stored raw; path list JSON-encoded in signature_requests.wet_ink_upload_path.
  Supporting docs: SigningController.php:2783 `docuperfect/supporting-documents/{request}` (max 10 files, 15 MB each, line 2775-2776). Pre-signed: SignatureController.php:401. Sales returns: SalesDocumentController.php:393/448/608.
- Importer: DocumentImporterController.php:181 `$file->move()`; PDF to page images via pdftoppm.

### 4b. Where signed PDFs are written and what is done to them
- Primary writer: app/Services/Docuperfect/SignaturePdfService.php. generate() writes TWO files per signature_template under disk 'local':
  `docuperfect/signed-documents/{template_id}/client_signed.pdf` (no certificate) and `docuperfect/signed-documents/{template_id}/final_signed.pdf` (document + audit certificate appended).
  Lines 62-64 and 158-160 (paths), 66-82 and 135-170 (rename of temp output into place). For web templates both are Chromium renders of the same HTML; the internal copy is the same HTML + a page-break + the `docuperfect.signatures.pdf.audit-certificate` Blade view (lines ~125-140).
  => the document body is stored twice per signed document (internal = client + certificate pages). Client copy has the "Schedule of Amendments" appendix stripped (stripAmendmentSchedule, line ~175).
- DB columns: signature_templates.signed_pdf_path (internal) and signed_pdf_client_path (client) (SignatureService.php:4046-4057, 4228-4239).
- Rendering route used: page-image templates use dompdf over flattened page PNGs (renderPdf); web templates use Chromium (SigningController::generatePdfFromHtml; temp files in storage/app/temp/doc_{id}_{ts}.html|pdf :3126-3140, html unlinked after, pdf renamed into final location; failed runs can leave temp PDFs).
- Pack documents: SignatureService.php:4558-4620 creates per-member PDFs `docuperfect/signed-documents/{template}/individual/{tplId}_client.pdf` in addition to the merged client/internal PDFs (third and further copies of the same content).
- Filing: SignatureService::fileSingleDocument (SignatureService.php:4423-4503) creates a `documents` row (source_type='esign') that REFERENCES the same path (no copy). Disk 'local'. No encryption (sampled /corex docuperfect/signed-documents 0/400 encrypted).
- Post-sign alteration: there is no stamping/flatten step applied to the final PDF after generation. Alteration happens BEFORE PDF generation, on page images, in DocumentFlattener:
  - flattenFields / flattenSignerFields / flattenSignature (DocumentFlattener.php:23, 146, 248) draw typed text, ticks, strikethrough and signature images onto page PNGs with GD.
  - saveFlattenedPage (DocumentFlattener.php:996-1013) writes `docuperfect/signed-documents/{template}/flattened/page_{n}.png` as PNG compression level 6 (lossless), overwriting the same page file at each signature; paths tracked in signature_templates.flattened_pages_json.
  - flattenWetInkScan (DocumentFlattener.php:374-430) replaces flattened pages with rasterised wet-ink scans (pdftoppm 200 DPI, PNG).
  The flattened PNG set (lossless) is therefore a large third artefact next to the two PDFs; sizes on /corex: docuperfect/signed-documents = 794M, docuperfect/templates = 108M (section 12).
- Certificate appending: the audit certificate is appended only to final_signed.pdf (HTML concatenation before Chromium render, SignaturePdfService.php ~line 125-140). No PDF-level merge (no qpdf/FPDI on this path).
- Hashes: signature_templates.document_hash = sha256 of fields_json (SignatureService.php:255-258), NOT of the PDF. No sha256 of final_signed.pdf / client_signed.pdf is stored. document_sealed_versions stores full sealed HTML per signing hop in the DB (hash-chained, DocumentSealService.php:36-90) = additional storage in MySQL.
  signatures.signature_data is a longText base64 image in the DB (migration 2026_02_26_600004).
- Dedupe: none for the PDFs; only a path-based "already filed" check (SignatureService.php:4435, 4586).
- Thumbnails: page PNGs are served as-is by PageImageController (app/Http/Controllers/Docuperfect/PageImageController.php:31-75); no resize/cache layer found.
- Cleanup: PruneAbandonedCdsDrafts soft-deletes idle CDS builder drafts (DB only). No job removes flattened PNGs / docuperfect/documents/{id}/page-*.png after completion (not found).

### 4c. Platform E-Sign (subscription agreement)
- Source PDF: PlatformEsign/EsignService.php:202 `platform-esign/templates/{id}/v{ts}{rand}/source.pdf`, pages rasterised with pdftoppm -r 110 -png (line 263) to p{n}.png; max 60 pages (line 277). Disk 'local' (const line 32).
- Sealed PDF: EsignService.php:803-808 `platform-esign/documents/{id}/signed-{id}.pdf` built by SealService (dompdf, SealService.php:54), sha256 stored in platform_esign_documents.document_hash; content_hash set at send; wet-ink files in platform_esign_wetink_files with sha256 (EsignService/AgreementService.php:849).
- Superseded template files are MOVED not deleted (EsignService.php:230-246).

----------------------------------------------------------------------------------------------------------------------------------------
## 5. Rental inspections, inspection photos, site-form scans

### 5a. Inspection photos (rental_inspection_photos)
- Staff/mobile capture: CoreX/RentalInspectionRecordingController.php:983 (single, `jpg,jpeg,png,webp,heic,heif|max:51200`), :1074-1075 (batch up to 10, 51200 KB each); stored via PrivateImageStore::store() (line 1018, 1174).
- app/Services/Images/PrivateImageStore.php:48 : disk 'local' via PrivateImageService::DISK; path `private-images/rental-inspections/{agency}/{inspection}/{uuid}.jpg`; DB storage_path holds `private:<path>` (+ file_size_bytes = the UPLOAD size, not the stored size).
  Pipeline (cleanJpegBytes :104-157): EXIF orientation baked (ImageOrientationNormalizer, JPEG q92 rewrite only when needed) -> GD decode -> cap longest edge 2560 px -> imagejpeg quality 85 -> bytes. ALWAYS re-encoded to JPEG (drops all EXIF incl. GPS). White fill for transparency.
  HEIC: GD cannot read it, so Imagick fallback (readImageBlob, autoOrient, stripImage, thumbnail to 2560, JPEG q85, :145-165); if neither works HEIC is refused (UnreadableImageException), other undecodable files stored as received (:62-68).
- Original is NOT kept (upload is discarded after re-encode). File-level hash: none. Dedupe: client_idempotency_key (DB column) stops duplicate rows for retried uploads only.
- Thumbnails (portal/tenant/owner and signed-URL views): app/Services/Images/PortalImageService.php: derived on first request, then STORED on the private disk: `private-images/portal-img/{480|1600}/{sha1(src|mtime|edge)[0:24]}.jpg`; thumb 480 px q78, full 1600 px q82; skipped when already small (thumb <=60000 B and <=480 px JPEG; full <=600000 B and <=1600 px JPEG) (lines 27-37, 109-130). No cleanup job for these copies (only PrivatiseFaultPhotos/PrivatiseRentalInspectionPhotos remove the copies of files they move).
- Legacy photos on the public disk: rentals `PrivatiseRentalInspectionPhotos` command moved older ones (command app/Console/Commands/PrivatiseRentalInspectionPhotos.php). RentalDocumentPdfService.php:395-416 re-encodes photos on the fly for PDFs (width 640, q78) into data URIs, nothing stored.
- Reports: RentalInspectionCopiesService.php:49-59 builds the report PDF with dompdf, files it via SignedDocumentDistributionService::fileToProperty (SignedDocumentDistributionService.php:66-95; path `signed-documents/{type}/{id}/{rand20}.pdf`, disk local, Document row source_type per distributable, idempotent per (source_type,source_id)); a temp copy for email goes to sys_get_temp_dir() and is unlinked in finally.
- Form PDFs: RentalInspectionFormPdfService.php:132-157 `properties/{property}/rental-inspection-forms/inspection-{id}-v{n}.pdf` (local), content_hash for idempotence; blank form per version.
- Signatures: RentalInspectionSignature.php:439/464 (`rental-inspection-signatures/{property}`, local, PNG re-encoded via GD) and PrivateScanFile::validatedPng (app/Support/PrivateScanFile.php:105-140).

### 5b. Site form scans (rental_inspection_scans)
- CoreX/RentalInspectionScanController.php:41-54: validation `pdf,jpg,jpeg,png,heic,heif|max:20480`; stored raw at `properties/{property_id}/rental-inspection-scans/{hashName}` on 'local' (no resize, no re-encode, original kept: "the scan on file is what backs up anything we could not read", docblock line 29-31). Files re-routed by QR are `Storage::move`d (line 150).
- Reader (RentalInspectionScanReaderService.php): rasterises IN MEMORY at 200 DPI (RASTER_DPI :59, rasterize :905-925), grayscale, photographs capped at 3000 px long edge (capResolution :930-950) for analysis only; max pages per upload enforced (MAX_PAGES); no derivative written to disk (flushRasterCache). Hash: none. Dedupe: none.
- Other wet-ink scan class: PrivateScanFile::store (app/Support/PrivateScanFile.php:36-52) raw, `local`, name wetink_{random32}.ext, ext from sniffed mime (pdf, jpg, png, heic).

----------------------------------------------------------------------------------------------------------------------------------------
## 6. Property listing photos (marketing gallery) incl. P24 image signature

Upload
- Web: CoreX/PropertyController.php:1457-1458 `gallery_images.*  image|max:204800` (200 MB!), dawn/noon/dusk same; stored via storeImages() (PropertyController.php:3840-3846) -> PropertyImageStorer::storeMany (app/Services/Images/PropertyImageStorer.php:130-140). Wizard: PropertyWizardController.php:390-391.
- Mobile: Api/MobilePropertyController.php:703-736 : `$file->store("properties/{id}", 'public')`, EXIF normalise, downscale(), thumbnail.
- PropertyImageStorer::store (:32) -> storeOnDisk (:51) on disk 'public', path `properties/{id}/{hashName40}.{ext}`:
  1. HEIC/HEIF -> Imagick -> upright JPEG, longest edge 2560, q85 (HeicConverter::convert, HeicConverter.php:62-78); else `$file->store()` raw.
  2. ImageOrientationNormalizer::normalizeInPlace: rewrites JPEG at quality 92 ONLY if EXIF orientation requires rotating (ImageOrientationNormalizer.php:67, 163).
  3. downscale() (:147-193): if longest edge <= 2560 AND file is already JPEG => file untouched (original bytes kept, EXIF kept incl. GPS). Otherwise GD decode, resample to 2560 max edge, imagejpeg q85 written IN PLACE over the same path (so a PNG keeps its `.png` extension but contains JPEG bytes).
  => stored "original" is the 2560 px / q85 version for large files, byte-identical upload for small JPEGs. The true original is never kept.
- Thumbnails: PropertyThumbnailService (:36 maxEdge 500, quality 80 default; :176-221 GD resample; flattens alpha to white) generated ONCE at upload and STORED at `properties/{id}/thumbs/{stem}.jpg`. Served by displayUrl() (:55-73); falls back to original when absent. Backfill: `app/Console/Commands/Properties/GeneratePropertyThumbnails`. 710M of thumbs on /corex.
  Orphan risk: web delete/rotate paths (PropertyController.php:2649, 2747, 3344) delete only the original; PropertyThumbnailService::deleteForUrl is called only from MobilePropertyController.php:799 (thumb delete on web path: not found).
- Rotation: PropertyImageRotator (:50-115) writes a NEW random-named file (GD imagerotate, JPEG q90 / WebP q90 / PNG) and the controller unlinks the old one after the JSON swap (PropertyController.php:3311-3344). Hard unlink of the file (images are the one place files are really deleted).
- Imports from portals: DownloadP24RowImagesJob.php:~256 `Storage::disk('public')->put("{$dir}/{$ordinal}.{$ext}", $body)` raw bytes, no downscale or thumb in this job (P24 CDN images are ~1280x720); DownloadPortalPropertyImages.php:120-148 stores `{dir}/{NNN}_{rand8}.{ext}`, md5 content dedupe within a run, then downscale() + thumbnail; DownloadOtherAgencyStockGalleryJob.php:194 raw put; Importer/P24ImageDownloader.php:23 raw; SoldPropertyImporter.php:500-546 downscale 2560 q85.
- P24 image signature: Property::p24ImageSignature() (app/Models/Property.php:3615) = md5(json(syndicationImages(), gallery_categories_json)). Path list only, NO file read, so it changes on add/delete/reorder/recaption, NOT if the bytes of a path change. Compared in Property24SyndicationService.php:506-507 and Property24ListingMapper.php:194-195; written at :457. Not a content hash and not usable for dedupe.
- P24 payload compression is ON THE FLY, in memory, never stored: Property24ListingMapper.php:21-25 (60 MB payload cap, 57 MB budget), compressImageToBytes :1257-1310 steps scale [1.0,0.8,0.6,0.45,0.33,0.25] x quality 80..40 until it fits; Property24SyndicationService.php:1555-1595 transcodes WebP->JPEG q88 (agent profile pictures).
- Other derivatives: PropertyBrochureService.php:553-579 (JPEG q74, maxW), CoreMatchListPdfService.php:326-348 (q72), ScheduledPostImages.php:200-219 (q90, social posts, purged by marketing:purge-scheduled-images), SellerOutreach/AgentCardImageService (outreach-cards on public disk), CompanyAdsController.php:401-423 (q88).
- Agent profile photo: AgentPhotoNormalizer (:26 SIZE 1200 square crop; WebP q82 stepping down to 60 under MAX_BYTES, plus JPEG twin) at agents/{id}/photo.webp + photo.jpg (public), overwritten in place; AgentProfilePhotoService.php:106 md5 of file used to guard the background-removal job; cutouts stored as PNG next to it (RemoveAgentPhotoBackgroundJob.php:132).
- Other property-photo fields: rental_main_water_valve_photo_path / rental_db_board_photo_path through PropertyImageStorer (PropertyController.php:3148-3154); pp_agent_image(_path) max 1024 KB `store(..., 'public')` (PropertyController.php:1575-1578).
- Size on /corex: storage/app/public/properties = 14G.

----------------------------------------------------------------------------------------------------------------------------------------
## 7. Maintenance / fault / work-order photos and documents

- Fault report photos: RentalFaultReportController.php:890 -> PropertyImageStorer::store (public disk, same pipeline and stored thumb as section 6). Tenant/owner portal uploads go to the PRIVATE disk via PrivateImageService::storeUpload (PrivateImageService.php:118-122 -> PropertyImageStorer::storeOnDisk on 'local' under private-images/properties/{id}/; same downscale rule as section 6 but NO per-file thumb, the PortalImageService copies in 5a are used). `rentals:privatise-fault-photos` moves older public ones (app/Console/Commands/PrivatiseFaultPhotos.php).
- Work-order photos: RentalWorkOrderService.php:251-255 (private = tenant uploads, otherwise public pipeline), RentalJobCardService.php:892-896 (crew receipt photo: private).
- Contractor/quote documents: RentalWorkOrderQuoteController.php:56 and :148 (`pdf,jpg,jpeg,png,webp,heic,heif|max:20480`, rental-work-order-quotes/{wo}, local, raw), ContractorSecureLinkController.php:298 (same dir). Job card PDF: RentalJobCardService.php:801 (dompdf, local); signed copy RentalJobCardSignedCopyController.php:51 (rental-job-card-signed-copies/{id}).
- Fault type guide image/documents: RentalFaultTypeController.php:209 (public), :222 (local); RentalOwnerFaultSettingsService.php:43; RentalWhoPaysService.php:231 (rental-payer-rulings/{wo}).
- None of the above store a hash; no HEIC conversion for the document-class uploads (quotes/evidence).

----------------------------------------------------------------------------------------------------------------------------------------
## 8. HR / staff documents

- Agent self-upload: Agent/AgentPortalController.php:405-440 (rule `pdf,jpg,jpeg,png,webp|max:10240`), `agent-docs/{userId}/{hashName}` on 'local'; user_documents row (file_path, file_name, file_size, mime_type, status, expiry_date). No resize, no hash, not encrypted.
- ID copy as two photos: AgentPortalController.php:476-504 + IdDocumentPdfService (app/Services/Images/IdDocumentPdfService.php:20-46): both photos embedded UNCHANGED as base64 data URIs in a dompdf 2-page PDF (no downscale, no JPEG recompress; PDF is roughly 1.33x the photo bytes) -> `agent-docs/{userId}/{rand20}.pdf`.
- Admin upload on behalf: Compliance/UserDocumentAdminController.php:36-44 (`pdf,jpg,jpeg,png|max:10240`, `user-documents/{userId}`); Admin/UserManagementController.php:228/581/1113 FFC certificate (`max:5120`) -> `agents/{id}/ffc.{ext}` on 'local'.
- Staff take-on: StaffTakeOnController.php:395-404 `take-on-docs/{userId}` on the PUBLIC disk (`pdf,jpg,jpeg,png|max:10240`) i.e. identity/compliance documents on a web-addressable disk (older rows moved by MigrateUserDocumentsToPrivateDisk command, app/Console/Commands/MigrateUserDocumentsToPrivateDisk.php).
- Onboarding applicants: Onboarding/OnboardingController.php:161-169 (`max:10240`, no mimes rule) -> onboarding/{application} on 'local'; ApplicationDocument has no size/hash columns.
- Employee screening: Compliance/EmployeeScreeningController.php:151-161 (`file|max:10240`, no mimes) screening-docs/{agency}; Leave: MyPortalLeaveController.php:92-93,173 (`pdf,jpg,jpeg,png|max:5120`) leave-documents/{app} + `documents` row; Payslips: PayslipPdfService.php:52,91 write to storage_path('app/payslips/{taxYear}/{period}/{payslip_number}.pdf') i.e. OUTSIDE the `local` disk root (storage/app, not storage/app/private); 436K on /corex.
- PPRA / policy / RMCP / whistleblow evidence: PolicyController.php:292, RmcpController.php:192, WhistleblowController.php:150/158, EmployeeScreeningController, InformationOfficerAppointmentsController.php:43, FicaOfficerAppointmentsController.php:43, AgencyComplianceSettingsController.php:67/154, AgencyTransformationNoteService.php:42, PpraEmploymentLetterService.php:163 — all `store(..., 'local')`, raw, mostly max:10240 or unspecified, no hash. Generated PDFs (PpraInspectionReportPdfService, PpraEmploymentLetterPdfService, PpraSalesRentalsPdfService, PpraPractitionerRegisterPdfService, WhistleblowComplaintService, RMCP/Policy acknowledgements) render HTML via Chromium to storage/app/temp then move/copy.
- Training: TrainingController.php:282/326 lesson files on 'local' (rule not inspected).

----------------------------------------------------------------------------------------------------------------------------------------
## 9. Imports (CSV/XLSX and bulk)

- Admin/ImporterController.php:52-55, 296-315 : P24 agents/listings/images CSVs, `csv,txt|max:51200`, `->store('imports/p24/...')` default disk = local; NEVER deleted by the code (only the run row is soft-deleted, :282). 
- CoreX/RentalTakeOnImportController.php:89-98 (`xlsx,csv,txt|max:51200`, imports/rental-takeon, retained; run delete :420 is DB only).
- CoreX/RentalCatalogueImportController.php:58-91 (same rule; `Storage::delete($path)` at :91 on a failure branch).
- CoreX/SoldPropertyImportController.php:37-102 (`xlsx,xls|max:51200`, staged at STAGE_DIR, deleted at :49/:54/:102).
- MarketReportController.php:140,282 + MarketReportIngestService.php:49-80: PDF reports, `market-reports/{agency}/{Y}/{m}/{uuid}.pdf` local, sha256 file_hash with per-agency dedupe incl. restore-on-rehash (soft-deleted dedupe). Original kept.
- Listing/portal captures: Presentation/PortalCaptureController.php:30-80 (raw HTML + optional PNG screenshot under portal_captures/{id}.html|png on local, sha256 of the DOM in DB; 291M on the data volume); chrome extension payload `html` has no size cap in the validator (line ~34 `required|string`).
- Storage::put of one-off JSON/CSV: BulkSyndicateP24/PP (results), Properties/ReconcilePropertyAddresses etc.

----------------------------------------------------------------------------------------------------------------------------------------
## 10. Generated reports, CMAs, presentations, brochures

- Presentations: Presentations/PresentationPdfService.php:20-36 stores the pack as HTML `presentations/{presentation}/versions/{version}.html` (disk local, one file per version, no PDF stored; PDF produced on demand). Domain/Presentation/UploadProcessor.php:42-51 stores uploaded source docs at a deterministic name `presentations/{id}/{slug}` and content_hash (sha256) in presentation_uploads. 484M on the data volume.
- Viewing packs: ViewingPack/ViewingPackRedactionService.php:124 and ViewingPackAgentPdfService.php:84 / ViewingPackBuyerPdfService.php:100 write `viewing-packs/...` PDFs; redaction rasterises every page via pdftoppm at per-agency DPI (default 150, clamp 72-300, lines 32-44) and re-encodes as JPEG q85 (:251), i.e. redacted PDFs are image-only. 327M on /corex.
- Property brochures / CoreMatch lists: PropertyBrochureService / CoreMatchListPdfService render on demand with data-URI JPEG recompress (q74 / q72); no stored cache found.
- Evaluation certificate PDF: Tools/EvaluationCertificateController.php:537 (`Storage::put`, local) + fileToPropertyDrive (:316).
- Rental application PDFs: RentalApplicationPdfService.php:121 (Storage local put), :51 `data_volume` cache of sealed generations (the only use of that disk), fileAsDocument :113-135 (filed copy in rental-applications/{id}/filed/); RentalApplicationDocumentHighlightService caches rasterised pages (file_put_contents tmp + JPEG q90 at :1199).
- Applicant documents: RentalApplicationSigningController.php:1282/1528/1663/1748, RentalApplicationController.php:1850/1924, RentalApplicationReviewController.php:1643 -> `rental-applications/{id}/documents` local raw.
- Marketing/social: PropertyMarketingController.php:356 (public disk), CompanyAdsController.php:423, Tools/CompanyAdsController, ScheduledPostImages (purged by command).
- AI Template Builder reference images: CompanyAdBuilderService / AiTemplateBuilderService.php:1161-1185 (private, resized to JPEG q88 or PNG), purged after 7 days by ai-templates:purge-reference-images (scheduled routes/console.php:625).

----------------------------------------------------------------------------------------------------------------------------------------
## 11. Temp files, PDF tools and communications media

- storage/app/temp/doc_{id}_{ts}_{rand}.html|pdf : html-to-pdf scratch (SigningController.php:3126-3217). HTML unlinked after, PDF moved. 1.3M now on /corex. No scheduled sweeper for this dir. (The off-box backup excludes /mnt/HC_Volume_103099143/corex-storage/app/temp and .../imports.)
- Headless Chromium profiles: scripts/html-to-pdf.mjs creates a per-run profile and removes it on exit; backstop /etc/cron.d/corex-housekeeping runs /mnt/HC_Volume_103099143/bin/corex-tmp-cleanup.sh hourly at :50: deletes /tmp/puppeteer_dev_chrome_profile-* and /tmp/corex-pdf-profile-* older than 60 min, /var/crash >2 days, apt-get clean. Nothing else.
- PDF Splitter (Tools/PdfSplitterController.php): originals copied/stored at `private/splitter/originals/{base}__{ts}.pdf` (lines 254, 368-370, 461-463), per-run `private/splitter/tmp/{base}__{ts}/` (manifest.json + page_NNN-NN.png at 200 DPI + `__crop.png` + review thumbs JPEG q50 720 px, makeThumbnail :2176-2192), `private/splitter/output/{base}__{ts}/` split PDFs, `private/splitter/zips`. NOTE the path is under the `local` disk root so it is storage/app/private/private/splitter. Nothing deletes originals/tmp/output (only the ZIP is unlinked at :1350). On /corex: splitter total 11G = tmp 8.1G (423 run folders), originals 1.5G, output 882M, zips 96M. Filing then writes a THIRD copy (public disk for contacts :786-834, private for rental apps :614, FICA copy via FicaWetInkService).
- PDF Suite tools (Tools/PdfSuiteController.php:482-498): inputs stashed to storage/app/private/pdf-suite/in/{user}/{uuid}.ext and outputs to pdf-suite/out/{user}/ (`$file->move`), never deleted (219M). Ghostscript `-dPDFSETTINGS=/screen|/ebook|/printer` compress (:44-47) is the only GS call. Limit 51200 KB.
- Communications archive (WhatsApp/email media): CommunicationStorageService.php:44-60 content-addressed `communications/{agency}/{kind}/{sha[0:2]}/{sha256}` on disk config('communications.disk','local'); hash of PLAINTEXT, dedupe by existence check; ciphertext (when enabled) stored at that path; attachments are stored raw (no transcode/resize); media_status pending rows when download fails. Spec says nothing deletes media (specs/wa-media-durability-transcription.md §0.1). TranscriptionService.php:91 writes a decrypted temp audio file for transcription.
- Contact sheet CSV/JSON dumps: Storage::put in Console commands (results/reports).

----------------------------------------------------------------------------------------------------------------------------------------
## 12. On-disk size snapshot from /corex (this host, 2026-10-24; for scale only)

/corex/storage/app total 27G (du -x; symlinked dirs excluded) = public 14G + private 14G.
- public/properties 14G (of which thumbs/ ≈ 710M), public/agents 26M, outreach-cards 1.3M, public/fica 572K.
- private/private (splitter) 11G (tmp 8.1G, originals 1.5G, output 882M, zips 96M); private/communications 1.9G; private/prospecting 378M; private/fica 375M; private/viewing-packs 327M; private/properties 184M; private/deals 23M; private/deal-documents 15M; fica-legacy-orphans 15M; imports 18M.
- Symlinked to /mnt/HC_Volume_103099143/corex-storage/app/private (3.9G): private/private 1.1G, docuperfect 907M (signed-documents 794M, templates 108M), presentations 484M, contact-documents 318M, portal_captures 291M, shared_drive 252M, pdf-suite 219M, sales-documents 113M, market-reports 104M, prospecting 101M.
- storage/backups 223M, logs 46M.
- Backups on the data volume: /mnt/HC_Volume_103099143 contains at173-fica-backup-20260720-135034, at173-live-backup-20260720-122739, at366-live-dedupe-backup-20260814, corex-backups, backups-moved etc. (plaintext FICA backup from the AT-173 migration may still be there; not inspected). A dir `at173-KEY-BACKUP` also exists.
- Off-box backup (restic nightly 03:30, /usr/local/bin/corex-offbox-backup.sh:8-129) covers /corex/storage/app, /corex-staging/storage/app and /mnt/HC_Volume_103099143/corex-storage/app; excludes storage/framework, corex-storage/app/temp and /imports. Because restic dedups on content-defined chunks, encrypted (random-nonce) files do not dedupe night to night only if rewritten; unchanged ciphertext files do.

----------------------------------------------------------------------------------------------------------------------------------------
## A. "Media Encryption" feature (AT-173)

Where it lives
- config/media-encryption.php (whole file); App\Services\Security\MediaCipher (app/Services/Security/MediaCipher.php); App\Services\Compliance\FicaDocumentStorage; App\Services\Communications\CommunicationStorageService; Document::decryptedContents/downloadResponse (app/Models/Document.php:133-150, 205+); FicaController.php:1414-1437; commands app/Console/Commands/GenerateMediaKey.php (`media:key:generate`) and EncryptMediaBackfill.php (`media:encrypt-backfill --scope=comms|fica [--dry-run]`); admin status page App\Http\Controllers\Admin\MediaEncryptionStatusController (route compliance.media-encryption.status); spec .ai/specs/per-agency-media-encryption.md (top "DELIVERED" section; the per-agency envelope/seal/unseal design below it is NOT built, it is the "future upgrade path").

What is encrypted
- ONLY: (1) communication media (WhatsApp media, email .eml, attachments) written by CommunicationStorageService on disk `local` (or COMMUNICATIONS_DISK); (2) FICA documents in fica_documents (FicaDocumentStorage, disk local) and the filed FICA copy in `documents` (source_type='fica', contact-documents/...).
- NOT encrypted (confirmed by file magic sampling and by spec "Phase 2 separate ticket"): DocuPerfect/e-sign working files and signed PDFs, deal documents, contact documents of other types, HR documents, inspection photos/scans, property photos (public by design), presentations, imports. Sampled: /corex docuperfect/signed-documents 0/400, deal-documents 0/8, contact-documents 0.

Algorithm / key handling
- AES-256-GCM via openssl_encrypt, per-file random 12-byte nonce, 16-byte tag, envelope `CXE1 | keyVersion(1) | nonce(12) | tag(16) | ciphertext` (MediaCipher.php:7-12, 51-63). Whole-file in memory (no streaming): encrypt(file_get_contents(...)).
- Key: MEDIA_ENCRYPTION_KEY, `base64:` 32 raw bytes, in each environment's .env (config/media-encryption.php:25), separate from APP_KEY. MEDIA_ENCRYPTION_KEY_PREVIOUS slot + key-version byte for rotation (a `media:rotate-key` command is mentioned in config comments but was NOT found in app/Console/Commands). Generate refuses to overwrite.
- Decrypt is passthrough for non-envelope bytes, so mixed state is safe. Tag mismatch throws.

On or off
- config/media-encryption.php:17 `enabled = env('MEDIA_ENCRYPTION_ENABLED', env('MEDIA_ENCRYPTION_KEY') !== null)`: auto-ON whenever a key exists. MEDIA_ENCRYPTION_KEY is set (base64 present) in /corex-staging/.env, /corex-qa1/.env and /corex/.env; MEDIA_ENCRYPTION_ENABLED is not set in any of them. .env.example has the key blank.
- Verified by magic sampling of up to 400 files per dir: /corex communications 400/400 and fica 400/400 encrypted; QA1 communications 400/400, fica 391/393; staging communications 210/400, fica 5/7 (mixed/legacy).

Consequences for storage optimisation
- Encrypted-at-rest bytes ARE what is stored (ciphertext on disk, no plaintext copy kept). Ciphertext is incompressible, and compress/resize after the fact is impossible without the key + a decrypt-rewrite pass. Because nonce is random per write, two copies of the same plaintext produce different ciphertext: FICA copy-on-file duplicates (section 1) cannot be found by hashing files; they would have to be matched by decrypting.
- Communications are the exception: path is sha256 of the PLAINTEXT, so dedupe still works at write time (existence check) even though bytes are ciphertext.
- Any optimisation for FICA or comms media must go through MediaCipher (decrypt -> optimise -> re-encrypt), and the app only supports whole-file in-memory processing.

----------------------------------------------------------------------------------------------------------------------------------------
## B. Is the S3 disk used?

- config/filesystems.php:50-61 defines `s3` reading AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION, AWS_BUCKET, AWS_URL, AWS_ENDPOINT. In /corex-staging/.env (lines 64-68) and /corex/.env and /corex-qa1/.env AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY and AWS_BUCKET are EMPTY; AWS_DEFAULT_REGION=us-east-1 only.
- The package league/flysystem-aws-s3-v3 is NOT installed (vendor/league contains only commonmark, config, flysystem, flysystem-local, mime-type-detection, uri*), so the s3 driver cannot even be instantiated.
- `Storage::disk('s3')` / `disk("s3")`: not found anywhere in app/ routes/ config/. The only text mention is a comment in app/Services/SG/SgSearchService.php:317-321 ("local in dev + 's3' (or equivalent) in production") and that function simply returns config('filesystems.default') = 'local'.
- Code that follows FILESYSTEM_DISK/default disk (would silently follow if it were ever flipped): SgSearchService::disk(), DealV2Controller::storeDocument, DealStepController, any bare `->store($dir)` with no disk (ImporterController, RentalCatalogueImportController, RentalTakeOnImportController, `Storage::put` in TemplateController/TemplatePackageImporter/MigrateDocuperfect). But much code calls `Storage::disk('local')->path()` or `file_get_contents(disk->path())` (PropertyImageStorer, PropertyThumbnailService, DocumentFlattener, Splitter, Imagick readers) and would break on a non-local disk.
- Offsite durability is restic over SFTP to a Hetzner Storage Box (corex-offbox-backup.sh), not S3. config/communications.php:14 has COMMUNICATIONS_DISK as the only per-feature disk switch.

----------------------------------------------------------------------------------------------------------------------------------------
## C. Upload size limits

Server layer (this host)
- PHP 8.2 FPM (the pool behind staging, QA1: nginx fastcgi_pass unix:/run/php/php8.2-fpm.sock in sites-enabled/staging.corexos.co.za and qatesting1.corexos.co.za): /etc/php/8.2/fpm/php.ini upload_max_filesize=60M, post_max_size=65M, max_file_uploads=20, memory_limit=1024M, max_execution_time=30. 8.2 CLI: upload 2M / post 8M / memory unlimited.
- PHP 8.3 FPM: upload_max_filesize=512M, post_max_size=550M, max_file_uploads=100, max_execution_time=600. (I did not determine which pool serves /corex; the sites-enabled file for corexos.co.za is NOT in sites-enabled on this host, only in sites-available with `client_max_body_size 1000M`, fastcgi_read_timeout 600.)
- nginx: client_max_body_size 64M for staging.corexos.co.za, qatesting1.corexos.co.za, staging.corex.hfcoastal.co.za, demo, corexweb; 1000M in sites-available/corexos.co.za and live-testing.corexos.co.za; 100M global in /etc/nginx/nginx.conf:42 and docuperfect sites.
- Effective limit on staging/QA1 is therefore min(PHP 60M/65M, nginx 64M) ≈ 60 MB per file, 20 files per request. Property gallery validation `max:204800` (200 MB) and inspection `max:51200` (50 MB) are above or near the PHP 8.2 limit, so on 8.2 the PHP limit is what actually binds.

Application validation `max:` (KB) by type
- FICA 10240 (mimes pdf,jpg,jpeg,png,heic); contact documents 20480 (allow-list incl. zip); mobile contact docs 20480 (no mimes); deal docs Dr2 20480, DealV2 10240 (no mimes).
- E-sign originals 20480 (pdf,doc,docx); wet-ink 20480 (pdf,jpg,jpeg,png); supporting docs 15360 each, max 10 files.
- Inspection photos 51200 (batch max 10); inspection scans 20480; quotes 20480; supplier invoice per-agency setting rental_work_order_settings.invoice_max_file_mb default 10, ceiling 50 (RentalWorkOrderSetting.php:62-63, 299-304) with extension choice pdf / pdf+images / +heic.
- Property gallery 204800 (image rule); pp agent images 1024; HR documents 10240 (FFC cert 5120; leave 5120); onboarding/screening 10240 (no mimes); imports 51200; PDF Splitter 51200 per file, max 20 files; PDF Suite 51200; market reports (rule not inspected).
- Rules with only `file|max` and NO mimes/extension allow-list: DealV2Controller.php:466, DealStepController.php:~89/:170, Dr2/DealDocumentController.php:30, Onboarding uploadDocument, EmployeeScreeningController, MobileContactComplianceController.

----------------------------------------------------------------------------------------------------------------------------------------
## D. Cleanup / retention jobs for files and soft-deleted files

Scheduled (routes/console.php) that touch FILES
- ai-templates:purge-reference-images dailyAt 03:15 (line ~625): deletes reference images older than 7 days (app/Console/Commands/PurgeAiTemplateReferenceImages.php).
- marketing:purge-scheduled-images dailyAt 03:20 (~639): deletes scheduled-post pictures (published+30 days, unused uploads after 2 days).
- communications:purge-embargoed-bodies dailyAt 03:30 (line 102): real delete of embargoed message bodies/media (POPIA).
- Cron (outside Laravel): corex-tmp-cleanup.sh hourly (Chromium profiles, crash dumps, apt cache); lane-test-sweep weekly (test schemas only); nightly restic backup + forget/prune (keep-daily/weekly/monthly variables, corex-offbox-backup.sh:155).
Scheduled but DB-only (no file removal)
- communications:prune-retention (03:20, 5 years, soft purged_at only), communications:prune-pending (03:35), communications:prune-provisional (hourly), contacts:purge-retention (02:00; contacts/consent/log retention default 5 years each, agency_contact_settings migration 2026_05_05_000011; consent evidence hard-deleted, contacts soft-purged), docuperfect:prune-abandoned-cds-drafts (03:40, soft), command-center:archive-done-tasks, fault reports auto soft-delete after 3 days (routes/console.php ~line 606), p24:prune-logs (45 days), queue:prune-failed (720 h), model:prune AssistantActivityLog, AI cache purge jobs.
Not scheduled
- db:purge-soft-deleted (app/Console/Commands/PurgeSoftDeleted.php:11-55): hard-deletes every row with deleted_at in ALL tables, DB rows only; does not remove files; not in the scheduler.
- No scheduled job found for: storage/app/temp, private/splitter/{tmp,originals,output,zips}, pdf-suite/in|out, private-images/portal-img (derived copies), properties/*/thumbs orphans, docuperfect flattened/page PNGs, imports/p24 CSVs, orphan files of soft-deleted Documents/FICA docs/Shared drive files (files are deliberately kept: SharedDriveService docblock; WA spec "write-once, no hard delete").
- Hard file deletes that do exist in request paths: property gallery image delete / rotate (PropertyController.php:2649, 2747, 3344), agent photo replace (AgentPhotoNormalizer.php:56, AgentProfilePhotoService.php:118-139), branch/agency logo replace, PrivatiseFaultPhotos / PrivatiseRentalInspectionPhotos / MovePropertyFilesToLocalDisk (migration commands), SoldPropertyImport staging.

----------------------------------------------------------------------------------------------------------------------------------------
## E. Existing storage/retention specs and rules (.ai)

No spec dedicated to storage/retention/file lifecycle was found (no file named storage*/retention*/popia* in .ai/specs). Relevant statements:
- .ai/specs/rental-inspections.md:575-600 : photo volume model (348 KB avg, 2560 px JPEG85), "retained for FICA's five years minimum", "never deleted"; flags that object storage (S3-compatible) or more disk is an UNDECIDED infrastructure call for Johan or Andre.
- .ai/specs/claude_communication_archive_spec.md:37 : 5-year retention for communication records and logs; prune is a recorded soft event.
- .ai/specs/wa-media-durability-transcription.md §0.1-0.2, §1.1: media is write-once, no hard delete; invariant "no code path unlinks files under communications/**/attachment/**"; recommends restic offsite (implemented as corex-offbox-backup.sh).
- .ai/specs/esign-v3-complete-spec.md:1046-1049 : documents and signing audit logs retained 7 years (Property Practitioners Act); AI generation logs 1 year; audit tables insert-only (§13.2).
- .ai/specs/per-agency-media-encryption.md : AT-173 encryption scope and the explicit "Phase 2" list (DocuPerfect/e-sign working files and deal documents NOT encrypted, need a decrypt-to-temp shim).
- CLAUDE.md Non-negotiable #1: no hard deletes (soft delete only; files are kept). CLAUDE.md disk-hygiene rules (2026-08-20): dumps go to the data volume; worktrees disposable.
- .ai/specs/contact-consent.md and agency_contact_settings: contact_retention_years / consent_retention_years / access_log_retention_years default 5.
- .ai/specs/rental-inspection-form.md : "original is retained" for scans; esign gaps: .ai/audits/2026-07-11-esign-v3-gap-analysis.md.
- Nothing in the specs sets a retention period for: deal documents, contact documents, FICA documents themselves (FICA 5 years is implied by the inspection spec reference only), HR documents, splitter intermediates, portal captures, imports.

----------------------------------------------------------------------------------------------------------------------------------------
## F. Quick observations relevant to a storage-optimisation audit (facts only, no changes made)

1. Largest, clearly derived/regenerable pools: splitter tmp 8.1G + originals 1.5G + output 0.9G (nothing deletes them); public/properties thumbs 710M; private-images/portal-img derived copies; pdf-suite in/out 219M; portal_captures 291M.
2. Multiple copies of the same signed content: client_signed.pdf + final_signed.pdf (+ individual pack PDFs + lossless flattened PNG pages) per signature_template; FICA files exist twice (fica/... and contact-documents/... with different ciphertext); splitter file exists 3 times after filing.
3. Property gallery stores up to 2560 px q85 JPEG (or an untouched smaller JPEG with EXIF/GPS); inspection photos 2560 px q85 with EXIF stripped; there are two different pipelines (PropertyImageStorer keeps small JPEGs byte-identical, PrivateImageStore always re-encodes).
4. FICA/communications media are ciphertext (incompressible, random nonce); signed e-sign PDFs, deal documents, contact documents, scans, HR docs are plaintext, no hash column -> dedupe/compress candidates need a hash backfill.
5. S3 is not available (driver package absent, credentials empty); any move to object storage needs the package, config and a review of code that uses Storage::disk()->path().
6. Retention is not defined or enforced for most file classes; hard delete exists only for gallery images and agent photos.

---------------------------------------------------------------------------------------------------------
## APPENDIX B — other sites and portal feeds (helper report)

# Audit Q3: do other sites on this box hold their own copies of CoreX property photos?

Date: 2026-10-24. Read-only. Nothing changed. Scratch scripts only in the scratchpad dir. Box = 91.99.130.85 (this server). The real live (62.238.31.82) is a different machine and was not touched.

## 0. Headline numbers

| Item | Files | Size |
|---|---|---|
| hfcoastal.co.za photo cache (own copies of CoreX photos): 12,833 trimmed originals + 6,644 resized variants | 19,477 | 6.35 GB (`du -x`: 6,102 MiB). Originals 5.51 GB, w480 0.14, w960 0.04, w1600 0.66 |
| Of those originals, verified as copies of a CoreX photo (URL-hash match) | 10,169 | 3.85 GB (CoreX-side source of the same photos: 4.26 GB) |
| Byte-identical to the CoreX file | 0 | 0 GB. Every copy is re-encoded (JPEG q85), so sha256 never matches |
| Originals with no match on this box | 2,664 | 1.66 GB. 2,447 of them were cached in Sep/Oct, so they are probably newer than this box's /corex photo snapshot. Unverified |
| themandatecompany.co.za (/var/www/hfc-website) own images | 228 | 50.3 MB. 57 files (5.4 MB) are byte-identical to CoreX files |
| corexweb.co.za, agent-targets (performance.hfcoastal.co.za) | 5 / 11 | 0.7 MB / 2.8 MB. Marketing assets, no property photos |
| In-CoreX duplication: staging / qa1 / qa2 each hold their own full copy of `properties/` | 69.5k / 39.7k / 23.1k | 15.3 / 7.9 / 6.2 GB. No hardlinks. /corex (live-testing) holds 14.1 GB |

Answer in one line: the public HFC website (hfcoastal.co.za) does NOT hot-link photos for display. It downloads every CoreX listing photo, white-border-trims and re-encodes it, and keeps it forever on `/`, in a private cache (6.1 GB, growing, never pruned). Visitors are served the site's own copy through `/media/trimmed`. The other sites do not hold CoreX property photos in any meaningful amount.

## 1. Sites and document roots (nginx `sites-enabled`)

| Hostname | Root / upstream | App |
|---|---|---|
| hfcoastal.co.za (+www) | `/var/www/home-finders-coastal/public` (php8.2-fpm) | Laravel public HFC site. File `home-finders`. Port 1050 also serves it on the IP |
| corexweb.co.za | `/var/www/corex-os-website/public` (php8.4-fpm). Port 8095 only redirects | Laravel marketing site. File `corexweb.co.za` (`corex-os-website` is just the 8095 redirect) |
| themandatecompany.co.za (+www) | `/var/www/hfc-website/public` (php8.2) | Laravel "Mandate Company" site, SQLite DB |
| performance.hfcoastal.co.za | `/var/www/agent-targets/agent-targets/public` (php8.4) | Agent targets app. Also `agent-targets` default site |
| staging.corexos.co.za, staging.corex.hfcoastal.co.za, hfc-staging (default) | `/corex-staging/public` | CoreX Staging |
| qatesting1 / qatesting2 | `/corex-qa1/public` / `/corex-qa2/public` | CoreX QA |
| live-testing.corexos.co.za | `/corex/public` (php8.3) | CoreX copy of live ("live-testing"), NOT production |
| demo1.corexos.co.za | `/mnt/HC_Volume_103099143/corex-demo/public` | CoreX demo |
| tmc, docuperfect, docuperfect-dev, boomdev, arweb1, notif-api | `/tmc/dist`, `/Docuperfect-*/dist`, `/boomdev/dist`, proxies | Those directories do not exist on this box. Nothing to audit |

## 2. Per-site findings

### 2a. hfcoastal.co.za (`/var/www/home-finders-coastal`)

Mechanism (file:line):
- `.env` points at `COREX_API_BASE=https://corexos.co.za/api/v1/website` (the production API, not this box). Allowed photo hosts: `config/services.php:64-66` = `staging.corexos.co.za, corexos.co.za, www.corexos.co.za`.
- `app/Console/Commands/CorexWarmImages.php` (whole file; `handle()` and `pendingUrls()`): downloads every listing photo URL from the CoreX listings feed, covers first, then the rest of each gallery.
- `app/Services/Media/TrimmedImageStore.php:67-84` `original()`: `Http::get($url)->body()`, then `WhiteBorderTrimmer::trim()`, then writes `trimmed/{sha1(url)}.jpg` on the `local` disk (`storage/app/private`). Cached for good.
- `app/Support/WhiteBorderTrimmer.php:41-76`: always re-encodes JPEG q85, even when there is no border (sample: crop changed dimensions on 0 of 400 photos).
- `TrimmedImageStore.php:28,91-135`: width variants 480/960/1600, JPEG q82, generated on request. Served by route `media/trimmed` (`bootstrap/app.php:24`, `Public/MediaController.php:41`).
- Scheduling: `/etc/cron.d/hfc-coastal-warm` (added 2026-10-14) runs `corex:warm-cache` every 5 min and `corex:warm-images --limit=100` every 10 min as www-data. `routes/console.php:29` also schedules it. These drain the backlog, so the cache keeps growing.
- No pruning: grep of `app/` finds no delete or unlink of cached images. Files for withdrawn or sold listings stay forever. The cache has grown from 27 files on 2026-06-11 to 12,833 originals.
- DB: `.env` has DB `home_finders` (mysql). The credentials in `.env` were refused (ERROR 1045), so no DB queries were run. Photos are not stored in the DB (the cache table holds API JSON).

Measurements (`du -x`, `find -printf %s`):
- `storage/app/private/trimmed`: 12,833 files, 5.51 GB (median 304 KB, max 7.2 MB). `w480` 3,740 files, 0.14 GB. `w960` 305 files, 0.04 GB. `w1600` 2,599 files, 0.66 GB. 0 stray `.tmp`.
- Filesystem is `/` (`/dev/sdb1`, 38 GB, 81% used, 7.0 GB free). This is the disk the CLAUDE.md disk-hygiene rule says to protect. The cache is 6.1 GB of that, and grows by about 100 photos per 10 minutes until the backlog is cleared.
- `public/images` is 108 KB. `storage/app/public` is 1.3 MB (only `seo/`).

### 2b. themandatecompany.co.za (`/var/www/hfc-website`)
- Own uploaded images: `storage/app/public/{properties,agents}` (220 jpg, 7 webp, 1 png), 50.3 MB in 228 files. `public/storage` symlinks to `storage/app/public`.
- Code has a CoreX-ish ("Nexus") `Listing` model (`app/Models/Listing.php:86`, `hero_image` from absolute URLs in `images_json`/`gallery_images`), which means it hot-links, not copies. But the SQLite DB (`database/database.sqlite`, read-only SELECT) has `listings` = 0 rows, `properties` = 1 row, `property_images` = 0 rows. The 220 jpgs are legacy or orphaned uploads. 57 of the 228 are byte-identical to CoreX files (5.4 MB, sha256 against /corex, staging, qa1, qa2). No cron or importer references this site. No `corexos.co.za/storage` strings in its code.
- Verdict: no meaningful duplication. About 5 MB.

### 2c. corexweb.co.za (`/var/www/corex-os-website`) and performance.hfcoastal.co.za (`/var/www/agent-targets`)
- 5 images (0.7 MB) and 11 images (2.8 MB) respectively. Static marketing and UI assets. No CoreX photo references found (grep `corexos.co.za/storage` over app/resources/routes/config/database: none). No cron. Verdict: nothing.

### 2d. Cron / timers
- Root crontab: demo schedule, disk alarm, and `/corex` scheduler (live-testing). Staging and QA1 sync are commented out.
- `/etc/cron.d`: only `hfc-coastal-warm` touches a website (2a). `corex-housekeeping`, `corex-offbox-backup` and `nexus_os-backup` were not read for photos (backups out of scope).

## 3. Duplication method and result

Method: the HFC cache name is `sha1(<photo URL>)`, and the CoreX API emits `https://<host>/storage/properties/<id>/<file>` (`app/Http/Resources/WebsiteApi/ListingResource.php:377-401`, `Storage::disk('public')->url()`). For every image under `properties/` in /corex, /corex-staging, /corex-qa1 and /corex-qa2 I computed `sha1("https://{corexos.co.za|www.corexos.co.za|staging.corexos.co.za}/storage/<rel>")` and looked it up in `trimmed/`. Scripts: `scratchpad/match.py`, `dims.py`, `unm.py`.
- 10,169 of 12,833 cached originals match a CoreX file (10,130 via corexos.co.za, 39 via staging host). 10,103 are on /corex (live-testing), 10,142 on staging, 8,943 on qa1, 8,046 on qa2.
- Duplicated bytes: 3.85 GB of site-side files (CoreX source of those = 4.26 GB). Add the derived variants (0.84 GB) and the unmatched 1.66 GB (probably newer-than-snapshot photos, most cached Sep/Oct, so they likely match the real live box) and the whole 6.35 GB is a CoreX-derived copy. Only 3.85 GB is proven.
- Byte-identical: 0, because of the always-re-encode in `WhiteBorderTrimmer`. Per-file size ratio trimmed/source: median 1.28, p10 1.00, p90 1.33 (small files grow). Across the 10,103 /corex matches the trimmed total is 3.85 GB against 4.26 GB of source.
- Source sizes (sample of 400): width median 1600, 23 have a long edge >= 3000 px. /corex jpgs: 60,721 files, 12.39 GB, median 127 KB, p90 390 KB, max 12.4 MB, 967 files > 1 MB.
- Caveat: `/corex` here is live-testing, not production. The production photo set lives on 62.238.31.82 and could not be read. The HFC site fetches from there.
- Cross-environment duplication inside CoreX (same relative path and same size as /corex): staging 60,727 images / 12.39 GB, qa1 37,298 / 6.33 GB, qa2 17,539 / 4.30 GB. Staging also holds 22,318 `thumbs/` files (0.68 GB).

## 4. Portal feeds (in /corex-staging)

Property24 (files in `app/Services/Syndication/Property24/`):
- Sends BYTES, not URLs: base64 inside the JSON listing (`'bytes' => base64_encode(...)`, `mimeContentType`, `caption`, `isFloorPlan`), `Property24ListingMapper.php:1177-1184`. Files are read from the public disk at `:1155-1169`. Spec says the same: `.ai/specs/p24-syndication.md:16` ("Images: Sent as base64-encoded bytes inline (not URLs)").
- Refresh gate: photos sent only when the gallery signature changed, else `photos: null` (`Property24ListingMapper.php:186-204`; spec `p24-syndication.md:320,336`).
- Hard cap 60 MB of image data per submit: `Property24ListingMapper.php:17-25`. Budget 57 MB measured on base64 size. If over, `fitPhotosToPayloadBudget()` (`:1199-1250`) gives each photo an equal share and re-encodes only the oversized ones via `compressImageToBytes()` (`:1260-1300`): scale steps 1.0/0.8/0.6/0.45/0.33/0.25, JPEG quality 80 down to 40. No photo is dropped.
- Count cap: per-agency `p24_max_photos`, default 150 (`app/Models/Agency.php:153,157`), applied in `buildPhotos()` `:1121-1150`. Gallery source is `syndicationImages()` (`app/Models/Property.php:3408`). Minimum of 1 photo: `:1076`.
- No per-image pixel, dimension or format check anywhere for P24 (grep of the mapper, `Property24ApiClient`, `PortalContentValidator`: none). The source files are CoreX's own, stored by `PropertyImageStorer` (max edge 2560, JPEG q85, `app/Services/Images/PropertyImageStorer.php:23-25,167-191`). Any P24 per-image limits beyond the 60 MB total: needs verification (P24 docs not in the repo).
- Agent photo: `Property24ApiClient::uploadAgentPhoto` (`:465-467`), gated by `users.p24_photo_signature`.

Private Property (`app/Services/PrivateProperty/`):
- Sends URLs: `PhotoUrls = {string: [https://...]}` (`PrivatePropertyListingMapper.php:104,156-159`, built by `buildPhotoUrls()` `:1101-1188`). PP downloads each URL itself during the SOAP call. One 404 makes PP fail the whole `UpdateListing` (PP120, comments `:1133-1146`; `PropertyImageGuard` header comment). So CoreX photos MUST stay publicly reachable over HTTPS at a stable URL on the CoreX host (`PP_IMAGE_BASE_URL` override or `APP_URL`, `:1163-1184`). HTTPS enforced at `:339-345`.
- Limits: min 3 photos for sale, 1 for rental (`:424-430`). Cap per agency `pp_max_photos`, default 150 (`Agency.php:175,178`; mapper `:1103-1131`). Spec `.ai/specs/private-property.md:148` says "min 3 (sale) / 1 (rental); first 20", which conflicts with the code (150). Flag for the conductor: the spec is stale or the code deviates.
- No size, dimension or format limits are stated or enforced for listing photos in the code or the spec. The only stated image limits are for the AGENT image: min 160x120, max 1 MB, JPG only (`private-property.md:372,376-381,546`).
- Official PP listing-photo limits: needs verification.

Practical consequence: PP reads our files through the public `/storage` URL (so originals must stay web-served), and P24 reads our files from disk (so the original bytes must be on the CoreX disk). Any move of photos to another host, CDN or resized store must keep both paths working.

## 5. Where QA1 and Staging keep photos, and whether nginx serves them

| Checkout | Photos | Size and count | Public link |
|---|---|---|---|
| /corex-staging | `storage/app/public/properties/<propertyId>/…` (also `thumbs/`) | `public` disk: 15 GB total. properties/ 69,502 files, 15.32 GB (67,554 jpg, 1,895 pdf, 49 png) | `public/storage -> /corex-staging/storage/app/public` |
| /corex-qa1 | same layout | properties/ 39,729 files, 7.90 GB | `public/storage -> /corex-qa1/storage/app/public` |
| /corex-qa2 | same layout | 23,103 files, 6.17 GB | symlink to its own storage |
| /corex (live-testing) | same layout, real path `/mnt/HC_Volume_103099143/corex/storage/app/public` (data volume) | 62,549 files, 14.08 GB | symlink `public/storage -> /mnt/HC_Volume_103099143/corex/storage/app/public` |

- Disk config: `config/filesystems.php:41-47`, `public` disk root `storage/app/public`, URL `APP_URL/storage`. The `local` disk is `storage/app/private`. Private rental photos go to the private disk and are served by signed URL (`PortalImageService`/`PrivateImageService`, 480 and 1600 px copies under `portal-img`).
- Web serving: yes, directly. The nginx vhosts (staging, qatesting1, etc.) have only `location / { try_files $uri ... }` and the PHP block. There is no special `/storage` location, so nginx serves files through the `public/storage` symlink, with no PHP involved and no auth. That is what PP relies on. The staging and qa1 vhosts set no cache `expires` for it.
- Disk: /corex, /corex-staging, /corex-qa1 and /corex-qa2 are all mounts on the data volume (`/dev/sda`, 197 GB, 85% used, 29 GB free; `findmnt`). So the CoreX photo copies do not sit on `/`. The HFC website cache (6.1 GB) is the one that does (`/dev/sdb1`, 81% used).
- The three non-prod CoreX checkouts hold no hardlinks and overlap heavily with /corex (see section 3).

## 6. Observations for the conductor (report only, nothing changed)
1. hfcoastal.co.za cache (6.1 GB on `/`) is the biggest "other site" copy. It is unbounded: no pruning, warm job runs every 10 min. Worth a retention or orphan-prune rule, or a move to the data volume. It is re-encoded, so it cannot be deduplicated against CoreX byte-for-byte.
2. Only the 480/960/1600 variants are actually needed for display. The 5.5 GB trimmed "original" is only used as the source for variants, and `WhiteBorderTrimmer` changed dimensions on 0 of 400 sampled photos.
3. `private-property.md:148` ("first 20") disagrees with the code cap of 150.
4. Spec for P24 states no per-image limit; only the 60 MB total is coded.
5. The 2,664 unmatched originals should be re-checked against the real live box (62.238.31.82) for exact numbers.

---------------------------------------------------------------------------------------------------------
## APPENDIX C — raw inventory tables (file mtimes; unreliable for growth, see section 1c)
```

##### qa1
TOTAL 62515 files 18.42 GB
category                                                     files      GB median KB   p90 KB  last-6-months GB/month (by file mtime)
                                                                                          2026-05 2026-06 2026-07 2026-08 2026-09 2026-10
Property photos (listing galleries)                          39729   7.902        70      351     0.00    4.81    3.01    0.03    0.03    0.02
PDF splitter TEMP page renders (png/jpg)                     15312   6.525       204     1190     0.00    2.85    3.32    0.05    0.21    0.10
PDF splitter originals (uploaded scans)                        410   1.324      2271     6553     0.00    0.59    0.59    0.11    0.02    0.02
PDF splitter outputs/zips (split docs)                        1279   0.876       327     1487     0.00    0.51    0.30    0.03    0.01    0.01
Communications (email/WhatsApp raw + attachments)             1118   0.576         7      874     0.00    0.00    0.53    0.00    0.05    0.00
FICA documents                                                 406   0.297       405     1756     0.00    0.01    0.26    0.00    0.00    0.01
Rental application docs: highlighted copies                    791   0.264       110      357     0.00    0.00    0.00    0.00    0.21    0.05
Viewing packs (redacted PDFs)                                   77   0.162      1910     4331     0.00    0.00    0.16    0.00    0.00    0.00
Rentals: inspections, inventory, notices, lease copies         752   0.136        38      463     0.00    0.00    0.00    0.00    0.00    0.14
Rental application supporting docs                             462   0.126         7      951     0.00    0.00    0.00    0.00    0.08    0.05
Deal / sales / contact / property documents                     80   0.080       948     2127     0.00    0.00    0.06    0.00    0.00    0.01
Prospecting captures, thumbnails, imports                     1682   0.042        19       26     0.00    0.01    0.00    0.03    0.00    0.00
Other                                                           46   0.030        33     2136     0.00    0.00    0.00    0.01    0.02    0.00
Stray root scripts/test artefacts, fonts                       119   0.030         2       69     0.00    0.00    0.03    0.00    0.00    0.00
Temp files                                                      19   0.019      1362     1522     0.00    0.00    0.00    0.00    0.00    0.02
HR / agent profile / knowledge / branding                      202   0.018        68      176     0.00    0.00    0.00    0.00    0.00    0.01
E-sign: originals, templates, signed PDFs                       30   0.011       183     1190     0.00    0.00    0.00    0.00    0.00    0.01
Generated reports / CMAs / presentations / exports               1   0.000       277      277     0.00    0.00    0.00    0.00    0.00    0.00
-- by extension
   jpg        44609   6.799 GB
   png        12037   6.375 GB
   pdf         4090   4.524 GB
   (none)      1118   0.576 GB
   zip           56   0.108 GB
   html           6   0.021 GB
   csv            4   0.006 GB
   json         369   0.004 GB
   geojson        1   0.003 GB
   webp          16   0.001 GB
   ttf            8   0.001 GB
   ufm            8   0.000 GB

##### stg
TOTAL 83160 files 18.84 GB
category                                                     files      GB median KB   p90 KB  last-6-months GB/month (by file mtime)
                                                                                          2026-05 2026-06 2026-07 2026-08 2026-09 2026-10
Property photos (listing galleries)                          69502  15.319       130      398     0.77    5.14    4.65    4.62    0.00    0.01
PDF splitter TEMP page renders (png/jpg)                      1699   1.057       232     1646     0.03    0.09    0.01    0.01    0.01    0.00
E-sign: originals, templates, signed PDFs                     1105   0.585       251     1445     0.00    0.00    0.00    0.39    0.07    0.00
Deal / sales / contact / property documents                    193   0.430      1261     6944     0.00    0.27    0.00    0.00    0.00    0.00
Generated reports / CMAs / presentations / exports             333   0.401       462     2608     0.09    0.11    0.00    0.03    0.00    0.00
Prospecting captures, thumbnails, imports                     6890   0.306        18       39     0.00    0.06    0.00    0.05    0.00    0.00
PDF splitter originals (uploaded scans)                         63   0.239      2398     8288     0.02    0.03    0.00    0.03    0.00    0.00
Communications (email/WhatsApp raw + attachments)             2955   0.236         0      114     0.00    0.07    0.08    0.05    0.03    0.00
PDF splitter outputs/zips (split docs)                         116   0.095       341     1980     0.02    0.03    0.00    0.00    0.00    0.00
HR / agent profile / knowledge / branding                      203   0.090        93      840     0.00    0.03    0.00    0.02    0.00    0.00
FICA documents                                                  36   0.029       328     1970     0.00    0.01    0.00    0.01    0.01    0.00
Viewing packs (redacted PDFs)                                   16   0.028      2178     3440     0.00    0.00    0.02    0.00    0.00    0.00
Temp files                                                      18   0.022      1224     1351     0.02    0.00    0.00    0.00    0.00    0.00
Stray root scripts/test artefacts, fonts                        17   0.001        12       68     0.00    0.00    0.00    0.00    0.00    0.00
Rental application supporting docs                               5   0.001         8      328     0.00    0.00    0.00    0.00    0.00    0.00
Other                                                            7   0.000         1       75     0.00    0.00    0.00    0.00    0.00    0.00
Rentals: inspections, inventory, notices, lease copies           2   0.000         2        2     0.00    0.00    0.00    0.00    0.00    0.00
-- by extension
   jpg        74199  13.693 GB
   pdf         3407   3.220 GB
   png         1744   1.335 GB
   html         674   0.305 GB
   (none)      2955   0.236 GB
   zip           13   0.034 GB
   xlsx           5   0.007 GB
   geojson        1   0.003 GB
   webp          20   0.001 GB
   csv           56   0.001 GB
   json          55   0.001 GB
   gif            1   0.001 GB
```

## APPENDIX D — exact-duplicate runs
```
== QA1
qa1 size-collision candidates: 26106
qa1: exact duplicate groups=5674  redundant copies=10041  redundant GB=5.080
   PDF splitter TEMP page renders (png/jpg)                   groups= 2569 redundant copies=  5606    3.171 GB
   Property photos (listing galleries)                        groups= 2661 redundant copies=  3087    1.242 GB
   PDF splitter originals (uploaded scans)                    groups=   53 redundant copies=   100    0.413 GB
   Rental application docs: highlighted copies                groups=  120 redundant copies=   471    0.089 GB
   Rental application supporting docs                         groups=   93 redundant copies=   190    0.084 GB
   Rentals: inspections, inventory, notices, lease copies     groups=   67 redundant copies=   443    0.042 GB
   Temp files                                                 groups=    8 redundant copies=    10    0.014 GB
   FICA documents                                             groups=    2 redundant copies=     4    0.009 GB
   Deal / sales / contact / property documents                groups=    6 redundant copies=     7    0.005 GB
   HR / agent profile / knowledge / branding                  groups=   36 redundant copies=    52    0.004 GB
   Viewing packs (redacted PDFs)                              groups=    1 redundant copies=     1    0.004 GB
   Stray root scripts/test artefacts, fonts                   groups=   16 redundant copies=    24    0.002 GB
   Prospecting captures, thumbnails, imports                  groups=   36 redundant copies=    38    0.001 GB
   E-sign: originals, templates, signed PDFs                  groups=    4 redundant copies=     4    0.001 GB
   Other                                                      groups=    2 redundant copies=     4    0.000 GB

== Staging
stg size-collision candidates: 54947
stg: exact duplicate groups=16067  redundant copies=24838  redundant GB=5.397
   Property photos (listing galleries)                        groups=15383 redundant copies= 22552    4.376 GB
   PDF splitter TEMP page renders (png/jpg)                   groups=  355 redundant copies=   757    0.524 GB
   Deal / sales / contact / property documents                groups=   71 redundant copies=    92    0.274 GB
   Generated reports / CMAs / presentations / exports         groups=   20 redundant copies=    31    0.116 GB
   FICA documents                                             groups=   14 redundant copies=    34    0.025 GB
   PDF splitter originals (uploaded scans)                    groups=    4 redundant copies=    12    0.021 GB
   Prospecting captures, thumbnails, imports                  groups=  176 redundant copies=  1289    0.017 GB
   HR / agent profile / knowledge / branding                  groups=   13 redundant copies=    27    0.016 GB
   Temp files                                                 groups=    6 redundant copies=    12    0.015 GB
   E-sign: originals, templates, signed PDFs                  groups=   23 redundant copies=    30    0.012 GB
   Other                                                      groups=    1 redundant copies=     1    0.000 GB
   Rentals: inspections, inventory, notices, lease copies     groups=    1 redundant copies=     1    0.000 GB
```

## APPENDIX E — methods
Listings: find -printf over storage/app. Duplicates: SHA-256 of size-colliding files (>=1 KB). PDFs: pdfinfo/pdftotext/pdfimages on first 3 pages. Photos: ImageMagick convert -auto-orient -resize 2560x2560> -strip, seeded random sample (seed 24). PDFs: gs -sDEVICE=pdfwrite 200 dpi (DCT q75, mono Subsample), qpdf --object-streams=generate --recompress-flate. DB: read-only SELECTs via the checkouts own .env credentials.
