# ES-6 — AI Template Import: PDF into the CDS path + Path A retirement plan

**Date:** 2026-06-10
**Branch:** AT-12-E-Sig
**Author:** Claude (build), for Johan Reichel (morning review — NOT pushed)
**Scope:** Make Path B (`/import/cds`, the CDS marker-aware path) accept PDF as
well as Word, converging onto the SAME `CdsDraft → CDS builder → cdsGenerate →
blade` pipeline; wire insertable-block detection into the CDS import; and PROPOSE
(not execute) the retirement of Path A.

> Pre-reads honoured: CLAUDE.md, STANDARDS.md, BUILD_STANDARD.md,
> esign-v3-complete-spec.md §12/§24, esign-reconciliation-2026-06-10.md §2 ES-6.
> Branch confirmed `AT-12-E-Sig` before any change. dev-check.ps1 skipped
> (Windows/PowerShell, not runnable on this Linux host) — targeted Tinker
> verification used instead (§Verification).

---

## 0. The two paths (investigation summary)

| | Path A (`/import/parse`) | Path B (`/import/cds`) — THE KEEPER |
|---|---|---|
| Entry | `DocumentImporterController::parse:100` | `DocumentImporterController::generateCdsTemplate:72` |
| Parser | `DocxParserService` (Mammoth) + `ImporterAiService` (AI) | `CdsParserService::parse` (WordprocessingML XML) |
| Staging | `docuperfect_import_drafts` (`ImportDraft`) | `cds_drafts` (`CdsDraft`) |
| Review | `/import/review` → `/import/generate` (own UI) | redirect → CDS builder (`TemplateController::cdsBuilder/cdsGenerate`) |
| Output | one `Template` via `DocumentTemplateGenerator` | one `Template` via `cdsGenerate` |

**Critical fact:** the two paths share **no parser/generator service**. They share
only the final `Template` model + reference data (`NamedField`, `FieldGroup`,
`AgencySigningParty`, `AnthropicGateway`). This makes Path A safe to retire once
PDF capability lives on Path B (which this build delivers).

---

## Gap 1 — PDF into the CDS path: ✅ BUILT (text PDF) / ⛔ DEFERRED (scanned)

**Root cause:** `generateCdsTemplate` validated `mimes:docx` (`:79`) and
`CdsParserService::parse()` hard-requires a `.docx` ZIP (`word/document.xml`,
WordprocessingML XPath, `CdsParserService.php:17-33`). A PDF cannot feed `parse()`.
The convergence point is the **`cds_json` `{version,title,sections[]}` shape**, not
the `.docx`-specific `parse()` entry.

**Built (text-based PDF — deterministic, no AI):**
- `CdsParserService::parsePdf()` (new) — extracts text via `smalot/pdfparser`
  (already in `composer.json`, used by `DocumentExtractor`/`DocumentProcessingService`),
  builds `sections[]` via the new `buildSectionsFromText()` (blank-line blocks,
  single-line fallback for flat extractors, title/heading heuristic mirroring the
  docx section shapes), then runs the EXISTING private `detectMarkers()` so literal
  `~~~~PURPOSE~~~~ / @@@@ / %%%% / ####` markers are recognised exactly as in Word.
  Returns the same shape as `parse()` plus `source_format:'pdf'`.
- `generateCdsTemplate` now validates `mimes:docx,pdf` + `max:` (both configurable —
  `config/docuperfect.php` `import.allowed_extensions` / `max_upload_kb`), branches
  docx→`parse()` / pdf→`parsePdf()`, and lands the SAME `CdsDraft` → same builder
  redirect. Parse happens BEFORE any write — a rejected/failed parse leaves nothing
  half-created.
- Robustness: corrupt/encrypted PDF → `RuntimeException` with a user message (no raw
  500); oversized/wrong-type → validation message; **title length capped to 150
  chars** before storing in `cds_drafts.template_name` (a real column-contract bug
  found in verification — a flat PDF's title can be the whole document; now never
  overflows, always non-empty).
- Landing page: the CDS import file input now `accept=".docx,.pdf"` with updated copy
  and an `@error('document')` message slot (`importer/index.blade.php`).

