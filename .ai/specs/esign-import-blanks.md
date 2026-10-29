# E-sign Word import: blanks, kept source, guessed type, save with open fields

> **Status:** built on Staging 29 Oct 2026 (then QA1). Johan: document imports are critical - "so we can set up docs, including Mega's".
> Pairs with `esign-lease-field-source.md` (section 8 listed these as known limits), `cds-reimport-from-word.md`, `esign-template-readiness.md`.

## 1. What counts as a blank (CdsParserService)
Before: only `@@@@` markers, `~~~~Name~~~~` tokens and dotted leaders of 5+ dots were fields, and only in paragraphs - a Word file written
with underscore blanks, or an all-tables form, imported with no fields. Now, in the same pass as dotted leaders:
- **Underscore runs of 3+** in a paragraph ("Physical address: ________"). A line made only of underscores is a signing line and is left
  to the signature detectors (AT-177 D4); paragraphs that mention signature / witness / "signed at" are skipped, as before.
- **Tab-leader blanks**: a short line that ends in a label followed by 2+ tabs and nothing else ("Total Rental Amount<tab><tab><tab>").
- **Blanks inside a table** (`CdsTableFields`): `@@@@`, underscore runs and dotted runs typed in a cell; an **empty cell beside its label**
  ("Property address:" | empty) and an empty row under short column headings. Not blanks: a tick or Y/N cell, a clause number, a whole
  sentence, a signing label, a cell with a picture, a merged-down cell, tables where fewer than half the rows start with a label. A field
  in a cell is a `['field' => ...]` run inside `cell_paras`; the renderer draws it as the same `.corex-field` span, the builder
  numbers it with the others, and `TemplateController::extractFieldsFromCds`, `CdsBindingSuggester::collectInputTokens` and
  `CdsReimportService` list table fields in the order the page draws them (header cells, then rows).
Each blank is then named by the same rules as any other (lease wording first in a lease, then the general patterns; "Property address"
now reads as the property's address). Low-confidence blanks show a red dot in the builder.

## 2. The Word file stays on the draft
`cds_drafts.source_path` / `source_name` (migration `2026_12_29_110000`): the import stores the uploaded .docx on the private disk
(`cds-draft-sources/<agency>/<draft>-<sha>.docx`). Builder, Settings panel: **Re-read with current rules** (`docuperfect.cds.builder.reread`,
draft owner with `manage_templates`) parses the kept file again, replaces the draft's reading and clears its field links and saved builder state
(confirm dialog first). A draft made before this (no file) says so. A template's own Word files are still kept by Re-import from Word.
PDF uploads are a different pipeline (the older overlay import) and keep their PDF as the page images.

## 3. Document type and category are guessed
`DocumentTypeGuesser` reads the title, then the opening wording: lease, mandate (letting / to sell), offer to purchase, disclosure, addendum, notice,
inspection / inventory, FICA, marketing permission, rental application ("other"); category rentals / sales from the type, else from the
words used. Stored on the draft as `settings.guess`, applied to the two builder dropdowns on a fresh draft with the note "Guessed from the
title and wording of the Word file - change it if it is wrong." Nothing is saved on the guess alone.

## 4. Save with open fields
With fields still unlinked the builder shows **Save with open fields** beside the amber "N of M linked" banner (confirm dialog first). The
template is saved; unlinked blanks print as plain underlines; the success message says how many are open; readiness check R07 (every blank is
linked) fails, so the template shows as not ready and cannot be released until they are linked.

## 5. Deploys run the reference-data sync
`scripts/deploy.sh` (staging and production) now runs `php artisan deploy:sync-reference-data` after the explicit reference seeders, the same
command `scripts/qa-deploy.sh` runs. It first writes the list of what will run (`--dry-run`) to the deploy log, then runs it: every seeder
implementing `App\Contracts\SyncableReferenceSeeder` (AgencyDocumentTypeConfig, AssistantRole, CalendarEventClass, DataDictionary,
DemoTncVersion, DocumentTypesCatalogue, Dr2PipelineCatalog, HfcAddendumBEsign, NotificationEventType, PayrollTaxRebate, PayrollTaxTable,
RecipientTemplate, ReferencePackDictionary, RoleTemplate, SalesMandatoryDisclosureEsign, SuburbAlias, SuburbMunicipality) and
`corex:sync-permissions --merge-defaults`, then fails the deploy if `role_permissions` is empty (AT-265). All are find-or-update. **Live note:**
the two template seeders (HfcAddendumBEsign, SalesMandatoryDisclosureEsign) put those two templates back to the repo's JSON on every deploy.

## 6. Tests
`tests/Feature/Docuperfect/Import/BlankDetectionTest.php` (underscore / tab / table blanks, signing lines kept, builder order, kept file + re-read,
guess, save-with-open-fields control, deploy.sh runs the sync); `tests/Unit/LeasePhraseMatcherTest.php`.

## 7. Files
`CdsParserService` (detectDottedLeaderFields, detectTabLeaderBlanks, detectTableFields, identifyFieldsFromContext, property-address pattern),
`CdsTableFields`, `CdsRendererService::fieldSpan`, `DocumentTypeGuesser`, `DocumentImporterController::generateCdsTemplate`,
`TemplateController` (cdsReread, extractFieldsFromCds, builder view data, generate message), `cds-builder.blade.php`, `routes/web.php`,
migration `2026_12_29_110000_add_source_file_to_cds_drafts`, `scripts/deploy.sh`.
