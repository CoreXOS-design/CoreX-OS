# Mobile Property Drive — Spec

> Mobile (`corex_mobile`) Property Drive module.
> Created: 2026-08-03

## Purpose

An agent on a property's mobile detail screen needs to see and open every file that's
been filed against that listing on the web — mandate, FICA docs, e-signed offers,
compliance PDFs, photos filed as documents, anything uploaded via the web Drive tab —
without going back to a laptop. This brings the existing web **Property → Drive** tab
(`resources/views/corex/properties/show.blade.php`, Drive tab) to mobile, read-only.

This is deliberately scoped to **Property Drive only** (not Contact Drive, not the
separate Shared Drive module — `.ai/specs/shared-drive.md`). It reuses the same
`documents` store the web Drive tab already reads from; there is no new table, no new
upload path, no new document type taxonomy. Upload/delete/tag from mobile is out of
scope for this pass — it's a viewer.

## Pillars

- **Property** — every document listed here is filed against one property (`document_properties` pivot)
- **Agent** — visibility is scoped to properties the logged-in agent (or their assistant,
  data-scope permitting) can already view — same rule as every other mobile property endpoint

## Data model

No migrations. Reuses://
- `documents` table / `App\Models\Document` (agency-scoped via `BelongsToAgency`, `app/Models/Document.php`)
- `document_properties` pivot (`Property::documents()`, `app/Models/Property.php:662`)
- `document_types` table / `App\Models\DocumentType` — folder label/slug/sort_order

## API surface (auth:sanctum, existing `mobile/properties` group — `routes/api.php`)

| Method | Route | Purpose |
|---|---|---|
| GET | `/api/v1/mobile/properties/{property}/documents` | List every document filed on the property, plus folder (document-type) counts |
| GET | `/api/v1/mobile/properties/{property}/documents/{document}/download` | Stream the file bytes |

Both routes sit inside the existing `mobile/properties` prefix group (`routes/api.php:371`),
so they inherit `deny_assistant_property_write` for free — GET requests always pass that
middleware (it only blocks non-safe methods), which is correct here since this is read-only.
The download route additionally carries `deny_assistant_download`, mirroring the web
route (`routes/web.php:3084`) and every other document-download surface in the app — an
assistant whose agent has switched off "download documents" gets a 403 on mobile too,
not just on web.

Authorization: both actions call the controller's existing `authorizeProperty($user,
$property)` (the same private helper every other `MobilePropertyController` action uses,
`app/Http/Controllers/Api/MobilePropertyController.php:1398`) — no new permission key.
This isn't new capability: it's the existing property-view scope, applied to a view the
web app already renders for the same scope. (Non-negotiable #5 note: reusing the
established property-access gate here, rather than inventing a parallel
`property_documents.view` key that would just have to be kept in sync with it, is the
deliberate call — same pattern as `contactsIndex`/`galleryTags`, which also carry no
document-specific permission key of their own.)

Not duplicated into the `LEGACY: remove after 2026-08-21` route block
(`routes/api.php:604`) — that block is scheduled for removal and existing app builds
have no Drive UI to call it from.

## Response shapes

**`GET /documents`**

```json
{
  "property_id": 42,
  "folders": [
    { "document_type_id": 3, "label": "Mandate", "slug": "mandate", "count": 2 },
    { "document_type_id": null, "label": "Unfiled", "slug": null, "count": 1 }
  ],
  "documents": [
    {
      "id": 101,
      "original_name": "Mandate Agreement.pdf",
      "mime_type": "application/pdf",
      "size": 245678,
      "human_size": "240.0 KB",
      "document_type": { "id": 3, "label": "Mandate", "slug": "mandate" },
      "source_type": "upload",
      "uploaded_by": { "id": 5, "name": "Jane Agent" },
      "created_at": "2026-08-01T10:22:00+02:00",
      "can_download": true,
      "download_url": "https://corex.test/api/v1/mobile/properties/42/documents/101/download"
    }
  ]
}
```

- `documents` is the flat list, newest first (`created_at desc`) — no file-type filter;
  mirrors web Drive (all file types, not PDF-only — confirmed against
  `PropertyFileController::store`, which validates any document mime, not just PDF).
- `document_type` is `null` for unfiled documents (same "Unfiled" bucket web shows).
- `folders` is precomputed server-side (group + count) so mobile doesn't have to
  replicate the web's grouping logic — it drives the folder/accordion list; tapping a
  folder just filters the already-fetched `documents` array client-side by
  `document_type_id`.
- `can_download` reflects `$user->canDownloadDocuments()` per row, so the app can grey
  out the download button instead of the request 403ing (same signal the web Blade view
  uses to decide whether to render the download link at all).
- `download_url` is absolute (mirrors the existing image-URL convention in
  `MobilePropertyPortalAndImagesTest`), points at the second endpoint below.

**`GET /documents/{document}/download`** — binary stream, `Content-Disposition:
attachment; filename="<original_name>"`, matching `PropertyFileController::download`
exactly (`Storage::disk($document->disk ?: 'local')->download(...)`). 404 if the
document isn't actually pivoted to this property (cross-property/cross-agency guard,
same check the web controller makes). 403 (via `deny_assistant_download`) if the
assistant's download toggle is off.

## User flow

```
Property detail screen (mobile)
  └─ Drive section/tab
        ├─ GET /documents on mount (and pull-to-refresh)
        ├─ Folder chips/accordion built from `folders` (incl. "Unfiled")
        ├─ Tap a folder → filter `documents` client-side by document_type_id
        ├─ Each row: filename, type badge, human_size, uploaded_by · created_at
        │     └─ Download button (hidden/greyed if can_download=false)
        └─ Tap Download → GET .../download → save/open the file on-device
```

## Permissions

No new permission key (see API surface note above). Gated by:
- existing property data-scope (`authorizeProperty` → same own/branch/all rules as every
  other mobile property read)
- `deny_assistant_download` on the download route only

## Acceptance criteria

- Agent viewing a property they have access to sees every document filed against it
  (all sources: upload, esign, pdf_splitter), matching the web Drive tab's contents
  exactly for the same property.
- Agent without access to the property gets 403 on both routes (property scope).
- Assistant with `can_download_documents = false` gets `can_download: false` on every
  row from the index, and a 403 if they call download anyway.
- A document belonging to a different property (or a different agency) 404s on
  `/documents/{document}/download` even if the ID is guessed.
- `folders` counts match the number of documents in `documents` with that
  `document_type_id` (including the `null`/"Unfiled" bucket).
- All routes resolve, appear on `/admin/api`, `php -l` clean.

## Files created/modified

- **MOD** `app/Http/Controllers/Api/MobilePropertyController.php` — `documentsIndex`, `documentsDownload`
- **MOD** `routes/api.php` — two routes in the `mobile/properties` group
- **MOD** `.ai/MOBILE_APP.md` — endpoint list
- **NEW** `.ai/specs/mobile-property-drive.md` (this file)
- **NEW** `.ai/specs/mobile-property-drive-MOBILE-PROMPT.md`
- **NEW** `tests/Feature/Api/MobilePropertyDriveTest.php`
