# File storage optimisation — every upload is optimised on arrival

> Spec only — **no code written**. Investigated 26 Oct 2026 against `origin/QA1` at `c18f99f38`.
> Source: Johan's rulings 24 Oct 2026 + `/root/file-storage-audit-2026-10-24.md` (sizes, sample savings) +
> `.ai/specs/private-document-storage.md` (where files live and who may read them).
> Pillars: Property (listing photos, filed documents), Contact (FICA / documents), Deal (deal documents), Agent (HR documents).
> Status: **DRAFT — needs Johan's answers to §9 before build.** Nothing here is built.

Line references: `file:line` were checked on QA1 unless marked **†** (taken from the audit's Staging read at `7d4837ad7`,
not re-verified on QA1 — the lane re-checks them before editing).

---

## 1. What this does and why (business terms)

Johan's rulings (24 Oct 2026), recorded as the requirements:

1. **Every file that touches CoreX is optimised at the point of upload, never afterwards** — listing photos, document
   scans, FICA/ID uploads, e-sign template Word files, inspection photos, attachments.
2. **CoreX-signed PDFs are never altered after signing.**
3. **A reduced-size scan is still the signed document, provided the content is unchanged and legible.**

Why it matters: the audit measured ~18 GB of files on each of QA1 and Staging, 29 GB free on a 197 GB data volume, and a
projected ~45 GB per agency per year unoptimised. Ordinary photos are already small; the waste is (a) photos that escaped
the 2560 px cap (22% of photo bytes), (b) scans stored at 300 dpi / full colour (a typical scanned page is 200 KB;
200 dpi brings the 20-file sample down 17% in colour, 40% in greyscale), and (c) several upload paths that do nothing
at all. Today **no stored PDF is ever compressed** and the two image pipelines disagree with each other.

Multi-agency: the second agency (Cape Town, rentals-led) will upload phone photos of inspections and scanned leases.
Every limit below is a per-agency setting with a neutral default; nothing names HFC.

---

## 2. Investigation — (a) every upload entry point and what it does to the file today

Legend: **nothing** = stored byte-for-byte as uploaded. "Kept" = whether the true original survives.

### 2.1 Images

| # | Entry point | What happens today | Original kept |
|---|---|---|---|
| I1 | Web listing gallery / dawn-noon-dusk: `CoreX/PropertyController.php:1452-1458` (validate `image\|max:204800`), `:1562-1565` → `storeImages()` → `Images/PropertyImageStorer.php:129` `storeMany` → `:51` `storeOnDisk` | EXIF orientation baked; HEIC→JPEG via Imagick (`:76`, `HeicConverter.php:56`); `downscale()` (`:147`) caps long edge at **2560**, JPEG **q85** (`:23-25`, `imagejpeg` `:191`) **only if larger than 2560 or not a JPEG** — a small JPEG is stored byte-identical (EXIF + GPS kept). PNG keeps `.png` name with JPEG bytes. | No |
| I2 | Mobile app: `Api/MobilePropertyController.php:703` `store(..., 'public')`, `:731` `downscale()` | Same storer rules + stored 500 px thumb | No |
| I3 | Thumbnails: `Images/PropertyThumbnailService.php:36-37,176,214` | 500 px, q80, generated once at upload, stored in `thumbs/` | – |
| I4 | Portal/other-agency imports: `Jobs/DownloadP24RowImagesJob.php:256` `Storage::disk('public')->put` | **nothing** (raw CDN bytes). `DownloadPortalPropertyImages` (**†** `:120-148`) does downscale + thumb, `DownloadOtherAgencyStockGalleryJob` (**†** `:194`) and `Importer/P24ImageDownloader` (**†** `:23`) raw | raw is the only copy |
| I5 | Rental inspection photos: `CoreX/RentalInspectionRecordingController.php:983,1018,1075,1157` → `Images/PrivateImageStore.php:48` `store`, `:109` `cleanJpegBytes` | **Second pipeline**: always re-encoded, 2560 px, q85 (`:28-30,139`), EXIF stripped, Imagick fallback for HEIC (`:147`). `file_size_bytes` in DB is the *upload* size, not stored size | No |
| I6 | Fault / work-order / tenant-portal photos: `CoreX/RentalFaultReportController.php:17,651` → `PropertyImageStorer`; tenant uploads `Images/PrivateImageService.php:118-120` → `storeOnDisk(..., 'local')` | Storer rules (I1) | No |
| I7 | Portal copies of private photos: `Images/PortalImageService.php:27-33,119` | 480 px / 1600 px copies made on first view, stored, never cleaned | – |
| I8 | Agent photo: `Images/AgentPhotoNormalizer` (**†** `:26`) | 1200 px square, WebP q82 + JPEG twin | No |
| I9 | Site-form scans `CoreX/RentalInspectionScanController.php:50-54` (`pdf,jpg,jpeg,png,heic,heif`, 20 MB); wet-ink `PrivateScanFile.php:39-47` | **nothing** (kept raw on purpose: "what backs up anything we could not read") | Yes (only copy) |
| I10 | HR ID as two photos: `Agent/AgentPortalController.php:547-553` → `Images/IdDocumentPdfService.php:33-38` | both photos embedded **unchanged** as base64 in a dompdf PDF (≈1.33× photo bytes) | No |
| I11 | Quote / evidence / receipt images: `CoreX/RentalWorkOrderQuoteController.php:42,56,143,148` (`pdf,jpg,jpeg,png,webp,heic,heif`, 20 MB) | **nothing** (HEIC stored as HEIC) | Yes |

### 2.2 PDFs and scans