**Deferred (scanned / image-only PDF — fidelity-gated):** a PDF whose extractable
text is below `config('docuperfect.import.min_pdf_text_chars')` (default 120) is an
image-only/scanned document. `parsePdf()` throws `ScannedPdfException`
(`app/Exceptions/Docuperfect/ScannedPdfException.php`) with actionable guidance
("upload a text-based PDF or the Word version"). **Why deferred, not OCR'd:** a CDS
web template needs the faithful document *body*; for a scan, only OCR can produce
one. `detectFromPdf`/`ClaudeVisionParserService` return *fields*, not a faithful
body. Reconstructing a legally-binding mandate body from OCR is (a) **Document
Fidelity-sensitive** (STANDARDS: character-for-character identical), (b)
**non-deterministic** (AI), and (c) **unverifiable unattended** here (no real
scanned sample; live AI). Per the task's explicit STOP/defer clause for
legally-significant, unverifiable output, the scanned path is a clean PREVENT
(clear rejection) rather than a half-fidelity legal template. Proper-fix proposal in
§Path A / §Deferrals below.

> **Decision flagged for Johan — `ClaudeVisionParserService` intentionally NOT
> wired.** The spec (ES-6.1) named it, but the investigation found it is unwired
> scaffolding that takes PNG page-images and returns *fields* (not a document body),
> and is superseded by `ImporterAiService::detectFromPdf()` (native Anthropic PDF
> block — Path A already uses it). Wiring it would not solve the scanned-*body*
> fidelity problem. Recommendation: delete it in the Path A retirement (it is
> referenced by nothing — confirmed by grep). The genuine scanned-PDF solution is an
> OCR-with-fidelity-review path (proposed below), not this scaffold.

## Gap 2 — Output is an editable CDS template in templates: ✅ VERIFIED (interpretation flagged)

**Investigation finding:** neither import path nor `cdsGenerate` creates a
`web_pack`/`web_pack_items` — both produce exactly ONE `Template` row +
one blade view. `WebPack` is a separate subsystem (a *pack* = a sequence of multiple
templates; `WebPackController`).

**Interpretation (flagged for Johan):** "CDS web pack" is read here as **one editable
CDS web *template* per imported document on the e-sign rails** — which is what both
paths already produce. Assembling a multi-item `WebPack` from a *single* imported
document is not meaningful; bundling several imported templates into a pack is the
existing separate `WebPack` feature. I therefore did **not** build spurious
web-pack-from-one-doc assembly. If Johan intends imported docs to auto-assemble into
a `WebPack`, that is a distinct follow-up (assemble-pack-from-templates), noted.

**Verified the (a)/(b)/(c) chain the PDF now feeds (existing, proven pipeline):**
- (a) **Lands in templates:** `cdsGenerate` (`TemplateController.php:581-586`) creates
  a `docuperfect_templates` row (`render_type='web'`, `template_type='cds'`,
  `is_esign`), listed on the template index.
- (b) **Editable in the builder:** the import redirects to `cdsBuilder`
  (`docuperfect.cds.builder`) — the same builder that edits any CDS draft; navigation
  preserved.
- (c) **Usable in the e-sign wizard:** the wizard template query
  (`ESignWizardController.php:59-67`) selects `is_esign=true` AND
  (`render_type='web'` AND `blade_view NOT NULL`) — **exactly** what `cdsGenerate`
  emits. So a CDS-generated template (docx or now PDF) is wizard-selectable by
  contract. (corex_dev currently has zero CDS templates generated, so there is no
  live row to point at; the contract is confirmed against the query + the
  `cdsGenerate` output fields.)

Because the PDF path produces a **byte-shape-identical `CdsDraft`** to the docx path
(verified: same `version/title/extracted_at/original_text/sections` keys, +
`source_format`), it inherits (a)/(b)/(c) wholesale — no parallel generator.

## Gap 3 — Insertable-block detection in import: ✅ BUILT

