# CDS Document look — "CoreX standard" vs "Match source document"

> Built 2026-10-23 on QA1 (cc1). Johan, 23 Oct: "our efforts to make the documents look more like the
> original needs to increase... centre headers, and greyscale". Related: ESIGN-CANON §2 (import strips),
> the CDS importer (`CdsParserService`) and the CDS builder (`cds-builder.blade.php`).

## What it does

Every CDS template (a Word file imported through the CDS builder) carries a per-template **Document look**,
set in the builder under **Template Settings → Document look**:

| Look | What the document looks like |
|---|---|
| **CoreX standard** (default) | Exactly today's CoreX house style. A template with no stored look is standard — nothing changes for any template that existed before this setting. |
| **Match source document** | The Word file's paragraph alignment (centred / right / justified) is kept; a title the source centred is centred; a section heading the source shaded, or a bold all-caps section title, is drawn as a **light-grey band**; body colours are **greyscale only** (no brand or accent colour in the body). |

The look is identical in the builder preview, the recipient signing view / agent review, and the signed PDF.

## Pillars / events

Template setting only — it reads nothing from Property / Contact / Deal / Agent and emits no domain event.
Permission: the builder is already gated by `manage_templates`; no new key. Multi-agency: the setting is
per template (templates are already agency-scoped); the default is neutral (standard), nothing is HFC-specific.

## How it works (one mechanism, three surfaces)

1. **Parser** (`CdsParserService::extractParagraphLook`): each paragraph/heading keeps `align`
   (`center|right|justify`, from `w:jc`, falling back to the paragraph style and its `basedOn` chain; an
   explicit left overrides a centred style) and `shading` (`rrggbb` fill from `w:pPr/w:shd`, or a fill carried
   by every text run; `auto`/white are not shading). Both keys exist only when they differ from the standard
   look, so plain paragraphs add nothing to the stored CDS JSON. Field detection is untouched.
2. **Renderer** (`CdsRendererService::sourceLookAttributes`): headings, clauses and paragraphs get inert
   `data-src-align="center|right|justify"`; headings get `data-src-band="1"` when `shading` is set or the
   heading is bold + all-caps (≥3 letters, every text run bold). Sections without those keys render
   byte-identically to before.
3. **Stylesheet** (`public/css/corex-document.css`, block "DOCUMENT LOOK"): every rule is scoped under
   `.corex-look-source`, so nothing changes unless that class is present. Band = `#e6e6e6` background with
   `print-color-adjust: exact` (so the PDF prints it); text `#111`/`#1a1a1a`; clause numbers `#404040`.
4. **The setting** (`App\Support\Docuperfect\DocumentLook`): stored in `cds_drafts.settings.document_look` while
   editing and in `templates.editor_state.document_look` once saved (no migration). The builder toggles
   `corex-look-source` on `#docContainer` live; `TemplateController::generateCdsBladeView(..., $documentLook)`
   adds the class to the published blade's `.corex-document-wrapper`; `WebTemplateBladeEnsurer::regenerate`
   and `saveContent` pass the stored look so a regenerated blade keeps it. The signing paginator keeps
   `.corex-document-wrapper` (it only re-parents its children), and `SigningController::wrapHtmlForPdf`
   inlines the same stylesheet, so the signed PDF inherits the look with no PDF-specific code.

## Setup Wizard (non-negotiable 10a)

Not added to the Agency Onboarding Setup Wizard: this is a per-template choice made while building a template,
not an agency-wide setting. Recorded as a decision on the record — Johan can overrule (e.g. an agency-wide
default look) and it would then be built as an agency setting + wizard step.

## Limits (stated, not hidden)

- **Alignment/shading is read when a Word file is imported.** A template imported before this build has none
  stored. Switching such a template to "Match source document" still gives greyscale, and bold all-caps
  headings still become grey bands **when the builder renders from the stored import**; a template whose
  edited body was already saved (`tagged_html`) keeps its saved markup, so its centring/alignment appears only
  after the Word file is re-imported. The builder says so under the setting. (26 Oct: **Re-import from Word** now exists —
  see `cds-reimport-from-word.md` — so a template can be re-read from a corrected file in place, keeping its id, settings and bindings.)
- Table cell alignment, borders, font size and paragraph indents are not read (not asked for).
- Heading text is drawn from the heading's stored `text`; a heading that also holds an input field
  (the "bold label + @@@@" false-heading case) is a separate importer matter, not changed here.

## Acceptance criteria

1. New template, default: nothing visibly changes (standard).
2. Import a Word file with a centred title and a shaded / bold all-caps section heading; set **Match source
   document**: the builder preview shows the title centred and the heading as a light-grey band, greyscale.
3. Save → the e-sign signing view and the signed PDF show the same.
4. Set back to **CoreX standard** and save: the document returns to the house style.
5. Existing templates: unchanged until someone switches them.

## Files

`app/Services/Docuperfect/CdsParserService.php`, `CdsRendererService.php`, `WebTemplateBladeEnsurer.php`,
`app/Http/Controllers/Docuperfect/TemplateController.php`, `app/Support/Docuperfect/DocumentLook.php`,
`resources/views/docuperfect/templates/cds-builder.blade.php`, `public/css/corex-document.css`;
tests `tests/Unit/CdsDocumentLookTest.php`, `tests/Feature/Docuperfect/CdsDocumentLookBladeTest.php`.