| # | Entry point | What happens today |
|---|---|---|
| P1 | FICA (encrypted): `Compliance/FicaController.php:1160,1176` (`pdf,jpg,jpeg,png,heic`, 10 MB) → `Compliance/FicaDocumentStorage.php:33` `putUploaded` / `:42` `putBytes` → `Security/MediaCipher.php:53` `encrypt`; applicant form `:285-291`; public link `FicaPublicController` (**†** `:244,253`); wet-ink `FicaWetInkService` (**†** `:95,121`) | **nothing** before encryption; HEIC stored as HEIC. Then `FicaController.php:1414-1431` copies every file again into `contact-documents/` (re-encrypted, new nonce) |
| P2 | Contact documents: `CoreX/ContactDocumentController.php:21-43` (rule `Concerns/ValidatesDocumentUploads.php:36`, 20 MB) | **nothing** |
| P3 | Property documents: `CoreX/PropertyFileController.php:60` (`local`) | **nothing** |
| P4 | Deal documents: `Dr2/DealDocumentController.php:24-39` (`file\|max:20480`, no mimes); `DealV2/DealV2Controller.php:458-470`; `DealV2/DealStepController.php:91,181` (10 MB, no mimes) | **nothing** |
| P5 | Shared drive: `Documents/SharedDriveService` (**†** `:156-163`) | **nothing** |
| P6 | Rental application documents: `RentalApplicationController` (**†** `:1850,1924`), `RentalApplicationReviewController.php:1638`, `RentalApplicationSigningController` (**†** `:1282…1748`) | **nothing** (a highlighted copy is rasterised separately, **†** JPEG q90) |
| P7 | HR / staff: `Agent/AgentPortalController.php:472,504`; `StaffTakeOnController.php:399-405`; `Compliance/UserDocumentAdminController` (**†** `:36-44`); onboarding / screening / leave (**†** §8 of audit) | **nothing** |
| P8 | Quote PDFs: `RentalWorkOrderQuoteController.php:42-56` | **nothing** |
| P9 | E-sign originals sent for signing: `Docuperfect/SignatureController.php:93,103` (`pdf,doc,docx`, 20 MB); `SalesDocumentController.php:82,95` | **nothing** |
| P10 | Wet-ink returns (signed paper, scanned): `Docuperfect/SigningController.php:2767-2772` (`pdf,jpg,jpeg,png`, 20 MB) | **nothing** — this is the signed document |
| P11 | Recipient supporting documents: `SigningController.php:2868-2876` (10 files × 15 MB) | **nothing** |
| P12 | **PDF Splitter**: uploads `Tools/PdfSplitterController.php:215` (50 MB, 20 files), originals `:254-255,368,461` → `private/splitter/originals`; page renders at 200 dpi PNG (`:2090-2104`) + 90 dpi review thumbs (`:977-1030`) in `private/splitter/tmp`; outputs `:898-901`; zips `:1329`; filing then writes a 3rd copy | **nothing** to originals/outputs; tmp renders are the 6.5 GB (QA1) problem and are **never deleted** |
| P13 | PDF Suite "Compress" (user tool): `Tools/PdfSuiteController.php:36-44` — Ghostscript `-dPDFSETTINGS=/screen\|/ebook\|/printer` | **The only Ghostscript call on a stored-or-streamed PDF.** Output is streamed back to the user; no upload is compressed at rest |
| P14 | Signed PDFs (CoreX-generated): `Docuperfect/SignaturePdfService.php:63-64,159-160` (`client_signed.pdf`, `final_signed.pdf`), flattened pages `DocumentFlattener.php:996-1010` (lossless PNG level 6) | **write-once, never altered after generation** (alteration happens on page images *before* the PDF). No SHA-256 of the PDF is stored |
| P15 | Market reports `MarketReports/MarketReportIngestService.php:50` | **nothing**; `file_hash` UNIQUE per agency (dedupe key) |

### 2.3 Word documents and e-sign templates

| # | Entry point | What happens today |
|---|---|---|
| W1 | E-sign importer `Docuperfect/DocumentImporterController.php:174-181` (`extensions:docx`) | saved to `storage/app/public/imports/temp`, parsed, then `@unlink` (`:282,331,335`). **Never kept** → nothing to optimise (but note it briefly sits on the public disk — report-only, §10) |
| W2 | Rental notice templates `CoreX/RentalNoticeTemplateController.php:133,~170` (`docx`, 10 MB) | original `.docx` copied byte-for-byte to `rental-notice-templates/{agency}/…` on `local` and kept |
| W3 | E-sign "upload and send" `SignatureController.php:93-103`, `SalesDocumentController.php:82-95` (`pdf,doc,docx`) | stored raw; recipients see the page-image render |
| W4 | Supporting documents `SigningController.php:2869`; rental application supporting `…ReviewController.php:1638` (`doc,docx` allowed) | raw |
| W5 | DocuPerfect template page images `Docuperfect/TemplateController.php:441-445` (`Storage::put("docuperfect/templates/{id}/page-{i}.png")` from client base64); web-template flatten `Docuperfect/WebTemplatePdfService` (**†** `:93,414`, pdftoppm 200 dpi PNG) | PNG as sent / as rendered, lossless, one per page (108 MB on the live-testing copy) |
| W6 | Knowledge / PPRA transformation docs `Admin/KnowledgeController.php:59`, `Admin/PpraTransformationController.php:94` | raw |

### 2.4 Other

Imports (CSV/XLSX, **†** audit §9), market-report PDFs (P15), portal captures, presentation uploads (`presentation_uploads.content_hash`),
**communications media** (`Communications/CommunicationStorageService.php:41-53`: path = SHA-256 of the plaintext, write-once, encrypted).
These are **not optimised** by this spec — §6.4 lists why each is excluded.

### 2.5 What the above adds up to

* Three different image pipelines (`PropertyImageStorer`, `PrivateImageStore`, `AgentPhotoNormalizer`) plus raw writers (I4, I9, I11).
* **~35 PDF/document upload sites, none with any size treatment**, each calling `->store()` itself.
* Hash columns: none on `documents`, `fica_documents`, `user_documents`, deal/quote/rental documents (audit Appendix A §0) — so
  neither "was this already optimised?" nor "is this a duplicate?" can be answered from the data today. The ledger in §5.4 fixes the first.

---

## 3. Investigation — (b) tooling present and missing

Checked on this box (the host of Staging and QA1), 26 Oct 2026:

| Tool | Status |
|---|---|
| PHP GD (8.2, 8.3, 8.4 pools) | present — JPEG, PNG, **WebP**, **AVIF** support compiled in |
| PHP Imagick | present for **php8.2 and php8.3** (`conf.d/20-imagick.ini`), **not** php8.4 (serves other sites, not CoreX). ImageMagick 6.9.12; formats incl. AVIF, HEIC, WEBP, JPEG, PDF. Policy: 1 GiB memory, 1 GP area |
| Ghostscript | `/usr/bin/gs` **10.02.1** — can down-sample, JPEG-recompress, greyscale. **Cannot write JBIG2** |
| qpdf | `/usr/bin/qpdf` **11.9.0** — lossless object-stream / flate recompress, `--check`, linearise |
| Poppler | `pdftoppm`, `pdfimages` (+ `pdfinfo`, `pdftotext` used by the audit) |
| `convert` (ImageMagick CLI), `tesseract` | present (tesseract not needed here) |
| **Missing** | `jbig2enc`, `ocrmypdf`, `mozjpeg` / `jpegoptim`, `optipng` / `pngquant` / `oxipng`, `veraPDF` (PDF/A validation), `libvips`. Intervention Image / Spatie Image are **not installed and not needed** (composer has only `barryvdh/laravel-dompdf`). `league/flysystem-aws-s3-v3` absent (S3 unusable) |