**Root cause:** ES-6.2/6.3 (AI emits `insertable_blocks` + `~~~~` markers) were
already built in `ImporterAiService::insertableBlocksPromptSection()`
(`:291-355`) — but only on **Path A**. Path B never called it; and
`CdsParserService::collectInsertableBlocks()` (`:837`) existed but was **never
wired** (dead public method).

**Built:**
- `generateCdsTemplate` now calls `collectInsertableBlocks($cds['sections'])` (for BOTH
  docx and pdf — same `detectMarkers` pipeline) and persists the result on
  `cds_drafts.settings['insertable_blocks']`.
- ES-6.4 surfacing: `cdsBuilder` already passes `savedSettings` to the view; the
  builder now renders a **"Detected N insertable block(s) on import — confirm
  placement before saving"** banner (`cds-builder.blade.php`) listing each detected
  block, so the agent confirms them before generating. (The markers are also
  visually present inline as block placeholders in the builder document.)
- AI-inferred block detection (inferring blocks where the source has no explicit
  marker) remains the Path-A `ImporterAiService` mechanism; it is available to layer
  onto the CDS path as a future enhancement, not rebuilt here.

**Verified:** a PDF carrying `~~~~OTHER_CONDITIONS~~~~`/`@@@@`/`%%%%`/`####` →
`insertable_block_placeholder` + field/signature/initial placeholders detected;
`collectInsertableBlocks` returns the `other_conditions` block; it persists on the
draft settings.

---

## PATH A RETIREMENT — PROPOSED ONLY (DO NOT EXECUTE)

A full dependency trace. **No Path A code was modified or deleted in this commit.**
This is a plan for Johan to execute under review, AFTER the Path-B PDF capability
delivered here is accepted.

### A. Safe-to-remove (Path-A-exclusive — confirmed not used by Path B)

