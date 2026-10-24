# Private document storage — no sensitive file on the public disk

> Security fix, 24 Oct 2026. Johan approved. QA1 only.
> Source finding: `/root/file-storage-audit-2026-10-24.md` ("Documents on the web-served disk").
> Pillars: Property, Contact, Deal, Agent (documents hang off all four).

## 1. The problem (business terms)

nginx serves everything under `storage/app/public` (the `public/storage` symlink) to **anyone on the
internet, with no login**. Several code paths still wrote *documents* there: filed mandate and disclosure
PDFs, PDF-splitter filings, staff take-on documents, rental buyer-acceptance signatures. The file names
carry a random prefix, but a link that leaks (email, browser history, a forwarded URL) opens the document
for anybody. Property photos and website images are meant to be public and stay public.

## 2. Investigation (done before any code)

### 2.1 Every writer of a sensitive file to the public disk (before the fix)

| # | Where | What lands there | Public path |
|---|---|---|---|
| W1 | `app/Http/Controllers/Tools/PdfSplitterController.php` `fileGroupsToDestinations()` (`$publicDisk = Storage::disk('public')`, ~:1747, `put` ~:1850, `'disk' => 'public'` ~:1857) | split mandate / disclosure / ID / FICA PDFs filed to a **property** | `properties/{id}/files/…` |
| W2 | same file `fileGroupsToContact()` (`:793`, `put` ~:824, `'disk' => 'public'` ~:833) | split PDFs filed to a **contact** | `contacts/{id}/files/…` |
| W3 | `app/Http/Controllers/CoreX/PropertyController.php:1597` (create-listing "Drive files") | mandates etc. uploaded while creating a listing; written via the legacy `PropertyFile` model (`property_files`), which no screen reads | `properties/{id}/files/…` |
| W4 | `app/Http/Controllers/StaffTakeOnController.php:404` `uploadDocument()` | staff ID copies, contracts, bank letters (`user_documents` row) | `take-on-docs/{userId}/…` |
| W5 | `app/Models/RentalInventoryBuyerAcceptance.php:133` `storeCanvasImage()` (reached by an **unauthenticated** buyer link) | drawn buyer signature (+ `Storage::url` stored in `party_signature_path`) | `properties/{id}/rental-inventory-buyer-acceptances/…` |
| W6 | `app/Services/Compliance/FicaDocumentStorage::LEGACY_DISK` read fallback + `FicaController.php:1415` (filing copy source) | **legacy** FICA / wet-ink uploads written before AT-173 | `fica/{submission}/…`, `fica/wet-ink/…` |

Already fixed earlier and verified, so NOT touched: property Drive uploads (`PropertyFileController` → `local`, AT-267),
contact Drive uploads (`ContactDocumentController` → `local`), deal documents, deal steps, FICA (encrypted, `local`),
e-sign signed PDFs and originals (`local`), inspection photos (`PrivateImageStore`), inspection/inventory signatures and
wet-ink scans (`PrivateScanFile`), user/HR documents (`AgentPortal…`, `UserDocumentAdminController`), comms attachments,
proformas, CoC/work-authorisation PDFs, rental application documents, viewing packs, rental notices.

### 2.2 Every place that builds a public URL to a sensitive file

| # | Where | Note |
|---|---|---|
| U1 | `resources/views/admin/misfiled-documents/index.blade.php:66` — `Storage::disk($doc->disk ?? 'public')->url(...)` | the misfiled-documents register linked straight to the public file |
| U2 | `resources/views/deals-v2/show.blade.php:358` — `Storage::url($doc->file_path)` | deal-step document chips; on the private disk this link was already dead (404); on `public` it would be open |
| U3 | `app/Models/Document.php::url()` and `app/Models/PropertyFile.php::url()` | public-URL helpers; no Blade uses them but they invite re-introduction |
| U4 | `resources/views/corex/rental-inventories/show.blade.php:477` — `<img src="{{ $acceptance->party_signature_path }}">` | the public URL returned by W5 |
| U5 | `app/Services/Rentals/RentalFaultTypePortalView.php:108` | fault-type **illustration images** — intentionally public, listed for completeness |

Emails and the tenant / landlord / seller portals and the e-sign signer links were checked: none build a
`/storage/` URL to a document. They go through their own token routes (`RentalPortalDocumentService`,
`SecureDocumentController`, signer `SigningController`), which read the file via the `documents.disk` column, so
moving the file and flipping the column keeps them working.

### 2.3 What is on QA1 today (24 Oct 2026)

Database (`documents`, all rows including archived):

| disk | source_type | rows | size |
|---|---|---:|---:|
| public | pdf_splitter | 1,941 | 1.15 GB |
| public | upload | 502 | 0.87 GB |
| public | contact | 14 | 5.5 MB |
| local | (all others) | 1,248 | – |

2,457 document rows point at the public disk (2,370 live + 87 archived). Of those, only 1,567 files under
`properties/*/files/` and 2 under `contacts/*/files/` are physically present on QA1 (it is a partial copy).