Consequences for the design:

* **Images**: GD can do everything the current pipelines do; Imagick adds shrink-on-load (`jpeg:size`), progressive JPEG, 4:2:0
  sampling, lossless metadata strip and HEIC. Use **Imagick when the running pool has it, GD otherwise** — capability-detect at
  runtime (the audit found a code comment claiming the queue/CLI PHP lacks Imagick; on this box php8.2 CLI has it, so the claim is
  stale or pool-specific — step 0 settles it by probing the actual FPM pool and queue worker binary).
* **PDFs**: Ghostscript `pdfwrite` is the only available engine that can re-sample embedded images; qpdf for lossless passes and
  for `--check` structural verification. No JBIG2, so already-1-bit CCITT scans (~50 KB/page) are at the floor and are left alone.
* **Environment parity (AT-169 lesson, BUILD_STANDARD §8)**: live is a different host (62.238.31.82). `gs`, `qpdf`, `pdftoppm`,
  `pdfimages`, `php-imagick` and the PHP version of the live pool must be diffed against this box **before** promotion, or the
  optimiser silently degrades to "store as received" on live (by design, §5.6) and nobody notices. The spec makes the degraded
  state visible (ledger `engine_unavailable`, Admin status line) so it cannot go quiet.
* Format choice: WebP/AVIF would save 30% / 53% on typical photos (audit §4a) but P24 receives **bytes** (`Property24ListingMapper`
  base64) and Private Property fetches **URLs** that must stay stable JPEGs. **JPEG stays the master format.** A WebP/AVIF *derivative* for
  CoreX's own screens is parked (§10), not part of this spec.

---

## 4. Principles

1. **One door.** Every upload goes through one service. Callers keep owning *where* the file is stored (disk, path, encryption,
   DB row); the service owns *what the bytes become*. This keeps `FicaDocumentStorage` encryption, the private-disk rules of
   `private-document-storage.md` and every existing path/URL contract exactly as they are.
2. **Never worse, never lost.** If anything goes wrong or the result is not clearly smaller and verified, the file is stored **as
   received**. The user never sees an optimiser error.
3. **Optimise before encrypt.** New FICA/comms-style uploads are plaintext at the moment of upload, so optimisation happens first and
   encryption second — no decrypt step on the upload path. (Backfill is the only path that must decrypt, §6.)
4. **Legibility over size.** Per-profile floors that an agency setting cannot go below.
5. **Signed means untouched.** Protection is a guard in the service, checked first, not a convention callers must remember.
6. **Measure everything.** One ledger row and one log line per file: before/after bytes.

---

## 5. Proposed design — (c) single pipeline

### 5.1 The service

```
App\Services\Files\FileIntake                      // the one door
  ::process(UploadedFile|string $source, FileIntakeContext $ctx): FileIntakeResult   // transform only, returns a temp file
  ::storeUploaded(UploadedFile $f, string $disk, string $dir, FileIntakeContext $ctx): StoredFile
        // convenience for callers that today do $file->store($dir, $disk): returns path, size, mime, sha256, report
App\Services\Files\FileIntakeContext               // agency id, FileProfile, subject (type,id) nullable, protected flag, source ('upload'|'backfill')
App\Services\Files\FileProfile                     // enum: LISTING_PHOTO, PRIVATE_PHOTO, DOCUMENT_IMAGE, SCAN_PDF, ID_FICA, OFFICE_DOC, TEMPLATE_PAGE, SIGNED_PROTECTED, PASSTHROUGH
App\Services\Files\Handlers\{ImageHandler, PdfHandler, OfficeHandler, PassthroughHandler}
App\Services\Files\ProtectedFileGuard              // §5.5
App\Services\Files\FileSettingsResolver            // agency row merged over config('file-optimisation') defaults, clamped to floors
App\Services\Files\OptimisationLedger              // writes file_optimisations rows
```

`FileIntakeResult`: `{ path (temp), mime, bytesBefore, bytesAfter, sha256Before, sha256After, state, reason, engine, pages, dpiBefore, dpiAfter, ms }`.

Type is detected by **content sniff** (`finfo`, `%PDF`, zip central directory), never by client extension or client MIME
(the importer already hit this exact trap — `DocumentImporterController.php:81-92`).

The existing classes become thin callers, not rewritten: `PropertyImageStorer::storeOnDisk` and `PrivateImageStore::cleanJpegBytes` call
`ImageHandler` (so there is **one** image pipeline, with the existing public methods and test suite intact); `HeicConverter` and
`ImageOrientationNormalizer` are reused by `ImageHandler`, not duplicated.

### 5.2 Per-type rules, defaults and agency settings

Settings live in one new per-agency row (§5.3). "Floor" = value the setting cannot be set below/above (protects legibility).

