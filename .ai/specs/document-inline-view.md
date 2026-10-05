# CoreX OS — Document Inline View ("View" alongside "Download")

> Status: APPROVED for build (Johan's verbal instruction 2026-09-28 — "add a view
> button everywhere so users can also view the pdf instead of having to download
> each time")
> Module: cross-cutting — every drive / document list that stores an uploaded file
> Author: Andre
> Last updated: 2026-09-28

---

## 1. Business requirement

Today every drive in CoreX offers only **Download**. To read a single page of a
mandate an agent must pull the whole file onto the machine they are sitting at,
open it in whatever desktop reader is installed, and then remember to delete it.
On a phone that is worse: the file lands in Downloads and the agent loses the
CoreX page they were on.

Every place that stores an uploaded document gets a **View** action next to
Download. View opens the file **inside CoreX** — a viewer over the current page,
so the agent reads the document and carries on without leaving the record, and
without a copy of a client's FICA document sitting in a shared machine's
Downloads folder.

Download is unchanged and stays exactly where it is. View is additive.

## 2. Pillars

Reads only; writes nothing. Documents already hang off **Property**, **Contact**
and **Deal** through the `documents` pivots — View reaches the same rows through
the same per-record authorization the Download route already enforces. No new
pillar edges.

## 3. Surfaces in scope

| # | Screen | List partial | Existing action | Added |
|---|--------|--------------|-----------------|-------|
| 1 | Property → Drive tab | `corex/properties/_drive-row.blade.php` | Download | View |
| 2 | Contact → Drive tab | `corex/contacts/_drive-row.blade.php` | Download | View |
| 3 | Documents → Shared Drive | `documents/shared-drive/drive.blade.php` | Download (name click already opened a viewer) | explicit View button |
| 4 | Documents → Library | `documents/library/index.blade.php` | Download | View |
| 5 | Deal Register (DR2) → Documents | `dr2/_deal-documents.blade.php` | name → download | View + Download |

**Deliberately NOT in scope — Deals v2 (`deals-v2/show.blade.php`).** Reached for during the
build, then backed out: `DealV2Controller::show()` has been soft-retired since AT-219 and returns
`dr2RetiredRedirect()` before it ever renders, so every `deals-v2.show` link in CoreX now lands on
the DR2 index. That view, and a `deals-v2.documents.view` route to go with it, would have been UI
no user can reach. The live deal-document surface is #5 (the DR2 pipeline Documents tab), which is
in scope and done. If AT-219 is ever un-retired, that page needs the same two-line treatment as #5.

**Deliberately NOT in scope.** DocuPerfect / e-sign download surfaces
(`docuperfect.signatures.download`, the signing-portal download pages, the
compliance FICA submission viewer, rental-application document viewers) already
have their own view/preview routes or are token-gated public pages with their own
spec. They are untouched by this build.

## 4. Data model

**No migration.** No new columns, no new tables.

## 5. Behaviour

### 5.1 What "View" does
A View click opens the file in the CoreX document viewer — a modal over the
current page holding the browser's own PDF renderer (`<iframe>`) or, for an
image, an `<img>`. The modal has the file name, an Open-in-new-tab link, a
Download link (only when the viewer has download rights) and Close (× or Esc).

If JavaScript has not booted, the View anchor is a plain link to the inline URL
with `target="_blank"` — so it degrades to "opens in a new tab", never to nothing.

### 5.2 What is viewable
Only PDF and raster images (`jpg`, `jpeg`, `png`, `gif`, `webp`). Anything else
(Word, Excel, `.svg`, unknown) is **not** viewable: the View button does not
render for that row, and the route falls back to the gated download response.

**SVG is deliberately excluded.** An SVG is a script carrier; serving one inline
from the app origin is stored XSS. For the same reason the inline response never
echoes the stored `mime_type` — it sends a **fixed Content-Type from the
whitelist above** plus `X-Content-Type-Options: nosniff`, so an attacker-chosen
mime on the row cannot change how the browser treats the bytes.

### 5.3 Encrypted documents
`Document::inlineResponse()` streams through `decryptedContents()`, exactly like
`downloadResponse()`. AT-173-enveloped files (`source_type='fica'`) therefore
view correctly; legacy plaintext passes through unchanged. **No inline path ever
reads the raw file off disk.**

## 6. Permissions

Each new view route carries **the same permission + scope guard as the download
route beside it**, with one deliberate difference:

**The view routes do NOT carry `deny_assistant_download`.** That is not an
oversight — it is the documented intent of AT-267. `DenyAssistantDownload`'s own
contract states: *"The assistant can still OPEN and VIEW a document in the
browser — this blocks only the act of pulling the file down."* An assistant with
the download toggle off therefore sees **View but not Download**, which is
exactly the behaviour the agent chose when they set that toggle. The View button
is correspondingly **not** wrapped in `canDownloadDocuments()`; the Download
button still is.

Route-by-route:

| Route name | Guard |
|---|---|
| `corex.properties.files.view` | `authorizeProperty(forEdit: false)` + document-belongs-to-property |
| `corex.contacts.documents.view` | contacts group middleware + document-belongs-to-contact |
| `documents.shared-drive.files.view` | **already exists** — `shared_drive.view` + `authorizeFileDrive()` |
| `documents.library.view` | `features.document_library_v1` + agency scope (BelongsToAgency) |
| `deals-dr2.documents.view` | `permission:view_deals` + owns-by-twin / owns-by-source |

Direct-URL access by ID is blocked by those guards, not merely unlinked
(CLAUDE.md rule 8).

## 7. Files

**Create**
- `resources/views/components/document-viewer.blade.php` — the global viewer modal; exposes `window.CoreXDocViewer`
- `resources/views/components/document-view-link.blade.php` — the reusable View button
- `tests/Feature/Documents/DocumentInlineViewTest.php`

**Modify**
- `app/Models/Document.php` — `inlineMimeType()`, `isViewableInline()`, `inlineResponse()`
- `app/Http/Controllers/CoreX/PropertyFileController.php` — `view()`
- `app/Http/Controllers/CoreX/ContactDocumentController.php` — `view()`
- `app/Http/Controllers/Documents/DocumentLibraryController.php` — `view()`
- `app/Http/Controllers/Dr2/DealDocumentController.php` — `view()`
- `routes/web.php` — 4 new named routes
- `resources/views/layouts/corex.blade.php` + `layouts/corex-app.blade.php` — include the viewer once
- the 6 list partials in §3

## 8. Acceptance criteria

1. Each of the 5 screens in §3 shows **View** next to Download for a PDF row.
2. View opens the PDF over the page; Esc and × close it; the underlying page is
   not reloaded and no file lands in Downloads.
3. A non-viewable row (e.g. `.docx`) shows Download only — no dead View button.
4. Every view route 404s for a document that does not belong to the parent
   record, and 403s for a user outside the record's scope.
5. An assistant with `can_download_documents = false` sees **View** and does not
   see Download; the view route returns 200 and the download route returns 403.
6. A FICA-encrypted document renders as a readable PDF inline (not cipher bytes).
7. An inline response carries `Content-Disposition: inline`, a whitelisted
   `Content-Type`, and `X-Content-Type-Options: nosniff`.
