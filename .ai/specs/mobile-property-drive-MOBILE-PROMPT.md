# Mobile App Prompt — Property Drive (view + download PDFs/files)

> Paste the section below into the Claude session running in the **mobile app repo** (`corex_mobile`).
> The CoreX OS backend (API routes + controller) is already built. No migration, no backend work needed.

---

## ▼▼▼ COPY-PASTE INTO MOBILE APP CLAUDE SESSION ▼▼▼

Add a **Drive** section to the property detail screen: every file that's been filed
against that listing on the web (mandates, FICA docs, e-signed offers, compliance
PDFs, anything from the web app's Drive tab), with folders and a download action. This
is **read-only** for now — no upload/delete/tag from mobile in this pass.

### Endpoints (Sanctum bearer token, same auth as every other mobile property endpoint)

Base: `https://corex.hfcoastal.co.za/api/v1/mobile/properties/{propertyId}`

| Method | Path | Returns |
|---|---|---|
| GET | `/documents` | `{ property_id, folders: [...], documents: [...] }` |
| GET | `/documents/{documentId}/download` | Binary file stream (`Content-Disposition: attachment`) |

Headers: `Authorization: Bearer <token>`, `Accept: application/json` (drop `Accept:
application/json` for the download call — let it stream as a normal file response).

On 401: token expired — trigger the existing re-login flow.
On 403: either the agent doesn't have access to this property, or (for the download
call only) an assistant account has downloads switched off — show the server's
`message` field as a toast/snackbar; don't retry.
On 404 from download: the document isn't actually on this property (stale list) —
re-fetch `/documents` and drop the stale row.

### Data shapes

**List response** (`GET /documents`)
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
      "download_url": "https://corex.hfcoastal.co.za/api/v1/mobile/properties/42/documents/101/download"
    }
  ]
}
```

Notes:
- `documents` is the full flat list, newest first — **not filtered to PDF**, any file
  type filed on the property shows up (mirrors the web Drive tab exactly). Show a
  type-appropriate icon off `mime_type` (pdf/image/doc/generic).
- `document_type` is `null` for files that have no folder assigned yet — group those
  under the "Unfiled" folder from the `folders` array (it's always present, even at
  count 0... actually it's only present when there IS at least one unfiled doc, so
  don't assume it's always there).
- `can_download` is per-row (an assistant's download toggle can change without the
  list changing) — hide/grey the download button when false rather than letting the
  tap 403.
- `download_url` is absolute and already points at the right endpoint — you can use it
  directly instead of constructing the URL yourself, but still attach the bearer token
  header when fetching it (it's not a public link).

### UI

- On the property detail screen, add a **Drive** section (tab or expandable card,
  match whatever pattern the screen already uses for Contacts/Compliance).
- Top: folder chips or an accordion list built from `folders` (label + count). Tapping
  a folder filters the already-fetched `documents` list client-side by
  `document_type_id` (no extra network call) — an "All" chip shows everything unfiltered.
- Each document row: file-type icon, `original_name`, `human_size · uploaded_by.name ·
  created_at (relative, e.g. "3 days ago")`, and a download icon button on the right
  (hidden/greyed if `can_download` is false).
- Tap download → `GET` the `download_url` with the bearer token → save to
  device/open in the OS viewer (same pattern used for any other file download already
  in the app, if one exists; otherwise use `dio`/`http` to stream to a temp file then
  `open_file`/share sheet).
- Pull-to-refresh on the Drive section re-fetches `/documents`.
- Empty state: "No files on this property yet."

### Sync rules

- Purely read-only from mobile — uploading/tagging/deleting stays web-only for now.
  A file uploaded on web appears on mobile after the next fetch/pull-to-refresh; no
  socket/push needed.

### Acceptance

- Opening a property with Drive files shows them grouped correctly, matching what the
  same property's web Drive tab shows.
- Tapping a folder chip filters the list without a network round-trip.
- Downloading a PDF/file opens or saves it successfully on-device.
- A property with zero Drive files shows the empty state, not an error.
- 403 (no property access, or assistant download-blocked) shows a clear message, not a crash.

### Files to look at / modify (typical mobile structure)

- API client module (`MobileProperty`/property service) — add a `documents` fetch and
  a `downloadDocument` call.
- Property detail screen / view-model — add a Drive section state slice.
- Reuse the existing token-storage / auth interceptor — do not roll new auth.
- Reuse whatever file-download/open helper already exists in the app (check for one
  before writing a new one — this is the first file-download feature if none exists yet).

### Spec source of truth

Full spec on the backend repo: `.ai/specs/mobile-property-drive.md`. If anything below
conflicts with that file, that file wins.

## ▲▲▲ END COPY-PASTE ▲▲▲
