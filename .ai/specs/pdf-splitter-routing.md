# PDF Splitter — Destination-Aware Routing + FICA Auto-Kickoff (AT-105)

> Spec for AT-105. Build #5 on `Staging`, stacks with MIC / matcher / portal-leads / map
> builds for a combined live promotion. Extends existing systems — NO forks.

## Business requirement

When the PDF Pack Splitter imports a multi-document pack split against a selected
property, each split document must auto-file to the right place (the property
record and/or the linked seller/owner contact) per a per-doc-type, agency-configurable
setting. When a FICA document is in the pack, the agent may (toggle, not silent)
kick off a wet-ink FICA verification pre-populated with the contact, the FICA form,
and any ID copy / Proof of Residence found in the pack. Saves agents major manual
filing + FICA setup time.

## Pillars

- **Property** — split target; documents file to its Drive (`document_properties`).
- **Contact** — seller/owner derived from `contact_property` (role seller/owner/landlord/lessor);
  documents file to the contact (`document_contacts`); FICA verification is contact-keyed.
- **Compliance (FICA)** — wet-ink `fica_submissions` / `fica_documents`, the existing flow, pre-filled.

## Found systems (extended, not forked)

| System | Location |
|--------|----------|
| PDF Splitter | `app/Http/Controllers/Tools/PdfSplitterController.php` (`confirm()` line 306, `linkOutputsToProperty()` line 462) |
| Splitter UI | `resources/views/tools/pdf_splitter_review.blade.php`, `pdf_splitter.blade.php` |
| Doc-type catalogue | `document_types` table (`SplitterDocType` / `DocumentType`), `grouping` col contact/property/shared |
| Per-agency doc-type config | `agency_document_type_compliance` + `App\Services\Compliance\AgencyComplianceDocTypeService` |
| Doc-type settings UI | `app/Http/Controllers/Admin/SplitterDocTypeController.php`, `resources/views/admin/splitter/doc-types.blade.php` |
| FICA wet-ink | `app/Http/Controllers/Compliance/FicaController.php` (`storeWetInk()` line 173) + `FicaSubmission` / `FicaDocument` |

## Data model

`document_types` is a GLOBAL catalogue; per-agency config already lives in
`agency_document_type_compliance` (the compliance-required flag). The destination
config extends that same per-agency table — single source of truth, no new island.

Migration `add_destination_flags_to_agency_document_type_compliance`:
- `save_to_property` boolean NULLABLE (NULL = use grouping default)
- `save_to_contact`  boolean NULLABLE (NULL = use grouping default)

Default resolution (when no explicit row value): grouping `contact` → contact only;
grouping `property`/`shared`/null → property only (preserves current behaviour where
everything files to the property). Defaults: Mandate → Property; ID copy + Proof of
Residence + FICA → Contact (these three are grouping=`contact`).

## Part 1 — Destination settings

`AgencyComplianceDocTypeService` gains `destinationMapFor()`, `destinationForSlug()`,
`setDestination()`, `defaultDestinationForGrouping()`. Settings UI (Document Types)
gains a **"Save To"** column with two independent checkboxes (Property, Contact) per
row — tick either or both. `bulkSave` persists them per-agency.

## Part 2 — Splitter routes per settings

`confirm()` derives the seller/owner contact from the selected property
(`Property::sellerOwnerContact()`), then `linkOutputsToDestinations()` creates ONE
`Document` per split output and attaches it to property and/or contact per the
resolved destination. **No-orphan guarantee:** the property is always the split target,
so any doc whose configured destination is unavailable (e.g. contact-only with no
linked contact) falls back to the property. Both-ticked → attached to both.

## Part 3 — FICA auto-kickoff (agent toggle)

Trigger shown on the review screen ONLY when a FICA form (`fica`) page is present AND
the user holds `access_compliance` AND a seller/owner contact resolves. Toggling on:
`FicaWetInkService` (extracted from `storeWetInk`, used by BOTH the controller and the
splitter — single source of truth) creates a wet-ink `FicaSubmission` for the contact,
attaches the split `fica` → `fica_form`, `ids` → `id_copy`, `por` → `proof_of_address`
PDFs that are present, fires `FicaSubmitted`, and surfaces a link to the submission so
the agent completes the remaining verification steps manually. Entity defaults to
`natural`; received date defaults to today; source tagged `pdf_splitter`.

## Permissions

Splitter: `access_pdf_splitter` (unchanged). Settings: `access_settings` (unchanged).
FICA kickoff gated by `access_compliance` — hidden + server-enforced if absent.

## Robustness (input space)

- No property selected → no filing (existing behaviour); ZIP still produced.
- Property with no linked contact → contact-destined docs fall back to property (no orphan).
- Both destination flags off for a type → doc stays in ZIP, not auto-filed (agency choice; reported).
- FICA toggle off / absent → no FICA created; docs still file per Part 2.
- FICA form absent → toggle not offered; ID/POR still file to contact.
- All writes inside a transaction; no half-created records.

## Acceptance criteria

- Doc-type settings show Property/Contact tickboxes, either-or-both, per-agency.
- Splitting files each doc to its configured destination(s); both → both; Mandate→property, ID/POR→contact.
- FICA doc present → toggle appears; triggering creates a pre-populated wet-ink FICA
  (contact + form + any ID/POR auto-attached); agent finishes the rest.
- Existing splitter + FICA extended, not forked; `storeWetInk` still works via the shared service.
- Nav/settings present; configurable; no hardcode. php -l, view:clear, dev-check pass; Tinker-verified.

## Files

- NEW `database/migrations/*_add_destination_flags_to_agency_document_type_compliance.php`
- NEW `app/Services/Compliance/FicaWetInkService.php`
- EDIT `app/Services/Compliance/AgencyComplianceDocTypeService.php`
- EDIT `app/Models/Property.php` (`sellerOwnerContact()`)
- EDIT `app/Http/Controllers/Tools/PdfSplitterController.php`
- EDIT `app/Http/Controllers/Compliance/FicaController.php` (route storeWetInk through service)
- EDIT `app/Http/Controllers/Admin/SplitterDocTypeController.php`
- EDIT `resources/views/admin/splitter/doc-types.blade.php`
- EDIT `resources/views/tools/pdf_splitter_review.blade.php`
- EDIT `resources/views/tools/pdf_splitter.blade.php`