| Profile | Used by | Default rules | Agency setting (default · floor–ceiling) |
|---|---|---|---|
| `LISTING_PHOTO` | I1 I2 I4 (public gallery, portal imports, mobile) | decode → bake EXIF orientation → long edge ≤ **2560** → JPEG **q85**, progressive, 4:2:0 → strip **GPS/EXIF** → PNG/WebP/HEIC without alpha → JPEG. **Keep the received bytes if the re-encode is not ≥ 10% smaller and nothing needs stripping** (audit: re-encoding an ordinary photo *grows* it 12.6%). Thumbnail unchanged (500 px q80) | `photo_max_edge` 2560 · 1600–4000; `photo_jpeg_quality` 85 · 70–95; `strip_photo_location` on |
| `PRIVATE_PHOTO` | I5 I6 I7-sources I11-images (inspection, fault, work-order, tenant) | same engine; EXIF always stripped (existing behaviour, `PrivateImageStore`); HEIC→JPEG | same two settings (shared with above) |
| `DOCUMENT_IMAGE` | a *photo of a paper document*: ID/POA photos, receipts, invoices, wet-ink JPEG/PNG, HR IDs (I10 input) | colour kept, long edge ≤ **2400** (A4 at ~200 dpi = 2339), JPEG q85, orientation baked, EXIF stripped, HEIC→JPEG | `doc_image_max_edge` 2400 · **1600**–3600 |
| `SCAN_PDF` | P2–P11 PDFs, P12 originals/outputs, P8 | classify (§5.2.1). Scanned/mixed → Ghostscript `pdfwrite`: colour & grey images down-sampled to **200 dpi** only when above 1.15× target, JPEG q≈80; 1-bit images kept (not down-sampled below 300 dpi) ; fonts/text layer untouched; **colour kept**. Text-only PDFs → qpdf lossless pass only. Result kept only if ≥ **10%** smaller and verified (§5.2.2) | `scan_dpi` 200 · **150**–300; `scan_jpeg_quality` 80 · 65–90; `scan_greyscale` **off** (§9 Q2); `min_saving_percent` 10 · 5–30 |
| `ID_FICA` | P1 FICA (all 3 writers), HR ID PDFs, `contact-documents` filing copy | like `SCAN_PDF`/`DOCUMENT_IMAGE` but **stricter floors**: dpi never < 200, colour never dropped, JPEG q ≥ 80, stricter legibility guard (§5.2.2), never greyscale | not separately configurable below these floors |
| `OFFICE_DOC` | W2 W3 W4 W6 `.docx` (kept ones) | re-zip with maximum deflate; every embedded `word/media/*` image > `doc_image_max_edge` is down-sampled (JPEG q85; PNG with alpha stays PNG, lossless only); **`document.xml`, styles, numbering, rels, headers/footers, `[Content_Types].xml` byte-identical**; legacy `.doc` → passthrough | (uses `doc_image_max_edge`) |
| `TEMPLATE_PAGE` | W5 DocuPerfect page PNGs | **lossless only**: PNG level 9, metadata stripped, no resize, no colour reduction (signing surfaces and field placement depend on exact pixels). Expected gain small (≈ 5–10%) | none |
| `SIGNED_PROTECTED` | P14 and anything §5.5 matches | **bytes never touched.** Ledger row with SHA-256 only | none |
| `PASSTHROUGH` | zip, xlsx, csv, audio, unknown, corrupt, password-protected PDF, animated GIF/WebP, >100 MP image | stored as received; ledger row says why | none |

Cross-cutting settings:

| Setting | Default | Range | Meaning |
|---|---|---|---|
| `optimise_uploads` (master switch) | on | on/off | off = everything is passthrough (support escape hatch; ledger still records) |
| `keep_original_days` | **30** for `ID_FICA`/`SCAN_PDF` only; 0 for photos | 0–90 | see §5.7 and §9 Q1 |
| `max_upload_mb` | existing validator value per entry point | ≤ 50 (platform ceiling = PHP 60 MB / nginx 64 MB) | **size ceiling**: rejected *before* processing with a plain message. Not an optimisation rule; consolidates the scattered `max:` values behind one config so agencies see one number |
| `sync_page_limit` | 40 pages / 15 MB | platform-owned (not agency-visible) | above this the PDF is processed by a queued job (§5.6) |

#### 5.2.1 PDF classification (cheap, before any engine runs)

`pdfinfo` (pages, encrypted?) + `pdfimages -list` on the first 3 pages + `pdftotext` char count — the same method the audit used
(scan = images and < 80 text chars/page). Outcomes: `scanned`, `mixed`, `text`, `encrypted` (→ passthrough), `invalid`
(→ passthrough), `digitally_signed` (→ passthrough, §5.5), `already_small` (every image ≤ target dpi and ≤ ~60 KB/page → passthrough, no engine run).

#### 5.2.2 Verification before the optimised file replaces the received one

All must pass or the received file is stored (state `skipped_unsafe`, reason logged):

1. `qpdf --check` passes on the result; page count equal to the original.
2. Smaller by ≥ `min_saving_percent`.
3. Text layer preserved: `pdftotext` character count within 2% (mixed/text PDFs).
4. **Legibility guard**: rasterise every page of both files at 72 dpi (≤ 20 pages: every page; more: first, last and a deterministic sample of 10) and compare — mean
   difference below a profile threshold (stricter for `ID_FICA`); catches blank, black or garbled pages. Threshold constants are fixed in step 3 against the
   30-document human review set (§8 step 3 acceptance) — **they are not guessed in this spec**.
5. Time: engine finished inside the budget (`gs` 25 s sync; queued job 120 s).

Images: decoded dimensions match the expected resize, output decodes, output ≤ received size (or received kept).

### 5.3 Settings storage, UI and the wizard (Non-negotiables #10a, #9)

* New table `agency_file_settings` (one row per agency, `BelongsToAgency`), model `App\Models\AgencyFileSettings` — same shape as
  `AgencyContactSettings` / `AgencyAuctionSettings`. Defaults live in `config/file-optimisation.php` (platform defaults) so a missing
  row behaves exactly like defaults — no seeder needed, nothing to "travel" with a deploy (BUILD_STANDARD §8 reference-data rule satisfied by construction).
* Screen: one **"Files & storage"** card on Company Settings (`routes/web.php:5728` `admin.company-settings`). One row of settings, not a list → no
  search/sort/filter/pagination apply; archive/restore does not apply to a settings row (it is not a user-created entity) — stated per BUILD_STANDARD §1.
* Permission: a new key in `config/corex-permissions.php` (name follows the existing company-settings keys; lane confirms in step 6), route middleware, controller check, sidebar not needed (card on an existing page).
* **Setup Wizard**: a new "Files & storage" step in `config/agency-onboarding-copy.php` with `source => 'file_settings'` (new source key, same mechanism as `rental_inspections`/`title_deeds`), every control with `explain` + `affects`, canonical saver wired per `.ai/specs/agency-onboarding-setup.md` §6.1 — **`$request->has()` guard on every boolean** (a wizard step posts a subset; a saver that coerces an absent checkbox to false would wipe `strip_photo_location` / `scan_greyscale`).
* The wizard copy must be plain business language: "Photos are shrunk to 2560 px on upload — big enough for every portal. Scans are saved at 200 dpi — easy to read, a third of the size."
* Audit: setting changes write the existing settings audit entry (who/when/old/new).
* No HFC wording, no agency-1 default. Test: *"what does this look like for the second agency?"* — identical defaults, their own overrides, ledger rows carry their `agency_id`.

### 5.4 The ledger (report line per file)

New table `file_optimisations` (`BelongsToAgency`; system log — no soft delete, insert/update only, never a user-facing list):

