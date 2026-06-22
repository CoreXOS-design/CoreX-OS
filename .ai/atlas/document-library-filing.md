# Atlas — Document Library / Filing Register

> **Status: DONE** · Last verified: 2026-06-22
> Pillars: **Property** × **Contact** (documents auto-link to both). Cross-ref: `esign-docuperfect.md`
> (the main writer), `compliance.md` (FICA docs), `payroll-leave.md` (payslips).

---

## 1. WHAT IT DOES

Stores and files documents, auto-linking each upload to the relevant contact(s) and property (the "one
upload auto-links" principle). **Naming overlaps across THREE+ distinct systems** — keep them separate:

| System | Model | Table | Purpose |
|--------|-------|-------|---------|
| **Unified Documents** (the auto-link target) | `App\Models\Document` | `documents` + pivots `document_contacts`/`document_properties` | E-Sign, FICA, payroll, manual uploads write here; auto-linked to contact/property |
| **Document Library** | `App\Models\DocumentLibraryItem` | `document_library_items` | reusable marketing/CMA files attached to **presentations** — NOT auto-linked |
| **Filing Register** | `App\Models\DocumentFiling` | `document_filing_register` | **metadata-only ledger** (no file storage) of OA/EA mandate filings per branch/agent |
| Shared Drive | `SharedDriveFile`/`SharedDriveFolder` | — | Google-Drive-*style* team store on **local disk** (no actual Google integration) |
| (legacy) | `Docuperfect\Document` + `document_contact` (singular) | — | the e-sign editor's working docs (§9.1) |

---

## 2. ENTRY POINTS

### Routes (`routes/web.php`)
- **Filing Register** `:1047-1054` (`permission:access_filing_register`): `filing-register.index` `:1049`,
  `.store` `:1050`, `.update` `:1051`, `.destroy` `:1052`, `.restore` (`withTrashed`) `:1053` →
  `DocumentFilingController`.
- **Document Library** `:3145-3162` (`permission:access_document_library`): `documents.library.index`
  `:3146`, `.upload` `:3148`, `.download` `:3150`, `.attach` `:3152`, `library.types.*` `:3156-3161` →
  `Documents\DocumentLibraryController`.
- **Shared Drive** `:3165-3193` (`permission:access_shared_drive`): index/folder/upload/download/destroy →
  `Documents\SharedDriveController`.

Views `resources/views/{filing-register/index,documents/library/index,documents/shared-drive/index}.blade.php`.
Nav `corex-sidebar.blade.php`: Library `:1288-1295`, Filing Register `:1300-1306`, Shared Drive `:902`.

---

## 3. THE FLOW — auto-linking ("one upload auto-links")

Canonical helper `SignatureService::linkFiledDocumentToContactsAndProperty` (`:2091-2102`):
`$filedDoc->contacts()->syncWithoutDetaching([$contactId => ['party_role'=>$role]])` per signer, +
`$filedDoc->properties()->syncWithoutDetaching([$propertyId])`. Contacts resolved by
`resolveSigningContacts()` (`:2074-2086`, matches signer email → `Contact` by email); property from
`$document->property_id` (`:1821`); document-type from the template (`:1876`). Manual-upload auto-link is
hand-rolled per controller via `attach()`: `PropertyFileController.php:44-48`,
`MobileContactComplianceController.php:197-201`, `PdfSplitterController.php:500`, `FicaController.php:838`.

---

## 4. DATA IT READS / WRITES

### `App\Models\Document` (`documents`) — `Document.php`
`SoftDeletes, BelongsToAgency, BelongsToBranch` `:15`. Fillable `:19-24`: `agency_id`, `branch_id`,
`original_name`, **`storage_path`** (no `file_path` column), `disk`, `mime_type`, `size`,
`document_type_id`, **`source_type`** (upload/esign/fica/payroll/pdf_splitter/leave_application),
`source_id`, `uploaded_by` (no `owner` column). Relationships: `documentType()` `:30`, `uploader()` `:35`,
`contacts()` via **`document_contacts`** (plural, pivot `party_role`) `:40-45`, `properties()` via
**`document_properties`** `:47-51`. Migration `2026_03_24_200001_create_unified_documents_table.php`
(pivots unique `(document_id,contact_id,party_role)` / `(document_id,property_id)`; `agency_id` added
`2026_05_23_090*`). `document_types` table (`2026_03_03_000001`; FICA types seeded `2026_04_21_121927`).

### Writers of `Document` (§5 detail)
| Writer | file:line | source_type |
|--------|-----------|-------------|
| E-Sign single doc | `SignatureService.php:1870` (`fileSingleDocument`) | esign |
| E-Sign pack (split) | `SignatureService.php:1979` (`filePackDocuments`, PDFs under `docuperfect/signed-documents/{id}/individual` `:1923`) | esign |
| FICA → contact drive | `FicaController.php:827` | fica |
| Payroll payslips | `PayrollFinaliseService.php:76` (+`UserDocument` `:96`) | payroll |
| Manual property upload | `PropertyFileController.php:35` (disk `public`, `properties/{id}/files`) | upload |
| Mobile contact drive | `MobileContactComplianceController.php:187` | upload |
| PDF Splitter | `PdfSplitterController.php:490` | pdf_splitter |
| Leave application docs | `MyPortalLeaveController.php:178` | leave_application |

> Note: `Document::create` in `Docuperfect/DocumentController.php:82`, `ESignWizardController.php:1907` etc.
> create **`App\Models\Docuperfect\Document`** (the e-sign editor working docs), NOT the unified `Document`.
> Presentation PDFs go through `DocumentLibraryItem`, not the unified table.

### Filing Register — `DocumentFiling` (`document_filing_register`)
`BelongsToAgency, SoftDeletes` `:12`. **Metadata-only — no file stored.** Columns: `branch_id`, `agent_id`,
`document_type` enum(OA/EA/Other), `file_reference`, `sequence_number`, `property_address`, `seller_name`,
`expiry_date`, `notes`, `captured_by`. **Only writer = manual entry** (`DocumentFilingController@store:116`).
`getStatusAttribute` computes active/expiring/expired from `expiry_date` `:104-119`.

---

## 5. STORAGE

Per-row `disk` column. E-sign/FICA/payroll/leave/mobile use `local`; property-file & pdf-splitter use
`public`. Paths: e-sign `docuperfect/signed-documents/{tpl}/individual/`, wet-ink
`docuperfect/wet-ink-uploads`, FICA/mobile `contact-documents/{id}/`, property `properties/{id}/files`.
Library on `local` under `document_library/Y/m/`. **No actual Google Drive** — the "Shared Drive" is a
local-disk store (`SharedDriveService.php:47`); grep for `googleapis`/`GoogleDrive` returns nothing.

---

## 6. AFFECTS DOWNSTREAM / 7. AFFECTED BY UPSTREAM

Documents are read by the contact drive (`Contact::documents()`/`signedDocuments()`), property files tab,
FICA review, and compliance. They are **written by** e-Sign completion, FICA approval, payroll finalise,
leave applications, and manual uploads — i.e. nearly every pillar files into the unified `documents` table.

---

## 8. AGENCY SETTINGS / CONFIG

Permission-gated only (`access_filing_register`, `access_document_library`, `access_shared_drive`).
`document_types` (key/label/sort/is_active) and `RentalDocumentType` are the configurable catalogues.
No agency-level document settings beyond these.

---

## 9. KNOWN FRAGILITIES

1. **TWO pivot lineages — `document_contact` (singular) vs `document_contacts` (plural).** The single
   biggest fragility. Singular (`2026_03_22_240001`, FK → `docuperfect_documents`) is written by
   `SignatureService::linkDocumentToContacts` (raw `DB::table('document_contact')->updateOrInsert` `:1783`),
   read by `Contact::signedDocuments()`/`ficaDocuments()`. Plural (`2026_03_24_200001`, FK → `documents`)
   is written by `linkFiledDocumentToContactsAndProperty` (`:2094`), read by `Contact::documents()`/
   `Document::contacts()`. **On one e-sign completion BOTH are written against different document records**
   (`:1732` then `:1735`) — the contact's drive UI and signed-documents UI read from two unrelated tables
   and can diverge (the plural write sits in a post-completion try/catch `:1731-1748` and is swallowed on
   failure).
2. **Orphan documents.** Nothing globally reaps `documents` rows with all-empty pivots except an ad-hoc
   check in `PropertyFileController::destroy:73-77`. A failed `attach()` after a successful `Document::create`
   (e.g. exception between create `:1870` and link `:1882`) leaves an unlinked, invisible document.
3. **Soft-delete recovery asymmetry.** `Document` soft-deletes keep the file + pivots, but there is **no UI
   route to restore a unified `Document`** (unlike Filing Register `restore` `web.php:1053` and Shared
   Drive) — recovery is DB-only.
4. **Auto-link race (V2 BUG#4) — FIXED on the singular side, residual on the plural.** The original
   non-atomic `exists()+insert()` on `document_contact` was replaced with atomic `updateOrInsert`
   (`SignatureService.php:1783`). But the document-record de-dup guard is still a non-atomic
   `Document::where('storage_path')->exists()` (`:1847`, pack `:1934`) — concurrent completions for the same
   PDF path can create duplicate `documents` rows (the pivot unique key doesn't protect the table itself).
5. **`party_role` nullable in the plural unique key** `(document_id, contact_id, party_role)` — a contact
   linked once with a role (e-sign) and once with null (manual `attach`, `FicaController.php:838`) produces
   TWO pivot rows for the same contact+document, inflating the drive listing.
6. **`source_type` is a loose 20-char string** with no enum/DB constraint — a typo silently mis-categorises
   a document and breaks source-based filtering.

---

## Key file:line index
- `app/Models/Document.php:15-63`; `app/Models/DocumentFiling.php:12-119`; `app/Models/DocumentLibraryItem.php`.
- `app/Services/Docuperfect/SignatureService.php:1783-2102` (both pivot writers + auto-link).
- `app/Http/Controllers/DocumentFilingController.php:109-187`; `Documents/DocumentLibraryController.php:59-143`; `Documents/SharedDriveController.php`.
- Migrations `2026_03_24_200001_create_unified_documents_table.php`, `2026_03_22_240001_create_document_contact_table.php`, `2026_02_24_500000_create_document_filing_register_table.php`.
