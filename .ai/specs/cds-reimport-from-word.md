# CDS "Re-import from Word" and "Copy template to another agency"

> Built 26 Oct 2026 on QA1 (branch `cds-reimport-from-word-2026-10-26`). Conductor's order, with Johan's ruling of 26 Oct:
> CoreX imports and builds every agency's documents in a CoreX workshop agency, then transfers the finished
> template to the client agency. Related: `cds-document-look.md` (its "Limits" section — alignment is read at import),
> `esign-template-transfer.md` (the package pipeline the copy is built on), `private-document-storage.md` (how the
> stored Word file is served), the CDS importer (`CdsParserService`, `DocumentImporterController::generateCdsTemplate`).

## 1. What it does and why

An agency re-uploads a corrected Word file routinely. Before this build the only way to use a corrected file was to
import it as a **new** template — a new template number, new settings, new field bindings, and a lease link
(`rental_lease_templates.field_map`) still pointing at the old one. The importer also kept no copy of the file it had
read, so a template could never be re-read after the importer improved (the 23 Oct "Match source document" look could
not reach templates 82 and 83).

### 1.1 Re-import from Word

On a saved CDS template, in the builder's **Template Settings → Word file**: choose the corrected `.docx`, press
**Re-import from Word**. The template keeps its **number, name, settings, signing roles, document type, look and the
lease link**; only its wording is replaced, and each field that has the same **name** in the new file keeps the binding it
had. Nothing on the template changes at upload — the builder opens on the new wording with a **match report as the
first thing on the screen**, and the template changes only when the person presses **Save** (so an upload nobody saves
costs nothing, and documents already sent are never touched: a sent document froze its own copy).