```
id, agency_id, disk, path, subject_type, subject_id (nullable polymorphic: documents#, fica_documents#, rental_inspection_photos#…),
profile, state, reason (short code), source ('upload'|'backfill'),
original_sha256, original_bytes, original_mime, stored_sha256, stored_bytes, stored_mime,
pages, dpi_before, dpi_after, engine ('imagick'|'gd'|'gs'|'qpdf'|'zip'), engine_version, ms,
protected_signed (bool), original_retained_until (nullable date), created_at, updated_at
UNIQUE (disk, path)
```

States: `optimised`, `skipped_unchanged` (not worth it), `skipped_protected` (signed), `skipped_passthrough` (type), `skipped_unsafe` (a verification failed),
`failed`, `engine_unavailable`, `pending` (queued job will replace).

* One **log line per file** on a dedicated daily channel `file-optimiser`:
  `OPT ok profile=scan_pdf agency=3 subject=documents#4821 before=5108KB after=1330KB (-74%) pages=12 dpi=300→200 engine=gs ms=412`.
* `UNIQUE(disk,path)` is also the **idempotency key** for the backfill (§6): a file whose current `stored_sha256` matches is skipped without any work.
* The original's SHA-256 + size + page count are kept forever in the row — that is the "content unchanged" evidence trail for a shrunk scan without keeping the bytes.
* Several handy side effects: first hash for `documents`/`fica_documents` files (enables later dedupe, parked §10); `file_size_bytes` stops lying (I5 stored the upload size).

### 5.5 "Never touch signed PDFs" — `ProtectedFileGuard`

A single check, run first, that returns `SIGNED_PROTECTED` (bytes untouched) when **any** of these is true — and **fails closed**: if the check itself errors, the file is protected.

1. The caller's context says `protected = true` (every CoreX-generated signing writer passes it).
2. The path is under `docuperfect/signed-documents/`, `platform-esign/documents/`, `signed-documents/` (`Distribution/SignedDocumentDistributionService`), `rental-job-card-signed-copies/`, or the rental-application sealed cache.
3. The path equals any `signature_templates.signed_pdf_path` / `signed_pdf_client_path`, a `SignedDocumentVersion`/`DocumentSealedVersion` file, a `platform_esign_documents` signed file, or the `documents` row has `source_type` in the signed set (`esign` confirmed at `SignatureService.php:4518,4681`; `lease` and sealed `rental_application` copies — **the lane confirms each against the writer before step 1 closes**; an unconfirmed type is treated as protected).
4. **Generic:** the PDF itself contains a digital signature (`/ByteRange` with `/Sig`) — any change would invalidate it, whoever signed it.