Physical files on `storage/app/public`: `properties/` 39,729 (1,567 are `files/` documents: 1,476 pdf, 85 jpg,
2 png, 2 docx, 1 zip, 1 xlsx; 18,815 are `thumbs/`), `fica/` 10 (15 MB, legacy), `contacts/` 2, `esign/` 23
(old developer screenshots, no code writes there), `agents/` 70, `portal-img/` 50, `outreach-cards/` 17,
`at452-test/` 6 (proof JPEGs), plus 31 legacy rental signature PNGs under `properties/*/rental-*-signatures/`.

### 2.4 Meant to be public — left public on purpose (explicit list)

Property gallery photos and `thumbs/` (`properties/{id}/*.jpg`, portals fetch them by URL), property-24/PP agent
images, agent profile photos (`agents/`), agency / branch / company logos, `outreach-cards/` (shareable agent
cards), `marketing-exports/`, `portal-img/`, system-update and agent-article cover images, rental fault-type
illustration images, company-ad images.

## 3. The fix

1. **Writers** W1–W5 write to the private `local` disk only, and record `disk = 'local'` on the row.
2. **One authenticated route** for any document file: `GET /corex/documents/{document}/file`
   (`corex.documents.file`, `?download=1` forces a download). `DocumentFileController` →
   `DocumentAccessService::authorise()`. Checks, in order:
   1. logged in (route is inside the `auth` group; a guest is redirected to login);
   2. same agency — `Document` carries `BelongsToAgency`, so another agency's document id is a **404** (never 403,
      so existence is not leaked);
   3. OWN / BRANCH / AGENCY — allowed when the viewer uploaded it (OWN), or can see a linked property / contact /
      deal / rental application under their existing data scope (`Property::visibleTo`, `ContactScope`,
      `DealV2::visibleTo`, `RentalApplication::visibleTo`), or has agency-wide (`all`) `documents` scope. Anything
      else is **403**;
   4. document-type permission — FICA/identity documents (`source_type = fica` or a type with a `fica_slot`)
      additionally require `fica.view` or `compliance.view`;
   5. assistants: a download needs the "download documents" toggle (view stays allowed), same as today.
   The response is a stream (`Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`), decrypting
   through `MediaCipher` where the file is encrypted. Inline only for PDF / raster images (existing whitelist).
3. Portals and signer links keep their **existing token checks** — untouched.
4. **Every old link repointed**: U1, U2, U3 (the helpers now return the route, never a `/storage` URL), U4.
5. **Move command** `php artisan documents:privatise {--dry-run} {--batch=200}` — moves every `documents` row whose
   `disk = 'public'`, the `user_documents` rows under `take-on-docs/`, the legacy FICA files and the public rental
   signature PNGs onto the private disk. Idempotent (a row already on `local` is skipped, a missing source is
   reported not failed); copies, verifies size, flips the DB pointer inside **one transaction per batch**, and only
   then deletes the public copy; logs every move to the `privatise-documents` log channel/file. After it runs the old
   public URL is a 404 because the file is gone from the public disk.
6. **Second layer (documented, not applied by this change)**: `.ai/deploy/nginx-deny-public-document-folders.conf` —
   an nginx `location` block returning 404 for the document sub-folders of `/storage/`. Andre applies it on live /
   staging. Not applied to any nginx on this box by this change.

## 4. List-screen / CRUD / scoping statement

No new list screen or entity: this change adds one **download** endpoint and a console command. Scoping for the
download is own / branch / agency as in §3.2, enforced in the controller via the query layer (global
`AgencyScope` on route binding), not by hiding links. Archived (soft-deleted) documents are not served.
Multi-agency: nothing in the change names an agency; paths and rules are per-document.

## 5. Permissions / navigation / wizard

No new permission key (reuses `documents.view` data scope, `fica.view`, `compliance.view`). No new page → no
navigation entry. No new agency setting → nothing for the Setup Wizard.

## 6. Acceptance criteria

- A guest on `corex.documents.file` is redirected to login.
- Another agency's document → 404.
- An `own`-scope agent opening a colleague's listing document → 403.
- The uploader, a viewer with a linked-property/contact scope, and an agency-wide user can download.
- Rental portal / deal secure-link / signer token paths still return their documents.
- After `documents:privatise`, the old `/storage/properties/{id}/files/…` URL returns 404 and the document still
  opens through the route.
- Running `documents:privatise` twice moves nothing the second time.

## 7. Files

Created: `app/Services/Documents/DocumentAccessService.php`, `app/Http/Controllers/CoreX/DocumentFileController.php`,
`app/Console/Commands/PrivatiseDocuments.php`, `.ai/deploy/nginx-deny-public-document-folders.conf`,
`tests/Feature/Documents/PrivateDocumentStorageTest.php`.
Modified: `PdfSplitterController`, `PropertyController`, `StaffTakeOnController`, `RentalInventoryBuyerAcceptance`,
`Document`, `PropertyFile`, `routes/web.php`, `misfiled-documents/index.blade.php`, `deals-v2/show.blade.php`,
`rental-inventories/show.blade.php`.