The report says, in this order: what came back ("3 of the 4 fields in the old template were found again by name; 3
kept their binding"), the **new or unmatched fields** (each a link that scrolls to that field in the builder), the
**fields from the old template that are gone** (and what each was set to), and the next action ("check the fields
above, set up the new ones, then press Save. Until you Save, the template stays exactly as it was").

### 1.2 The Word file and the previous version are kept

- The uploaded `.docx` is stored on the **private** disk (`local`, `template-sources/<agency>/<template>/…`), in a
  `documents` row (`source_type = template_source`, `source_id = template id`), never under `storage/app/public`. It is
  opened only through the authenticated route `corex.documents.file` (`DocumentAccessService::allowsTemplateSource`:
  needs `manage_templates` **and** the template must be accessible to the viewer; another agency's id is a 404; an
  agency-wide documents scope alone does not open it).
- At Save, the template's **previous** `cds_json`, `editor_state` and `field_mappings` are kept as a **superseded
  generation** (`docuperfect_template_generations`); a template that never had a Word file kept gets a baseline
  generation "content before the first re-import (no Word file was kept)". Superseded generations and their Word
  files are never deleted (soft deletes only; no code path removes one). The builder lists the Word files
  (download link, version number, in use / replaced).

### 1.3 Copy template to another agency (CoreX administrators only)

In the builder's owner bar, beside **Export package**: pick an agency, optionally tick **Also copy the rental lease link
and field map**, press **Copy to another agency**. It creates a **new template id in the target agency** with
sections, fields, tags/bindings, settings, look and signing parties intact; the original is untouched. Name already
taken in the target → `"<name> v2"` (never replaces). A lease link + `field_map` is copied **only if ticked**: a row is
created in the target agency pointing at the new template, `field_map` as it was, re-checked by
`LeaseAgreementTemplateGuard`, and becomes the target agency's default for that category only when it has none yet.

## 2. Already existed (found before building) — reused, not rebuilt

| Existing | Where | Used for |
|---|---|---|
| Template package export / import (owner-only, `templates.transfer`) with an audit table | `TemplateTransferController`, `TemplatePackageExporter`, `TemplatePackageImporter`, `template_transfer_log`; spec `esign-template-transfer.md` | **The copy IS this pipeline run in memory, one click, same box.** Field definitions and groups travel by stable key, unsafe content is refused, a failed copy rolls back everything, and the target agency can never be NULL. Before this, copying between agencies was a two-step manual round trip (download a `.cxpkg`, upload it, choose the agency). |
| In-place update of a template from a builder draft | `TemplateController::cdsGenerate` (draft with `source_template_id` → `$template->update(...)`) | Re-import saves through it unchanged — same id. |
| Plain "Duplicate" | `TemplateController::copy` | Not used: it stays inside one agency and copies no wording-bindings by key. |
| Builder prefers a per-field `binding` over its own guess | `cds-builder.blade.php` `cdsFields[parserIndex].binding` | The carried-over bindings are fed in through this existing seam — no new client logic. |

Small changes to the existing pipeline: `TemplatePackageExporter::export(..., bool $audit = true)` (a same-box copy
passes `false`) and `TemplatePackageImporter::import(..., ?Template $copiedFrom = null)` (writes a `copy` audit row
carrying the source agency). Nothing else about export/import behaves differently.

## 3. Pillars / events / permissions / multi-agency

- **Pillars:** Documents (templates). Reads and writes no Property / Contact / Deal / Agent row. Field bindings keep
  pointing at the same field definitions (matched by stable key on copy).
- **Events:** the copy fires the existing `TemplatePackageImported` (agency = target). Re-import emits none (a template
  edit, same as any builder save).
- **Permissions (no new keys):** Re-import = the builder's own gate, `manage_templates`, plus
  `Template::assertAccessibleBy` (another agency's template is a 404 — direct-URL access by id is blocked at the
  query/route layer). Copy = `owner_only` **and** `templates.transfer` on the route, re-checked inside
  `TemplateAgencyCopier` (an agency admin is refused even with the key granted; the control is not rendered for them).
- **Multi-agency:** nothing HFC-specific. Generations carry the template's `agency_id`; the stored `.docx` carries it
  too. The copy's target is an explicit, required agency; the new template belongs to it only
  (`isVisibleToAgency` false for the source agency and any third agency).
- **Routes:** server-rendered form posts in the web route table, like the neighbouring template actions
  (`docuperfect.templates.reimport`, `docuperfect.templates.copyToAgency`) — not JSON API endpoints.

## 4. Matching rules (the point of the feature)

Fields are the importer's input fields in the **same order the builder numbers them** (`parserIndex`): `@@@@` /
dotted-leader placeholders (named from the importer's context identification), `~~~~Name~~~~` custom markers
(named by their `block_id`), and label/value pairs. A field's **name** is its `field_name` (else its label); the
generic labels *field / input / blank / text* are not names.

1. Fields match **by name only, never by position** — a paragraph or field added above them moves nothing.
2. A name used once on both sides matches.
3. A name used several times matches (in order) only when it is used the **same number of times** on both sides, or
   when **every** old use had the **identical** binding (then any pairing is the same answer). Otherwise the whole group is
   reported unmatched, with the reason — never guessed.
4. A field with no name on either side is **unmatched and reported** ("has no name in the Word file"), never paired by
   position. (A template full of unnamed `@@@@` fields therefore carries few bindings; the report says exactly which.)
5. A matched field whose old binding was empty carries nothing; the builder's own auto-suggestion applies as on a fresh
   import.
6. Tags, wording edits made in the builder after the original import, and signature / initial / other-conditions
   blocks are **rebuilt from the new Word file** — re-import means "the Word file is the source". Settings (signing
   parties, party mode, delivery modes, security tier, category, document type, look, e-sign) are carried by the draft
   exactly as a normal edit would carry them.

## 5. Data

New table `docuperfect_template_generations` (migration `2026_12_02_100000`): `template_id`, `agency_id`,
`generation` (0 while pending; numbered when applied), `status` (`pending` → `current` → `superseded`; a pending row a
newer upload overtakes is `abandoned`), `draft_id`, `document_id`, `original_filename`, `file_sha256`, `uploaded_by`,
`report`, `carried_bindings`, the superseded snapshot (`cds_json`, `editor_state`, `field_mappings`), timestamps,
soft deletes. The re-import state lives here, **not** in `cds_drafts.settings`, because the builder's autosave
replaces `settings` wholesale. `template_transfer_log.direction` gains the value `copy` (a plain string column — no
migration).

## 6. Setup Wizard (non-negotiable 10a)

Not added to the Agency Onboarding Setup Wizard: neither feature is an agency setting. Re-import is a template
action; copy is a CoreX-administrator tool (no agency ever sees it). Recorded as a decision on the record — Johan can
overrule.

## 7. Limits (stated, not hidden)

- Re-import needs a Word file again for templates imported before this build (none was kept) — the first re-import
  of 82 and 83 needs the corrected `.docx` supplied; their present content is kept as generation 1.
- The match is by name. A Word file whose `@@@@` blanks the importer could not name carries those fields unbound.
- Copy takes the last **saved** version of the template (same as Export package).
- Copy does not copy lease links unless ticked, never branch assignments, and never overwrites.
- No screen lists generations beyond the builder's Word-file block; no "restore a superseded generation" button
  (the content is kept so it can be restored by hand or in a follow-up).

## 8. Acceptance criteria

1. Re-import keeps the template id, settings and signing roles; fields with the same name keep their bindings; a
   paragraph added above does not shift a binding; the report lists exactly the dropped and the new fields, each new
   one linked.
2. Nothing on the template changes until Save; an unsaved upload leaves it as it was; a newer upload overtakes it.
3. After Save the previous content is a superseded generation, the new Word file is stored privately and downloadable
   only by someone who may manage and open that template; a second re-import keeps the first file.
4. A copy to another agency is a new, working template there (page file generated, bindings by stable key, look,
   signing parties, settings), the original untouched, nothing visible to a third agency, one audit row source →
   target; lease link copied only when ticked; only a CoreX administrator can do it.

## 9. Files

`app/Services/Docuperfect/CdsReimportService.php`, `TemplateAgencyCopier.php`; `app/Http/Controllers/Docuperfect/
TemplateReimportController.php`, `TemplateAgencyCopyController.php`, `TemplateController.php` (builder wiring, save hook);
`app/Models/Docuperfect/TemplateGeneration.php`; `app/Support/Docuperfect/CdsDraftSettings.php`;
`app/Services/Documents/DocumentAccessService.php`; `app/Services/Docuperfect/TemplateTransfer/TemplatePackage{Exporter,Importer}.php`;
`resources/views/docuperfect/templates/cds-builder.blade.php`; `routes/web.php`; migration `2026_12_02_100000_…`;
tests `tests/Unit/CdsReimportMatchTest.php`, `tests/Feature/Docuperfect/CdsReimportFromWordTest.php`,
`tests/Feature/Docuperfect/TemplateAgencyCopyTest.php`.