What is deliberately **not** protected (Johan's ruling 3): the *paper-signed document scanned back in* (wet-ink return P10, signed-copy uploads) is an ordinary scan and is optimised
as `SCAN_PDF`/`ID_FICA` — content unchanged, legible, verified (§5.2.2). The distinction in code: *CoreX produced and sealed it* → protected; *a person scanned it* → optimisable.

For the backfill the rule is stricter: **allow-list of sources** (§6.2) plus this guard, so a document type nobody thought of is skipped, not optimised.

Companion hardening (§8 step 8, optional per Q4): store the **SHA-256 of each signed PDF at the moment it is created** (today only a hash of the field data exists — `SignatureService.php:255-258` †). Then "never altered after signing" becomes provable, not just true.
It touches `SignaturePdfService`, which is an e-sign moat-gate file → needs a test diff under `tests/Feature/Docuperfect/SigningView/`.

### 5.6 Sync vs queue, and failure behaviour (prevent-or-absorb)

* **Synchronous by default** — measured costs: Ghostscript ≈ 0.3 s/file (max 8 s greyscale), images < 1 s. The upload request finishes with the file already optimised ("at the point of upload").
* PHP-FPM 8.2 has `max_execution_time=30` and the box has 4 GB RAM: a **time budget** (25 s) and a **concurrency cap** (flock semaphore, 2 simultaneous Ghostscript runs) protect it.
* Over `sync_page_limit` (40 pages / 15 MB) or over budget: the file is stored as received with state `pending`, and `OptimiseStoredFile` (queued) replaces it atomically within minutes. The business effect: a very large scan may take a minute to shrink after upload; nothing is lost or blocked meanwhile.
* Absorb, never break: corrupt / password-protected / zero-byte / truncated files, decompression bombs (pixel count checked with `getimagesize` before decode, cap 100 MP), CMYK / 16-bit / progressive / animated inputs, EXIF orientation 1-8, transparency, engine missing, engine timeout, disk full on temp → **store as received**, ledger `failed|skipped_*|engine_unavailable`, no exception reaches the user.
* Atomic replace: write temp → verify → `rename` into place on the same filesystem; no DB transaction is held open during an engine run.
* Temp files go to `FILE_OPTIMISER_TMP` (default `storage_path('app/temp')`, overridable to the data volume) and are removed in `finally`. Disk-hygiene rule: nothing multi-GB is ever written to `/`.

### 5.7 Is the original kept? — recommendation

* **Photos (listing, inspection, fault, document images): no.** Today's behaviour already discards the original; keeping it would erase the saving.
* **Scans, FICA/ID, wet-ink returns: not permanently — a short safety net.** Keep the received bytes for `keep_original_days` (default **30**) in a quarantine folder (`file-originals/{agency}/{yyyy}/{mm}/{sha256}`, same disk, private; encrypted if the source was encrypted), then discard. The ledger keeps SHA-256 / size / pages of the original forever.
  Why: the pipeline is new and "content unchanged and legible" is a judgement the first weeks should be able to reverse; after 30 days of use with no complaint the original adds cost without value.
  Note: discarding the quarantined original is a **real file deletion**, which Non-negotiable #1 normally forbids — this is why it is Johan's call (§9 Q1), and why it is limited to a *superseded copy of a file that still exists*, never a record.
* **Signed (protected): the only copy is the file; never touched.**

### 5.8 Wiring map — which caller gets which profile

| Caller (file:line) | Profile | Change |
|---|---|---|
| `PropertyImageStorer::storeOnDisk` (`:51`), `downscale` (`:147`) | `LISTING_PHOTO` / `PRIVATE_PHOTO` (by disk) | delegate to `ImageHandler`; keep public API |
| `PrivateImageStore::cleanJpegBytes` (`:109`) | `PRIVATE_PHOTO` | delegate to `ImageHandler` (fixes the two-pipeline split) |
| `MobilePropertyController.php:703-731` | `LISTING_PHOTO` | route through storer instead of `->store()` + `downscale()` |
| `DownloadP24RowImagesJob.php:256`, other-agency/portal import jobs | `LISTING_PHOTO` | optimise before `Storage::put` |
| `RentalWorkOrderQuoteController.php:56,148` | `DOCUMENT_IMAGE` / `SCAN_PDF` by sniff | `storeUploaded` |
| `RentalInspectionScanController.php:54`, `PrivateScanFile.php:47` | `SCAN_PDF` / `DOCUMENT_IMAGE` | `storeUploaded` (original is no longer the only copy of a *scan*; ledger holds its hash) — **requires Johan's Q1 since these were kept raw deliberately** |
| `FicaDocumentStorage::putUploaded` (`:33`) / `putBytes` (`:42`) | `ID_FICA` | optimise bytes **before** `MediaCipher::encrypt` (`:53`); all three FICA writers + wet-ink go through it already |
| `FicaController.php:1414-1431` filing copy | – | copies the already-optimised bytes; no extra step |
| `ContactDocumentController.php:33`, `PropertyFileController.php:60`, `DealDocumentController.php:34`, `DealV2Controller.php:470`, `DealStepController.php:91,181`, `SharedDriveService`†, rental-application controllers†, `AgentPortalController.php:504`, `StaffTakeOnController.php:405`, HR/PPRA/onboarding writers† | `SCAN_PDF` / `DOCUMENT_IMAGE` / `OFFICE_DOC` by sniff | replace `$file->store()` with `FileIntake::storeUploaded` |
| `SigningController.php:2772` (wet-ink), `:2876` (supporting) | `SCAN_PDF` | `storeUploaded` |
| `SignatureController.php:103`, `SalesDocumentController.php:95` | `SCAN_PDF` / `OFFICE_DOC` | `storeUploaded` |
| `RentalNoticeTemplateController.php:~170` | `OFFICE_DOC` | optimise before `put` |
| `TemplateController.php:445` | `TEMPLATE_PAGE` | lossless pass before `put` |
| `PdfSplitterController.php:255,368,461` (originals), `:898` (outputs) | `SCAN_PDF` | originals optimised on arrival; outputs are cut from the optimised original (so they inherit it) |
| `IdDocumentPdfService.php:33-38` | `DOCUMENT_IMAGE` first, then embed | photos are shrunk *before* base64 embedding |
| `SignaturePdfService.php:63-64,159-160`, `DocumentFlattener.php:1010` | `SIGNED_PROTECTED` | unchanged; hash recorded (step 8) |
| `CommunicationStorageService`, `MarketReportIngestService`, `PresentationUploadProcessor`, imports | **not wired** | §6.4 |

Validators with only `file\|max` (no mimes) — `Dr2/DealDocumentController.php:30`, `DealV2Controller.php:466`, `DealStepController`, mobile compliance, onboarding, screening — get the shared allow-list rule `Concerns/ValidatesDocumentUploads.php:36` **as part of being wired to the one door** (the door sniffs content; an unknown type is passthrough, but an executable should not be accepted at all). This is a side-effect of wiring, named here so it is not a surprise.

---

## 6. Backfill — (d)

### 6.1 Command

```
php artisan files:optimise
    --dry-run            # default-safe: analyse only, write nothing (no temp output, no ledger rows)
    --sample=N           # with --dry-run: really optimise N random candidates into scratch, report measured savings, delete scratch
    --source=documents|fica|user_documents|shared_drive|property_photos|inspection_photos|rental_scans|quotes|...   (repeatable)
    --agency=ID          # required unless --all-agencies
    --profile=…          # restrict
    --batch=200          # rows per chunk (chunkById)
    --limit=N            # stop after N files
    --max-seconds=S      # stop cleanly after S seconds (cron/overnight friendly)
    --include-encrypted  # FICA / encrypted documents: decrypt → optimise → re-encrypt (off by default; separate sign-off)
    --concurrency=1
```

* **Idempotent:** a file with a ledger row whose `stored_sha256` equals the file's current hash is skipped without running an engine. A second run therefore changes nothing and costs only the hash read. Files with no row are analysed and, if not worth it, get a `skipped_unchanged` row so they are never re-analysed.
* **Batched & resumable:** `chunkById`, per-file time budget, `--max-seconds`; progress is the ledger itself (no checkpoint file to lose).
* **Logged:** same `file-optimiser` channel with `source=backfill`; at the end a summary table: files seen / optimised / skipped by state / bytes before / bytes after / % saved, plus a per-source CSV written to `storage/logs/` (data volume path via config).
* **Never touches signed PDFs:** allow-list sources (§6.2) + `ProtectedFileGuard` (§5.5) — double lock; dry-run lists the count of protected files it saw, so the exclusion is visible.
* **Path and extension never change** in the backfill (§6.3).
* **Replace procedure per file:** read → engine to temp → §5.2.2 verification → copy into `…/.opt-{id}` on the same disk → size check → one short DB transaction (update `size`/`mime`/hash columns where the table has them, write ledger row; quarantine the original if `keep_original_days > 0`) → `rename` over the original. A crash leaves either the old file or the new file, never a row without a file.
* Agency isolation: every query is explicit `where agency_id = ?`; the command iterates agencies one at a time. Console code is the only place the global scope is bypassed, by design, never in request code.
* Output is also safe on QA1's *partial* copy: a row whose file is missing is reported (`missing`), not failed (same convention as `documents:privatise`).

### 6.2 Allow-listed sources (explicit, not "everything minus signed")

`documents` where `source_type` ∈ {`upload`, `contact`, `deal_upload`, `deal_step`, `deal`, `pdf_splitter`, `rental_application` (uploaded applicant files only)} and not matched by the guard; `fica_documents` + `documents.source_type='fica'` (only with `--include-encrypted`); `user_documents`; `shared_drive_files`; listing photos (paths from `properties` JSON columns + `property_files`); `rental_inspection_photos`; `rental_inspection_scans`; rental quote/receipt files; `splitter/originals` and `splitter/output`; `.docx` in `rental-notice-templates/`; `docuperfect/templates/*/page-*.png` (lossless).
Anything else is out.

### 6.3 Why the backfill never renames or changes extension

* The P24 refresh-cost contract (CLAUDE.md "Portal sync"): `properties.p24_image_signature` is an md5 of the **path list** (`Property.php:3615`). Changing a path would change the signature and force a full photo re-upload to Property24 on the next refresh of every touched listing. Same-path content changes do **not** alter the signature, and portals keep the larger copy they already hold. So the backfill keeps JPEG→JPEG and PNG→PNG (downscale + lossless), never PNG→JPEG with a new name. (New uploads *may* convert PNG→JPEG because no signature exists yet.)
* Private Property fetches the stable URL — a same-path, smaller file is invisible to it.
* `thumbs/` are left as they are.

### 6.4 Excluded on purpose (and why)

| Excluded | Reason |
|---|---|
| **Communications media** (WhatsApp/email attachments, 1.7 GB, ~0.6 GB/month — the biggest real grower) | Path = SHA-256 of the *received* bytes, `content_hash` verifies it, spec says write-once/no unlink (`wa-media-durability-transcription.md`). Shrinking breaks the evidence invariant. **§9 Q3** |
| Market reports | `market_reports.file_hash` UNIQUE per agency is the dedupe key; reports are text PDFs (≈ 49 KB/page) |
| Presentation uploads | `presentation_uploads.content_hash` |
| Generated text PDFs (payslips, proformas, CoC, eval certs, notices) | already small vector/text; no gain |
| Signed / sealed anything | Johan's ruling 2 |
| Flattened signing PNGs (`flattened/page_n.png`) | working files of a *live* signing; lossless recompress after all parties signed is possible later (parked) |
| PDF-splitter tmp renders (6.5 GB on QA1) | derived and regenerable: the right fix is **deletion after N days** (a retention spec), not compression. Reported, not changed (scope lock) |

### 6.5 Rollout on QA1

dry-run → `--dry-run --sample=50` per source → **Johan's person reviews the sample pages** (esp. ID documents, handwriting, small print) → real run per source, smallest/lowest risk first (photos → documents → splitter originals → rental scans → FICA last, with `--include-encrypted`). The audit's own caveat applies: legibility was judged by eye on two files; this is the gate that replaces that.

---

## 7. List-screen / CRUD / scoping statement (BUILD_STANDARD §1)

* No new entity, no list screen: one settings row per agency (Company Settings card), one system ledger (not user-facing), one console command. Search / sort / filter / pagination / archive / restore therefore do not apply; this is stated rather than omitted.
* Scoping: settings and ledger are `BelongsToAgency` (global `AgencyScope`); the settings card is agency-admin permissioned; no download or API endpoint is added. Existing document/FICA/photo read paths (`DocumentAccessService`, FICA stream, signed URLs) are unchanged and keep their own own/branch/agency checks.
* Domain events (#9): none — no cross-pillar reactivity is introduced. No new `/api/v1` endpoint.
* Permissions: settings-card key only (§5.3). Nav: card on an existing page. Wizard: §5.3 step.
* "Deliberately NOT in the wizard" candidates (Johan's call per #10a; recommended to stay out): `max_upload_mb` ceilings per entry point (a single global number is in; per-feature numbers stay in code), `keep_original_days` if Johan rules a fixed 30.

---

## 8. Build steps (≈ 1 lane-day each, Sonnet build lanes, one cleared lane per step) and tests

Tests: one targeted file per step through `scripts/lane-test.sh <file>` (Non-negotiable #13 — never the suite). Every step needs a real-world fixture set under `tests/Fixtures/files/` built from messy inputs, not clean samples (BUILD_STANDARD §2/§5): 300 dpi bilevel mandate scan; colour JPEG scan; 12 MP phone photo with orientation 6 + GPS; HEIC; PNG with alpha; CMYK JPEG; 8160×6120 photo; text-only PDF; mixed PDF; password-protected PDF; truncated PDF; 0-byte file; PDF with a digital signature; `.docx` with large embedded images; `.docx` with none; a pixel-bomb PNG.

| # | Step | Contents | Tests (file) |
|---|---|---|---|
| 0 | **Probe & parity** (½ day, with step 1) | `files:probe` (read-only): per FPM pool + queue worker binary report `gs`/`qpdf`/`pdftoppm`/`imagick`/GD-AVIF/PHP version; run on QA1, Staging, and *the live host (read-only, Johan's order needed to touch live)* | none (manual output attached to report) |
| 1 | **Foundation** | `config/file-optimisation.php`; migrations `agency_file_settings` + `file_optimisations` (+ `schema:dump`, strip `DEFINER`, commit snapshot); models; `FileSettingsResolver` (clamp to floors); `FileIntake` skeleton, sniffing, `PassthroughHandler`, `ProtectedFileGuard`, ledger writer, `file-optimiser` log channel, concurrency semaphore | `tests/Feature/Files/FileIntakeFoundationTest.php`: sniff beats extension/MIME lies; guard matrix (path, row, `/ByteRange`, fail-closed on error); settings clamp; **agency A vs B different values, no leakage**; ledger idempotency |
| 2 | **Image handler** | `ImageHandler` (Imagick-or-GD, shrink-on-load, progressive, strip, never-bigger rule, HEIC, pixel cap); `PropertyImageStorer`, `PrivateImageStore` delegate; mobile + import jobs wired; fault/work-order photos inherit | extend `Images/PropertyImageStorerTest.php`, `PropertyThumbnailServiceTest.php`; new `Images/ImageHandlerTest.php` (all image fixtures; output never larger; GPS gone; orientation right; PNG alpha kept; ≤ cap) |
| 3 | **PDF handler + document upload sites** | classifier, Ghostscript profile, qpdf lossless, §5.2.2 verification & legibility guard, time budget, queued `OptimiseStoredFile`; wire contact/property/deal/shared-drive/quote/rental-app/HR/splitter-originals/wet-ink/supporting/upload-and-send writers (split 3a core+first half of writers, 3b rest if it runs over a day); validators get the shared allow-list. **Acceptance: Johan's reviewer looks at 30 before/after pages** and sets the guard thresholds | `Files/PdfHandlerTest.php` (page count, text preserved, never larger, signed PDF byte-identical, encrypted/corrupt → stored as received, timeout → as received, queue path); one smoke test per wired controller only where the controller test already exists |
| 4 | **FICA / ID path** | `ID_FICA` profile, optimise-before-encrypt in `FicaDocumentStorage`, `IdDocumentPdfService` shrink-then-embed, HEIC handling, stricter floors; decrypt→optimise→re-encrypt helper for backfill | `Files/FicaOptimiseTest.php`: stored bytes still `CXE1`; decrypt equals optimised output; floors can't be lowered; filing copy decrypts; mixed encrypted/plain state safe |
| 5 | **Word & template files** | `OfficeHandler` (re-zip, media down-sample, XML byte-identical proof), `TEMPLATE_PAGE` lossless; wire notice templates, upload-and-send, supporting docs, `TemplateController:445` | `Files/OfficeHandlerTest.php`; docx still opens through `NoticeDocxConverter` and the CDS importer; e-sign moat gate: none of the gate files is expected to change in this step; if one does, add the test diff under `tests/Feature/Docuperfect/SigningView/` |
| 6 | **Settings card + Wizard step** | Company Settings "Files & storage" card, permission key, saver (with `$request->has()` guards), wizard step + copy + current-value resolution (§6.2 of the wizard spec), settings audit entry; Blade render gate + Alpine sweep per Standards −1/−1u | `Files/FileSettingsScreenTest.php`; existing wizard test additions; saver does not wipe unrendered fields |
| 7 | **Backfill command** | `files:optimise` + source registry + dry-run/sample + ledger skip + atomic replace + quarantine + summary/CSV; **run on QA1 (never live without Johan's order)**; report before/after | `Files/FilesOptimiseCommandTest.php`: dry-run writes nothing; real run idempotent (2nd run = 0 changes); signed/protected/non-allow-listed untouched (sha equal); path+ext unchanged; missing file reported; per-agency scope; crash-safety (kill between copy and rename) |
| 8 | **Signed-PDF fingerprint** (optional, Q4) | `SignaturePdfService` writes SHA-256 + size of `client_signed.pdf`/`final_signed.pdf` to the ledger at creation | `tests/Feature/Docuperfect/SigningView/` new test (moat gate) |

Total ≈ 8–9 lane-days; steps 2 and 3 can run in parallel after step 1 (different files), 4 after 3, 5 any time after 1, 6 after 1, 7 last.
Every step: `php -l`, `view:clear`/`route:clear`/`cache:clear`, the single relevant test through `lane-test.sh`, update this spec in the same commit, QA1 only, `.ai/CHAT_STARTER.md` entry at the end (#i), env-parity note on the promotion.

---

## 9. Decisions for Johan (business only)

1. **Short safety copy of the original?** After a scan, ID or FICA upload is shrunk, keep the file exactly as received for **30 days**, then delete it (we always keep a fingerprint and the page count). Alternatives: no copy at all (biggest saving, no way back), or keep forever (almost no saving on scans). Deleting that spare copy is the one place this breaks the "no hard deletes" rule — so it needs your yes. *Recommendation: 30 days, then delete.* Also confirms that rental site-form scans, which were deliberately kept raw so the paper always backs up the reading, now also get shrunk.
2. **Colour or greyscale scans?** Greyscale saves a further ~20% on scans (40% vs 17% in the audit sample) but loses coloured stamps, ink and the colour of IDs. *Recommendation: keep colour for everything now; it can be a per-agency switch later if storage ever pinches.* (Built as an agency setting, default off — answer only changes the default.)
3. **Email and WhatsApp attachments (the fastest-growing pile, ~0.6 GB a month): leave them alone for now?** Shrinking them means the stored file is no longer identical to what was received, which weakens them as evidence of what a client sent. *Recommendation: leave out of this build; revisit once you decide how much that evidence matters.*
4. **Fingerprint every CoreX-signed PDF at the moment it is created** so "never altered after signing" can be proven, not just promised (small change inside the e-sign signing code). *Recommendation: yes.*

Not asked of Johan (engineering calls, made here): JPEG stays the photo format; sizes/dpi/quality defaults; sync-first with queue overflow; ledger table; backfill keeps file names. Business consequence in one line: **a listing's photos and documents look the same on screen, the files are roughly a third smaller, and old files are shrunk the same way from a command that can first be run as a preview.**

Note for the record (not a question): Johan's ruling covers FICA/ID uploads, so those are included here with the strictest legibility floors. The audit §6 advised never recompressing IDs pending legal advice on "true copy"; that caution is superseded by the ruling but the attorney confirmation the audit asked for is still worth having before the FICA backfill (step 7, `--include-encrypted`) is run on live.

---

## 10. Risks, open items and report-only findings

**Risks**

| Risk | Mitigation |
|---|---|
| Illegible/garbled scan (handwriting, small print, ID holograms) | colour kept, dpi floors, legibility guard, 30-document human review before any bulk run, 30-day safety copy, QA1-first backfill |
| Live host lacks `gs` / `php-imagick` / matching PHP version (AT-169 repeat) | step 0 probe; degraded mode = store as received + visible `engine_unavailable`; parity check on every promotion |
| P24 refresh-cost regression from changed image paths | backfill never changes path/extension (§6.3); new uploads happen before any signature exists |
| Upload slowness / 4 GB box under load | sync budget 25 s, semaphore of 2, queue overflow |
| Encrypted-file corruption in FICA backfill | optimise-before-encrypt for new files; backfill verifies decrypt(new) == optimised bytes before replacing, keeps quarantine, FICA last, separate flag |
| Restic nightly backup churn after a backfill | one-off growth equal to the rewritten bytes; schedule the real run after a successful backup and before the next prune window |
| In-place replacement vs "no hard deletes" | only superseded bytes of a file that still exists; quarantine purge is Q1 |
| `file_size_bytes`/`size` columns stale after replace | backfill + upload path update them in the same transaction |
| Wizard saver wipes unrendered booleans | `$request->has()` guard + test (step 6) |

**Parked (separate specs, not part of this build)**: delete splitter tmp/in/out after N days (the biggest single saving, ≈ 6.5 GB on QA1); retention/purge classes (audit §6.3); S3/object storage (driver + credentials absent, code assumes local paths); WebP/AVIF derivatives for CoreX screens; lossless recompress of flattened signing PNGs once fully signed; content-hash dedupe now possible via the ledger; the hfcoastal.co.za 6.35 GB photo cache on the nearly-full `/` disk (another repo/host).

**Report-only (outside scope, not changed — scope lock #2)**
* `Docuperfect/DocumentImporterController.php:174` writes the uploaded Word/PDF to `storage/app/public/imports/temp` (web-served) before parsing; it is `@unlink`ed afterwards (`:282,331,335`), so exposure is brief but it is a document on the public disk — same class as `private-document-storage.md` W1–W5.
* Several upload validators accept any type (`file|max` only) — listed in §5.8; folded into step 3 only because the one door must sniff anyway.
* `PropertyImageStorer` stores PNG uploads as JPEG bytes under a `.png` name (I1); new uploads fixed by step 2, old ones left as is.
* `rental_inspection_photos.file_size_bytes` records the upload size, not the stored size (I5).
