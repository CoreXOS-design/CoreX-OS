# PDF Suite — Spec

> Status: Approved (2026-05-09) — supersedes standalone "PDF Splitter" navigation.
> Owner: Andre. Reviewer: Johan.

---

## 1. What & Why

The existing **PDF Splitter** is being expanded into a **PDF Suite** — a single entry point in the sidebar that hosts the splitter plus seven new PDF utilities every HFC agent needs day-to-day:

| # | Tool | Slug | Why an agent needs it |
|---|------|------|------------------------|
| 0 | PDF Splitter (existing) | `split` | OCR-driven pack splitting (mandates, FICA bundles) |
| 1 | PDF Compressor | `compress` | Bond applications, scanned FICA — banks/attorneys reject >10MB attachments |
| 2 | PDF Merger | `merge` | Build offer packets: OTP + FICA + proof of funds + ID into one file |
| 3 | Image → PDF | `image-to-pdf` | Phone photos of signed docs / IDs / utility bills → proper PDF |
| 4 | PDF Rotator | `rotate` | Fix sideways phone-scanned IDs and utility bills |
| 5 | Page Reorder / Delete | `reorder` | Drag pages into order, drop blanks from scans |
| 6 | Password Protect / Unlock | `protect` | Lock commission statements & mandates with sensitive figures |
| 7 | PDF Redactor | `redact` | Black out ID numbers / bank details before sharing — POPIA win |

The Splitter's URLs and routes remain intact (no broken bookmarks); the sidebar simply re-points to the new hub at `/tools/pdf-suite`.

---

## 2. Pillar Connections

PDF Suite is a **utility module** — files in, files out. It does not own pillar data.
However, it **reads** from pillars where helpful (future-friendly hooks, not built day one):

- **Deal** — none day one. Future: "attach merged packet to Deal" button.
- **Contact** — none day one. Future: "save signed FICA to Contact's documents".
- **Property / Agent** — n/a.

Day-one scope is pure tools. Pillar write-back is deferred and tracked in ROADMAP, not this spec.

---

## 3. UI Placement & Navigation

### Sidebar
- The existing entry **"PDF Splitter"** is renamed to **"PDF Suite"**.
- Same icon, same position in the sidebar, same permission gate (`access_pdf_suite` — see §6).
- Active state: `request()->routeIs('tools.pdf_suite.*') || request()->routeIs('tools.pdf_splitter.*')`.

### Hub layout (`/tools/pdf-suite`)
- `<x-page-header title="PDF Suite">` — uses `--brand-default` per design system.
- 8 tool cards in a responsive grid (`grid-cols-1 md:grid-cols-2 lg:grid-cols-4`).
- Each card: icon (in `--brand-icon`), title, one-line description, `rounded-md` 6px corner, `var(--surface)` bg, `var(--border)` border, `transition-all duration-300` hover lift.
- Click → navigates to that tool's own page.

### Tool pages
- Each tool gets its own URL: `/tools/pdf-suite/{slug}`.
- All tool pages share a slim **tool switcher pill bar** at the top, immediately below the page header — pills for each sibling tool, the current one highlighted with `--brand-button`. Lets agents jump between tools without going back to the hub.
- All forms / cards follow `.ai/DESIGN-SYSTEM.md`:
  - `rounded-md` (6px) only — no `rounded-lg` / `rounded-xl`.
  - Inputs: `var(--surface)` bg, `var(--border)`, focus ring `var(--brand-button)`.
  - Primary button: `corex-btn-primary` (`--brand-button` solid, `shadow-lg`).
  - Secondary: `corex-btn-outline`.
  - All hover states: `transition-all duration-300`.
  - Page header: `--brand-default` background.
  - All text via `var(--text-primary)` / `var(--text-secondary)` — no hardcoded slate/gray.
  - Dual-theme verified (dark + light).

---

## 4. Architecture & Data Model

### No new database tables.
Each tool is stateless: upload → process → stream download. Inputs are written to `storage/app/private/pdf-suite/in/{user_id}/{uuid}.pdf` and outputs to `…/out/{user_id}/{uuid}.pdf`, both purged by an existing scheduled cleanup that already runs against the `private/splitter/*` tree (extend its glob to `private/pdf-suite/*`).

### Server-side tooling
Reuses the same external binaries already configured for the splitter (`config/splitter.php`) and adds two more in a new `config/pdf-suite.php`:

| Binary | Used by | Notes |
|--------|---------|-------|
| `qpdf` | rotate, reorder, protect, merge | Already configured |
| `pdfunite` | merge (fallback) | Already configured |
| `gs` (Ghostscript) | compress | New — `pdf_suite.gs_path` |
| `pdftoppm` | redact (rasterise pages) | Already configured |
| Imagick / GD (PHP ext) | image-to-pdf, redact (composite) | Imagick preferred, GD fallback |

If a binary is missing, the tool returns a friendly error pointing to the env key — **never** a 500.

### Controller
Single facade: `App\Http\Controllers\Tools\PdfSuiteController` with method per tool:
`hub`, `compress`, `compressRun`, `merge`, `mergeRun`, `imageToPdf`, `imageToPdfRun`, `rotate`, `rotateRun`, `reorder`, `reorderRun`, `protect`, `protectRun`, `redact`, `redactRun`.

The existing `PdfSplitterController` is untouched — only its URL is reachable via the new hub.

---

## 5. User Flow (per tool)

All seven new tools share the same shape — keeps cognitive load low:

```
1. Agent clicks the tool card on /tools/pdf-suite
2. Tool page loads with a single upload-style card
3. Agent selects file(s) + tool-specific options (e.g. compression level, password)
4. Submit → server processes → streams the resulting PDF as a download
5. Agent stays on the same page; success banner shows; can run another file immediately
```

Tool-specific deltas:

- **Compress** — option: quality (`screen` / `ebook` / `printer`). Default `ebook`.
- **Merge** — multi-file input, drag-reorder list before submit.
- **Image → PDF** — accepts JPG/PNG/HEIC, multi-file, auto-orients EXIF, A4 page size.
- **Rotate** — preview pages with rotation chips (0/90/180/270), apply per-page or all.
- **Reorder** — drag-and-drop thumbnail grid, X-button to drop a page.
- **Protect** — two modes: "Lock" (set owner+user password) or "Unlock" (provide existing password).
- **Redact** — pdf.js client-side preview, drag rectangles, server rasterises pages and burns black rects (true redaction — text is destroyed, not just covered).

---

## 6. Permissions

### New permission key (replaces `access_pdf_splitter`)
| Key | Label | Section | Type | Module |
|-----|-------|---------|------|--------|
| `access_pdf_suite` | Access PDF Suite | `pdf-suite` | access | `pdf_suite` |

### Backward compatibility
- Keep `access_pdf_splitter` as a permission for one release cycle so existing role assignments don't break.
- A user with **either** key can reach Suite + Splitter routes.
- Migration: when seeding/refreshing, any role that has `access_pdf_splitter` is also granted `access_pdf_suite` (handled in `CoreXPermissionSeeder`).

### Route middleware
All Suite routes use `permission:access_pdf_suite,access_pdf_splitter` (any-of). Existing splitter routes keep their current single-permission guard but also accept the new key.

---

## 7. Files to Create / Modify

### Create
- `.ai/specs/pdf-suite.md` ← this file
- `config/pdf-suite.php` — Ghostscript path + size limits
- `app/Http/Controllers/Tools/PdfSuiteController.php`
- `resources/views/tools/pdf-suite/hub.blade.php`
- `resources/views/tools/pdf-suite/_switcher.blade.php` (the pill bar partial)
- `resources/views/tools/pdf-suite/compress.blade.php`
- `resources/views/tools/pdf-suite/merge.blade.php`
- `resources/views/tools/pdf-suite/image-to-pdf.blade.php`
- `resources/views/tools/pdf-suite/rotate.blade.php`
- `resources/views/tools/pdf-suite/reorder.blade.php`
- `resources/views/tools/pdf-suite/protect.blade.php`
- `resources/views/tools/pdf-suite/redact.blade.php`

### Modify
- `routes/web.php` — add `Route::prefix('tools/pdf-suite')->…` block
- `resources/views/layouts/corex-sidebar.blade.php` — rename label, repoint href, broaden active state
- `config/corex-permissions.php` — add `access_pdf_suite` row
- `database/seeders/CoreXPermissionSeeder.php` — grant `access_pdf_suite` to any role that has `access_pdf_splitter`

### Untouched
- `app/Http/Controllers/Tools/PdfSplitterController.php`
- All `tools.pdf_splitter.*` routes (still valid; reachable via hub card)

---

## 8. Acceptance Criteria

A reviewer can mark the feature done only when **all** of these pass:

1. Sidebar shows **"PDF Suite"** (not "PDF Splitter") and links to `/tools/pdf-suite`.
2. The hub renders 8 tool cards. Each card click opens a working tool page.
3. Existing PDF Splitter at `/tools/pdf-splitter` still works end-to-end (upload → review → ZIP).
4. Each of the 7 new tools, given a real PDF/image, produces a valid downloadable result:
   - **Compress:** output file is smaller than input on a typical scanned mandate (≥30% reduction at `ebook` setting).
   - **Merge:** a 3-file upload yields a single PDF whose page count = sum of inputs.
   - **Image → PDF:** 3 phone photos yield a 3-page A4 PDF, EXIF-rotated correctly.
   - **Rotate:** every page rotated by the requested angle in the output.
   - **Reorder:** output page order matches the user's chosen order; deleted pages are gone.
   - **Protect (lock):** output requires the password to open in a viewer.
   - **Protect (unlock):** locked input + correct password → unlocked output.
   - **Redact:** rectangles selected in the browser appear as solid black in the output, and text under them is gone (verified by `pdftotext` finding none of the redacted string).
5. Every page passes the **Post-Restyle Audit Checklist** in `DESIGN-SYSTEM.md` §9 (geometry `rounded-md`, brand vars, dual-theme, no hardcoded slate/gray).
6. Permission gating works: a user without `access_pdf_suite` (or legacy `access_pdf_splitter`) sees no sidebar entry and gets a 403 on direct URL.
7. Missing-binary path: if `gs` is not installed, the Compress tool shows an inline error pointing to `PDF_SUITE_GS_PATH` — no 500.
8. `php -l` on every changed PHP file → 0 errors.
9. `php artisan view:clear && route:clear && cache:clear` succeed.
10. `scripts/dev-check.ps1` passes with **0 new failures** vs baseline.

---

## 9. Out of Scope (explicit)

- Pillar write-back ("save to Deal", "attach to Contact"). Future spec.
- PDF→Word / OCR text extraction. Future tool.
- Watermarking, page numbering, form filling, metadata stripping, diff/compare, e-sign prep. Listed in the original menu but deferred — agency picked the highest-ROI seven.
- Bulk batch processing (queue jobs). Day one is single-request, synchronous, ≤50MB per file.