| Item | file:line | Note |
|---|---|---|
| Route `POST /import/parse` | `routes/web.php:2628` | Path A entry |
| Route `GET /import/review` | `routes/web.php:2630` | Path A review |
| Route `POST /import/generate` | `routes/web.php:2631` | Path A generate |
| Route `POST /import/review/mappings` | `routes/web.php:2632` | Path A |
| Route `POST /import/draft/save` | `routes/web.php:2633` | Path A |
| Route `DELETE /import/draft/{id}` | `routes/web.php:2634` | Path A |
| Route `POST /import/template/{id}/edit` | `routes/web.php:2642` | Path A re-edit |
| `DocumentImporterController::parse` | `:100` | + PDF helpers `aiFieldsObjectToList:1196`, `buildPdfStubHtml:1216` |
| `::review` `:283`, `::saveMappings` `:419`, `::generate` `:459` | | operate only on `ImportDraft` |
| `::editFromTemplate` `:557`, `::saveDraft` `:871`, `::destroyDraft` `:920`, `::logFieldCorrections` `:1119` | | Path A only |
| `DocxParserService` (whole service) | `app/Services/Docuperfect/DocxParserService.php` | Path A only |
| `ImporterAiService` (whole service) | `app/Services/Docuperfect/ImporterAiService.php` | Path A only — **but see WARNING 1** |
| `DocumentTemplateGenerator` (whole service) | `app/Services/Docuperfect/DocumentTemplateGenerator.php` | builds Template from ImportDraft — Path A only |
| `ClaudeVisionParserService` | `app/Services/Docuperfect/ClaudeVisionParserService.php` | **fully unwired — safe to delete regardless** |
| `FieldCorrection` model + `field_corrections` table | | Path A learning loop only |
| `ImportDraft` model + `docuperfect_import_drafts` table | | Path A staging only (soft-delete; keep data per no-hard-delete — archive, don't drop the table without data review) |
| `review.blade.php` + the Path A `parse` drag-drop card + JS in `importer/index.blade.php` | | the two-button UI collapses to one |

### B. Landing page becomes a single "Import Document" entry

`resources/views/docuperfect/importer/index.blade.php`: remove the Path A
drag-drop card (the `fetch → /import/parse → review` flow, ~:100-238) and the
"or" divider (:241-248); promote the CDS form (now docx+pdf) to the sole "Import
Document" action. Sidebar entry "Import Document" already routes to `GET /import`
(`docuperfect.import.index`) — navigation unchanged.

### C. DANGER — shared / must-NOT-remove

1. **WARNING 1 — `ImporterAiService` owns the only AI PDF ingestion
   (`detectFromPdf`).** If Path A is removed, that capability is gone UNLESS the
   scanned-PDF OCR path (deferred above) is first built on Path B. This build
   delivers *text* PDF on Path B independently of `ImporterAiService`, so retiring
   Path A loses only the (currently field-only) AI PDF path — acceptable once the
   proper scanned-OCR path is built. **Do not delete `ImporterAiService` until the
   scanned-PDF story on Path B is decided.**
2. **Shared reference data — KEEP:** `NamedField`, `FieldGroup`,
   `AgencySigningParty` + the party CRUD methods/routes (`/import/parties*`,
   `:2637-2641`) are used by BOTH the Path A review editor AND the CDS builder.
   Do NOT remove with Path A.
3. **`AnthropicGateway`** is used across the app — never remove.
4. **`Template` model / `docuperfect_templates`** — both paths' destination; keep.

### D. Proposed proper-fix for scanned PDFs (the deferred piece)

Rasterize via the existing `pdftoppm`+Imagick utilities
(`app/Http/Controllers/Tools/PdfSplitterController.php`) → OCR the body (vision or
`pdftotext`-OCR) → build `sections[]` → **mandatory human fidelity-review gate in the
builder before generate** (the legal body must be agent-confirmed character-accurate)
→ live verification on real scanned mandates. This is a fidelity-critical feature
deserving its own prompt + real-scan test set, not an unattended build.

---

## Verification (Tinker, corex_dev — ES-6.6 acceptance, deterministic subset)

A real text PDF was generated (dompdf) with `@@@@/%%%%/####/~~~~OTHER_CONDITIONS~~~~`
markers and driven through the real services. **All checks passed:**

```
text PDF generated on disk                                            PASS
parsePdf returns version+title+sections (same shape as docx parse())  PASS
source_format tagged 'pdf'                                            PASS
title detected; body text preserved (commission clause)              PASS
@@@@  → field_placeholder        detected (same detectMarkers)        PASS
%%%%  → signature_placeholder    detected                             PASS
####  → initial_placeholder      detected                             PASS
~~~~OTHER_CONDITIONS~~~~ → insertable_block_placeholder detected       PASS
collectInsertableBlocks finds other_conditions                       PASS
PDF lands a CdsDraft with cds_json + settings.insertable_blocks       PASS
draft routes to the SAME builder pipeline (status=draft, v1.0)        PASS
scanned/image-only PDF → ScannedPdfException, actionable message      PASS
corrupt PDF → RuntimeException (clean rejection, no 500)              PASS
docx parse() unchanged — real fixture, 21 sections                   PASS (regression)
docx vs pdf output shape identical (− source_format)                 PASS
wizard selectability contract (is_esign+web+blade_view)              CONFIRMED vs ESignWizardController:59-67
```

php -l clean on all changed PHP; `view:cache` compiled all blades with no errors;
`config:clear`/`view:clear` clean.

### Not verifiable unattended (documented limits)
- **Scanned/image-only PDF live output** — deferred (no real scan + non-deterministic
  AI + Document Fidelity). Verified only that such input is cleanly REJECTED.
- **Full PDF→builder→cdsGenerate→wizard click-through** — the post-`CdsDraft` steps
  are the existing production pipeline (interactive builder). Verified by shape parity
  (PDF `CdsDraft` ≡ docx `CdsDraft`) + the wizard-query contract, not by a live
  browser click-through (no browser harness here).
- **phpunit** for the import path was not added/run: the import flow needs `smalot`
  + file uploads through HTTP and the corex_dev-only MySQL (no isolated test DB on
  this Linux host; `corexdev` is scoped to `corex_dev`; in-memory SQLite is rejected
  by a raw MySQL `ALTER…MODIFY` migration). The Tinker run above exercises the real
  services end-to-end and is the proving evidence; a feature test should be added on
  the Windows schema-snapshot bootstrap.
